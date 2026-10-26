<?php

declare(strict_types=1);

/**
 * Relecture d'un email préparé par un connecteur (Gmail, Outlook) — SOURCE UNIQUE.
 *
 * LE PROBLÈME RÉSOLU. La carte d'approbation d'un brouillon montrait une ligne
 * « objet → destinataire » et un extrait de 280 caractères replié derrière
 * « Aperçu du contenu ». Pour relire l'email, l'utilisateur dépliait un bloc
 * de texte brut, et pour le corriger il n'avait qu'un chemin : le dire à
 * l'assistant, attendre une nouvelle réponse, une nouvelle carte. Or un email
 * est ce qu'on relit le plus attentivement avant de l'approuver — et le
 * modèle recopiait en plus le corps entier dans sa réponse, juste au-dessus
 * de la carte qui le répétait.
 *
 * DÉSORMAIS la carte porte un bloc `mail` (cf. block()) : le message tel
 * qu'il partira — À, Cc, Objet, corps rendu par le MÊME convertisseur que le
 * brouillon (MailHtml) —, et deux issues d'EXÉCUTION plus trois de RELECTURE :
 *  - CRÉER LE BROUILLON (décision `approve`) : il se range dans les
 *    Brouillons de la messagerie, rien ne part ;
 *  - ENVOYER (décision `send`) : le message part immédiatement, sans retour
 *    possible — d'où une confirmation dans la carte avant l'appel, et une
 *    disponibilité annoncée dans le bloc (`envoi`, cf. withSendAvailability :
 *    Outlook exige le droit Mail.Send, qu'une connexion antérieure n'a pas) ;
 *  - MODIFIER à la main (destinataires, copie, objet, corps) ;
 *  - AMÉLIORER par le modèle, sur un préréglage (plus court, plus formel…) ou
 *    une consigne libre (« propose un créneau jeudi matin ») ;
 *  - ABANDONNER.
 * Chaque révision est ENREGISTRÉE dans l'action en attente avant d'être
 * affichée (DrivePendingAction::updatePending) : brouillon comme envoi
 * portent toujours la dernière version relue, et un rechargement de page la
 * retrouve. Le modèle, lui, ne recopie plus le corps dans sa réponse (cf. le
 * message rendu par `$queueConnectorWrite` pour les outils mail), et n'envoie
 * JAMAIS rien lui-même : l'envoi n'existe que comme geste de l'utilisateur.
 *
 * Tout ici est PUR : aucune requête, aucun réseau. L'appel au modèle, la
 * relecture du message d'origine et la lecture des droits d'envoi vivent
 * dans ChatController (reviseMailDraft, mailSendAvailability).
 */
final class MailDraftReview
{
    /** Outil de brouillon → service de messagerie. */
    public const TOOLS = [
        'creer_brouillon_gmail'   => 'gmail',
        'creer_brouillon_outlook' => 'outlook',
    ];

    /**
     * Corps gardé dans l'aperçu : `preview_json` est un TEXT MySQL (64 Ko) et
     * l'aperçu porte aussi le rendu HTML. Un email dépasse rarement 3 000
     * caractères ; au-delà de cette borne seul l'APERÇU est raccourci, le
     * brouillon créé reste entier (les arguments, eux, sont en MEDIUMTEXT).
     */
    public const CORPS_APERCU_MAX = 16000;

    /** Au-delà, le rendu HTML n'est plus joint : le texte brut suffit à relire. */
    public const CORPS_HTML_MAX = 8000;

    public const OBJET_MAX       = 300;
    public const CORPS_MAX       = 120000;
    public const INSTRUCTION_MAX = 500;
    public const LIBELLE_MAX     = 60;

    /** Caractères du message d'origine passés au modèle pour une réponse. */
    public const ORIGINE_MAX = 3500;

    /**
     * Préréglages d'amélioration. La clé est stable (elle voyage dans le POST),
     * le libellé est traduit à l'accès (presetsForClient — une constante ne
     * peut pas appeler t()), la consigne est un prompt de MÉTHODE : elle ne
     * cite rien à l'écran, elle reste en français.
     */
    public const PRESETS = [
        'plus_court' => [
            'label'       => 'Plus court',
            'instruction' => "Raccourcis le message : garde l'essentiel, retire les redites et les formules creuses, sans perdre une information utile ni un engagement.",
        ],
        'plus_formel' => [
            'label'       => 'Plus formel',
            'instruction' => "Rends le ton plus formel et professionnel (vouvoiement, formules soignées, registre soutenu), sans le rendre pompeux ni allonger le message.",
        ],
        'plus_chaleureux' => [
            'label'       => 'Plus chaleureux',
            'instruction' => "Rends le ton plus chaleureux et humain, en gardant le même degré de familiarité que le brouillon (ne passe pas au tutoiement s'il vouvoie).",
        ],
        'plus_direct' => [
            'label'       => 'Plus direct',
            'instruction' => "Va droit au but : la demande ou l'information principale dès la première phrase, moins de précautions oratoires, des phrases courtes.",
        ],
        'plus_detaille' => [
            'label'       => 'Plus détaillé',
            'instruction' => "Développe le message pour qu'il soit autoportant : précise le contexte, les points clés et la suite attendue, sans rien inventer et sans le rendre bavard.",
        ],
        'corriger' => [
            'label'       => 'Corriger la langue',
            'instruction' => "Corrige l'orthographe, la grammaire, la ponctuation et la typographie, sans changer le sens, le ton ni la structure du message.",
        ],
    ];

    // =====================================================================
    // Identité de l'outil
    // =====================================================================

    /** Service (`gmail` | `outlook`) d'un outil de brouillon, null pour tout autre outil. */
    public static function serviceForTool(string $tool): ?string
    {
        return self::TOOLS[$tool] ?? null;
    }

    public static function isMailTool(string $tool): bool
    {
        return isset(self::TOOLS[$tool]);
    }

    // =====================================================================
    // Bloc `mail` de l'aperçu (carte de relecture)
    // =====================================================================

    /**
     * Le message tel qu'il partira, pour la carte. Le corps est rendu par
     * MailHtml::toHtml — exactement ce que le brouillon contiendra, et un HTML
     * sûr par construction (tout est échappé ou nettoyé par liste blanche),
     * donc injectable tel quel dans la carte.
     *
     * @return array{service:string,reponse:bool,destinataire:string,cc:string,objet:string,corps:string,corps_html:string,tronque:bool,presets:list<array{key:string,label:string}>,version:int,revision:string}
     */
    public static function block(string $service, array $args): array
    {
        $corps   = self::normalizeBody((string)($args['corps'] ?? ''));
        $tronque = mb_strlen($corps) > self::CORPS_APERCU_MAX;
        $apercu  = $tronque ? mb_substr($corps, 0, self::CORPS_APERCU_MAX) : $corps;

        return [
            'service'      => $service,
            'reponse'      => trim((string)($args['repondre_a'] ?? '')) !== '',
            'destinataire' => self::cleanHeader((string)($args['destinataire'] ?? '')),
            'cc'           => self::cleanHeader((string)($args['cc'] ?? '')),
            'objet'        => self::cleanHeader((string)($args['objet'] ?? '')),
            'corps'        => $apercu,
            // Une image du dossier intégrée au message (étape de workflow) : son
            // nom à sa place — le fichier part avec le message, pas avec la carte.
            'corps_html'   => (!$tronque && mb_strlen($corps) <= self::CORPS_HTML_MAX) ? MailHtml::withInlineImages(MailHtml::toHtml($corps), self::inlineImages($args), true) : '',
            'tronque'      => $tronque,
            'presets'      => self::presetsForClient(),
            'version'      => 1,
            'revision'     => '',
        ];
    }

    /**
     * Images intégrées au message par le serveur (références internes qui
     * portent un `cid`, WorkflowInserts) : leur `cid` et leur nom.
     *
     * @return list<array{cid: string, name: string}>
     */
    public static function inlineImages(array $args): array
    {
        $out = [];
        foreach ((array)($args[WorkflowDeliveries::ATTACH_KEY] ?? []) as $ref) {
            if (is_array($ref) && MailHtml::isImageCid((string)($ref['cid'] ?? ''))) {
                $out[] = ['cid' => (string)$ref['cid'], 'name' => (string)($ref['name'] ?? '')];
            }
        }
        return $out;
    }

    /** @return list<array{key:string,label:string}> préréglages, libellés traduits */
    public static function presetsForClient(): array
    {
        $out = [];
        foreach (self::PRESETS as $key => $preset) {
            $out[] = ['key' => $key, 'label' => t($preset['label'])];
        }
        return $out;
    }

    /**
     * Numérote la révision : l'aperçu recalculé repart toujours de la
     * version 1, c'est l'aperçu PRÉCÉDENT (celui de l'action en base) qui
     * porte le compte. La note dit ce qui a changé (« Modifié à la main »,
     * « Amélioré par MIA : plus court »).
     */
    public static function withRevision(array $preview, ?array $previous, string $note): array
    {
        if (!isset($preview['mail']) || !is_array($preview['mail'])) {
            return $preview;
        }
        $preview['mail']['version']  = (int)($previous['mail']['version'] ?? 1) + 1;
        $preview['mail']['revision'] = $note;
        return $preview;
    }

    /** Note de révision affichée dans la carte (traduite : c'est de l'interface). */
    public static function revisionNote(string $mode, string $libelle = ''): string
    {
        return match ($mode) {
            'improve' => t('Amélioré par MIA : {consigne}', ['consigne' => $libelle]),
            'restore' => t('Version précédente restaurée'),
            default   => t('Modifié à la main'),
        };
    }

    /**
     * Pose la disponibilité de l'ENVOI sur le bloc `mail` de l'aperçu :
     * `envoi.possible` et, sinon, `envoi.raison` (traduite, affichée dans la
     * carte au clic sur « Envoyer »). Le bouton n'est jamais masqué : une
     * absence inexpliquée est indistinguable d'un bug, une raison dit quoi
     * faire (reconnecter, ou créer le brouillon).
     *
     * Le calcul du droit (connexion, scope) est celui de l'appelant : ici on
     * ne fait que l'écrire. Un aperçu sans bloc `mail` est rendu tel quel.
     */
    public static function withSendAvailability(array $preview, bool $possible, string $raison = ''): array
    {
        if (!isset($preview['mail']) || !is_array($preview['mail'])) {
            return $preview;
        }
        $preview['mail']['envoi'] = $possible
            ? ['possible' => true]
            : ['possible' => false, 'raison' => $raison !== '' ? $raison : t('Envoi indisponible pour cette connexion.')];
        return $preview;
    }

    // =====================================================================
    // Modification à la main
    // =====================================================================

    /**
     * Applique les champs de l'éditeur aux arguments de l'outil. Un champ
     * ABSENT du POST est inchangé (l'aperçu d'un corps raccourci n'offre pas
     * le corps à l'édition : le renvoyer tronqué amputerait le brouillon) ;
     * un champ présent remplace, y compris vide pour la copie.
     *
     * Les adresses sont validées une à une, comme le font les services à
     * l'exécution — une adresse refusée ici est une carte qui n'échoue pas
     * au clic « Approuver ». Les messages lèvent en clair : ils s'affichent
     * dans la carte.
     *
     * @throws \RuntimeException message pour l'utilisateur
     */
    public static function applyEdit(array $args, array $post): array
    {
        $reponse = trim((string)($args['repondre_a'] ?? '')) !== '';

        if (array_key_exists('destinataire', $post)) {
            $args['destinataire'] = self::normalizeAddresses((string)$post['destinataire']);
        }
        if (array_key_exists('cc', $post)) {
            $args['cc'] = self::normalizeAddresses((string)$post['cc']);
        }
        if (array_key_exists('objet', $post)) {
            $args['objet'] = mb_substr(self::cleanHeader((string)$post['objet']), 0, self::OBJET_MAX);
        }
        if (array_key_exists('corps', $post)) {
            $corps = self::normalizeBody((string)$post['corps']);
            if ($corps === '') {
                throw new \RuntimeException(t('Le corps du message est vide.'));
            }
            if (mb_strlen($corps) > self::CORPS_MAX) {
                throw new \RuntimeException(t('Le message est trop long ({max} caractères au plus).', ['max' => self::CORPS_MAX]));
            }
            $args['corps'] = $corps;
        }
        // En réponse, un destinataire vide est repris du message d'origine à
        // l'exécution ; un brouillon neuf, lui, ne partira nulle part sans.
        if (!$reponse && trim((string)($args['destinataire'] ?? '')) === '') {
            throw new \RuntimeException(t('Indiquez au moins un destinataire.'));
        }
        return $args;
    }

    /**
     * Liste d'adresses telle qu'on la tape (virgules OU points-virgules,
     * « Nom <adresse> » toléré) → « a@ex.fr, b@ex.fr », dédoublonnée. Vide
     * accepté (c'est à l'appelant de dire si le champ est obligatoire).
     *
     * @throws \RuntimeException sur la première adresse invalide
     */
    public static function normalizeAddresses(string $list): string
    {
        $clean = self::cleanHeader($list);
        if ($clean === '') {
            return '';
        }
        $out = [];
        foreach (preg_split('/[,;]+/', $clean) ?: [] as $brut) {
            $brut = trim($brut);
            if ($brut === '') {
                continue;
            }
            $addr = preg_match('/<([^<>]+)>\s*$/', $brut, $m) === 1 ? trim($m[1]) : $brut;
            if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException(t('Adresse email invalide : « {adresse} »', ['adresse' => $brut]));
            }
            if (!in_array(strtolower($addr), array_map('strtolower', $out), true)) {
                $out[] = $addr;
            }
        }
        return implode(', ', $out);
    }

    // =====================================================================
    // Amélioration par le modèle
    // =====================================================================

    /**
     * Consigne d'amélioration depuis le POST : un préréglage (`preset`) OU une
     * consigne libre (`instruction`). Rend le texte pour le modèle et le
     * libellé court pour la note de révision.
     *
     * @return array{texte:string,libelle:string}
     * @throws \RuntimeException
     */
    public static function instruction(array $post): array
    {
        $preset = trim((string)($post['preset'] ?? ''));
        if ($preset !== '') {
            if (!isset(self::PRESETS[$preset])) {
                throw new \RuntimeException(t('Préréglage inconnu.'));
            }
            return [
                'texte'   => self::PRESETS[$preset]['instruction'],
                'libelle' => mb_strtolower(t(self::PRESETS[$preset]['label'])),
            ];
        }
        $libre = trim((string)preg_replace('/\s+/u', ' ', (string)($post['instruction'] ?? '')));
        if ($libre === '') {
            throw new \RuntimeException(t('Décrivez la modification souhaitée.'));
        }
        $libre = mb_substr($libre, 0, self::INSTRUCTION_MAX);
        return [
            'texte'   => $libre,
            'libelle' => mb_strlen($libre) > self::LIBELLE_MAX ? mb_substr($libre, 0, self::LIBELLE_MAX - 1) . '…' : $libre,
        ];
    }

    /**
     * Prompt de réécriture. Prompt de MÉTHODE, en français : il ne cite rien
     * de l'écran. Le message d'origine (réponse) et le fil de la conversation
     * donnent le contexte — c'est ce qui distingue « propose un créneau jeudi »
     * appliqué à l'aveugle de la même consigne appliquée en sachant ce que le
     * correspondant a demandé.
     *
     * @return array{system:string,user:string}
     */
    public static function improvementPrompt(string $service, array $args, string $instruction, string $digest = '', string $origine = ''): array
    {
        $system = "Tu es le rédacteur d'emails de MIA, un assistant d'entreprise. L'utilisateur relit un brouillon "
            . "d'email préparé pour lui et demande une retouche. Réécris le brouillon en appliquant EXACTEMENT sa "
            . "consigne, rien d'autre.\n"
            . "Règles :\n"
            . "- Garde la langue du brouillon, les faits, les noms, les dates, les montants et les engagements ; "
            . "n'invente rien, ne promets rien de nouveau.\n"
            . "- Le message d'origine et la conversation servent à comprendre le contexte, jamais à ajouter des "
            . "informations que l'utilisateur n'a pas demandées.\n"
            . "- N'ajoute ni signature, ni coordonnées, ni formule de fin suivie d'un nom : la signature de la boîte "
            . "est ajoutée automatiquement.\n"
            . "- Corps en Markdown simple : **gras**, *italique*, listes -, [liens](url), une ligne vide entre les "
            . "paragraphes. Pas de titre, pas de tableau, pas de bloc de code.\n"
            . "- Ne change l'objet que si la consigne le demande ou si le nouveau corps ne lui correspond plus ; "
            . "sinon renvoie-le tel quel.\n"
            . "- Ne modifie ni les destinataires ni les copies : ils se règlent ailleurs.\n"
            . "- Aucun commentaire, aucune explication, aucune balise HTML.\n\n"
            . "Réponds UNIQUEMENT par un objet JSON : {\"objet\": \"…\", \"corps\": \"…\"}";

        $label = $service === 'outlook' ? 'Outlook' : 'Gmail';
        $reponse = trim((string)($args['repondre_a'] ?? '')) !== '';
        $dest = self::cleanHeader((string)($args['destinataire'] ?? ''));
        $cc   = self::cleanHeader((string)($args['cc'] ?? ''));
        $obj  = self::cleanHeader((string)($args['objet'] ?? ''));

        $user = 'Brouillon actuel (' . $label . ($reponse ? ', réponse à un message reçu' : '') . ") :\n"
            . 'À : ' . ($dest !== '' ? $dest : ($reponse ? "(expéditeur du message d'origine)" : '(non renseigné)')) . "\n"
            . ($cc !== '' ? 'Cc : ' . $cc . "\n" : '')
            . 'Objet : ' . ($obj !== '' ? $obj : ($reponse ? "(repris du message d'origine)" : '(sans objet)')) . "\n\n"
            . "Corps :\n" . self::normalizeBody((string)($args['corps'] ?? '')) . "\n";

        if (trim($origine) !== '') {
            $user .= "\nMessage d'origine auquel ce brouillon répond :\n" . trim($origine) . "\n";
        }
        if (trim($digest) !== '') {
            $user .= "\nConversation qui a conduit à ce brouillon (du plus ancien au plus récent) :\n" . trim($digest) . "\n";
        }
        $user .= "\nConsigne de l'utilisateur : « " . trim($instruction) . " »\n\nJSON :";

        return ['system' => $system, 'user' => $user];
    }

    /**
     * Résumé du message d'origine (sortie de readMessage) pour le prompt :
     * expéditeur, objet, date, corps borné.
     */
    public static function originSummary(array $message): string
    {
        $contenu = trim((string)($message['contenu'] ?? ''));
        if (mb_strlen($contenu) > self::ORIGINE_MAX) {
            $contenu = mb_substr($contenu, 0, self::ORIGINE_MAX) . '…';
        }
        $lignes = [];
        foreach (['de' => 'De', 'a' => 'À', 'objet' => 'Objet', 'date' => 'Date'] as $cle => $libelle) {
            $v = self::cleanHeader((string)($message[$cle] ?? ''));
            if ($v !== '') {
                $lignes[] = $libelle . ' : ' . $v;
            }
        }
        return trim(implode("\n", $lignes) . "\n\n" . $contenu);
    }

    /**
     * Extrait {"objet": …, "corps": …} de la réponse du modèle, tolérant au
     * fence Markdown, au texte autour du JSON et aux sauts de ligne laissés
     * BRUTS dans les chaînes (fréquent pour un corps d'email multi-lignes :
     * JSON invalide strict, réparé en échappant les caractères de contrôle
     * à l'intérieur des littéraux). Null si rien d'exploitable.
     *
     * @return array{objet:?string,corps:string}|null
     */
    public static function parseImprovedJson(string $raw): ?array
    {
        $txt = trim($raw);
        if ($txt === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $txt, $m) === 1) {
            $txt = trim($m[1]);
        }
        $start = strpos($txt, '{');
        $end   = strrpos($txt, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = substr($txt, $start, $end - $start + 1);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $repare = preg_replace_callback(
                '/"(?:[^"\\\\]|\\\\.)*"/s',
                static fn(array $m): string => str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $m[0]),
                $json
            );
            $data = is_string($repare) ? json_decode($repare, true) : null;
        }
        if (!is_array($data)) {
            return null;
        }
        $corps = is_scalar($data['corps'] ?? null) ? self::normalizeBody((string)$data['corps']) : '';
        if ($corps === '') {
            return null;
        }
        if (mb_strlen($corps) > self::CORPS_MAX) {
            $corps = mb_substr($corps, 0, self::CORPS_MAX);
        }
        $objet = is_scalar($data['objet'] ?? null) ? self::cleanHeader((string)$data['objet']) : '';

        return [
            'objet' => $objet !== '' ? mb_substr($objet, 0, self::OBJET_MAX) : null,
            'corps' => $corps,
        ];
    }

    // =====================================================================
    // Normalisations
    // =====================================================================

    /** CR, LF et NUL neutralisés dans une valeur d'en-tête (même règle que les services). */
    public static function cleanHeader(string $value): string
    {
        return trim((string)preg_replace('/[\r\n\x00]+/', ' ', $value));
    }

    /** Fins de ligne unifiées, NUL retiré, blancs de bord retirés. */
    public static function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\x00"], ["\n", "\n", ''], $body);
        return trim($body);
    }
}
