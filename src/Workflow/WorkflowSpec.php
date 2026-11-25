<?php

declare(strict_types=1);

/**
 * Définition d'un workflow — SOURCE UNIQUE de sa forme, entièrement pure.
 *
 * Un workflow est un RAIL : un départ, une suite de briques, un livrable. Une
 * condition ouvre DEUX VOIES, « si oui » et « si non », chacune avec ses
 * briques ; une décision de MIA (aiguillage) en ouvre de deux à six, une par
 * scénario décrit par la personne ; chaque voie reprend le rail après la
 * brique ou arrête le workflow (branchesOf : la même mécanique pour les deux). Une boucle répète son CORPS pour chaque élément d'une liste, ou
 * jusqu'à ce qu'une condition soit remplie. Un DOSSIER de pièces circule : le
 * départ pose les premières, chaque étape en remet UNE, les briques suivantes
 * les lisent.
 *
 *   {
 *     v, objective,
 *     start: { title, fiche_name, fields: [{key, label, type, required, options, default, help}],
 *              schedule: null | {rule, timezone}, chat: bool,
 *              event: null | {kind: email|file|webhook|workflow, …, piece_name} },
 *     nodes: [ brique… ],                       le rail principal, dans l'ordre
 *     end:   { title, pieces: [clé…], note, deliveries: [livraison…] },
 *     notify: bool
 *   }
 *
 * Briques (`type`) :
 *   step      — un assistant (ou MIA) lit des pièces et en REMET une ;
 *               write: none | approve | auto (ce qu'il peut faire dans les outils) ;
 *               review: la personne relit la pièce avant la suite ;
 *               on_error: {retries, then: stop | skip}. Sa pièce peut aussi
 *               être une CRÉATION (`output.form` ∈ CREATE_KINDS : image, page,
 *               carte) faite par un outil du chat (`output.tool_id`,
 *               `output.options`) : l'étape PRÉPARE la demande (avec son
 *               assistant, ses sources), puis l'outil la fabrique — le même
 *               job qu'une brique Création (WorkflowCreations).
 *   action    — agir dans un outil (email, agenda, tâches, Slack, Drive, n8n, Gamma) ;
 *               write: approve | auto ; ne remet aucune pièce.
 *   create    — CRÉER avec un outil du chat ouvert à la personne : une image,
 *               une page web (simple ou illustrée), une carte d'itinéraire
 *               (`kind`, CREATE_KINDS) ; `instruction` dit quoi créer en citant
 *               des pièces ; remet UNE pièce de sa nature (image, page, map),
 *               relue au besoin (`review`). Une image lue sert d'image de départ,
 *               une page ou une carte lue de base à modifier.
 *   condition — si… sinon : une RÈGLE sur un champ de Fiche, ou une QUESTION à
 *               MIA ; `yes` / `no` = { nodes: [briques intérieures], then: continue | stop }.
 *   route     — décision de MIA : elle lit des pièces, suit des critères
 *               (`instruction`) et CHOISIT une voie parmi `routes`
 *               [{ key, label, when, nodes, then }] ; `fallback` : `ask`
 *               (la personne tranche) ou la clé d'une voie, quand MIA ne sait pas.
 *   loop      — boucle : `each` répète son corps pour chaque élément d'une
 *               pièce (liste, lignes d'un tableau, champ liste d'une Fiche),
 *               l'élément en cours étant une pièce du corps ; `until` le répète
 *               jusqu'à ce qu'une règle ou une question soit vraie. Remet UNE
 *               pièce : les résultats rassemblés (each), la dernière version (until).
 *   gate      — validation : une personne relit des pièces (approuver, corriger,
 *               renvoyer à l'étape avec une remarque, arrêter).
 *   wait      — attendre une durée, ou jusqu'à la date d'un champ de Fiche.
 *   notify    — prévenir la personne (appareils, conversation), sans IA.
 *   call      — APPELER UN AUTRE WORKFLOW de la personne (`workflow_id`) : ses
 *               paramètres (`params` : champ du départ de l'appelé → valeur,
 *               un texte qui cite le dossier ; un champ Fichier reçoit une
 *               pièce) remplissent son départ ; l'exécution attend qu'il se
 *               termine et REMET une pièce — une pièce de son livrable, sous
 *               sa forme (`output.from`), ou tout son livrable en un texte.
 *               `on_stop` : l'appelé s'arrête avant son livrable → ce workflow
 *               s'arrête aussi, ou continue sans la pièce (WorkflowCall).
 * Une voie ou un corps de boucle ne porte ni condition, ni décision, ni boucle
 * (INNER_TYPES). Une étape ou une action porte des FILTRES (WorkflowFilters) :
 * ce que ses recherches d'emails et de fichiers voient, à qui ses emails
 * partent.
 *
 * Pièces (`form`) : text, list, table, fiche (champs nommés), document
 * (Word, PDF, PowerPoint, Excel ou Markdown, fabriqué au téléchargement), file
 * (un fichier reçu au départ), et celles d'une création : image, page (page
 * web), map (itinéraire) — remises par une brique Création ou par une étape
 * dont la pièce est une création. Une consigne cite une pièce par `@{clé}` ; un
 * texte de livraison cite aussi un champ de Fiche par `@{clé.champ}`.
 *
 * Livraisons du livrable (`end.deliveries`) : email (Gmail, Outlook), Drive,
 * Slack, base de connaissances — chacune à approuver par une carte, ou sans
 * validation si l'administrateur le permet.
 *
 * `normalize()` ne lève JAMAIS : l'éditeur enregistre des brouillons
 * incomplets. Ce qui empêche de publier est dit par `issues()` (« À régler »),
 * calculé ici et nulle part ailleurs — l'écran, la publication et l'outil
 * `creer_workflow` lisent la même liste. Le rail du navigateur
 * (workflow-rail.js) recompte les pièces de la même façon : un test compare
 * les deux.
 */
final class WorkflowSpec
{
    public const VERSION = 1;

    public const START_KEY   = 'start';
    public const END_KEY     = 'end';
    public const START_PIECE = 'depart';
    /** Pièce posée par l'évènement qui a lancé l'exécution (email, fichier, appel…). */
    public const EVENT_PIECE = 'evenement';
    /** Issue d'une voie qui s'arrête : le workflow se termine sans livrable. */
    public const STOP = '__stop__';
    /** Issue de la dernière brique d'un corps de boucle : `@end:<clé de la boucle>`. */
    public const LOOP_END = '@end:';
    /** Suffixe du point « condition de sortie » d'une boucle (pièces disponibles). */
    public const UNTIL_SUFFIX = '~until';

    public const MAX_NODES       = 15;
    public const MAX_FIELDS      = 12;
    public const MAX_FICHE       = 12;
    public const MAX_RULE_ITEMS  = 8;
    public const MAX_OPTIONS     = 12;
    public const MAX_SOURCES     = 8;
    public const MAX_KBS         = 3;
    public const NAME_MAX        = 120;
    public const OBJECTIVE_MAX   = 500;
    public const TITLE_MAX       = 120;
    public const LABEL_MAX       = 80;
    public const HELP_MAX        = 200;
    public const INSTRUCTION_MAX = 4000;
    public const QUESTION_MAX    = 500;
    public const NOTE_MAX        = 600;
    public const KEY_MAX         = 24;

    public const SUBJECT_MAX     = 200;
    public const MESSAGE_MAX     = 2000;
    public const QUERY_MAX       = 200;
    public const FOLDER_MAX      = 300;

    public const TYPE_STEP      = 'step';
    public const TYPE_ACTION    = 'action';
    public const TYPE_CREATE    = 'create';
    public const TYPE_CONDITION = 'condition';
    public const TYPE_LOOP      = 'loop';
    public const TYPE_GATE      = 'gate';
    public const TYPE_WAIT      = 'wait';
    public const TYPE_NOTIFY    = 'notify';
    public const TYPE_ROUTE     = 'route';
    /** « Arrêter le workflow » : termine l'exécution proprement, dans une voie. */
    public const TYPE_STOP      = 'stop';
    /** « Appeler un workflow » : un autre workflow de la personne, avec ses paramètres (WorkflowCall). */
    public const TYPE_CALL      = 'call';
    public const TYPES = [
        self::TYPE_STEP, self::TYPE_ACTION, self::TYPE_CREATE, self::TYPE_CONDITION, self::TYPE_ROUTE, self::TYPE_LOOP,
        self::TYPE_GATE, self::TYPE_WAIT, self::TYPE_NOTIFY, self::TYPE_CALL,
    ];
    /** Briques qui ouvrent des voies (branchesOf). */
    public const BRANCHING_TYPES = [self::TYPE_CONDITION, self::TYPE_ROUTE];
    /** Briques d'une voie ou d'un corps de boucle : ni condition, ni décision, ni boucle. */
    public const INNER_TYPES = [self::TYPE_STEP, self::TYPE_ACTION, self::TYPE_CREATE, self::TYPE_GATE, self::TYPE_WAIT, self::TYPE_NOTIFY, self::TYPE_CALL];
    /**
     * Briques d'une voie de condition ou de décision : celles d'un corps de
     * boucle, plus « Arrêter le workflow ». Sur le rail, elle s'exécuterait
     * à chaque fois (le livrable ne serait jamais atteint) ; dans un corps
     * de boucle, au premier tour : elle n'a de sens qu'au bout d'une voie.
     */
    public const LANE_TYPES = [...self::INNER_TYPES, self::TYPE_STOP];
    /** Statut d'une exécution finie par « Arrêter le workflow ». */
    public const STOP_DONE    = 'done';
    public const STOP_STOPPED = 'stopped';
    /** Briques qui REMETTENT une pièce au dossier (et peuvent être relues, refaites). */
    public const PRODUCING_TYPES = [self::TYPE_STEP, self::TYPE_CREATE, self::TYPE_CALL];

    // Création
    /**
     * Ce qu'une brique Création fabrique (`kind`, qui est aussi la forme de
     * sa pièce) → les types d'outil du chat qui le font. Les créations à volet
     * qui ne se programment pas (vidéo : aucun fournisseur en service ;
     * présentation : un document PowerPoint d'étape la remplace) n'y sont pas.
     */
    public const CREATE_KINDS = [
        'image' => ['image'],
        'page'  => ['html', 'html_illustrated'],
        'map'   => ['map'],
    ];
    /** Format d'une image créée : '' = celui de l'outil. */
    public const IMAGE_FORMATS = ['', 'square', 'landscape', 'portrait'];
    /** Style des illustrations d'une page illustrée (caractères). */
    public const STYLE_MAX = 200;

    // Boucles
    public const LOOP_EACH  = 'each';
    public const LOOP_UNTIL = 'until';
    /**
     * Éléments d'une boucle « pour chaque », au plus : la borne de la forme.
     * Le plafond appliqué est celui de l'administrateur (Workflow::loopMax,
     * 200 par défaut), jamais au-delà de celle-ci.
     */
    public const LOOP_EACH_MAX      = 500;
    public const LOOP_EACH_DEFAULT  = 50;
    public const LOOP_UNTIL_MAX     = 5;
    public const LOOP_UNTIL_DEFAULT = 3;
    /** Texte d'un élément de boucle (caractères). */
    public const ITEM_MAX = 4000;
    /** Un élément échoue : la boucle passe au suivant (le marque en échec), ou le workflow s'arrête. */
    public const ITEM_ERROR_CONTINUE = 'continue';
    public const ITEM_ERROR_STOP     = 'stop';
    /** Échecs d'affilée qui mettent la boucle en pause (une clé refusée ne grille pas 200 tours). */
    public const ITEM_FAILURE_STREAK = 3;
    /** Au-delà, la fenêtre de lancement demande de confirmer le nombre d'éléments. */
    public const LOOP_CONFIRM_OVER = 50;
    /** Suite d'une voie de condition DANS une boucle : passer à l'élément suivant. */
    public const THEN_SKIP = 'skip';
    /** Issue d'une condition de boucle qui écarte l'élément : `@skip:<clé de la boucle>`. */
    public const LOOP_SKIP = '@skip:';
    /**
     * Briques d'un corps de boucle : celles d'une voie, plus une condition
     * SANS voies (elle continue le tour, passe à l'élément suivant ou arrête
     * le workflow).
     */
    public const BODY_TYPES = [self::TYPE_STEP, self::TYPE_ACTION, self::TYPE_CREATE, self::TYPE_CONDITION, self::TYPE_GATE, self::TYPE_WAIT, self::TYPE_NOTIFY, self::TYPE_CALL];

    // Appel d'un autre workflow (WorkflowCall)
    /** Valeur d'un paramètre (caractères) : un texte qui peut citer le dossier. */
    public const CALL_PARAM_MAX = 2000;
    /** L'appelé s'arrête avant son livrable : ce workflow s'arrête aussi, ou continue sans la pièce. */
    public const CALL_STOP_STOP     = 'stop';
    public const CALL_STOP_CONTINUE = 'continue';
    /** Formes que peut prendre la pièce récupérée : celles du livrable de l'appelé. */
    public const CALL_FORMS = ['text', 'list', 'table', 'fiche', 'document', 'file', 'image', 'page', 'map'];

    // Attente
    public const WAIT_UNITS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];
    public const WAIT_MAX_SEC = 30 * 86400;
    /** Une date de Fiche plus lointaine ne s'attend pas (l'exécution s'arrête, reprenable). */
    public const WAIT_DATE_MAX_DAYS = 90;

    // Reprise sur erreur
    public const RETRIES_MAX     = 3;
    public const RETRIES_DEFAULT = 1;
    public const ON_ERROR_STOP = 'stop';
    public const ON_ERROR_SKIP = 'skip';

    // Déclencheurs par évènement
    public const EVENTS = ['email', 'file', 'webhook', 'workflow'];
    public const EMAIL_SOURCES = ['gmail', 'outlook'];
    /** Champs de la Fiche « Email reçu » (noms de contenu, comme ceux d'un départ). */
    public const EMAIL_FIELDS = [
        ['key' => 'expediteur',     'label' => 'Expéditeur',     'type' => 'email'],
        ['key' => 'objet',          'label' => 'Objet',          'type' => 'text'],
        ['key' => 'recu_le',        'label' => 'Reçu le',        'type' => 'date'],
        ['key' => 'contenu',        'label' => 'Contenu',        'type' => 'text'],
        ['key' => 'pieces_jointes', 'label' => 'Pièces jointes', 'type' => 'list'],
    ];
    public const EVENT_PIECE_NAMES = [
        'email'    => 'Email reçu',
        'file'     => 'Fichier reçu',
        'webhook'  => 'Données reçues',
        'workflow' => 'Résultat du workflow précédent',
    ];

    // Livraison du livrable
    public const DELIVERY_TYPES = ['email', 'drive', 'slack', 'kb', 'table'];
    public const MAX_DELIVERIES = 5;
    /** Email : carte à approuver, brouillon créé, ou envoi — les deux derniers sans validation. */
    public const MAIL_MODES = ['approve', 'draft', 'send'];
    public const DELIVERY_MODES = ['approve', 'auto'];

    /** Formes rédigées d'une pièce d'étape ; elle peut aussi être une création (CREATE_KINDS). */
    public const FORMS = ['text', 'list', 'table', 'fiche', 'document'];
    /** Champs du formulaire de départ. */
    public const FIELD_TYPES = ['text', 'longtext', 'number', 'date', 'choice', 'email', 'yesno', 'file'];
    /** Champs d'une Fiche remise par une étape. */
    public const FICHE_TYPES = ['text', 'number', 'date', 'email', 'yesno', 'list'];
    /** Documents rédigés (Markdown ou tableau) : la liste « Documents » de l'éditeur. */
    public const DOC_FORMATS = ['docx', 'pdf', 'pptx', 'xlsx', 'md'];
    /**
     * Autres fichiers proposés par l'éditeur (brique « Fichier » du menu
     * Créer) : leur contenu est écrit tel quel par le modèle, puis recopié.
     * Toute autre extension de fichier texte s'accepte aussi (docFormat).
     */
    public const FILE_FORMATS = ['py', 'js', 'ts', 'json', 'csv', 'html', 'css', 'sql', 'sh', 'yaml', 'xml', 'txt', 'php', 'ps1', 'ics', 'tex'];
    /** Formats qu'habille le modèle Word ou PowerPoint de l'entreprise. */
    public const TEMPLATE_FORMATS = ['docx', 'pdf', 'pptx'];

    public const WRITE_NONE    = 'none';
    public const WRITE_APPROVE = 'approve';
    public const WRITE_AUTO    = 'auto';
    public const WRITE_MODES   = [self::WRITE_NONE, self::WRITE_APPROVE, self::WRITE_AUTO];

    /**
     * Outils qu'une brique Action sait piloter : clé → connecteur engagé pour
     * le tour (une clé de ConnectorRegistry), ou type d'outil (`tool:n8n`).
     */
    public const ACTION_TARGETS = [
        'gmail'      => 'gmail',
        'outlook'    => 'outlook',
        'calendar'   => 'calendar',
        'mscalendar' => 'mscalendar',
        'tasks'      => 'tasks',
        'mstodo'     => 'mstodo',
        'slack'      => 'slack',
        'teams'      => 'teams',
        'drive'      => 'drive',
        'n8n'        => 'tool:n8n',
        'gamma'      => 'tool:gamma',
    ];

    /**
     * Brique Slack : elle PUBLIE ou elle LIT — l'un ou l'autre, jamais les
     * deux (normalizeSlack). Publier : dans un canal ou à une personne
     * (message direct), un texte et/ou des pièces du dossier jointes en
     * fichiers ; la destination et les fichiers sont IMPOSÉS par le serveur,
     * le modèle n'écrit que le texte. Lire : un canal, la conversation avec
     * une personne ou une recherche, sur une période ; les messages sont lus
     * par le serveur avant le tour, et la brique remet une pièce, comme une
     * étape (isReadingAction, producesPiece).
     */
    public const SLACK_POST    = 'post';
    public const SLACK_READ    = 'read';
    public const SLACK_CHANNEL = 'channel';
    public const SLACK_PERSON  = 'person';
    public const SLACK_SEARCH  = 'search';
    public const SLACK_DAYS_DEFAULT = 7;
    public const SLACK_DAYS_MAX = 90;
    public const SLACK_REF_MAX = 300;
    public const SLACK_ATTACH_MAX = 10;

    public const RULE_OPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'empty', 'notempty', 'yes', 'no'];
    public const DOUBT_ASK = 'ask';
    public const DOUBT_NO  = 'no';
    public const THEN_STOP     = 'stop';
    public const THEN_CONTINUE = 'continue';
    /** Les deux voies d'une condition. */
    public const LANES = ['yes', 'no'];

    // Décision de MIA (aiguillage)
    public const MIN_ROUTES = 2;
    public const MAX_ROUTES = 6;
    public const ROUTE_LABEL_MAX = 60;
    public const ROUTE_WHEN_MAX  = 400;
    public const CRITERIA_MAX    = 1500;
    /** MIA ne sait pas choisir : la personne tranche (sinon, la clé d'une voie). */
    public const FALLBACK_ASK = 'ask';
    /** Clés de voie réservées : « main » (rail), « body » (corps de boucle), celles d'une condition. */
    public const RESERVED_LANES = ['main', 'body', 'yes', 'no'];

    public const GATE_DAYS_DEFAULT = 7;
    public const GATE_DAYS_MAX     = 14;
    /** Renvois d'une validation vers son étape, au plus. */
    public const MAX_RETURNS = 2;

    public const LEVEL_BLOCK = 'block';
    public const LEVEL_WARN  = 'warn';

    // ==================================================================
    // Forme
    // ==================================================================

    /** Workflow vide : un départ manuel, une étape, le livrable. */
    public static function blank(): array
    {
        return self::normalize([
            'objective' => '',
            'start'     => ['fields' => [['key' => 'demande', 'label' => 'Demande', 'type' => 'longtext', 'required' => true]]],
            'nodes'     => [[
                'key' => 'etape_1', 'type' => self::TYPE_STEP, 'title' => 'Première étape',
                'instruction' => '', 'output' => ['key' => 'resultat', 'name' => 'Résultat', 'form' => 'text'],
            ]],
            'end'       => ['pieces' => ['resultat']],
        ]);
    }

    /**
     * Met une définition en forme canonique : clés valides et uniques, textes
     * bornés, valeurs dans leurs listes. Ne lève jamais.
     */
    public static function normalize(array $raw): array
    {
        $used = [self::START_KEY => true, self::END_KEY => true, self::START_PIECE => true, self::EVENT_PIECE => true];
        $start = self::normalizeStart(is_array($raw['start'] ?? null) ? $raw['start'] : [], $used);

        $nodes = [];
        $count = 0;
        foreach (self::listOf($raw['nodes'] ?? []) as $node) {
            if ($count >= self::MAX_NODES) {
                break;
            }
            $n = self::normalizeNode($node, $used, false, $count);
            if ($n !== null) {
                $nodes[] = $n;
            }
        }

        $def = [
            'v'         => self::VERSION,
            'objective' => self::text($raw['objective'] ?? '', self::OBJECTIVE_MAX, true),
            'start'     => $start,
            'nodes'     => $nodes,
            'end'       => [],
            'notify'    => array_key_exists('notify', $raw) ? self::bool($raw['notify']) : true,
        ];

        $end = is_array($raw['end'] ?? null) ? $raw['end'] : [];
        $known = array_keys(self::pieces($def));
        $pieces = [];
        foreach (self::listOf($end['pieces'] ?? []) as $p) {
            $p = (string)$p;
            if (in_array($p, $known, true) && !in_array($p, $pieces, true)) {
                $pieces[] = $p;
            }
        }
        $deliveries = [];
        foreach (self::listOf($end['deliveries'] ?? []) as $d) {
            if (count($deliveries) >= self::MAX_DELIVERIES) {
                break;
            }
            $n = self::normalizeDelivery($d, $known);
            if ($n !== null) {
                $deliveries[] = $n;
            }
        }
        $def['end'] = [
            'title'      => self::text($end['title'] ?? '', self::TITLE_MAX) ?: 'Livrable',
            'pieces'     => $pieces,
            'note'       => self::text($end['note'] ?? '', self::NOTE_MAX, true),
            'deliveries' => $deliveries,
        ];
        return $def;
    }

    /**
     * Une livraison du livrable, ou null si son type est inconnu. Les pièces
     * citées sont limitées à celles que le workflow peut produire.
     */
    private static function normalizeDelivery(mixed $raw, array $known): ?array
    {
        if (!is_array($raw) || !in_array($raw['type'] ?? '', self::DELIVERY_TYPES, true)) {
            return null;
        }
        $keys = static function (mixed $list) use ($known): array {
            return array_values(array_filter(self::keyList($list), static fn(string $k): bool => in_array($k, $known, true)));
        };
        $mode = static fn(array $allowed): string => in_array($raw['mode'] ?? '', $allowed, true) ? (string)$raw['mode'] : $allowed[0];
        return match ((string)$raw['type']) {
            'email' => [
                'type'     => 'email',
                'source'   => in_array($raw['source'] ?? '', self::EMAIL_SOURCES, true) ? (string)$raw['source'] : 'gmail',
                'to'       => self::text($raw['to'] ?? '', 300),
                'to_field' => self::fieldRef($raw['to_field'] ?? ''),
                'subject'  => self::text($raw['subject'] ?? '', self::SUBJECT_MAX),
                'message'  => self::text($raw['message'] ?? '', self::MESSAGE_MAX, true),
                'attach'   => $keys($raw['attach'] ?? []),
                'mode'     => $mode(self::MAIL_MODES),
            ],
            'drive' => [
                'type'   => 'drive',
                'pieces' => $keys($raw['pieces'] ?? []),
                'folder' => self::text($raw['folder'] ?? '', self::FOLDER_MAX),
                'mode'   => $mode(self::DELIVERY_MODES),
            ],
            'slack' => [
                'type'    => 'slack',
                'channel' => self::text($raw['channel'] ?? '', 120),
                'message' => self::text($raw['message'] ?? '', self::MESSAGE_MAX, true),
                'pieces'  => $keys($raw['pieces'] ?? []),
                'mode'    => $mode(self::DELIVERY_MODES),
            ],
            // Une table permanente (WorkflowTables) : ajouter les lignes, mettre
            // à jour celles dont la clé se retrouve, ou tout remplacer.
            'table' => [
                'type'        => 'table',
                'kb_id'       => self::posInt($raw['kb_id'] ?? null),
                'pieces'      => $keys($raw['pieces'] ?? []),
                'write'       => in_array($raw['write'] ?? '', WorkflowTables::WRITE_MODES, true) ? (string)$raw['write'] : KbTableWrite::MODE_APPEND,
                'key'         => self::text($raw['key'] ?? '', self::LABEL_MAX),
                'key_source'  => self::text($raw['key_source'] ?? '', self::LABEL_MAX),
                'add_missing' => self::bool($raw['add_missing'] ?? false),
                'mode'        => $mode(self::DELIVERY_MODES),
            ],
            default => [
                'type'    => 'kb',
                'kb_id'   => self::posInt($raw['kb_id'] ?? null),
                'pieces'  => $keys($raw['pieces'] ?? []),
                'replace' => array_key_exists('replace', $raw) ? self::bool($raw['replace']) : true,
            ],
        };
    }

    /** Nom d'un document de référence enregistré par l'éditeur (dans UPLOAD_DIR). */
    public const REFERENCE_PATTERN = '/^wfref_[a-f0-9]{32}(?:\.[a-z0-9]{1,8})?$/';

    /**
     * Document de référence d'un champ Fichier : {ref, name, type, size},
     * ou null. `ref` = nom du fichier sur le disque, jamais un chemin.
     */
    public static function referenceFile(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $ref = is_scalar($raw['ref'] ?? null) ? (string)$raw['ref'] : '';
        if (!preg_match(self::REFERENCE_PATTERN, $ref)) {
            return null;
        }
        return [
            'ref'  => $ref,
            'name' => self::text($raw['name'] ?? '', 200) ?: $ref,
            'type' => self::text($raw['type'] ?? '', 120),
            'size' => max(0, (int)($raw['size'] ?? 0)),
        ];
    }

    /** Référence d'un champ de Fiche « pièce.champ », ou ''. */
    private static function fieldRef(mixed $v): string
    {
        $v = is_scalar($v) ? trim((string)$v) : '';
        return preg_match('/^[a-z0-9_]{1,' . self::KEY_MAX . '}\.[a-z0-9_]{1,' . self::KEY_MAX . '}$/', $v) ? $v : '';
    }

    private static function normalizeStart(array $raw, array &$used): array
    {
        $fields = [];
        foreach (self::listOf($raw['fields'] ?? []) as $i => $f) {
            if (!is_array($f) || count($fields) >= self::MAX_FIELDS) {
                continue;
            }
            $type = in_array($f['type'] ?? '', self::FIELD_TYPES, true) ? (string)$f['type'] : 'text';
            $label = self::text($f['label'] ?? '', self::LABEL_MAX);
            $key = self::uniqueKey((string)($f['key'] ?? ''), $label !== '' ? $label : 'champ', $used);
            $options = [];
            if ($type === 'choice') {
                // Une ligne « Rouge, Vert » (éditeur d'avant ce correctif encore
                // ouvert dans un onglet) se lit comme la liste qu'elle décrit.
                $rawOptions = $f['options'] ?? [];
                if (is_string($rawOptions)) {
                    $rawOptions = explode(',', $rawOptions);
                }
                foreach (self::listOf($rawOptions) as $o) {
                    $o = self::text($o, self::LABEL_MAX);
                    if ($o !== '' && !in_array($o, $options, true) && count($options) < self::MAX_OPTIONS) {
                        $options[] = $o;
                    }
                }
            }
            $default = $type === 'file' ? '' : self::text($f['default'] ?? '', 2000, $type === 'longtext');
            // Un Choix ne vaut que ses options : une autre valeur par défaut
            // serait refusée au lancement (« Choisissez une des options »).
            if ($type === 'choice' && !in_array($default, $options, true)) {
                $default = '';
            }
            $field = [
                'key'      => $key,
                'label'    => $label,
                'type'     => $type,
                'required' => self::bool($f['required'] ?? false),
                'options'  => $options,
                'default'  => $default,
                'help'     => self::text($f['help'] ?? '', self::HELP_MAX),
            ];
            // Un champ Fichier peut porter un DOCUMENT DE RÉFÉRENCE fourni
            // d'office à chaque lancement (un modèle, un texte de loi, nos
            // références) : la pièce existe toujours, on la cite avec @.
            $ref = $type === 'file' ? self::referenceFile($f['reference'] ?? null) : null;
            if ($ref !== null) {
                $field['reference'] = $ref;
            }
            $fields[] = $field;
        }

        $schedule = null;
        if (is_array($raw['schedule'] ?? null) && !empty($raw['schedule']['rule'])) {
            try {
                $rule = Recurrence::normalize((array)$raw['schedule']['rule']);
                $schedule = [
                    'rule'     => $rule,
                    'timezone' => ScheduledTask::normalizeTimezone((string)($raw['schedule']['timezone'] ?? '')),
                ];
            } catch (\InvalidArgumentException) {
                $schedule = null;
            }
        }

        return [
            'title'      => self::text($raw['title'] ?? '', self::TITLE_MAX) ?: 'Départ',
            'fiche_name' => self::text($raw['fiche_name'] ?? '', self::LABEL_MAX) ?: 'Informations de départ',
            'fields'     => $fields,
            'schedule'   => $schedule,
            'chat'       => array_key_exists('chat', $raw) ? self::bool($raw['chat']) : true,
            'event'      => self::normalizeEvent($raw['event'] ?? null),
        ];
    }

    /** Déclencheur par évènement, ou null. */
    private static function normalizeEvent(mixed $raw): ?array
    {
        if (!is_array($raw) || !in_array($raw['kind'] ?? '', self::EVENTS, true)) {
            return null;
        }
        $kind = (string)$raw['kind'];
        $event = match ($kind) {
            'email' => [
                'kind'    => 'email',
                'source'  => in_array($raw['source'] ?? '', self::EMAIL_SOURCES, true) ? (string)$raw['source'] : 'gmail',
                'query'   => self::text($raw['query'] ?? '', self::QUERY_MAX),
                // Expéditeur, objet, mots, pièce jointe : ajoutés à la requête.
                'filters' => self::eventFilters($raw['filters'] ?? null, 'mail'),
            ],
            'file' => [
                'kind'        => 'file',
                'source'      => 'drive',
                'folder'      => self::text($raw['folder'] ?? '', self::FOLDER_MAX),
                'folder_name' => self::text($raw['folder_name'] ?? '', self::LABEL_MAX),
                // Nature et nom des fichiers qui lancent une exécution.
                'filters'     => self::eventFilters($raw['filters'] ?? null, 'drive'),
            ],
            'workflow' => [
                'kind'        => 'workflow',
                'workflow_id' => self::posInt($raw['workflow_id'] ?? null),
            ],
            default => ['kind' => 'webhook'],
        };
        $event['piece_name'] = self::text($raw['piece_name'] ?? '', self::LABEL_MAX) ?: self::EVENT_PIECE_NAMES[$kind];
        $event['timezone'] = ScheduledTask::normalizeTimezone((string)($raw['timezone'] ?? ''));
        return $event;
    }

    /**
     * Filtres d'un déclencheur : la seule partie qui a un sens pour lui
     * (emails : expéditeur, sauf de, adressé à, objet, mots, pièce jointe ;
     * fichiers : nature, nom). Pas de citation du dossier : il n'existe pas
     * encore.
     */
    private static function eventFilters(mixed $raw, string $part): array
    {
        $f = WorkflowFilters::normalize($raw);
        $blank = WorkflowFilters::blank();
        $out = $blank;
        if ($part === 'mail') {
            $out['mail'] = ['from' => self::withoutMentions($f['mail']['from']), 'subject' => self::withoutMentions($f['mail']['subject']),
                'words' => self::withoutMentions($f['mail']['words']), 'attachment' => $f['mail']['attachment'], 'unread' => false, 'days' => 0,
                'exclude' => self::withoutMentions($f['mail']['exclude']), 'to' => self::withoutMentions($f['mail']['to'])];
        } else {
            $out['drive'] = ['folder' => '', 'name' => self::withoutMentions($f['drive']['name']), 'kind' => $f['drive']['kind'], 'days' => 0];
        }
        return $out;
    }

    /**
     * @param bool $inner dans une voie de condition ou un corps de boucle
     * @param bool $lane  dans une voie (sinon, avec `$inner` : un corps de boucle)
     */
    private static function normalizeNode(mixed $raw, array &$used, bool $inner, int &$count, bool $lane = false): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $type = (string)($raw['type'] ?? '');
        $body = $inner && !$lane;
        if (!in_array($type, $lane ? self::LANE_TYPES : ($body ? self::BODY_TYPES : self::TYPES), true)) {
            return null;
        }
        $count++;
        $title = self::text($raw['title'] ?? '', self::TITLE_MAX);
        $key   = self::uniqueKey((string)($raw['key'] ?? ''), $title !== '' ? $title : $type, $used);
        $node  = ['key' => $key, 'type' => $type, 'title' => $title];

        switch ($type) {
            case self::TYPE_STEP:
                $node += [
                    'profile_id'  => self::posInt($raw['profile_id'] ?? null),
                    'llm'         => self::text($raw['llm'] ?? '', 120),
                    'instruction' => self::text($raw['instruction'] ?? '', self::INSTRUCTION_MAX, true),
                    'reads'       => self::keyList($raw['reads'] ?? []),
                    'sources'     => self::sourceList($raw['sources'] ?? []),
                    'kb_ids'      => array_slice(self::intList($raw['kb_ids'] ?? []), 0, self::MAX_KBS),
                    'tool_ids'    => self::intList($raw['tool_ids'] ?? []),
                    'write'       => in_array($raw['write'] ?? '', self::WRITE_MODES, true) ? (string)$raw['write'] : self::WRITE_NONE,
                    'auto_send_mail' => self::bool($raw['auto_send_mail'] ?? false),
                    'review'      => self::bool($raw['review'] ?? false),
                    'output'      => self::normalizeOutput(is_array($raw['output'] ?? null) ? $raw['output'] : [], $title, $used),
                    'filters'     => WorkflowFilters::normalize($raw['filters'] ?? null),
                    'on_error'    => self::normalizeOnError($raw['on_error'] ?? null, true),
                ];
                break;

            case self::TYPE_ACTION:
                $target = (string)($raw['target'] ?? '');
                $node += [
                    'target'      => array_key_exists($target, self::ACTION_TARGETS) ? $target : '',
                    'tool_id'     => self::posInt($raw['tool_id'] ?? null),
                    'llm'         => self::text($raw['llm'] ?? '', 120),
                    'instruction' => self::text($raw['instruction'] ?? '', self::INSTRUCTION_MAX, true),
                    'reads'       => self::keyList($raw['reads'] ?? []),
                    'write'       => ($raw['write'] ?? '') === self::WRITE_AUTO ? self::WRITE_AUTO : self::WRITE_APPROVE,
                    'auto_send_mail' => self::bool($raw['auto_send_mail'] ?? false),
                    'filters'     => WorkflowFilters::normalize($raw['filters'] ?? null),
                    'on_error'    => self::normalizeOnError($raw['on_error'] ?? null, true),
                ];
                if ($node['target'] === 'slack') {
                    $node['slack'] = self::normalizeSlack(is_array($raw['slack'] ?? null) ? $raw['slack'] : []);
                    if ($node['slack']['mode'] === self::SLACK_READ) {
                        // Lire : rien n'est écrit, une pièce est remise —
                        // rédigée, jamais une création.
                        $node['write'] = self::WRITE_NONE;
                        $node['auto_send_mail'] = false;
                        $out = is_array($raw['output'] ?? null) ? $raw['output'] : [];
                        if (self::isCreationForm((string)($out['form'] ?? ''))) {
                            $out['form'] = 'text';
                        }
                        $node['output'] = self::normalizeOutput($out, $title, $used);
                    }
                }
                break;

            case self::TYPE_CREATE:
                $kind = array_key_exists((string)($raw['kind'] ?? ''), self::CREATE_KINDS) ? (string)$raw['kind'] : 'image';
                $outRaw = is_array($raw['output'] ?? null) ? $raw['output'] : [];
                $outName = self::text($outRaw['name'] ?? '', self::LABEL_MAX);
                $node += [
                    'kind'        => $kind,
                    'tool_id'     => self::posInt($raw['tool_id'] ?? null),
                    'instruction' => self::text($raw['instruction'] ?? '', self::INSTRUCTION_MAX, true),
                    'reads'       => self::keyList($raw['reads'] ?? []),
                    // Réglages propres à la nature : le format d'une image, le
                    // style des illustrations d'une page.
                    'options'     => self::creationOptions($kind, is_array($raw['options'] ?? null) ? $raw['options'] : []),
                    'review'      => self::bool($raw['review'] ?? false),
                    // La pièce remise a la forme de ce qui est créé.
                    'output'      => [
                        'key'  => self::uniqueKey((string)($outRaw['key'] ?? ''), $outName !== '' ? $outName : ($title !== '' ? $title : $kind), $used),
                        'name' => $outName,
                        'form' => $kind,
                    ],
                    'on_error'    => self::normalizeOnError($raw['on_error'] ?? null, true),
                ];
                break;

            case self::TYPE_CONDITION:
                $node += [
                    'mode'     => ($raw['mode'] ?? '') === 'rule' ? 'rule' : 'question',
                    'question' => self::text($raw['question'] ?? '', self::QUESTION_MAX, true),
                    'reads'    => self::keyList($raw['reads'] ?? []),
                    // Bases de connaissances consultées pour répondre (question
                    // à MIA) : « correspond-il à notre offre ? » exige de savoir
                    // ce qu'est notre offre (retour du 2026-11-04).
                    'kb_ids'   => array_slice(self::intList($raw['kb_ids'] ?? []), 0, self::MAX_KBS),
                    'rule'     => self::normalizeRule(is_array($raw['rule'] ?? null) ? $raw['rule'] : []),
                    'doubt'    => ($raw['doubt'] ?? '') === self::DOUBT_NO ? self::DOUBT_NO : self::DOUBT_ASK,
                    'on_error' => self::normalizeOnError($raw['on_error'] ?? null, false),
                ];
                // Dans une boucle : une condition SANS voies — « si oui »
                // continue le tour, « si non » passe à l'élément suivant (ou
                // arrête le workflow). Elle trie les éléments sur ce que le
                // tour vient de produire.
                if ($body) {
                    foreach (self::LANES as $laneKey) {
                        $l = is_array($raw[$laneKey] ?? null) ? $raw[$laneKey] : [];
                        $default = $laneKey === 'yes' ? self::THEN_CONTINUE : self::THEN_SKIP;
                        $then = (string)($l['then'] ?? $default);
                        $node[$laneKey] = [
                            'nodes' => [],
                            'then'  => in_array($then, [self::THEN_CONTINUE, self::THEN_SKIP, self::THEN_STOP], true) ? $then : $default,
                        ];
                    }
                    break;
                }
                // « si oui » reprend le rail par défaut ; « si non » l'arrête (le
                // « on y va ? » le plus courant).
                foreach (self::LANES as $lane) {
                    $l = is_array($raw[$lane] ?? null) ? $raw[$lane] : [];
                    $nodes = [];
                    foreach (self::listOf($l['nodes'] ?? []) as $child) {
                        if ($count >= self::MAX_NODES) {
                            break;
                        }
                        $c = self::normalizeNode($child, $used, true, $count, true);
                        if ($c !== null) {
                            $nodes[] = $c;
                        }
                    }
                    $default = $lane === 'yes' ? self::THEN_CONTINUE : self::THEN_STOP;
                    $then = (string)($l['then'] ?? $default);
                    $node[$lane] = [
                        'nodes' => $nodes,
                        'then'  => in_array($then, [self::THEN_CONTINUE, self::THEN_STOP], true) ? $then : $default,
                    ];
                }
                break;

            case self::TYPE_ROUTE:
                $routes = [];
                $laneKeys = [];
                foreach (self::listOf($raw['routes'] ?? []) as $r) {
                    if (!is_array($r) || count($routes) >= self::MAX_ROUTES) {
                        continue;
                    }
                    $label = self::text($r['label'] ?? '', self::ROUTE_LABEL_MAX);
                    $laneKey = self::laneKey((string)($r['key'] ?? ''), $label, $laneKeys);
                    $children = [];
                    foreach (self::listOf($r['nodes'] ?? []) as $child) {
                        if ($count >= self::MAX_NODES) {
                            break;
                        }
                        $c = self::normalizeNode($child, $used, true, $count, true);
                        if ($c !== null) {
                            $children[] = $c;
                        }
                    }
                    $then = (string)($r['then'] ?? self::THEN_CONTINUE);
                    $routes[] = [
                        'key'   => $laneKey,
                        'label' => $label,
                        'when'  => self::text($r['when'] ?? '', self::ROUTE_WHEN_MAX, true),
                        'nodes' => $children,
                        'then'  => $then === self::THEN_STOP ? self::THEN_STOP : self::THEN_CONTINUE,
                    ];
                }
                $fallback = (string)($raw['fallback'] ?? self::FALLBACK_ASK);
                $node += [
                    'instruction' => self::text($raw['instruction'] ?? '', self::CRITERIA_MAX, true),
                    'reads'       => self::keyList($raw['reads'] ?? []),
                    'kb_ids'      => array_slice(self::intList($raw['kb_ids'] ?? []), 0, self::MAX_KBS),
                    'routes'      => $routes,
                    'fallback'    => isset($laneKeys[$fallback]) ? $fallback : self::FALLBACK_ASK,
                    'on_error'    => self::normalizeOnError($raw['on_error'] ?? null, false),
                ];
                break;

            case self::TYPE_LOOP:
                $mode = ($raw['mode'] ?? '') === self::LOOP_UNTIL ? self::LOOP_UNTIL : self::LOOP_EACH;
                $cap = $mode === self::LOOP_EACH ? self::LOOP_EACH_MAX : self::LOOP_UNTIL_MAX;
                $max = (int)($raw['max'] ?? 0);
                if ($max <= 0) {
                    $max = $mode === self::LOOP_EACH ? self::LOOP_EACH_DEFAULT : self::LOOP_UNTIL_DEFAULT;
                }
                $itemRaw = is_array($raw['item'] ?? null) ? $raw['item'] : [];
                $itemName = self::text($itemRaw['name'] ?? '', self::LABEL_MAX) ?: 'Élément en cours';
                $item = ['key' => self::uniqueKey((string)($itemRaw['key'] ?? ''), $itemName, $used), 'name' => $itemName];
                $body = [];
                foreach (self::listOf($raw['body'] ?? []) as $child) {
                    if ($count >= self::MAX_NODES) {
                        break;
                    }
                    $c = self::normalizeNode($child, $used, true, $count);
                    if ($c !== null) {
                        $body[] = $c;
                    }
                }
                $outRaw = is_array($raw['output'] ?? null) ? $raw['output'] : [];
                $outName = self::text($outRaw['name'] ?? '', self::LABEL_MAX);
                $untilRaw = is_array($raw['until'] ?? null) ? $raw['until'] : [];
                $source = self::normalizeLoopSource(is_array($raw['source'] ?? null) ? $raw['source'] : []);
                $filterRaw = is_array($raw['filter'] ?? null) ? $raw['filter'] : [];
                $node += [
                    'mode'       => $mode,
                    'over'       => self::text($raw['over'] ?? '', self::KEY_MAX),
                    'over_field' => self::text($raw['over_field'] ?? '', self::KEY_MAX),
                    // Ce que la boucle parcourt (LoopSources) ; vide = boucle
                    // d'avant les découpeurs (Liste, Tableau, champ liste).
                    'source'     => $source,
                    'max'        => max($mode === self::LOOP_UNTIL ? 2 : 1, min($cap, $max)),
                    'item'       => $item,
                    'body'       => $body,
                    'collect'    => self::text($raw['collect'] ?? '', self::KEY_MAX),
                    'output'     => [
                        'key'  => self::uniqueKey((string)($outRaw['key'] ?? ''), $outName !== '' ? $outName : ($title !== '' ? $title . ' resultats' : 'resultats'), $used),
                        'name' => $outName,
                    ],
                    'until'      => [
                        'mode'     => ($untilRaw['mode'] ?? '') === 'rule' ? 'rule' : 'question',
                        'question' => self::text($untilRaw['question'] ?? '', self::QUESTION_MAX, true),
                        'reads'    => self::keyList($untilRaw['reads'] ?? []),
                        'rule'     => self::normalizeRule(is_array($untilRaw['rule'] ?? null) ? $untilRaw['rule'] : []),
                    ],
                    'exhausted'  => ($raw['exhausted'] ?? '') === self::THEN_STOP ? self::THEN_STOP : self::THEN_CONTINUE,
                    // Quels éléments garder : une règle sur leurs champs, sans
                    // doublon sur un champ, sans élément sans nom.
                    'filter'     => [
                        'rule'          => self::normalizeRule(is_array($filterRaw['rule'] ?? null) ? $filterRaw['rule'] : []),
                        'dedupe'        => self::text($filterRaw['dedupe'] ?? '', self::KEY_MAX),
                        'require_label' => array_key_exists('require_label', $filterRaw) ? self::bool($filterRaw['require_label']) : true,
                    ],
                    // Un élément qui échoue : passer au suivant (défaut) ou arrêter.
                    'on_item_error' => ($raw['on_item_error'] ?? '') === self::ITEM_ERROR_STOP ? self::ITEM_ERROR_STOP : self::ITEM_ERROR_CONTINUE,
                    // Relire la liste des éléments avant le premier tour.
                    'confirm'    => self::bool($raw['confirm'] ?? false),
                    // Relire ensemble, à la fin, les actions préparées à chaque tour.
                    'review_actions' => self::bool($raw['review_actions'] ?? false),
                ];
                break;

            case self::TYPE_WAIT:
                $unit = array_key_exists((string)($raw['unit'] ?? ''), self::WAIT_UNITS) ? (string)$raw['unit'] : 'days';
                $amount = max(1, (int)($raw['amount'] ?? 1));
                $amount = min($amount, intdiv(self::WAIT_MAX_SEC, self::WAIT_UNITS[$unit]));
                $time = (string)($raw['time'] ?? '');
                $node += [
                    'mode'   => ($raw['mode'] ?? '') === 'date' ? 'date' : 'delay',
                    'amount' => $amount,
                    'unit'   => $unit,
                    'piece'  => self::text($raw['piece'] ?? '', self::KEY_MAX),
                    'field'  => self::text($raw['field'] ?? '', self::KEY_MAX),
                    'time'   => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '09:00',
                    'offset' => max(-60, min(60, (int)($raw['offset'] ?? 0))),
                ];
                break;

            case self::TYPE_NOTIFY:
                $node += [
                    'message' => self::text($raw['message'] ?? '', self::NOTE_MAX, true),
                ];
                break;

            case self::TYPE_STOP:
                // Message facultatif laissé dans la conversation ; statut
                // affiché : « Terminée » (une fin prévue) ou « Arrêtée ».
                $node += [
                    'message' => self::text($raw['message'] ?? '', self::NOTE_MAX, true),
                    'outcome' => ($raw['outcome'] ?? '') === self::STOP_STOPPED ? self::STOP_STOPPED : self::STOP_DONE,
                ];
                break;

            case self::TYPE_CALL:
                // Paramètres : clé d'un champ du départ de l'appelé (ou
                // « evenement », ce qui tient lieu de son évènement) → valeur.
                $params = [];
                foreach (is_array($raw['params'] ?? null) ? $raw['params'] : [] as $pk => $pv) {
                    $pk = (string)$pk;
                    if (count($params) > self::MAX_FIELDS || !preg_match('/^[a-z0-9_]{1,' . self::KEY_MAX . '}$/', $pk)) {
                        continue;
                    }
                    $pv = self::text($pv, self::CALL_PARAM_MAX, true);
                    if ($pv !== '') {
                        $params[$pk] = $pv;
                    }
                }
                $node += [
                    'workflow_id' => self::posInt($raw['workflow_id'] ?? null),
                    'params'      => $params,
                    'on_stop'     => ($raw['on_stop'] ?? '') === self::CALL_STOP_CONTINUE ? self::CALL_STOP_CONTINUE : self::CALL_STOP_STOP,
                    'output'      => self::normalizeCallOutput(is_array($raw['output'] ?? null) ? $raw['output'] : [], $title, $used),
                    // Les pannes passagères sont réessayées par les briques de
                    // l'appelé : ici, seulement « arrêter » ou « ignorer ».
                    'on_error'    => ['retries' => 0, 'then' => self::normalizeOnError($raw['on_error'] ?? null, true)['then']],
                ];
                break;

            case self::TYPE_GATE:
                $days = (int)($raw['days'] ?? self::GATE_DAYS_DEFAULT);
                $node += [
                    'reviews'     => self::keyList($raw['reviews'] ?? []),
                    'returns_to'  => self::text($raw['returns_to'] ?? '', self::KEY_MAX),
                    'can_correct' => array_key_exists('can_correct', $raw) ? self::bool($raw['can_correct']) : true,
                    'can_return'  => array_key_exists('can_return', $raw) ? self::bool($raw['can_return']) : true,
                    'days'        => max(1, min(self::GATE_DAYS_MAX, $days > 0 ? $days : self::GATE_DAYS_DEFAULT)),
                    'note'        => self::text($raw['note'] ?? '', self::NOTE_MAX, true),
                ];
                break;
        }
        return $node;
    }

    /**
     * Ce que fait une brique quand elle échoue : `retries` nouveaux essais
     * automatiques pour une panne PASSAGÈRE (service saturé, réseau, délai),
     * puis `then` — arrêter l'exécution (reprenable) ou ignorer la brique et
     * continuer. Une condition ne s'ignore pas : sa réponse décide de la suite.
     */
    private static function normalizeOnError(mixed $raw, bool $allowSkip): array
    {
        $raw = is_array($raw) ? $raw : [];
        $retries = array_key_exists('retries', $raw) ? (int)$raw['retries'] : self::RETRIES_DEFAULT;
        return [
            'retries' => max(0, min(self::RETRIES_MAX, $retries)),
            'then'    => $allowSkip && ($raw['then'] ?? '') === self::ON_ERROR_SKIP ? self::ON_ERROR_SKIP : self::ON_ERROR_STOP,
        ];
    }

    /**
     * Source d'une boucle « pour chaque » (LoopSources). `kind` vide : la
     * boucle d'avant les découpeurs, qui lit `over` / `over_field`.
     */
    private static function normalizeLoopSource(array $raw): array
    {
        $kind = (string)($raw['kind'] ?? '');
        if (!LoopSources::isKind($kind)) {
            return ['kind' => ''];
        }
        $from = (string)($raw['from'] ?? '');
        $fromAllowed = match (true) {
            $kind === LoopSources::ROWS                         => [LoopSources::FROM_PIECE, LoopSources::FROM_DRIVE, LoopSources::FROM_TABLE],
            in_array($kind, LoopSources::DRIVE_FILE_KINDS, true) => [LoopSources::FROM_PIECE, LoopSources::FROM_DRIVE],
            $kind === LoopSources::ATTACHMENTS                  => [LoopSources::FROM_PIECE, LoopSources::FROM_SEARCH],
            $kind === LoopSources::FILES                        => [LoopSources::FROM_DRIVE],
            $kind === LoopSources::EMAILS                       => [LoopSources::FROM_SEARCH],
            default                                              => [LoopSources::FROM_PIECE],
        };
        $out = [
            'kind' => $kind,
            'from' => in_array($from, $fromAllowed, true) ? $from : $fromAllowed[0],
        ];
        if (in_array($kind, [...LoopSources::DRIVE_FILE_KINDS, LoopSources::FILES], true)) {
            // Un fichier ou un dossier Drive : lien, identifiant, nom ou chemin ; peut citer le dossier.
            $out['drive'] = self::text($raw['drive'] ?? '', self::FOLDER_MAX);
        }
        if (in_array($kind, [LoopSources::EMAILS, LoopSources::ATTACHMENTS], true)) {
            $out['service'] = in_array($raw['service'] ?? '', self::EMAIL_SOURCES, true) ? (string)$raw['service'] : 'gmail';
            $out['query']   = self::text($raw['query'] ?? '', self::QUERY_MAX);
            $out['days']    = max(0, min(90, (int)($raw['days'] ?? 7)));
            $out['unread']  = self::bool($raw['unread'] ?? false);
        }
        if ($kind === LoopSources::ATTACHMENTS) {
            $out['types']  = in_array($raw['types'] ?? '', LoopSources::ATTACHMENT_TYPES, true) ? (string)$raw['types'] : 'all';
            // Les images collées dans le corps (logos de signature) : écartées par défaut.
            $out['inline'] = self::bool($raw['inline'] ?? false);
        }
        if ($kind === LoopSources::FILES) {
            $out['name']      = self::text($raw['name'] ?? '', self::LABEL_MAX);
            $fileKind = (string)($raw['file_kind'] ?? '');
            $out['file_kind'] = in_array($fileKind, WorkflowFilters::DRIVE_KINDS, true) ? $fileKind : '';
        }
        if ($kind === LoopSources::ROWS) {
            // Une table permanente : son identifiant (base de connaissances) et
            // son nom, pour le dire sans la relire.
            $out['table_id'] = $out['from'] === LoopSources::FROM_TABLE ? self::posInt($raw['table_id'] ?? null) : null;
            $out['table']    = $out['from'] === LoopSources::FROM_TABLE ? self::text($raw['table'] ?? '', self::LABEL_MAX) : '';
            $out['sheet']   = self::text($raw['sheet'] ?? '', self::LABEL_MAX);
            $out['header']  = max(0, min(50, (int)($raw['header'] ?? 0)));
            $out['columns'] = self::normalizeColumns($raw['columns'] ?? [], LoopSources::MAX_COLUMNS);
            $label = self::text($raw['label'] ?? '', self::KEY_MAX);
            $out['label'] = in_array($label, array_column($out['columns'], 'key'), true) ? $label : '';
        }
        if ($kind === LoopSources::AI) {
            $out['instruction'] = self::text($raw['instruction'] ?? '', self::INSTRUCTION_MAX, true);
            $out['fields']      = self::normalizeColumns($raw['fields'] ?? [], LoopSources::MAX_AI_FIELDS);
        }
        return $out;
    }

    /**
     * Colonnes d'un tableau parcouru, ou champs des éléments repérés par MIA :
     * clés uniques, libellés, types de Fiche. Une colonne se retrouve dans le
     * fichier par son LIBELLÉ (sans majuscules, accents ni espaces).
     */
    private static function normalizeColumns(mixed $raw, int $max): array
    {
        $out = [];
        $seen = [];
        $labels = [];
        foreach (self::listOf($raw) as $c) {
            if (!is_array($c) || count($out) >= $max) {
                continue;
            }
            $label = self::text($c['label'] ?? '', self::LABEL_MAX);
            if ($label === '' || isset($labels[mb_strtolower($label)])) {
                continue;
            }
            $labels[mb_strtolower($label)] = true;
            $key = preg_match('/^[a-z][a-z0-9_]{0,' . (self::KEY_MAX - 1) . '}$/', (string)($c['key'] ?? '')) ? (string)$c['key'] : (self::slug($label) ?: 'colonne');
            $base = $key;
            $i = 2;
            while (isset($seen[$key])) {
                $suffix = '_' . $i++;
                $key = substr($base, 0, self::KEY_MAX - strlen($suffix)) . $suffix;
            }
            $seen[$key] = true;
            $type = in_array($c['type'] ?? '', self::FICHE_TYPES, true) ? (string)$c['type'] : 'text';
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type];
        }
        return $out;
    }

    /**
     * Réglages d'une brique Slack. Tous les champs restent, quel que soit le
     * mode : passer de « Publier » à « Lire » et revenir ne perd rien.
     */
    private static function normalizeSlack(array $raw): array
    {
        $mode = ($raw['mode'] ?? '') === self::SLACK_READ ? self::SLACK_READ : self::SLACK_POST;
        $where = (string)($raw['where'] ?? '');
        $allowed = $mode === self::SLACK_READ
            ? [self::SLACK_CHANNEL, self::SLACK_PERSON, self::SLACK_SEARCH]
            : [self::SLACK_CHANNEL, self::SLACK_PERSON];
        $days = filter_var($raw['days'] ?? null, FILTER_VALIDATE_INT);
        return [
            'mode'    => $mode,
            'where'   => in_array($where, $allowed, true) ? $where : self::SLACK_CHANNEL,
            'channel' => self::text($raw['channel'] ?? '', self::SLACK_REF_MAX),
            'person'  => self::text($raw['person'] ?? '', self::SLACK_REF_MAX),
            'query'   => self::text($raw['query'] ?? '', self::SLACK_REF_MAX),
            'days'    => $days === false ? self::SLACK_DAYS_DEFAULT : max(1, min(self::SLACK_DAYS_MAX, (int)$days)),
            'attach'  => array_slice(self::keyList($raw['attach'] ?? []), 0, self::SLACK_ATTACH_MAX),
        ];
    }

    /** Le texte cite-t-il un champ Email d'une Fiche (`@{clé.champ}`) ? */
    private static function citesEmailField(string $text, array $pieces): bool
    {
        preg_match_all('/@\{([a-z0-9_]{1,' . self::KEY_MAX . '})\.([a-z0-9_]{1,' . self::KEY_MAX . '})\}/', $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            foreach ($pieces[$hit[1]]['fields'] ?? [] as $f) {
                if ($f['key'] === $hit[2] && $f['type'] === 'email') {
                    return true;
                }
            }
        }
        return false;
    }

    /** Une action qui LIT (brique Slack en mode « Lire ») : elle remet une pièce et n'écrit rien. */
    public static function isReadingAction(array $node): bool
    {
        return ($node['type'] ?? '') === self::TYPE_ACTION && ($node['target'] ?? '') === 'slack'
            && (($node['slack']['mode'] ?? self::SLACK_POST) === self::SLACK_READ);
    }

    /** La brique remet-elle une pièce au dossier : étape, création, action qui lit ? */
    public static function producesPiece(array $node): bool
    {
        return in_array($node['type'] ?? '', self::PRODUCING_TYPES, true) || self::isReadingAction($node);
    }

    /**
     * Textes d'une brique Slack qui peuvent citer le dossier (`@{clé.champ}`) :
     * ceux de SON mode et de SA destination seulement — un champ laissé là par
     * un autre réglage ne compte pas.
     *
     * @return list<string>
     */
    public static function slackTexts(array $node): array
    {
        $s = $node['slack'] ?? null;
        if (($node['type'] ?? '') !== self::TYPE_ACTION || ($node['target'] ?? '') !== 'slack' || !is_array($s)) {
            return [];
        }
        return match ($s['where']) {
            self::SLACK_PERSON => [(string)$s['person']],
            self::SLACK_SEARCH => [(string)$s['query']],
            default            => [(string)$s['channel']],
        };
    }

    /** Pièces jointes d'une brique Slack qui publie. @return list<string> */
    public static function slackAttach(array $node): array
    {
        $s = $node['slack'] ?? null;
        return ($node['type'] ?? '') === self::TYPE_ACTION && ($node['target'] ?? '') === 'slack'
            && is_array($s) && $s['mode'] === self::SLACK_POST ? (array)$s['attach'] : [];
    }

    private static function normalizeOutput(array $raw, string $title, array &$used): array
    {
        $form = in_array($raw['form'] ?? '', self::FORMS, true) || self::isCreationForm((string)($raw['form'] ?? ''))
            ? (string)$raw['form'] : 'text';
        $name = self::text($raw['name'] ?? '', self::LABEL_MAX);
        $out = [
            'key'  => self::uniqueKey((string)($raw['key'] ?? ''), $name !== '' ? $name : ($title !== '' ? $title : 'piece'), $used),
            'name' => $name,
            'form' => $form,
        ];
        if ($form === 'fiche') {
            $fields = [];
            $seen = [];
            foreach (self::listOf($raw['fields'] ?? []) as $f) {
                if (!is_array($f) || count($fields) >= self::MAX_FICHE) {
                    continue;
                }
                $label = self::text($f['label'] ?? '', self::LABEL_MAX);
                if ($label === '') {
                    continue;
                }
                $fk = self::slug((string)($f['key'] ?? '')) ?: self::slug($label) ?: 'champ';
                $base = $fk;
                $i = 2;
                while (isset($seen[$fk])) {
                    $fk = substr($base, 0, self::KEY_MAX - 3) . '_' . $i++;
                }
                $seen[$fk] = true;
                $fields[] = [
                    'key'   => $fk,
                    'label' => $label,
                    'type'  => in_array($f['type'] ?? '', self::FICHE_TYPES, true) ? (string)$f['type'] : 'text',
                ];
            }
            $out['fields'] = $fields;
        }
        if ($form === 'document') {
            $out['format']   = self::docFormat($raw['format'] ?? '');
            $out['template'] = in_array($out['format'], self::TEMPLATE_FORMATS, true)
                && (array_key_exists('template', $raw) ? self::bool($raw['template']) : true);
        }
        if (self::isCreationForm($form)) {
            // Une création : l'outil qui la fabrique, ses réglages (comme une brique Création).
            $out['tool_id'] = self::posInt($raw['tool_id'] ?? null);
            $out['options'] = self::creationOptions($form, is_array($raw['options'] ?? null) ? $raw['options'] : []);
        }
        return $out;
    }

    /**
     * La pièce que remet l'appel d'un workflow. `from` : la pièce du livrable
     * de l'appelé à récupérer — la forme (champs d'une Fiche, format d'un
     * document) est RECOPIÉE ici par l'éditeur, pour que ce workflow sache ce
     * qu'il lira sans relire l'autre ; « À régler » signale un écart
     * (WorkflowCall). `from` vide : tout le livrable, en un texte.
     */
    private static function normalizeCallOutput(array $raw, string $title, array &$used): array
    {
        $from = is_scalar($raw['from'] ?? null) && preg_match('/^[a-z0-9_]{1,' . self::KEY_MAX . '}$/', (string)$raw['from']) ? (string)$raw['from'] : '';
        $form = $from !== '' && in_array($raw['form'] ?? '', self::CALL_FORMS, true) ? (string)$raw['form'] : 'text';
        $out = self::normalizeOutput(['form' => $form === 'file' ? 'text' : $form] + $raw, $title, $used);
        // Aucun outil de création ni modèle d'entreprise ici : la pièce arrive faite.
        unset($out['tool_id'], $out['options'], $out['template']);
        $out['form'] = $form;
        $out['from'] = $from;
        return $out;
    }

    /**
     * Format d'une pièce Document : un document (DOC_FORMATS) ou n'importe
     * quel fichier texte (.py, .json, .csv…) — l'extension propre de
     * FileFormats, jamais une que MIA ne sait pas fabriquer depuis du texte
     * (image, archive, ancien format Office). Sinon : Word.
     */
    public static function docFormat(mixed $raw): string
    {
        $f = FileFormats::normalizeExtension(is_scalar($raw) ? (string)$raw : '');
        return $f !== '' && FileFormats::unsupported($f) === null ? $f : 'docx';
    }

    /** Une forme de pièce que fabrique un outil de création (image, page, carte) ? */
    public static function isCreationForm(string $form): bool
    {
        return isset(self::CREATE_KINDS[$form]);
    }

    /**
     * Réglages d'une création, selon sa nature : le format, le fond et le
     * format du fichier d'une image (ImageOptions — ce que l'outil n'accepte
     * pas est écarté à la génération), le style des illustrations d'une page.
     */
    private static function creationOptions(string $kind, array $opts): array
    {
        $format = (string)($opts['format'] ?? '');
        $image  = ImageOptions::normalize($opts);
        return match ($kind) {
            'image' => [
                'format'        => in_array($format, self::IMAGE_FORMATS, true) ? $format : '',
                'background'    => $image['background'],
                'output_format' => $image['output_format'],
            ],
            'page'  => ['style' => self::text($opts['style'] ?? '', self::STYLE_MAX)],
            default => [],
        };
    }

    private static function normalizeRule(array $raw): array
    {
        $items = [];
        foreach (self::listOf($raw['items'] ?? []) as $it) {
            if (!is_array($it) || count($items) >= self::MAX_RULE_ITEMS) {
                continue;
            }
            $op = in_array($it['op'] ?? '', self::RULE_OPS, true) ? (string)$it['op'] : 'eq';
            $items[] = [
                'piece' => self::text($it['piece'] ?? '', self::KEY_MAX),
                'field' => self::text($it['field'] ?? '', self::KEY_MAX),
                'op'    => $op,
                'value' => self::text($it['value'] ?? '', 200),
            ];
        }
        return ['join' => ($raw['join'] ?? '') === 'any' ? 'any' : 'all', 'items' => $items];
    }

    // ==================================================================
    // Lecture
    // ==================================================================

    /**
     * Toutes les pièces que le workflow peut produire, dans l'ordre du rail :
     * clé → { key, name, form, fields, format, producer, branch }.
     * `branch` : null sur le rail, « <brique>:<voie> » dans une voie (condition, décision),
     * « loop:<clé> » dans un corps de boucle (élément en cours compris). La
     * pièce d'une boucle (ses résultats) est remise par la boucle, sur le rail.
     *
     * @return array<string, array>
     */
    public static function pieces(array $def): array
    {
        $out = [];
        $start = $def['start'] ?? [];
        $ficheFields = [];
        foreach ($start['fields'] ?? [] as $f) {
            if ($f['type'] === 'file') {
                $out[$f['key']] = [
                    'key' => $f['key'], 'name' => $f['label'] !== '' ? $f['label'] : $f['key'], 'form' => 'file',
                    'fields' => [], 'producer' => self::START_KEY, 'branch' => null,
                ];
            } else {
                $ficheFields[] = ['key' => $f['key'], 'label' => $f['label'], 'type' => self::ficheTypeOfField($f['type'])]
                    // Un Choix garde ses options : la règle d'une condition
                    // propose ces valeurs, et signale celle qui n'en est pas une.
                    + ($f['type'] === 'choice' ? ['options' => array_values((array)$f['options'])] : []);
            }
        }
        if ($ficheFields !== []) {
            $out = [self::START_PIECE => [
                'key' => self::START_PIECE, 'name' => (string)($start['fiche_name'] ?? 'Informations de départ'),
                'form' => 'fiche', 'fields' => $ficheFields, 'producer' => self::START_KEY, 'branch' => null,
            ]] + $out;
        }
        if (is_array($start['event'] ?? null)) {
            $out[self::EVENT_PIECE] = self::eventPiece($start['event']);
        }
        foreach ($def['nodes'] ?? [] as $node) {
            self::collectPieces($node, null, $out);
            foreach (self::branchesOf($node) as $lane => $l) {
                foreach ($l['nodes'] as $child) {
                    self::collectPieces($child, $node['key'] . ':' . $lane, $out);
                }
            }
            if ($node['type'] === self::TYPE_LOOP) {
                $branch = 'loop:' . $node['key'];
                if ($node['mode'] === self::LOOP_EACH) {
                    // L'élément en cours : un texte, ou une Fiche dont les champs
                    // viennent du découpeur (colonnes, champs fixes, champs
                    // demandés à MIA) — LoopSources::itemShape.
                    $shape = LoopSources::itemShape($node);
                    $out[$node['item']['key']] = [
                        'key' => $node['item']['key'], 'name' => $node['item']['name'], 'form' => $shape['form'], 'fields' => $shape['fields'],
                        'producer' => $node['key'], 'branch' => $branch, 'item' => true, 'file' => $shape['file'],
                    ];
                }
                foreach ($node['body'] as $child) {
                    self::collectPieces($child, $branch, $out);
                }
                $out[$node['output']['key']] = self::loopOutputPiece($node, $out);
            }
        }
        return $out;
    }

    /** La pièce posée par l'évènement déclencheur. */
    public static function eventPiece(array $event): array
    {
        $kind = (string)$event['kind'];
        return [
            'key'      => self::EVENT_PIECE,
            'name'     => (string)($event['piece_name'] ?? self::EVENT_PIECE_NAMES[$kind] ?? 'Évènement'),
            'form'     => match ($kind) { 'email' => 'fiche', 'file' => 'file', default => 'text' },
            'fields'   => $kind === 'email' ? self::EMAIL_FIELDS : [],
            'producer' => self::START_KEY,
            'branch'   => null,
            'event'    => $kind,
        ];
    }

    /**
     * La pièce rassemblée par une boucle. Pour chaque élément : les résultats
     * de la pièce suivie (`collect`, sinon celle de la dernière étape du corps)
     * — une Fiche par élément devient un tableau (une ligne par élément), un
     * texte des sections, une liste la liste de tous ; jusqu'à : la dernière
     * version de la pièce suivie, sous sa forme.
     */
    private static function loopOutputPiece(array $loop, array $known): array
    {
        $collect = self::loopCollect($loop);
        $src = $collect !== null ? ($known[$collect] ?? null) : null;
        $form = 'text';
        $fields = [];
        $format = null;
        if ($src !== null) {
            if ($loop['mode'] === self::LOOP_UNTIL) {
                $form = $src['form'];
                $fields = $src['fields'] ?? [];
                $format = $src['format'] ?? null;
            } else {
                $form = match ($src['form']) {
                    'fiche', 'table' => 'table',
                    'list'           => 'list',
                    'document'       => ($src['format'] ?? '') === 'xlsx' ? 'table' : 'document',
                    default          => 'text',
                };
                $format = $form === 'document' ? ($src['format'] ?? 'docx') : null;
            }
        }
        $name = (string)$loop['output']['name'];
        return [
            'key'       => $loop['output']['key'],
            'name'      => $name !== '' ? $name : ($loop['title'] !== '' ? $loop['title'] : $loop['output']['key']),
            'form'      => $form,
            'fields'    => $fields,
            'format'    => $format,
            'producer'  => $loop['key'],
            'branch'    => null,
            'aggregate' => true,
            'collect'   => $collect,
        ];
    }

    /**
     * Clé de la pièce qu'une boucle rassemble : `collect` si c'est une pièce du
     * corps, sinon celle de la dernière étape. Une boucle « jusqu'à » suit
     * aussi une création (sa dernière version) ; « pour chaque » ne rassemble
     * que des pièces rédigées — une image (ou une page, une carte) par
     * élément, qu'elle vienne d'une brique Création ou d'une étape, sert aux
     * briques de son tour.
     */
    public static function loopCollect(array $loop): ?string
    {
        $outputs = [];
        foreach ($loop['body'] ?? [] as $child) {
            if (!self::producesPiece($child)) {
                continue;
            }
            $form = (string)($child['output']['form'] ?? '');
            // Un fichier récupéré d'un workflow appelé, comme une création : un
            // par élément, il sert aux briques de son tour.
            if ((!self::isCreationForm($form) && $form !== 'file') || ($loop['mode'] ?? '') === self::LOOP_UNTIL) {
                $outputs[] = $child['output']['key'];
            }
        }
        if ($outputs === []) {
            return null;
        }
        $wanted = (string)($loop['collect'] ?? '');
        return in_array($wanted, $outputs, true) ? $wanted : (string)end($outputs);
    }

    private static function collectPieces(array $node, ?string $branch, array &$out): void
    {
        if (!self::producesPiece($node)) {
            return;
        }
        $o = $node['output'];
        $out[$o['key']] = [
            'key'      => $o['key'],
            'name'     => $o['name'] !== '' ? $o['name'] : ($node['title'] !== '' ? $node['title'] : $o['key']),
            'form'     => $o['form'],
            'fields'   => $o['fields'] ?? [],
            'format'   => $o['format'] ?? null,
            'producer' => $node['key'],
            'branch'   => $branch,
        ];
    }

    /**
     * Une pièce se corrige-t-elle à la main, à la validation qui la relit ?
     * Ni un fichier reçu, ni une création (image, page, carte) : une création
     * se refait d'après une remarque (« Renvoyer »).
     */
    public static function isCorrectable(array $piece): bool
    {
        $form = (string)($piece['form'] ?? '');
        return $form !== 'file' && !isset(self::CREATE_KINDS[$form]);
    }

    /** Type de champ de Fiche pour un champ du formulaire de départ. */
    public static function ficheTypeOfField(string $type): string
    {
        return match ($type) {
            'number' => 'number',
            'date'   => 'date',
            'email'  => 'email',
            'yesno'  => 'yesno',
            default  => 'text',
        };
    }

    /**
     * Où se trouve une brique : ['in' => 'main'|'lane'|'body', 'lane' =>
     * 'main' | clé de voie (yes, no, voie d'une décision) | 'body', 'index',
     * 'cond' (clé de la brique qui ouvre la voie, ou de la boucle),
     * 'condIndex', 'node'] ; null si inconnue.
     */
    public static function locate(array $def, string $key): ?array
    {
        foreach ($def['nodes'] ?? [] as $i => $node) {
            if ($node['key'] === $key) {
                return ['in' => 'main', 'lane' => 'main', 'index' => $i, 'cond' => null, 'condIndex' => null, 'node' => $node];
            }
            foreach (self::branchesOf($node) as $lane => $l) {
                foreach ($l['nodes'] as $j => $child) {
                    if ($child['key'] === $key) {
                        return ['in' => 'lane', 'lane' => (string)$lane, 'index' => $j, 'cond' => $node['key'], 'condIndex' => $i, 'node' => $child];
                    }
                }
            }
            if ($node['type'] === self::TYPE_LOOP) {
                foreach ($node['body'] as $j => $child) {
                    if ($child['key'] === $key) {
                        return ['in' => 'body', 'lane' => 'body', 'index' => $j, 'cond' => $node['key'], 'condIndex' => $i, 'node' => $child];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Les voies qu'ouvre une brique : clé de voie → { nodes, then } (+ key,
     * label, when pour une décision). Une condition : yes, no. Une décision :
     * ses voies, dans l'ordre. Toute autre brique : aucune.
     *
     * @return array<string, array>
     */
    public static function branchesOf(array $node): array
    {
        if (($node['type'] ?? '') === self::TYPE_CONDITION) {
            return ['yes' => $node['yes'] ?? ['nodes' => [], 'then' => self::THEN_CONTINUE], 'no' => $node['no'] ?? ['nodes' => [], 'then' => self::THEN_STOP]];
        }
        if (($node['type'] ?? '') === self::TYPE_ROUTE) {
            $out = [];
            foreach ($node['routes'] ?? [] as $r) {
                $out[(string)$r['key']] = $r;
            }
            return $out;
        }
        return [];
    }

    /** La brique ouvre-t-elle des voies (condition, décision) ? */
    public static function isBranching(array $node): bool
    {
        return in_array($node['type'] ?? '', self::BRANCHING_TYPES, true);
    }

    /** Libellé d'une voie : « si oui », « si non », ou le nom de la voie d'une décision. */
    /**
     * Une voie arrête-t-elle le workflow ? Réglée sur « arrêter », ou
     * porteuse d'une brique « Arrêter le workflow » (qui finit l'exécution
     * avant d'atteindre la fin de la voie).
     */
    public static function laneStops(array $lane): bool
    {
        if (($lane['then'] ?? self::THEN_CONTINUE) === self::THEN_STOP) {
            return true;
        }
        foreach ($lane['nodes'] ?? [] as $n) {
            if (($n['type'] ?? '') === self::TYPE_STOP) {
                return true;
            }
        }
        return false;
    }

    public static function laneLabel(array $node, string $lane): string
    {
        if (($node['type'] ?? '') === self::TYPE_CONDITION) {
            return $lane === 'no' ? t('si non') : t('si oui');
        }
        $l = self::branchesOf($node)[$lane] ?? null;
        return $l !== null && $l['label'] !== '' ? (string)$l['label'] : $lane;
    }

    /** Une brique par sa clé (rail, voie ou corps de boucle), ou null. */
    public static function node(array $def, string $key): ?array
    {
        return self::locate($def, $key)['node'] ?? null;
    }

    /**
     * Brique suivante après `$key` : une clé, END_KEY (le livrable), STOP
     * (voie qui arrête le workflow) ou LOOP_END . clé de la boucle (fin d'un
     * tour : le moteur décide du tour suivant). `$outcome` ne compte que pour
     * une brique qui ouvre des voies : 'yes' ou 'no' (condition), la clé de
     * la voie choisie (décision ; inconnue → STOP). Après une boucle (tous ses
     * tours faits) : la brique qui la suit sur le rail.
     */
    public static function next(array $def, string $key, string $outcome = ''): string
    {
        $main = $def['nodes'] ?? [];
        if ($key === self::START_KEY) {
            return $main[0]['key'] ?? self::END_KEY;
        }
        if ($key === self::END_KEY) {
            return self::STOP;
        }
        $at = self::locate($def, $key);
        if ($at === null) {
            return self::STOP;
        }
        $afterMain = static fn(int $i): string => $main[$i + 1]['key'] ?? self::END_KEY;

        if ($at['lane'] === 'main') {
            $node = $at['node'];
            if (self::isBranching($node)) {
                $lanes = self::branchesOf($node);
                $lane = $node['type'] === self::TYPE_CONDITION ? $lanes[$outcome === 'no' ? 'no' : 'yes'] : ($lanes[$outcome] ?? null);
                if ($lane === null) {
                    return self::STOP;
                }
                $first = $lane['nodes'][0]['key'] ?? null;
                if ($first !== null) {
                    return $first;
                }
                return $lane['then'] === self::THEN_CONTINUE ? $afterMain($at['index']) : self::STOP;
            }
            return $afterMain($at['index']);
        }

        if ($at['lane'] === 'body') {
            $loop = $main[$at['condIndex']];
            // Une condition du corps : « continuer » poursuit le tour,
            // « passer » écarte l'élément (fin du tour), « arrêter » finit le workflow.
            if ($at['node']['type'] === self::TYPE_CONDITION) {
                $then = (string)($at['node'][$outcome === 'no' ? 'no' : 'yes']['then'] ?? self::THEN_CONTINUE);
                if ($then === self::THEN_STOP) {
                    return self::STOP;
                }
                if ($then === self::THEN_SKIP) {
                    return self::LOOP_SKIP . $loop['key'];
                }
            }
            return $loop['body'][$at['index'] + 1]['key'] ?? self::LOOP_END . $loop['key'];
        }

        $lane = self::branchesOf($main[$at['condIndex']])[$at['lane']];
        $nextInLane = $lane['nodes'][$at['index'] + 1]['key'] ?? null;
        if ($nextInLane !== null) {
            return $nextInLane;
        }
        return $lane['then'] === self::THEN_CONTINUE ? $afterMain($at['condIndex']) : self::STOP;
    }

    /**
     * Pièces DISPONIBLES avant une brique (celles qu'elle peut lire) : celles du
     * départ, des briques du rail qui la précèdent et — dans une voie ou un
     * corps de boucle — de la voie (du corps) avant elle ; l'élément en cours
     * dans tout le corps d'une boucle « pour chaque ». Après une condition ou
     * une décision, une pièce remise dans une voie n'est garantie que si TOUTES
     * les autres voies arrêtent le workflow : sinon elle n'est pas proposée. Après une boucle, seule sa
     * pièce rassemblée l'est. `<clé de boucle>~until` : le point de la
     * condition de sortie (tout le corps est passé).
     *
     * @return array<string, array> clé → pièce (cf. pieces())
     */
    public static function available(array $def, string $key): array
    {
        $all = self::pieces($def);
        $keep = [];
        foreach ($all as $k => $p) {
            if ($p['producer'] === self::START_KEY) {
                $keep[$k] = $p;
            }
        }
        $add = static function (string $nodeKey, bool $railOnly) use (&$keep, $all): void {
            foreach ($all as $k => $p) {
                if ($p['producer'] === $nodeKey && (!$railOnly || $p['branch'] === null) && empty($p['item'])) {
                    $keep[$k] = $p;
                }
            }
        };

        if ($key === self::END_KEY) {
            $at = ['lane' => 'main', 'index' => count($def['nodes'] ?? [])];
        } elseif (str_ends_with($key, self::UNTIL_SUFFIX)) {
            $loopAt = self::locate($def, substr($key, 0, -strlen(self::UNTIL_SUFFIX)));
            if ($loopAt === null || $loopAt['lane'] !== 'main' || $loopAt['node']['type'] !== self::TYPE_LOOP) {
                return $keep;
            }
            $at = ['lane' => 'body', 'index' => count($loopAt['node']['body']), 'condIndex' => $loopAt['index']];
        } else {
            $at = self::locate($def, $key);
        }
        if ($at === null) {
            return $keep;
        }
        $mainLimit = $at['lane'] === 'main' ? $at['index'] : $at['condIndex'];
        foreach (array_slice($def['nodes'] ?? [], 0, $mainLimit) as $node) {
            $add($node['key'], true);
            $lanes = self::branchesOf($node);
            foreach ($lanes as $lane => $l) {
                if (self::laneStops($l)) {
                    continue;
                }
                $othersStop = true;
                foreach ($lanes as $other => $o) {
                    if ($other !== $lane && !self::laneStops($o)) {
                        $othersStop = false;
                    }
                }
                if ($othersStop) {
                    foreach ($l['nodes'] as $child) {
                        $add($child['key'], false);
                    }
                }
            }
        }
        if (($at['in'] ?? '') === 'lane') {
            $lane = self::branchesOf($def['nodes'][$at['condIndex']])[$at['lane']];
            foreach (array_slice($lane['nodes'], 0, $at['index']) as $child) {
                $add($child['key'], false);
            }
        }
        if ($at['lane'] === 'body') {
            $loop = $def['nodes'][$at['condIndex']];
            if ($loop['mode'] === self::LOOP_EACH && isset($all[$loop['item']['key']])) {
                $keep[$loop['item']['key']] = $all[$loop['item']['key']];
            }
            foreach (array_slice($loop['body'], 0, $at['index']) as $child) {
                $add($child['key'], false);
            }
        }
        return $keep;
    }

    /** Clés de pièces citées dans un texte par `@{clé}` (ou `@{clé.champ}`). */
    public static function mentions(string $text): array
    {
        preg_match_all('/@\{([a-z0-9_]{1,' . self::KEY_MAX . '})(?:\.[a-z0-9_]{1,' . self::KEY_MAX . '})?\}/', $text, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /** Champs de Fiche cités par `@{clé.champ}` : liste de [clé, champ]. */
    public static function fieldMentions(string $text): array
    {
        preg_match_all('/@\{([a-z0-9_]{1,' . self::KEY_MAX . '})\.([a-z0-9_]{1,' . self::KEY_MAX . '})\}/', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $hit) {
            $out[$hit[1] . '.' . $hit[2]] = [$hit[1], $hit[2]];
        }
        return array_values($out);
    }

    /** Remplace les `@{clé}` par « Nom de la pièce » et `@{clé.champ}` par « Pièce › Champ » (affichage, prompt). */
    public static function renderMentions(string $text, array $pieces): string
    {
        return (string)preg_replace_callback(
            '/@\{([a-z0-9_]{1,' . self::KEY_MAX . '})(?:\.([a-z0-9_]{1,' . self::KEY_MAX . '}))?\}/',
            static function (array $m) use ($pieces): string {
                $p = $pieces[$m[1]] ?? null;
                if ($p === null) {
                    return $m[0];
                }
                if (($m[2] ?? '') !== '') {
                    foreach ($p['fields'] ?? [] as $f) {
                        if ($f['key'] === $m[2]) {
                            return '« ' . $p['name'] . ' › ' . $f['label'] . ' »';
                        }
                    }
                    return $m[0];
                }
                return '« ' . $p['name'] . ' »';
            },
            $text
        );
    }

    /**
     * Pièces qu'une brique LIT : celles cochées et celles citées dans sa
     * consigne, sa question ou son message, limitées aux disponibles. Une
     * boucle lit la pièce qu'elle parcourt ; une attente « jusqu'à la date »
     * lit sa Fiche.
     *
     * @return list<string>
     */
    public static function readsOf(array $def, array $node): array
    {
        $available = self::available($def, (string)$node['key']);
        $keys = (array)($node['reads'] ?? []);
        foreach (['instruction', 'question', 'message'] as $f) {
            if (isset($node[$f]) && is_string($node[$f])) {
                $keys = array_merge($keys, self::mentions($node[$f]));
            }
        }
        if ($node['type'] === self::TYPE_GATE) {
            $keys = array_merge($keys, (array)$node['reviews']);
            $keys = array_merge($keys, self::mentions((string)$node['note']));
        }
        if ($node['type'] === self::TYPE_ROUTE) {
            foreach ($node['routes'] as $r) {
                $keys = array_merge($keys, self::mentions((string)$r['when']));
            }
        }
        if (in_array($node['type'], [self::TYPE_STEP, self::TYPE_ACTION], true)) {
            foreach (WorkflowFilters::texts($node['filters'] ?? []) as $text) {
                $keys = array_merge($keys, self::mentions($text));
            }
        }
        // Brique Slack : la destination (ou la source) qui cite un champ, et
        // les pièces jointes au message.
        foreach (self::slackTexts($node) as $text) {
            $keys = array_merge($keys, self::mentions($text));
        }
        $keys = array_merge($keys, self::slackAttach($node));
        if ($node['type'] === self::TYPE_CONDITION && $node['mode'] === 'rule') {
            foreach ($node['rule']['items'] as $it) {
                $keys[] = $it['piece'];
            }
        }
        if ($node['type'] === self::TYPE_LOOP && $node['mode'] === self::LOOP_EACH) {
            if (LoopSources::readsPiece($node['source'] ?? [])) {
                $keys[] = $node['over'];
            }
            foreach (['instruction', 'query', 'drive'] as $f) {
                $keys = array_merge($keys, self::mentions((string)($node['source'][$f] ?? '')));
            }
        }
        if ($node['type'] === self::TYPE_WAIT && $node['mode'] === 'date') {
            $keys[] = $node['piece'];
        }
        // Appel d'un workflow : les pièces citées par ses paramètres.
        if ($node['type'] === self::TYPE_CALL) {
            foreach ($node['params'] as $value) {
                $keys = array_merge($keys, self::mentions((string)$value));
            }
        }
        $out = [];
        foreach ($keys as $k) {
            if (isset($available[$k]) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** Pièces lues par la condition de sortie d'une boucle « jusqu'à ». @return list<string> */
    public static function untilReadsOf(array $def, array $loop): array
    {
        $available = self::available($def, $loop['key'] . self::UNTIL_SUFFIX);
        $u = $loop['until'];
        $keys = array_merge((array)$u['reads'], self::mentions((string)$u['question']));
        if ($u['mode'] === 'rule') {
            foreach ($u['rule']['items'] as $it) {
                $keys[] = $it['piece'];
            }
        }
        $out = [];
        foreach ($keys as $k) {
            if (isset($available[$k]) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /**
     * Brique vers laquelle une validation RENVOIE (une étape, ou une création
     * refaite d'après la remarque) : celle choisie si elle précède la
     * validation et remet une pièce, sinon la productrice de la première pièce
     * relue. Null si aucune (une pièce du départ ne se refait pas, ni
     * l'élément en cours d'une boucle).
     */
    public static function returnTarget(array $def, array $gate): ?string
    {
        $pieces = self::pieces($def);
        $key = (string)$gate['key'];
        if (str_contains($key, '~')) {
            // Relecture implicite d'une étape (« clé~review ») : l'étape et sa pièce.
            $stepKey = (string)strstr($key, '~', true);
            $available = self::available($def, $stepKey);
            foreach ($pieces as $k => $p) {
                if ($p['producer'] === $stepKey) {
                    $available[$k] = $p;
                }
            }
        } else {
            $available = self::available($def, $key);
        }
        // Un appel de workflow ne se refait pas d'après une remarque : son
        // travail est celui d'un autre workflow, relu par ses propres validations.
        $steps = static function (string $producer) use ($def): bool {
            $n = self::node($def, $producer) ?? [];
            return self::producesPiece($n) && ($n['type'] ?? '') !== self::TYPE_CALL;
        };
        $choice = (string)($gate['returns_to'] ?? '');
        if ($choice !== '' && $steps($choice)) {
            foreach ($available as $p) {
                if ($p['producer'] === $choice) {
                    return $choice;
                }
            }
        }
        foreach ((array)$gate['reviews'] as $k) {
            $producer = $pieces[$k]['producer'] ?? null;
            if ($producer !== null && $producer !== self::START_KEY && isset($available[$k]) && $steps($producer)) {
                return $producer;
            }
        }
        return null;
    }

    /**
     * Silhouette du rail principal : une nature par brique, du départ au
     * livrable (`c` condition, `a` décision de MIA, `l` boucle, `g` validation, `x` action, `w`
     * attente, `n` prévenir, `k` création, `p` appel d'un workflow, `s` étape ; une étape ou une
     * création relue ajoute `g`). Sert aux vignettes de la liste et aux cartes du chat.
     */
    public static function silhouette(array $def): string
    {
        $s = 'd';
        foreach ($def['nodes'] ?? [] as $node) {
            $s .= match ($node['type']) {
                self::TYPE_CONDITION => 'c',
                self::TYPE_ROUTE     => 'a',
                self::TYPE_LOOP      => 'l',
                self::TYPE_GATE      => 'g',
                self::TYPE_ACTION    => 'x',
                self::TYPE_WAIT      => 'w',
                self::TYPE_NOTIFY    => 'n',
                self::TYPE_STOP      => 'q',
                self::TYPE_CALL      => 'p',
                self::TYPE_CREATE    => !empty($node['review']) ? 'kg' : 'k',
                default              => !empty($node['review']) ? 'sg' : 's',
            };
        }
        return $s . 'e';
    }

    /** Comptes affichés : étapes, actions, créations, conditions, boucles, validations, attentes, pièces. */
    public static function counts(array $def): array
    {
        $c = ['steps' => 0, 'actions' => 0, 'creations' => 0, 'conditions' => 0, 'routes' => 0, 'loops' => 0, 'gates' => 0, 'waits' => 0, 'notifies' => 0, 'stops' => 0, 'calls' => 0,
              'pieces' => count(self::pieces($def)), 'deliveries' => count($def['end']['deliveries'] ?? [])];
        foreach (self::flatNodes($def) as $n) {
            match ($n['type']) {
                self::TYPE_STEP      => $c['steps']++,
                self::TYPE_ACTION    => $c['actions']++,
                self::TYPE_CREATE    => $c['creations']++,
                self::TYPE_CONDITION => $c['conditions']++,
                self::TYPE_ROUTE     => $c['routes']++,
                self::TYPE_LOOP      => $c['loops']++,
                self::TYPE_GATE      => $c['gates']++,
                self::TYPE_WAIT      => $c['waits']++,
                self::TYPE_NOTIFY    => $c['notifies']++,
                self::TYPE_STOP      => $c['stops']++,
                self::TYPE_CALL      => $c['calls']++,
            };
            if (!empty($n['review'])) {
                $c['gates']++;
            }
        }
        return $c;
    }

    /** Toutes les briques, rail, voies (conditions, décisions) et corps de boucle, à plat (ordre d'affichage). */
    public static function flatNodes(array $def): array
    {
        $out = [];
        foreach ($def['nodes'] ?? [] as $n) {
            $out[] = $n;
            foreach (self::branchesOf($n) as $l) {
                foreach ($l['nodes'] as $c) {
                    $out[] = $c;
                }
            }
            if ($n['type'] === self::TYPE_LOOP) {
                foreach ($n['body'] as $c) {
                    $out[] = $c;
                }
            }
        }
        return $out;
    }

    /** Boucle qui contient une brique (corps), ou null. */
    public static function loopOf(array $def, string $key): ?array
    {
        $at = self::locate($def, $key);
        return $at !== null && $at['lane'] === 'body' ? $def['nodes'][$at['condIndex']] : null;
    }

    /** La brique écrit-elle dans des outils (étape avec mode, action) ? */
    public static function writes(array $node): bool
    {
        return ($node['type'] === self::TYPE_ACTION && !self::isReadingAction($node))
            || ($node['type'] === self::TYPE_STEP && ($node['write'] ?? self::WRITE_NONE) !== self::WRITE_NONE);
    }

    // ==================================================================
    // À régler
    // ==================================================================

    /**
     * Ce qui empêche de publier (`block`) ou mérite attention (`warn`).
     * `$ctx` vient de ScheduledTask::choicesFor() : profiles, llms, sources,
     * kbs, tools, auto — plus `tool_llms` (slug → le modèle sait piloter des
     * outils) et `default_llm`.
     *
     * @return list<array{node: string, target: string, level: string, text: string}>
     */
    public static function issues(array $def, array $ctx): array
    {
        $issues = [];
        // $connect : le connecteur à relier pour régler le point (lien vers
        // Réglages → Comptes connectés, et pré-contrôle du lancement).
        $add = static function (string $node, string $target, string $level, string $text, string $connect = '') use (&$issues): void {
            $issues[] = ['node' => $node, 'target' => $target, 'level' => $level, 'text' => $text] + ($connect !== '' ? ['connect' => $connect] : []);
        };
        $pieces = self::pieces($def);

        if (($def['nodes'] ?? []) === []) {
            $add(self::START_KEY, 'nodes', self::LEVEL_BLOCK, t('Ajoutez au moins une étape.'));
        }
        $counts = self::counts($def);
        $producers = count(array_filter(self::flatNodes($def), static fn(array $n): bool => self::producesPiece($n)));
        if ($producers === 0 && ($def['nodes'] ?? []) !== []) {
            $add(self::START_KEY, 'nodes', self::LEVEL_WARN, t('Aucune étape ne remet de pièce : le workflow ne produira rien à relire.'));
        }

        // Départ
        $start = $def['start'];
        $labels = [];
        foreach ($start['fields'] as $f) {
            if ($f['label'] === '') {
                $add(self::START_KEY, 'field:' . $f['key'], self::LEVEL_BLOCK, t('Un champ du départ n’a pas de nom.'));
            } elseif (isset($labels[mb_strtolower($f['label'])])) {
                $add(self::START_KEY, 'field:' . $f['key'], self::LEVEL_BLOCK, t('Deux champs du départ portent le même nom : « {nom} ».', ['nom' => $f['label']]));
            }
            $labels[mb_strtolower($f['label'])] = true;
            if ($f['type'] === 'choice' && count($f['options']) < 2) {
                $add(self::START_KEY, 'field:' . $f['key'], self::LEVEL_BLOCK, t('Le choix « {nom} » demande au moins deux options.', ['nom' => $f['label'] ?: '…']));
            }
        }
        if ($start['schedule'] !== null) {
            foreach ($start['fields'] as $f) {
                if ($f['required'] && empty($f['reference']) && ($f['type'] === 'file' || $f['default'] === '')) {
                    $add(self::START_KEY, 'schedule', self::LEVEL_BLOCK, $f['type'] === 'file'
                        ? t('Un workflow programmé ne peut pas exiger de fichier : « {nom} » n’aura personne pour le fournir.', ['nom' => $f['label']])
                        : t('Programmé, le workflow démarre seul : donnez une valeur par défaut à « {nom} » ou rendez-le facultatif.', ['nom' => $f['label']]));
                }
            }
        }
        self::eventIssues($def, $ctx, $add);

        // Noms de pièces
        $names = [];
        foreach ($pieces as $p) {
            if ($p['producer'] === self::START_KEY) {
                $names[mb_strtolower($p['name'])] = true;
            }
        }

        foreach (self::flatNodes($def) as $node) {
            $k = $node['key'];
            if ($node['title'] === '') {
                $add($k, 'title', self::LEVEL_BLOCK, t('Donnez un nom à cette brique.'));
            }
            $available = self::available($def, $k);
            foreach ((array)($node['reads'] ?? []) as $r) {
                if (!isset($available[$r])) {
                    $add($k, 'reads', self::LEVEL_BLOCK, isset($pieces[$r])
                        ? t('La pièce « {nom} » n’existe pas encore à ce point du workflow.', ['nom' => $pieces[$r]['name']])
                        : t('Cette brique lit une pièce qui n’existe plus.'));
                }
            }
            foreach (['instruction', 'question', 'note', 'message'] as $f) {
                foreach (self::mentions(is_string($node[$f] ?? null) ? $node[$f] : '') as $m) {
                    if (!isset($available[$m])) {
                        $add($k, $f, self::LEVEL_BLOCK, isset($pieces[$m])
                            ? t('La consigne cite « {nom} », qui n’existe pas encore à ce point.', ['nom' => $pieces[$m]['name']])
                            : t('La consigne cite une pièce qui n’existe plus.'));
                    }
                }
            }
            if (in_array($node['type'], [self::TYPE_STEP, self::TYPE_ACTION], true)) {
                self::filterIssues($node, $available, $pieces, $add);
            }

            match ($node['type']) {
                self::TYPE_STEP      => self::stepIssues($def, $node, $ctx, $names, $add),
                self::TYPE_ACTION    => self::actionIssues($def, $node, $ctx, $names, $available, $add),
                self::TYPE_CREATE    => self::createIssues($def, $node, $ctx, $names, $add),
                self::TYPE_CONDITION => self::conditionIssues($def, $node, $available, $add),
                self::TYPE_ROUTE     => self::routeIssues($def, $node, $available, $pieces, $add),
                self::TYPE_LOOP      => self::loopIssues($def, $node, $available, $names, $add, $ctx),
                self::TYPE_GATE      => self::gateIssues($def, $node, $available, $add),
                self::TYPE_WAIT      => self::waitIssues($node, $available, $add),
                self::TYPE_NOTIFY    => self::notifyIssues($node, $add),
                self::TYPE_CALL      => WorkflowCall::issues($def, $node, $ctx, $names, $available, $add),
                self::TYPE_STOP      => null,
            };
        }

        // Une brique posée après « Arrêter le workflow », dans la même voie,
        // ne sera jamais exécutée.
        foreach ($def['nodes'] ?? [] as $n) {
            foreach (self::branchesOf($n) as $l) {
                $stop = null;
                foreach ($l['nodes'] as $c) {
                    if ($stop !== null) {
                        $add($c['key'], 'title', self::LEVEL_WARN, t('Cette brique suit « {titre} » : elle ne sera jamais exécutée.', ['titre' => $stop['title'] !== '' ? $stop['title'] : t('Arrêter le workflow')]));
                    } elseif ($c['type'] === self::TYPE_STOP) {
                        $stop = $c;
                    }
                }
            }
        }

        $endAvailable = self::available($def, self::END_KEY);
        foreach (self::mentions((string)$def['end']['note']) as $m) {
            if (!isset($endAvailable[$m])) {
                $add(self::END_KEY, 'note', self::LEVEL_BLOCK, t('Le mot de la fin cite une pièce qui n’existe pas à la fin du workflow.'));
            }
        }
        if ($def['end']['pieces'] === [] && $def['end']['deliveries'] === [] && $producers > 0) {
            $add(self::END_KEY, 'pieces', self::LEVEL_WARN, t('Le livrable ne montre aucune pièce : choisissez ce que vous obtenez à la fin.'));
        }
        self::deliveryIssues($def, $ctx, $add);

        // Lancé par un contenu venu d'ailleurs (email, appel d'une adresse,
        // fichier déposé) : une écriture sans validation peut être détournée
        // par ce contenu — le dire, sans l'interdire.
        $event = $start['event']['kind'] ?? null;
        if (in_array($event, ['email', 'webhook', 'file'], true)) {
            foreach (self::flatNodes($def) as $node) {
                if (($node['write'] ?? '') === self::WRITE_AUTO) {
                    $add($node['key'], 'write', self::LEVEL_WARN, t('Ce workflow démarre sur un contenu venu de l’extérieur : une action sans validation pourrait être détournée par ce contenu. Préférez « À approuver ».'));
                }
            }
            foreach ($def['end']['deliveries'] as $i => $d) {
                if (in_array($d['mode'] ?? '', ['send', 'auto'], true)) {
                    $add(self::END_KEY, 'delivery:' . $i, self::LEVEL_WARN, t('Ce workflow démarre sur un contenu venu de l’extérieur : une livraison sans validation pourrait être détournée par ce contenu.'));
                }
            }
        }
        return $issues;
    }

    private static function eventIssues(array $def, array $ctx, callable $add): void
    {
        $start = $def['start'];
        $event = $start['event'];
        if ($event === null) {
            return;
        }
        $label = static fn(string $conn): string => (string)($ctx['sources'][$conn]['label'] ?? $conn);
        switch ($event['kind']) {
            case 'email':
                $conn = $event['source'];
                if (isset($ctx['sources']) && !isset($ctx['sources'][$conn])) {
                    $add(self::START_KEY, 'event', self::LEVEL_BLOCK, t('Ce connecteur n’est pas proposé sur cette instance.'));
                } elseif (isset($ctx['sources'][$conn]) && empty($ctx['sources'][$conn]['connected'])) {
                    $add(self::START_KEY, 'event', self::LEVEL_BLOCK, t('Reliez d’abord « {nom} » dans Réglages → Comptes connectés.', ['nom' => $label($conn)]), (string)$conn);
                }
                break;
            case 'file':
                if (trim($event['folder']) === '') {
                    $add(self::START_KEY, 'event', self::LEVEL_BLOCK, t('Indiquez le dossier Google Drive à surveiller.'));
                }
                if (isset($ctx['sources']) && !isset($ctx['sources']['drive'])) {
                    $add(self::START_KEY, 'event', self::LEVEL_BLOCK, t('Ce connecteur n’est pas proposé sur cette instance.'));
                } elseif (isset($ctx['sources']['drive']) && empty($ctx['sources']['drive']['connected'])) {
                    $add(self::START_KEY, 'event', self::LEVEL_BLOCK, t('Reliez d’abord « {nom} » dans Réglages → Comptes connectés.', ['nom' => $label('drive')]), 'drive');
                }
                break;
            case 'workflow':
                $id = $event['workflow_id'];
                if ($id === null) {
                    $add(self::START_KEY, 'event', self::LEVEL_BLOCK, t('Choisissez le workflow qui lance celui-ci.'));
                } elseif (isset($ctx['self_id']) && $id === (int)$ctx['self_id']) {
                    $add(self::START_KEY, 'event', self::LEVEL_BLOCK, t('Un workflow ne peut pas se lancer lui-même.'));
                } elseif (isset($ctx['workflows']) && !isset($ctx['workflows'][$id])) {
                    $add(self::START_KEY, 'event', self::LEVEL_BLOCK, t('Le workflow choisi n’existe plus : choisissez-en un autre.'));
                }
                break;
        }
        // Un évènement (sauf un appel, qui peut fournir les champs) lance le
        // workflow sans personne pour remplir le formulaire.
        if ($event['kind'] !== 'webhook') {
            foreach ($start['fields'] as $f) {
                if ($f['required'] && ($f['type'] === 'file' || $f['default'] === '')) {
                    $add(self::START_KEY, 'event', self::LEVEL_BLOCK, $f['type'] === 'file'
                        ? t('Lancé par un évènement, le workflow ne peut pas exiger de fichier : « {nom} » n’aura personne pour le fournir.', ['nom' => $f['label']])
                        : t('Lancé par un évènement, le workflow démarre seul : donnez une valeur par défaut à « {nom} » ou rendez-le facultatif.', ['nom' => $f['label']]));
                }
            }
        }
    }

    private static function loopIssues(array $def, array $node, array $available, array &$names, callable $add, array $ctx = []): void
    {
        $k = $node['key'];
        if ($node['body'] === []) {
            $add($k, 'body', self::LEVEL_BLOCK, t('Ajoutez au moins une brique dans la boucle.'));
        }
        if ($node['mode'] === self::LOOP_EACH) {
            self::loopSourceIssues($def, $node, $available, $add, $ctx);
            $item = mb_strtolower($node['item']['name']);
            if (isset($names[$item])) {
                $add($k, 'item', self::LEVEL_BLOCK, t('Deux pièces s’appellent « {nom} » : renommez-en une.', ['nom' => $node['item']['name']]));
            }
            $names[$item] = true;
            if (!empty($node['review_actions'])) {
                $acts = array_filter($node['body'], static fn(array $c): bool => self::writes($c) && ($c['write'] ?? '') !== self::WRITE_AUTO);
                if ($acts === []) {
                    $add($k, 'review_actions', self::LEVEL_WARN, t('Aucune brique de la boucle ne prépare d’action à approuver : il n’y aura rien à relire à la fin.'));
                }
            }
        } else {
            $u = $node['until'];
            $untilAvail = self::available($def, $k . self::UNTIL_SUFFIX);
            if ($u['mode'] === 'question') {
                if (trim($u['question']) === '') {
                    $add($k, 'until', self::LEVEL_BLOCK, t('Écrivez la question qui dit quand s’arrêter.'));
                }
                foreach (self::mentions($u['question']) as $m) {
                    if (!isset($untilAvail[$m])) {
                        $add($k, 'until', self::LEVEL_BLOCK, t('La question cite une pièce qui n’existe pas à ce point.'));
                    }
                }
            } else {
                self::ruleIssues($k, 'until', $u['rule'], $untilAvail, $add);
            }
            if (self::loopCollect($node) === null) {
                $add($k, 'body', self::LEVEL_BLOCK, t('Une boucle « jusqu’à » refait une étape : ajoutez une étape dans la boucle.'));
            }
        }
        $collect = (string)$node['collect'];
        if ($collect !== '' && self::loopCollect($node) !== $collect) {
            $add($k, 'collect', self::LEVEL_WARN, t('La pièce suivie n’est plus dans la boucle : la dernière étape de la boucle sera suivie.'));
        }
        if ($node['mode'] === self::LOOP_EACH && self::loopCollect($node) === null && $node['body'] !== []) {
            $add($k, 'collect', self::LEVEL_WARN, t('Aucune étape de la boucle ne remet de pièce : la boucle ne rassemblera rien.'));
        }
        $out = self::pieces($def)[$node['output']['key']] ?? null;
        if ($out !== null) {
            $name = mb_strtolower($out['name']);
            if (isset($names[$name])) {
                $add($k, 'output', self::LEVEL_BLOCK, t('Deux pièces s’appellent « {nom} » : renommez-en une.', ['nom' => $out['name']]));
            }
            $names[$name] = true;
        }
    }

    /** Ce que parcourt une boucle « pour chaque » : la pièce, le fichier, la recherche, le filtre. */
    private static function loopSourceIssues(array $def, array $node, array $available, callable $add, array $ctx): void
    {
        $k = $node['key'];
        $source = $node['source'] ?? ['kind' => ''];
        $kind = (string)($source['kind'] ?? '');
        $over = $available[$node['over']] ?? null;

        if ($kind === '') {
            // Boucle d'avant les découpeurs : une liste, un tableau, un champ liste.
            if ($over === null) {
                $add($k, 'over', self::LEVEL_BLOCK, t('Choisissez la pièce à parcourir : une liste, un tableau ou un champ liste d’une Fiche, remis avant la boucle.'));
            } elseif ($over['form'] === 'fiche') {
                if (LoopSources::listField($over, (string)$node['over_field']) === null) {
                    $add($k, 'over', self::LEVEL_BLOCK, t('Dans « {nom} », choisissez un champ de type liste à parcourir.', ['nom' => $over['name']]));
                }
            } elseif (!in_array($over['form'], ['list', 'table'], true)) {
                $add($k, 'over', self::LEVEL_BLOCK, t('Une boucle parcourt une liste, un tableau ou un champ liste d’une Fiche.'));
            }
            return;
        }

        if (LoopSources::readsPiece($source)) {
            if ($over === null || ($kind === LoopSources::ATTACHMENTS && !LoopSources::accepts($kind, $over))) {
                // Des pièces jointes ne se lisent que dans l'email reçu au départ.
                $add($k, 'over', self::LEVEL_BLOCK, $kind === LoopSources::ATTACHMENTS
                    ? t('Les pièces jointes se lisent dans l’email qui lance le workflow : choisissez le déclencheur « Un email arrive », ou cherchez des emails.')
                    : t('Choisissez ce que la boucle parcourt : une pièce remise avant elle.'));
            } elseif (!LoopSources::accepts($kind, $over, (string)$node['over_field'])) {
                $add($k, 'over', self::LEVEL_BLOCK, $over['form'] === 'fiche' && $kind === LoopSources::LIST
                    ? t('Dans « {nom} », choisissez un champ de type liste à parcourir.', ['nom' => $over['name']])
                    : t('« {nom} » ne se parcourt pas ainsi : choisissez une autre pièce, ou une autre façon de la parcourir.', ['nom' => $over['name']]));
            }
        }
        $connector = LoopSources::connector($source, $def['start']['event'] ?? null);
        if ($connector !== '' && isset($ctx['sources'])) {
            if (!isset($ctx['sources'][$connector])) {
                $add($k, 'source', self::LEVEL_BLOCK, t('Ce connecteur n’est pas proposé sur cette instance.'));
            } elseif (empty($ctx['sources'][$connector]['connected'])) {
                $add($k, 'source', self::LEVEL_BLOCK, t('Reliez d’abord « {nom} » dans Réglages → Comptes connectés.', ['nom' => (string)($ctx['sources'][$connector]['label'] ?? $connector)]), (string)$connector);
            }
        }
        if (($source['from'] ?? '') === LoopSources::FROM_TABLE) {
            $tableId = (int)($source['table_id'] ?? 0);
            if ($tableId <= 0) {
                $add($k, 'source', self::LEVEL_BLOCK, t('Choisissez la table à parcourir.'));
            } elseif (isset($ctx['kbs']) && empty($ctx['kbs'][$tableId]['table'])) {
                $add($k, 'source', self::LEVEL_BLOCK, t('Cette table n’existe plus ou ne vous est plus partagée : choisissez-en une autre.'));
            } elseif (isset($ctx['kbs'][$tableId]) && (int)($ctx['kbs'][$tableId]['rows'] ?? 0) === 0) {
                $add($k, 'source', self::LEVEL_WARN, t('La table « {nom} » est vide : la boucle n’aura rien à parcourir.', ['nom' => (string)$ctx['kbs'][$tableId]['name']]));
            }
        }
        if (($source['from'] ?? '') === LoopSources::FROM_DRIVE && trim((string)($source['drive'] ?? '')) === '') {
            $add($k, 'source', self::LEVEL_BLOCK, $kind === LoopSources::FILES
                ? t('Indiquez le dossier Google Drive à parcourir.')
                : t('Indiquez le fichier Google Drive à lire : son lien, son nom ou son chemin.'));
        }
        if ($kind === LoopSources::AI && trim((string)($source['instruction'] ?? '')) === '') {
            $add($k, 'source', self::LEVEL_BLOCK, t('Dites à MIA ce qu’elle doit repérer : « chaque sujet abordé », « chaque référence produit »…'));
        }
        if ($kind === LoopSources::EMAILS && trim((string)($source['query'] ?? '')) === '' && (int)($source['days'] ?? 0) === 0) {
            $add($k, 'source', self::LEVEL_WARN, t('Sans recherche ni période, les emails les plus récents de la boîte de réception sont parcourus.'));
        }
        if ($kind === LoopSources::ROWS && ($source['columns'] ?? []) === []) {
            $add($k, 'source', self::LEVEL_WARN, ($source['from'] ?? '') === LoopSources::FROM_TABLE
                ? t('Aucune colonne déclarée : chaque tour reçoit la ligne entière, sans champ à citer ni à filtrer. Reprenez les colonnes de la table.')
                : t('Aucune colonne déclarée : chaque tour reçoit la ligne entière, sans champ à citer ni à filtrer. Lisez les colonnes sur un fichier exemple.'));
        }
        foreach (['instruction', 'query', 'drive'] as $f) {
            foreach (self::mentions((string)($source[$f] ?? '')) as $m) {
                if (!isset($available[$m])) {
                    $add($k, 'source', self::LEVEL_BLOCK, t('La source de la boucle cite une pièce qui n’existe pas encore à ce point.'));
                }
            }
        }

        // Filtre : il lit les champs de l'élément.
        $itemPiece = self::pieces($def)[$node['item']['key']] ?? null;
        $filter = $node['filter'] ?? [];
        $fields = array_column((array)($itemPiece['fields'] ?? []), null, 'key');
        if (($filter['rule']['items'] ?? []) !== []) {
            if ($itemPiece === null || $itemPiece['form'] !== 'fiche' || $fields === []) {
                $add($k, 'filter', self::LEVEL_BLOCK, t('Le filtre lit les champs de l’élément : déclarez d’abord ses colonnes ou ses champs.'));
            } else {
                self::ruleIssues($k, 'filter', $filter['rule'], [$itemPiece['key'] => $itemPiece], $add);
            }
        }
        if (($filter['dedupe'] ?? '') !== '' && !isset($fields[$filter['dedupe']])) {
            $add($k, 'filter', self::LEVEL_WARN, t('Le champ des doublons n’existe plus dans l’élément : les doublons ne seront pas écartés.'));
        }
    }

    private static function waitIssues(array $node, array $available, callable $add): void
    {
        if ($node['mode'] !== 'date') {
            return;
        }
        $p = $available[$node['piece']] ?? null;
        $ok = false;
        if ($p !== null && $p['form'] === 'fiche') {
            foreach ($p['fields'] ?? [] as $f) {
                $ok = $ok || ($f['key'] === $node['field'] && $f['type'] === 'date');
            }
        }
        if (!$ok) {
            $add($node['key'], 'date', self::LEVEL_BLOCK, t('Choisissez le champ date d’une Fiche remise avant l’attente.'));
        }
    }

    private static function notifyIssues(array $node, callable $add): void
    {
        if (trim($node['message']) === '') {
            $add($node['key'], 'message', self::LEVEL_BLOCK, t('Écrivez le message de la notification.'));
        }
    }

    private static function deliveryIssues(array $def, array $ctx, callable $add): void
    {
        $available = self::available($def, self::END_KEY);
        // Une image ne s'enregistre pas dans Drive ni ne se publie dans Slack
        // (aucun envoi de fichier binaire) : elle y est laissée de côté.
        $images = static function (array $keys) use ($available): array {
            return array_values(array_map(
                static fn(string $k): string => (string)$available[$k]['name'],
                array_filter($keys, static fn(string $k): bool => ($available[$k]['form'] ?? '') === 'image')
            ));
        };
        foreach ($def['end']['deliveries'] as $i => $d) {
            $t = 'delivery:' . $i;
            $source = match ($d['type']) { 'email' => $d['source'], 'drive' => 'drive', 'slack' => 'slack', default => null };
            if ($source !== null) {
                if (isset($ctx['sources']) && !isset($ctx['sources'][$source])) {
                    $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Ce connecteur n’est pas proposé sur cette instance.'));
                } elseif (isset($ctx['sources'][$source]) && empty($ctx['sources'][$source]['connected'])) {
                    $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Reliez d’abord « {nom} » dans Réglages → Comptes connectés.', ['nom' => (string)$ctx['sources'][$source]['label']]), (string)$source);
                }
            }
            if (in_array($d['mode'] ?? '', ['draft', 'send', 'auto'], true) && empty($ctx['auto'])) {
                $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('« Agir sans validation » n’est pas autorisé par l’administrateur.'));
            }
            foreach (array_merge($d['attach'] ?? [], $d['pieces'] ?? []) as $k) {
                if (!isset($available[$k])) {
                    $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Une livraison envoie une pièce qui n’existe pas à la fin du workflow.'));
                    break;
                }
            }
            foreach (['subject', 'message', 'to', 'folder'] as $f) {
                if (!isset($d[$f])) {
                    continue;
                }
                foreach (self::mentions((string)$d[$f]) as $m) {
                    if (!isset($available[$m])) {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Le texte d’une livraison cite une pièce qui n’existe pas à la fin du workflow.'));
                    }
                }
            }
            switch ($d['type']) {
                case 'email':
                    // Le destinataire peut citer un champ email du dossier :
                    // @{pièce.champ}, rempli à la livraison.
                    $cited = self::fieldMentions($d['to']);
                    foreach ($cited as [$pk, $fk]) {
                        $isEmail = false;
                        foreach ($available[$pk]['fields'] ?? [] as $f) {
                            $isEmail = $isEmail || ($f['key'] === $fk && $f['type'] === 'email');
                        }
                        if (!$isEmail) {
                            $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Le destinataire doit venir d’un champ email d’une Fiche du dossier.'));
                        }
                    }
                    $fixed = self::emailList(self::withoutMentions($d['to']));
                    if ($fixed['invalid'] !== []) {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Adresse invalide : {adresse}.', ['adresse' => implode(', ', $fixed['invalid'])]));
                    }
                    $fieldOk = false;
                    if ($d['to_field'] !== '') {
                        [$pk, $fk] = explode('.', $d['to_field'], 2);
                        foreach (($available[$pk]['fields'] ?? []) as $f) {
                            $fieldOk = $fieldOk || ($f['key'] === $fk && $f['type'] === 'email');
                        }
                        if (!$fieldOk) {
                            $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Le destinataire doit venir d’un champ email d’une Fiche du dossier.'));
                        }
                    }
                    if ($fixed['valid'] === [] && !$fieldOk && $d['to_field'] === '' && $cited === []) {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Indiquez à qui envoyer l’email.'));
                    }
                    if (trim($d['subject']) === '') {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Donnez un objet à l’email.'));
                    }
                    if (trim($d['message']) === '' && $d['attach'] === []) {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Écrivez le message de l’email, ou joignez une pièce.'));
                    }
                    break;
                case 'drive':
                    if ($d['pieces'] === []) {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Choisissez les pièces à enregistrer dans Drive.'));
                    }
                    foreach ($images($d['pieces']) as $name) {
                        $add(self::END_KEY, $t, self::LEVEL_WARN, t('Drive n’enregistre ici que des documents et des tableaux : l’image « {nom} » sera laissée de côté.', ['nom' => $name]));
                    }
                    break;
                case 'slack':
                    if (trim($d['channel']) === '') {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Indiquez le canal Slack.'));
                    } elseif (!UserSlackService::isPlausibleChannelRef($d['channel'])) {
                        // « Canal Slack » : une description, pas un canal — la
                        // livraison échouerait à chaque exécution, à la fin.
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('« {canal} » n’est pas un nom de canal Slack (il ne contient jamais d’espace) : écrivez-le comme dans Slack, par exemple #general.', ['canal' => trim($d['channel'])]));
                    }
                    // Une action qui publie déjà dans Slack : la livraison
                    // ajoute un SECOND message, ce qu'on ne voit pas sur le rail.
                    foreach (self::flatNodes($def) as $n) {
                        if ($n['type'] === self::TYPE_ACTION && ($n['target'] ?? '') === 'slack' && !self::isReadingAction($n)) {
                            $add(self::END_KEY, $t, self::LEVEL_WARN, t('L’action « {titre} » publie déjà dans Slack : cette livraison publiera un second message. Gardez l’une ou l’autre.', ['titre' => $n['title'] !== '' ? $n['title'] : t('Action')]));
                            break;
                        }
                    }
                    if (trim($d['message']) === '' && $d['pieces'] === []) {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Écrivez le message à publier, ou choisissez une pièce.'));
                    }
                    foreach ($images($d['pieces']) as $name) {
                        $add(self::END_KEY, $t, self::LEVEL_WARN, t('Slack ne reçoit ici que du texte : l’image « {nom} » sera laissée de côté.', ['nom' => $name]));
                    }
                    break;
                case 'table':
                    self::tableDeliveryIssues($d, $available, $ctx, $t, $add);
                    break;
                case 'kb':
                    if ($d['kb_id'] === null || (isset($ctx['delivery_kbs']) && !isset($ctx['delivery_kbs'][$d['kb_id']]))) {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Choisissez une base de connaissances que vous gérez.'));
                    }
                    if ($d['pieces'] === []) {
                        $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Choisissez les pièces à ajouter à la base.'));
                    }
                    break;
            }
        }
    }

    /** Livraison dans une table permanente : la table, les pièces, la clé d'une mise à jour. */
    private static function tableDeliveryIssues(array $d, array $available, array $ctx, string $t, callable $add): void
    {
        $tables = $ctx['delivery_tables'] ?? null;
        $target = $d['kb_id'] !== null && is_array($tables) ? ($tables[$d['kb_id']] ?? null) : null;
        if ($d['kb_id'] === null) {
            $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Choisissez la table où écrire.'));
        } elseif (is_array($tables) && $target === null) {
            $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Cette table n’existe plus, vous ne la gérez plus, ou elle est en lecture seule pour MIA (réglage de sa fiche).'));
        }
        if ($d['pieces'] === []) {
            $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Choisissez la pièce dont les lignes vont dans la table : un Tableau ou une Fiche.'));
        }
        foreach ($d['pieces'] as $k) {
            $p = $available[$k] ?? null;
            if ($p !== null && !in_array($p['form'], ['table', 'fiche'], true) && !($p['form'] === 'document' && ($p['format'] ?? '') === 'xlsx')) {
                $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('« {nom} » ne s’écrit pas dans une table : choisissez un Tableau, une Fiche ou un classeur Excel.', ['nom' => $p['name']]));
            }
        }
        if ($d['write'] === KbTableWrite::MODE_UPDATE) {
            if (trim($d['key']) === '') {
                $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('Choisissez la colonne de la table qui identifie une ligne (email, nom, référence).'));
            } elseif ($target !== null) {
                $found = false;
                foreach ((array)($target['columns'] ?? []) as $c) {
                    $found = $found || WorkTableOps::fold((string)$c['label']) === WorkTableOps::fold($d['key']) || (string)$c['key'] === WorkTableOps::slug($d['key']);
                }
                if (!$found) {
                    $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('La colonne « {colonne} » n’est plus dans la table : choisissez-en une autre.', ['colonne' => $d['key']]));
                }
            }
            // Une Fiche : son champ doit porter le nom de la colonne clé.
            $keyName = trim($d['key_source']) !== '' ? $d['key_source'] : $d['key'];
            foreach ($d['pieces'] as $k) {
                $p = $available[$k] ?? null;
                if ($p === null || $p['form'] !== 'fiche' || trim($keyName) === '') {
                    continue;
                }
                $has = false;
                foreach ((array)($p['fields'] ?? []) as $f) {
                    $has = $has || WorkTableOps::fold((string)$f['label']) === WorkTableOps::fold($keyName);
                }
                if (!$has) {
                    $add(self::END_KEY, $t, self::LEVEL_BLOCK, t('La Fiche « {nom} » n’a pas de champ « {champ} » : la ligne à mettre à jour ne se retrouverait pas.', ['nom' => $p['name'], 'champ' => $keyName]));
                }
            }
        } elseif ($d['write'] === KbTableWrite::MODE_REPLACE) {
            $add(self::END_KEY, $t, self::LEVEL_WARN, t('Chaque exécution remplace TOUT le contenu de la table (son historique permet de revenir en arrière).'));
        }
    }

    /**
     * Adresses d'un champ « À » : séparées par des virgules ou des
     * points-virgules, « Nom <adresse> » réduit à l'adresse.
     *
     * @return array{valid: list<string>, invalid: list<string>}
     */
    public static function emailList(string $to): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[,;]+/', $to) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/<([^>]+)>/', $part, $m)) {
                $part = trim($m[1]);
            }
            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $valid[] = $part;
            } else {
                $invalid[] = $part;
            }
        }
        return ['valid' => array_values(array_unique($valid)), 'invalid' => $invalid];
    }

    /**
     * Étape réglée pour agir sans rien qui la nourrisse ni rien où agir :
     * aucun connecteur, aucun outil, ni assistant ni Internet. Un assistant
     * apporte ses propres bases, documents et outils, et Internet nourrit
     * la pièce qu'elle rédige : l'avertir alors ferait chercher un réglage
     * qui ne manque pas.
     */
    public static function writesWithNothing(array $node): bool
    {
        if (($node['type'] ?? self::TYPE_STEP) !== self::TYPE_STEP
            || ($node['write'] ?? self::WRITE_NONE) === self::WRITE_NONE) {
            return false;
        }
        if (($node['profile_id'] ?? null) !== null) {
            return false;
        }
        $sources = (array)($node['sources'] ?? []);
        return $sources === [] && (array)($node['tool_ids'] ?? []) === [];
    }

    private static function stepIssues(array $def, array $node, array $ctx, array &$names, callable $add): void
    {
        $k = $node['key'];
        if (trim($node['instruction']) === '') {
            $add($k, 'instruction', self::LEVEL_BLOCK, t('Écrivez la consigne de l’étape.'));
        }
        if ($node['profile_id'] !== null && !isset($ctx['profiles'][$node['profile_id']])) {
            $add($k, 'profile', self::LEVEL_BLOCK, t('Cet assistant n’est plus accessible : choisissez-en un autre.'));
        }
        if ($node['profile_id'] === null && $node['llm'] !== '' && !isset($ctx['llms'][$node['llm']])) {
            $add($k, 'llm', self::LEVEL_BLOCK, t('Ce modèle n’est plus proposé : choisissez-en un autre.'));
        }
        $o = $node['output'];
        if ($o['name'] === '') {
            $add($k, 'output', self::LEVEL_BLOCK, t('Nommez la pièce que l’étape remet.'));
        } elseif (isset($names[mb_strtolower($o['name'])])) {
            $add($k, 'output', self::LEVEL_BLOCK, t('Deux pièces s’appellent « {nom} » : renommez-en une.', ['nom' => $o['name']]));
        } else {
            $names[mb_strtolower($o['name'])] = true;
        }
        if ($o['form'] === 'fiche' && ($o['fields'] ?? []) === []) {
            $add($k, 'output', self::LEVEL_BLOCK, t('Une Fiche demande au moins un champ.'));
        }
        if (self::isCreationForm($o['form'])) {
            // L'étape prépare, l'outil de création fabrique : il doit être là.
            self::creationToolIssues($def, $node, $o['form'], $o['tool_id'] ?? null, $ctx, $add, 'form');
        }
        foreach ($node['sources'] as $s) {
            if (isset($ctx['sources']) && !isset($ctx['sources'][$s])) {
                $add($k, 'sources', self::LEVEL_WARN, t('Une source n’est plus proposée et sera ignorée.'));
            } elseif (isset($ctx['sources'][$s]) && empty($ctx['sources'][$s]['connected'])) {
                $add($k, 'sources', self::LEVEL_WARN, t('« {nom} » n’est pas relié à votre compte : l’étape ne pourra pas le consulter.', ['nom' => $ctx['sources'][$s]['label']]), (string)$s);
            }
        }
        if ($node['write'] === self::WRITE_AUTO && empty($ctx['auto'])) {
            $add($k, 'write', self::LEVEL_BLOCK, t('« Agir sans validation » n’est pas autorisé par l’administrateur.'));
        }
        if (self::writesWithNothing($node)) {
            $add($k, 'write', self::LEVEL_WARN, t('L’étape peut agir, mais aucun outil n’est relié : choisissez une source à laquelle écrire.'));
        }
        $needsTools = $node['write'] !== self::WRITE_NONE || array_filter($node['sources'], static fn($s) => $s !== 'web') !== [];
        if ($needsTools && isset($ctx['tool_llms'])) {
            $llm = self::effectiveLlm($node, $ctx);
            if ($llm !== '' && empty($ctx['tool_llms'][$llm])) {
                $add($k, 'llm', self::LEVEL_WARN, t('Le modèle de cette étape ne sait pas utiliser les connecteurs : ses sources seront ignorées.'));
            }
        }
        // Une table permanente se lit par les outils de tables : sans eux, le
        // modèle n'en voit que le début (KbTablePrompt::preview).
        $tables = array_values(array_filter($node['kb_ids'], static fn(int $id): bool => !empty($ctx['kbs'][$id]['table'])));
        if ($tables !== [] && isset($ctx['tool_llms'])) {
            $llm = self::effectiveLlm($node, $ctx);
            if ($llm !== '' && empty($ctx['tool_llms'][$llm])) {
                $add($k, 'sources', self::LEVEL_WARN, t('Le modèle de cette étape ne sait pas utiliser les outils : la table « {nom} » ne sera lue qu’en aperçu (ses {n} premières lignes). Choisissez un modèle qui sait utiliser les connecteurs pour la lire en entier.', [
                    'nom' => (string)$ctx['kbs'][$tables[0]]['name'], 'n' => KbTablePrompt::PREVIEW_ROWS,
                ]));
            }
        }
    }

    private static function actionIssues(array $def, array $node, array $ctx, array &$names, array $available, callable $add): void
    {
        $k = $node['key'];
        $target = $node['target'];
        $reading = self::isReadingAction($node);
        if ($target === '') {
            $add($k, 'target', self::LEVEL_BLOCK, t('Choisissez l’outil dans lequel agir.'));
        } else {
            $conn = self::ACTION_TARGETS[$target];
            if (str_starts_with($conn, 'tool:')) {
                $tool = $node['tool_id'] !== null ? ($ctx['tools'][$node['tool_id']] ?? null) : null;
                if ($tool === null || $tool['type'] !== substr($conn, 5)) {
                    $add($k, 'target', self::LEVEL_BLOCK, t('Choisissez l’outil à utiliser.'));
                } elseif ($tool['reason'] !== null) {
                    $add($k, 'target', self::LEVEL_BLOCK, (string)$tool['reason']);
                }
            } elseif (isset($ctx['sources']) && !isset($ctx['sources'][$conn])) {
                $add($k, 'target', self::LEVEL_BLOCK, t('Ce connecteur n’est pas proposé sur cette instance.'));
            } elseif (isset($ctx['sources'][$conn]) && empty($ctx['sources'][$conn]['connected'])) {
                $add($k, 'target', self::LEVEL_BLOCK, t('Reliez d’abord « {nom} » dans Réglages → Comptes connectés.', ['nom' => $ctx['sources'][$conn]['label']]), (string)$conn);
            }
        }
        if ($target === 'slack') {
            self::slackIssues($node, $ctx, $names, $available, $add);
        } elseif (trim($node['instruction']) === '') {
            $add($k, 'instruction', self::LEVEL_BLOCK, t('Dites ce que l’action doit faire.'));
        }
        if ($node['write'] === self::WRITE_AUTO && empty($ctx['auto'])) {
            $add($k, 'write', self::LEVEL_BLOCK, t('« Agir sans validation » n’est pas autorisé par l’administrateur.'));
        }
        // Lire Slack : les messages sont lus AVANT le tour, le modèle ne fait
        // que rédiger la pièce — pas besoin qu'il pilote les connecteurs.
        if (isset($ctx['tool_llms']) && !$reading) {
            $llm = self::effectiveLlm($node, $ctx);
            if ($llm !== '' && empty($ctx['tool_llms'][$llm])) {
                $add($k, 'llm', self::LEVEL_BLOCK, t('Le modèle de cette action ne sait pas utiliser les connecteurs : choisissez-en un autre.'));
            }
        }
    }

    /**
     * Une brique Slack : où publier (ou lire), quoi, ce qui est joint ; et ce
     * que la connexion de la personne permet (`$ctx['slack']`,
     * UserSlackService::capabilities) — une autorisation qui manque se règle
     * en reconnectant Slack, d'où un avertissement plutôt qu'un blocage.
     */
    private static function slackIssues(array $node, array $ctx, array &$names, array $available, callable $add): void
    {
        $k = $node['key'];
        $s = $node['slack'];
        $caps = is_array($ctx['slack'] ?? null) ? $ctx['slack'] : null;
        $post = $s['mode'] === self::SLACK_POST;
        $target = $s['where'];

        $ref = trim((string)match ($target) {
            self::SLACK_PERSON => $s['person'],
            self::SLACK_SEARCH => $s['query'],
            default            => $s['channel'],
        });
        if ($ref === '') {
            $add($k, 'slack', self::LEVEL_BLOCK, match ($target) {
                self::SLACK_PERSON => $post ? t('Indiquez à qui écrire.') : t('Indiquez la personne dont lire les messages.'),
                self::SLACK_SEARCH => t('Indiquez ce qu’il faut chercher dans Slack.'),
                default            => $post ? t('Indiquez le canal où publier.') : t('Indiquez le canal à lire.'),
            });
        } elseif ($target === self::SLACK_CHANNEL && self::mentions($ref) === [] && !UserSlackService::isPlausibleChannelRef($ref)) {
            $add($k, 'slack', self::LEVEL_BLOCK, t('« {canal} » n’est pas un nom de canal Slack (il ne contient jamais d’espace) : écrivez-le comme dans Slack, par exemple #general.', ['canal' => $ref]));
        }
        foreach (self::mentions($ref) as $m) {
            if (!isset($available[$m])) {
                $add($k, 'slack', self::LEVEL_BLOCK, t('La destination cite une pièce qui n’existe pas encore à ce point.'));
            }
        }

        if ($post) {
            if (trim($node['instruction']) === '' && $s['attach'] === []) {
                $add($k, 'instruction', self::LEVEL_BLOCK, t('Écrivez ce que dit le message, ou joignez une pièce.'));
            }
            foreach ($s['attach'] as $a) {
                if (!isset($available[$a])) {
                    $add($k, 'attach', self::LEVEL_BLOCK, t('Une pièce jointe n’existe pas encore à ce point du workflow.'));
                }
            }
        } else {
            $o = $node['output'];
            if ($o['name'] === '') {
                $add($k, 'output', self::LEVEL_BLOCK, t('Nommez la pièce que la brique remet.'));
            } elseif (isset($names[mb_strtolower($o['name'])])) {
                $add($k, 'output', self::LEVEL_BLOCK, t('Deux pièces s’appellent « {nom} » : renommez-en une.', ['nom' => $o['name']]));
            } else {
                $names[mb_strtolower($o['name'])] = true;
            }
            if ($o['form'] === 'fiche' && ($o['fields'] ?? []) === []) {
                $add($k, 'output', self::LEVEL_BLOCK, t('Une Fiche demande au moins un champ.'));
            }
        }

        if ($caps === null) {
            return;
        }
        $reconnect = t('Reconnectez Slack dans Réglages → Comptes connectés pour accorder cette autorisation.');
        if ($post) {
            if (empty($caps['post'])) {
                $add($k, 'slack', self::LEVEL_WARN, t('Votre connexion Slack ne permet pas de publier.') . ' ' . $reconnect);
            } elseif ($target === self::SLACK_PERSON && empty($caps['direct'])) {
                $add($k, 'slack', self::LEVEL_WARN, t('Votre connexion Slack ne permet pas encore d’écrire à une personne.') . ' ' . $reconnect);
            }
            if ($s['attach'] !== [] && empty($caps['files'])) {
                $add($k, 'attach', self::LEVEL_WARN, t('Votre connexion Slack ne permet pas encore de joindre des fichiers.') . ' ' . $reconnect);
            }
        } elseif ($target === self::SLACK_SEARCH ? empty($caps['search']) : empty($caps['read'])) {
            $add($k, 'slack', self::LEVEL_WARN, t('Votre connexion Slack ne permet pas cette lecture.') . ' ' . $reconnect);
        }
        if ($target === self::SLACK_PERSON && empty($caps['email'])
            && (filter_var($ref, FILTER_VALIDATE_EMAIL) || self::citesEmailField($ref, $available))) {
            $add($k, 'slack', self::LEVEL_WARN, t('Retrouver une personne par son adresse demande une autorisation que votre connexion Slack n’a pas encore : désignez-la par son nom, ou reconnectez Slack.'));
        }
    }

    /**
     * Une création : son outil (ouvert à la personne, en service, de la bonne
     * nature — `$ctx['creations']`, WorkflowCreations::toolsFor), ce qu'il
     * faut créer, le nom de sa pièce ; une image lue par un outil qui ne sait
     * pas partir d'une image n'est pas montrée au modèle.
     */
    private static function createIssues(array $def, array $node, array $ctx, array &$names, callable $add): void
    {
        $k = $node['key'];
        self::creationToolIssues($def, $node, (string)$node['kind'], $node['tool_id'], $ctx, $add, 'tool');
        if (trim($node['instruction']) === '') {
            $add($k, 'instruction', self::LEVEL_BLOCK, t('Décrivez ce qu’il faut créer.'));
        }
        $o = $node['output'];
        if ($o['name'] === '') {
            $add($k, 'output', self::LEVEL_BLOCK, t('Nommez la pièce que la création remet.'));
        } elseif (isset($names[mb_strtolower($o['name'])])) {
            $add($k, 'output', self::LEVEL_BLOCK, t('Deux pièces s’appellent « {nom} » : renommez-en une.', ['nom' => $o['name']]));
        } else {
            $names[mb_strtolower($o['name'])] = true;
        }
    }

    /**
     * L'outil d'une création — brique Création, ou étape qui remet une image,
     * une page, une carte : ouvert à la personne, en service, de la bonne
     * nature (`$ctx['creations']`, WorkflowCreations::toolsFor) ; une image
     * lue par un outil qui ne sait pas partir d'une image n'est pas montrée
     * au modèle.
     */
    private static function creationToolIssues(array $def, array $node, string $kind, ?int $toolId, array $ctx, callable $add, string $target): void
    {
        $k = $node['key'];
        $tool = null;
        $step = $node['type'] === self::TYPE_STEP;
        if ($toolId === null) {
            $add($k, $target, self::LEVEL_BLOCK, $step
                ? t('Choisissez l’outil qui crée la pièce de l’étape.')
                : t('Choisissez l’outil qui fait la création.'));
        } elseif (isset($ctx['creations'])) {
            $tool = $ctx['creations'][$toolId] ?? null;
            if ($tool === null) {
                $add($k, $target, self::LEVEL_BLOCK, t('Cet outil ne vous est plus ouvert : choisissez-en un autre.'));
            } elseif ($tool['kind'] !== $kind) {
                $add($k, $target, self::LEVEL_BLOCK, $step
                    ? t('Cet outil ne fait pas la forme choisie : choisissez-en un autre.')
                    : t('Cet outil ne fait pas ce que la brique crée : choisissez-en un autre.'));
                $tool = null;
            } elseif ($tool['reason'] !== null) {
                $add($k, $target, self::LEVEL_BLOCK, (string)$tool['reason']);
            }
        }
        if ($kind === 'image' && $tool !== null && empty($tool['can_edit'])) {
            $pieces = self::pieces($def);
            foreach (self::readsOf($def, $node) as $r) {
                if (($pieces[$r]['form'] ?? '') === 'image') {
                    $add($k, $target, self::LEVEL_WARN, t('Cet outil ne sait pas partir d’une image : « {nom} » ne lui sera pas montrée, seule sa description.', ['nom' => $pieces[$r]['name']]));
                }
            }
        }
    }

    /** Un texte sans ses citations du dossier (validation d'une adresse saisie). */
    public static function withoutMentions(string $text): string
    {
        return trim((string)preg_replace('/@\{[a-z0-9_]{1,' . self::KEY_MAX . '}(?:\.[a-z0-9_]{1,' . self::KEY_MAX . '})?\}/', ' ', $text));
    }

    /** Filtres d'une étape ou d'une action : citations disponibles, destinataires valides. */
    private static function filterIssues(array $node, array $available, array $pieces, callable $add): void
    {
        $k = $node['key'];
        $f = $node['filters'] ?? WorkflowFilters::blank();
        foreach (WorkflowFilters::texts($f) as $text) {
            foreach (self::mentions($text) as $m) {
                if (!isset($available[$m])) {
                    $add($k, 'filters', self::LEVEL_BLOCK, isset($pieces[$m])
                        ? t('Un filtre cite « {nom} », qui n’existe pas encore à ce point.', ['nom' => $pieces[$m]['name']])
                        : t('Un filtre cite une pièce qui n’existe plus.'));
                }
            }
        }
        $rules = WorkflowFilters::recipientRules(self::withoutMentions((string)$f['recipients']));
        if ($rules['invalid'] !== []) {
            $add($k, 'filters', self::LEVEL_BLOCK, t('Destinataires permis : « {valeur} » n’est ni une adresse ni un domaine.', ['valeur' => implode(', ', $rules['invalid'])]));
        }
    }

    /** Décision de MIA : des critères, au moins deux voies nommées, des pièces à lire. */
    private static function routeIssues(array $def, array $node, array $available, array $pieces, callable $add): void
    {
        $k = $node['key'];
        if (trim($node['instruction']) === '') {
            $add($k, 'instruction', self::LEVEL_BLOCK, t('Dites à MIA sur quoi choisir : ses critères.'));
        }
        if (count($node['routes']) < self::MIN_ROUTES) {
            $add($k, 'routes', self::LEVEL_BLOCK, t('Une décision propose au moins deux voies.'));
        }
        $labels = [];
        foreach ($node['routes'] as $r) {
            $label = mb_strtolower(trim($r['label']));
            if ($label === '') {
                $add($k, 'routes', self::LEVEL_BLOCK, t('Nommez chaque voie : MIA choisit par son nom et sa description.'));
            } elseif (isset($labels[$label])) {
                $add($k, 'routes', self::LEVEL_BLOCK, t('Deux voies portent le même nom : « {nom} ».', ['nom' => $r['label']]));
            }
            $labels[$label] = true;
            foreach (self::mentions($r['when']) as $m) {
                if (!isset($available[$m])) {
                    $add($k, 'routes', self::LEVEL_BLOCK, isset($pieces[$m])
                        ? t('Une voie cite « {nom} », qui n’existe pas encore à ce point.', ['nom' => $pieces[$m]['name']])
                        : t('Une voie cite une pièce qui n’existe plus.'));
                }
            }
        }
        if (self::readsOf($def, $node) === []) {
            $add($k, 'reads', self::LEVEL_WARN, t('La décision ne s’appuie sur aucune pièce : MIA choisira sans rien lire.'));
        }
        $stops = 0;
        foreach ($node['routes'] as $r) {
            $stops += self::laneStops($r) ? 1 : 0;
        }
        if ($node['routes'] !== [] && $stops === count($node['routes']) && ($def['end']['pieces'] ?? []) !== []) {
            $add($k, 'routes', self::LEVEL_WARN, t('Toutes les voies arrêtent le workflow : le livrable ne sera jamais atteint.'));
        }
    }

    private static function conditionIssues(array $def, array $node, array $available, callable $add): void
    {
        $k = $node['key'];
        if ($node['mode'] === 'question') {
            if (trim($node['question']) === '') {
                $add($k, 'question', self::LEVEL_BLOCK, t('Écrivez la question à poser à MIA.'));
            }
            if (self::readsOf($def, $node) === []) {
                $add($k, 'reads', self::LEVEL_WARN, t('La question ne s’appuie sur aucune pièce : MIA répondra sans rien lire.'));
            }
        } else {
            self::ruleIssues($k, 'rule', $node['rule'], $available, $add);
        }
        // Les deux voies arrêtent le workflow (même constat qu'une décision) :
        // le livrable n'est jamais atteint — une voie qui a fait son travail
        // devrait reprendre le rail.
        $lanes = array_filter([$node['yes'] ?? null, $node['no'] ?? null], 'is_array');
        if (count($lanes) === 2 && self::laneStops($node['yes']) && self::laneStops($node['no'])
            && ($def['end']['pieces'] ?? []) !== []) {
            $add($k, 'lanes', self::LEVEL_WARN, t('Les deux voies arrêtent le workflow : le livrable ne sera jamais atteint.'));
        }
    }

    private static function ruleIssues(string $k, string $target, array $rule, array $available, callable $add): void
    {
        if ($rule['items'] === []) {
            $add($k, $target, self::LEVEL_BLOCK, t('Ajoutez une règle.'));
        }
        foreach ($rule['items'] as $it) {
            $p = $available[$it['piece']] ?? null;
            if ($p === null || $p['form'] !== 'fiche') {
                $add($k, $target, self::LEVEL_BLOCK, t('Une règle lit un champ de Fiche disponible avant la condition.'));
                continue;
            }
            $fields = array_column($p['fields'], null, 'key');
            if (!isset($fields[$it['field']])) {
                $add($k, $target, self::LEVEL_BLOCK, t('Le champ choisi n’existe pas dans « {nom} ».', ['nom' => $p['name']]));
            } elseif (!in_array($it['op'], ['empty', 'notempty', 'yes', 'no'], true) && $it['value'] === '') {
                $add($k, $target, self::LEVEL_BLOCK, t('Indiquez la valeur à comparer.'));
            }
        }
        foreach (WorkflowValues::ruleConflicts($rule, $available, $target) as $c) {
            $add($k, $target, $c['level'] === 'block' ? self::LEVEL_BLOCK : self::LEVEL_WARN, $c['text']);
        }
    }

    private static function gateIssues(array $def, array $node, array $available, callable $add): void
    {
        $k = $node['key'];
        $reviews = array_filter((array)$node['reviews'], static fn($r) => isset($available[$r]));
        if ($reviews === []) {
            $add($k, 'reviews', self::LEVEL_BLOCK, t('Choisissez la pièce à relire.'));
        }
        if ($node['can_return'] && self::returnTarget($def, $node) === null) {
            $add($k, 'returns_to', self::LEVEL_WARN, t('Aucune étape avant cette validation ne peut être refaite : « Renvoyer » ne sera pas proposé.'));
        }
    }

    /** Modèle qui travaillera pour une étape ou une action (règle du tour). */
    public static function effectiveLlm(array $node, array $ctx): string
    {
        $pid = $node['profile_id'] ?? null;
        if ($pid !== null && isset($ctx['profiles'][$pid])) {
            $own = trim((string)($ctx['profiles'][$pid]['default_llm'] ?? ''));
            if ($own !== '' && isset($ctx['tool_llms'][$own])) {
                return $own;
            }
            return (string)($ctx['default_llm'] ?? '');
        }
        $llm = (string)($node['llm'] ?? '');
        return $llm !== '' && isset($ctx['llms'][$llm]) ? $llm : (string)($ctx['default_llm'] ?? '');
    }

    /** Le workflow peut-il être publié ? */
    /**
     * Ce qui doit être réglé AVANT de lancer la version publiée, vérifié au
     * lancement avec les connexions et les outils DU MOMENT. Pure.
     *
     * Le défaut payé (retour du 2026-11-04) : un workflow publié quand tout
     * était relié, lancé plus tard, n'a découvert qu'à la toute fin que Gmail
     * n'était plus connecté — l'email de remise n'est pas parti, après des
     * minutes de travail et des crédits dépensés. « À régler » ne se calculait
     * qu'à la publication. Désormais, au lancement : tout point BLOQUANT
     * (connexion perdue, outil retiré, modèle supprimé, base inaccessible…)
     * et toute source d'étape qui n'est plus reliée (l'étape ne pourrait pas
     * la consulter). Chaque point porte le titre de sa brique et, s'il se règle
     * en reliant un compte, la clé du connecteur (`connect`).
     *
     * @return list<array{node: string, target: string, level: string, text: string, brick: string, connect?: string}>
     */
    public static function preflight(array $def, array $ctx): array
    {
        $titles = [self::START_KEY => (string)($def['start']['title'] ?? '') ?: t('Départ'), self::END_KEY => (string)($def['end']['title'] ?? '') ?: t('Livrable')];
        foreach (self::flatNodes($def) as $n) {
            $titles[$n['key']] = (string)$n['title'];
        }
        $out = [];
        $seen = [];
        foreach (self::issues($def, $ctx) as $i) {
            if ($i['level'] !== self::LEVEL_BLOCK && (string)($i['connect'] ?? '') === '') {
                continue;
            }
            $sig = $i['node'] . '|' . $i['text'];
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $out[] = $i + ['brick' => $titles[$i['node']] ?? ''];
        }
        return $out;
    }

    public static function publishable(array $issues): bool
    {
        foreach ($issues as $i) {
            if ($i['level'] === self::LEVEL_BLOCK) {
                return false;
            }
        }
        return true;
    }

    // ==================================================================
    // Outils
    // ==================================================================

    /**
     * Clé lisible : minuscules sans accents, chiffres et « _ », 24 caractères
     * (`$max` = 0 : sans limite — forme comparable d'un nom, cf.
     * WorkflowDraft : deux noms longs qui commencent pareil restent distincts).
     */
    public static function slug(string $text, int $max = self::KEY_MAX): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', '’' => '_', "'" => '_',
        ]);
        $text = (string)preg_replace('/[^a-z0-9]+/', '_', $text);
        $text = trim($text, '_');
        if ($text !== '' && ctype_digit($text[0])) {
            $text = 'p_' . $text;
        }
        return $max > 0 ? substr($text, 0, $max) : $text;
    }

    private static function uniqueKey(string $wanted, string $fallback, array &$used): string
    {
        $key = preg_match('/^[a-z][a-z0-9_]{0,' . (self::KEY_MAX - 1) . '}$/', $wanted) ? $wanted : self::slug($fallback);
        if ($key === '') {
            $key = 'b';
        }
        $base = $key;
        $i = 2;
        while (isset($used[$key])) {
            $suffix = '_' . $i++;
            $key = substr($base, 0, self::KEY_MAX - strlen($suffix)) . $suffix;
        }
        $used[$key] = true;
        return $key;
    }

    /** Clé d'une voie d'aiguillage : valide, unique dans sa brique, jamais réservée. */
    private static function laneKey(string $wanted, string $label, array &$used): string
    {
        $key = preg_match('/^[a-z][a-z0-9_]{0,' . (self::KEY_MAX - 1) . '}$/', $wanted) ? $wanted : self::slug($label);
        if ($key === '' || !ctype_alpha($key[0])) {
            $key = 'voie' . ($key !== '' ? '_' . $key : '');
            $key = substr($key, 0, self::KEY_MAX);
        }
        $base = $key;
        $i = 2;
        while (isset($used[$key]) || in_array($key, self::RESERVED_LANES, true)) {
            $suffix = '_' . $i++;
            $key = substr($base, 0, self::KEY_MAX - strlen($suffix)) . $suffix;
        }
        $used[$key] = true;
        return $key;
    }

    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $s = str_replace(["\r\n", "\r"], "\n", (string)$value);
        $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        if (!$multiline) {
            $s = (string)preg_replace('/\s+/u', ' ', $s);
        } else {
            $s = (string)preg_replace("/\n{3,}/", "\n\n", $s);
        }
        return mb_substr(trim($s), 0, $max);
    }

    private static function bool(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    private static function posInt(mixed $v): ?int
    {
        $i = filter_var($v, FILTER_VALIDATE_INT);
        return $i !== false && $i > 0 ? (int)$i : null;
    }

    private static function listOf(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }

    private static function keyList(mixed $v): array
    {
        $out = [];
        foreach (self::listOf($v) as $k) {
            $k = is_scalar($k) ? (string)$k : '';
            if (preg_match('/^[a-z0-9_]{1,' . self::KEY_MAX . '}$/', $k) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    private static function intList(mixed $v): array
    {
        $out = [];
        foreach (self::listOf($v) as $i) {
            $i = self::posInt($i);
            if ($i !== null && !in_array($i, $out, true)) {
                $out[] = $i;
            }
        }
        return $out;
    }

    private static function sourceList(mixed $v): array
    {
        $out = [];
        foreach (self::listOf($v) as $s) {
            $s = is_scalar($s) ? (string)$s : '';
            if (preg_match('/^[a-z]{2,20}$/', $s) && !in_array($s, $out, true) && count($out) < self::MAX_SOURCES) {
                $out[] = $s;
            }
        }
        return $out;
    }
}
