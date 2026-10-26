<?php

declare(strict_types=1);

/**
 * Internationalisation de l'interface — SOURCE UNIQUE.
 *
 * ================== LE CHOIX STRUCTURANT : LA CLÉ EST LE TEXTE FRANÇAIS ======
 *
 * `te('Nouvelle conversation')`, et non `t('sidebar.new_conversation')`.
 *
 * Conséquence directe, et c'est tout l'intérêt : une clé absente du
 * dictionnaire rend le TEXTE FRANÇAIS, jamais un identifiant technique à
 * l'écran. Un gabarit à moitié converti est donc un gabarit français qui
 * fonctionne — la migration peut s'étaler sur plusieurs lots sans qu'aucun
 * écran intermédiaire ne soit cassé, et sans qu'il existe jamais de `fr.php`
 * à maintenir en miroir du code (le français EST le code).
 *
 * Le défaut connu de ce schéma — un même mot français traduit différemment
 * selon l'endroit (« Usage » de la navigation vs « Usage » d'une colonne) — se
 * traite par le 3e argument `$ctx` : la recherche essaie « ctx|source » avant
 * « source ». À n'utiliser que sur conflit PROUVÉ ; par défaut, la clé nue
 * suffit et le dictionnaire reste lisible.
 *
 * ================== TROIS FONCTIONS, TROIS ÉCHAPPEMENTS =======================
 *
 * Le nom dit où le texte atterrit, parce que se tromper d'échappement est
 * silencieux jusqu'à la première apostrophe française :
 *
 *   t()   texte brut     — exceptions, JSON, fragments de prompt LLM
 *   te()  HTML échappé   — LA fonction des gabarits
 *   tj()  littéral JS    — GUILLEMETS INCLUS, obligatoire dans un <script>
 *
 * `alert('<?= t('Impossible d’enregistrer.') ?>')` casse le script ;
 * `alert(<?= tj('Impossible d’enregistrer.') ?>)` est correct.
 *
 * ================== VARIABLES : {nom} + strtr, JAMAIS sprintf ================
 *
 * L'ordre des mots change d'une langue à l'autre, donc un `%s` positionnel est
 * intraduisible dès que la phrase en porte deux. Et un `%` égaré dans une
 * traduction lève un ValueError en PHP 8 : `sprintf` peut faire tomber une
 * page, `strtr` ne le peut pas.
 *
 * ================== ATTENTION AU CACHE STATIQUE DANS LE WORKER ===============
 *
 * `self::$locale` est un statique de process. Le worker enchaîne les jobs de
 * plusieurs utilisateurs dans le MÊME process : la locale doit donc être
 * reposée à CHAQUE job (JobQueue::execute), jamais une seule fois au démarrage.
 * C'est exactement la cicatrice documentée en tête de Settings.php, où un cache
 * posé trop tôt servait des réglages périmés à vie de process.
 */
final class I18n
{
    /**
     * Langues servies. La clé est le code BCP-47 court.
     *
     * `nom` est le nom de la langue DANS CETTE LANGUE (« English », pas
     * « Anglais ») : c'est la convention des sélecteurs de langue, et la seule
     * qui reste lisible pour qui ne comprend pas la langue courante.
     *
     * `drapeau` est un emoji d'indicatif régional. Les systèmes qui ne les
     * dessinent pas — Windows, notamment — affichent les deux lettres du pays
     * (« FR », « GB ») : le repli reste informatif, aucune image n'est requise.
     */
    public const LOCALES = [
        'fr' => ['court' => 'FR', 'nom' => 'Français', 'drapeau' => '🇫🇷', 'invite' => 'français'],
        'en' => ['court' => 'EN', 'nom' => 'English',  'drapeau' => '🇬🇧', 'invite' => 'anglais'],
    ];

    /** Langue du code source : elle n'a pas de dictionnaire, elle EST le dictionnaire. */
    public const FALLBACK = 'fr';

    /** Clé Settings du défaut d'instance (administration). */
    public const SETTING_DEFAULT = 'default_lang';

    private static string $locale = self::FALLBACK;

    /** @var array<string, string> Vide tant que la locale est le français. */
    private static array $dict = [];

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function isSupported(string $code): bool
    {
        return isset(self::LOCALES[$code]);
    }

    /**
     * Nom de la langue tel qu'il s'écrit DANS UN PROMPT (« anglais »), pour
     * les consignes de méthode, rédigées en français. Locale courante par
     * défaut ; code inconnu → français.
     */
    public static function promptLanguage(?string $code = null): string
    {
        return self::localeInfo($code)['invite'];
    }

    /**
     * Consigne de LANGUE des réponses — SOURCE UNIQUE, ajoutée au prompt
     * système de chaque tour (envoi, régénération, tâche programmée, étape de
     * workflow). Prompt de MÉTHODE, en français, comme le reste des consignes.
     *
     * Le problème résolu : tout ce que le modèle lit avant la question est en
     * français (consignes de MIA, prompts d'assistants, guides) ; sans cette
     * ligne, une personne qui a choisi l'anglais recevait des réponses en
     * français dès que sa question était courte ou ambiguë. « Par défaut » :
     * une demande explicite, un message écrit dans une autre langue ou une
     * consigne d'assistant qui impose la sienne l'emportent.
     */
    public static function replyLanguageNote(?string $code = null): string
    {
        $langue = self::promptLanguage($code);

        return "\n\nLangue des réponses : réponds en " . $langue . " par défaut — c'est la langue que "
            . "l'utilisateur a choisie pour MIA. Garde-la même si tes consignes, les documents ou les résultats "
            . "d'outils sont rédigés dans une autre langue, et rédige aussi en " . $langue . " ce que tu prépares "
            . "pour lui (noms, consignes, contenus de documents, d'emails, de tâches ou de workflows). N'en change "
            . "que si l'utilisateur te le demande, s'il t'écrit lui-même dans une autre langue, ou si les consignes "
            . "de l'assistant imposent une langue.";
    }

    /**
     * Métadonnées d'affichage d'une langue (code court, nom natif, drapeau).
     * Un code inconnu rend celles du français plutôt que de lever : c'est un
     * libellé d'interface, il ne doit jamais faire tomber une page.
     *
     * @return array{court: string, nom: string, drapeau: string, invite: string}
     */
    public static function localeInfo(?string $code = null): array
    {
        $code = $code ?? self::$locale;

        return self::LOCALES[$code] ?? self::LOCALES[self::FALLBACK];
    }

    /**
     * Pose la locale et charge son dictionnaire. Idempotent, sans base ni session.
     * Un code inconnu retombe sur le français plutôt que de lever : la langue
     * d'affichage ne doit jamais pouvoir faire tomber une page.
     */
    public static function use(string $code): void
    {
        $code = self::isSupported($code) ? $code : self::FALLBACK;

        if ($code === self::$locale && ($code === self::FALLBACK || self::$dict !== [])) {
            return;
        }

        self::$locale = $code;
        self::$dict   = [];

        if ($code === self::FALLBACK) {
            return; // Identité : aucun fichier à lire.
        }

        $fichier = ROOT . '/lang/' . $code . '.php';
        if (is_file($fichier)) {
            $charge = require $fichier;
            self::$dict = is_array($charge) ? $charge : [];
        }
    }

    /**
     * Traduit. Repli EN CASCADE : contexte -> clé nue -> texte source français.
     * Une clé absente ne peut donc produire ni page blanche ni identifiant
     * affiché : au pire, la phrase reste en français.
     *
     * @param array<string, string|int|float> $vars Substituants {nom}
     */
    public static function t(string $source, array $vars = [], string $ctx = ''): string
    {
        $sortie = ($ctx !== '' ? (self::$dict[$ctx . '|' . $source] ?? null) : null)
            ?? self::$dict[$source]
            ?? $source;

        if ($vars === []) {
            return $sortie;
        }

        $remplacements = [];
        foreach ($vars as $nom => $valeur) {
            $remplacements['{' . $nom . '}'] = (string) $valeur;
        }

        return strtr($sortie, $remplacements);
    }

    /**
     * Pluriel. FR et EN NE PARTAGENT PAS la règle : « 0 action » (français,
     * singulier) contre « 0 actions » (anglais, pluriel). Les `> 1` codés en dur
     * dans les gabarits sont donc FAUX dès la bascule — d'où cette méthode.
     *
     * `{n}` est toujours disponible dans les deux formes.
     *
     * ATTENTION : cette méthode TRADUIT déjà. Dans un gabarit, l'échapper avec
     * `te()` la ferait retraduire — la sortie anglaise serait cherchée comme une
     * clé française. Écrire `htmlspecialchars(I18n::plural(…))`.
     *
     * @param array<string, string|int|float> $vars
     */
    public static function plural(int $n, string $un, string $plusieurs, array $vars = []): string
    {
        $pluriel = self::$locale === 'fr' ? ($n > 1) : ($n !== 1);

        return self::t($pluriel ? $plusieurs : $un, $vars + ['n' => $n]);
    }

    /**
     * Énumération de libellés CITÉS : « Fichiers Google Drive », « Emails Gmail ».
     *
     * SOURCE UNIQUE de ce geste, et ce n'est pas de la coquetterie : les
     * appelants écrivaient `implode(' », « ', $items)` à l'intérieur d'une
     * phrase qui ouvrait le premier guillemet et fermait le dernier. Traduite,
     * la phrase adopte les guillemets anglais mais le séparateur reste français
     * — on obtenait `“Google Drive files », « Gmail messages”`. Les guillemets
     * vivent donc AVEC le libellé, et la virgule, elle, s'écrit pareil dans les
     * deux langues.
     *
     * @param list<string> $items
     */
    public static function listeCitee(array $items): string
    {
        return implode(', ', array_map(
            static fn (string $item): string => self::t('« {label} »', ['label' => $item]),
            array_values($items)
        ));
    }

    /**
     * Réglage ÉDITORIAL d'instance servi dans la langue du lecteur tant que
     * personne ne l'a réécrit. SOURCE UNIQUE — `welcome_message`, `app_tagline`.
     *
     * Le raisonnement du chantier disait « l'éditorial d'instance ne se traduit
     * pas » : c'est vrai d'un texte QUE L'ADMINISTRATEUR A ÉCRIT (éditeur ≠
     * entreprise cliente), faux du texte LIVRÉ avec l'application, qui n'est l'éditorial de
     * personne. Or le second est indistinguable du premier une fois en base :
     * `004_settings.sql` sème « Comment puis-je vous aider ? », et l'écran
     * d'administration pré-remplit ses champs avec le texte français — un simple
     * enregistrement de la page suffit à le persister. Le repli traduit des
     * gabarits (`Settings::get($cle, t('…'))`) était donc INATTEIGNABLE : l'écran
     * d'accueil saluait « Alex, comment puis-je vous aider ? » en pleine
     * interface anglaise. Même piège, même remède que `ToolPrompts::fromPost`,
     * qui ne stocke rien quand la saisie égale le défaut livré.
     *
     * Comparaison sur le texte NORMALISÉ (blancs de bord, retours à la ligne
     * d'un champ recopié) : un octet d'écart ferait retomber sur la branche
     * « personnalisé », donc sur du français au milieu d'un écran anglais —
     * l'échec exact qu'on corrige.
     *
     * Un administrateur qui saisit MOT POUR MOT le texte livré retrouve la
     * traduction : c'est le comportement voulu (il n'a rien personnalisé), et
     * c'est dit dans le guide administrateur.
     */
    public static function editorial(?string $valeur, string $defautLivre): string
    {
        $normalise = static fn (string $texte): string
            => trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $texte)));

        $courant = $normalise((string) $valeur);

        return ($courant === '' || $courant === $normalise($defautLivre))
            ? self::t($defautLivre)
            : (string) $valeur;
    }

    /**
     * Langue à servir pour la requête courante.
     *
     * Ordre : choix explicite de l'utilisateur -> choix de session (visiteur non
     * connecté) -> négociation du navigateur -> défaut d'instance -> français.
     */
    public static function resolve(): string
    {
        $utilisateur = Session::currentUser();
        $prefere     = strtolower(trim((string) ($utilisateur['lang'] ?? '')));
        if (self::isSupported($prefere)) {
            return $prefere;
        }

        // Page de connexion : aucun compte à qui rattacher le choix, et pont
        // entre le POST de la bascule et le prochain refreshUser().
        $session = strtolower(trim((string) Session::get('lang', '')));
        if (self::isSupported($session)) {
            return $session;
        }

        $entete = self::fromAcceptLanguage((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        if ($entete !== null) {
            return $entete;
        }

        return self::instanceDefault();
    }

    /**
     * Langue d'un job asynchrone : celle de SON utilisateur, pas celle de la
     * requête qui l'a mis en file. Le job s'exécute dans un autre process,
     * parfois des minutes plus tard, et persiste ses messages dans la
     * conversation de cet utilisateur-là.
     *
     * @param array<string, mixed>|null $utilisateur
     */
    public static function forUser(?array $utilisateur): string
    {
        $prefere = strtolower(trim((string) ($utilisateur['lang'] ?? '')));
        if (self::isSupported($prefere)) {
            return $prefere;
        }

        return self::instanceDefault();
    }

    /**
     * Adopte, à la connexion, la langue choisie AVANT elle (bascule utilisée sur
     * l'écran de connexion, où le choix ne peut vivre qu'en session).
     *
     * Ne fait rien si le compte a déjà une langue : un choix enregistré prime
     * toujours sur celui d'un visiteur de passage sur le même poste.
     * Best-effort — rater cette promotion ne doit jamais faire échouer une
     * connexion, c'est la langue d'affichage qui est en jeu, rien d'autre.
     *
     * @param array<string, mixed>|null $utilisateur
     */
    public static function adoptSessionChoice(?array $utilisateur): void
    {
        if (!$utilisateur || trim((string) ($utilisateur['lang'] ?? '')) !== '') {
            return;
        }

        $session = strtolower(trim((string) Session::get('lang', '')));
        if (!self::isSupported($session)) {
            return;
        }

        try {
            UserPreferences::saveLang((int) $utilisateur['id'], $session);
        } catch (\Throwable $e) {
            error_log('[I18n] Promotion du choix de langue impossible : ' . $e->getMessage());
        }
    }

    /**
     * Défaut de la plateforme. Enveloppé : une base injoignable ne doit pas
     * empêcher d'afficher une page (c'est même le moment où on en a besoin).
     */
    public static function instanceDefault(): string
    {
        try {
            $defaut = strtolower(trim((string) Settings::get(self::SETTING_DEFAULT, self::FALLBACK)));

            return self::isSupported($defaut) ? $defaut : self::FALLBACK;
        } catch (\Throwable) {
            return self::FALLBACK;
        }
    }

    /**
     * Négociation `Accept-Language`. Rend null si aucune langue servie n'est
     * demandée — c'est ce null qui laisse la main au défaut d'instance.
     *
     * Statique pure : testable sans requête HTTP.
     */
    public static function fromAcceptLanguage(string $entete): ?string
    {
        $entete = trim($entete);
        if ($entete === '') {
            return null;
        }

        $candidats = [];
        foreach (explode(',', $entete) as $morceau) {
            $parties = explode(';', $morceau);
            $etiquette = strtolower(trim($parties[0]));
            if ($etiquette === '') {
                continue;
            }

            // « fr-CA » vaut « fr » ; « * » ne désigne rien de précis.
            $court = substr($etiquette, 0, 2);
            if (!self::isSupported($court)) {
                continue;
            }

            $q = 1.0;
            foreach (array_slice($parties, 1) as $param) {
                $param = trim($param);
                if (str_starts_with($param, 'q=')) {
                    $q = (float) substr($param, 2);
                }
            }

            // Premier arrivé gagne à q égal : l'en-tête est déjà ordonné par
            // préférence, et max() ne doit pas réordonner les ex aequo.
            if (!isset($candidats[$court]) || $q > $candidats[$court]) {
                $candidats[$court] = $q;
            }
        }

        if ($candidats === []) {
            return null;
        }

        arsort($candidats);
        $meilleur = (string) array_key_first($candidats);

        // q=0 signifie « surtout pas celle-là ».
        return $candidats[$meilleur] > 0 ? $meilleur : null;
    }

    /**
     * Dictionnaire livré au navigateur (window.I18N). Sous-ensemble : les
     * fichiers .js sont statiques, ils ne peuvent pas appeler t().
     *
     * `s` est un objet vide (et non un tableau vide) en français, pour que
     * json_encode rende `{}` et non `[]` — `window.I18N.s[k]` planterait sur
     * un tableau.
     *
     * @return array{lang: string, s: array<string, string>|\stdClass}
     */
    public static function clientDictionary(): array
    {
        if (self::$locale === self::FALLBACK) {
            return ['lang' => self::$locale, 's' => new \stdClass()];
        }

        $fichier = ROOT . '/lang/' . self::$locale . '.client.php';
        $chaines = is_file($fichier) ? require $fichier : [];

        return [
            'lang' => self::$locale,
            's'    => is_array($chaines) && $chaines !== [] ? $chaines : new \stdClass(),
        ];
    }
}

if (!function_exists('t')) {
    /** Texte BRUT : exceptions, charges utiles JSON, fragments de prompt LLM. */
    function t(string $source, array $vars = [], string $ctx = ''): string
    {
        return I18n::t($source, $vars, $ctx);
    }

    /**
     * Texte HTML échappé : LA fonction des gabarits. Une traduction peut
     * légitimement contenir « & » ou « < » — l'échappement n'est pas optionnel.
     * Corollaire : la clé est du TEXTE, jamais du HTML. Une entité y serait
     * échappée à son tour et s'afficherait en toutes lettres : écrire le
     * caractère lui-même (U+00A0, &).
     */
    function te(string $source, array $vars = [], string $ctx = ''): string
    {
        return htmlspecialchars(I18n::t($source, $vars, $ctx), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Texte HTML avec EMPHASE : `{b}…{/b}` devient `<strong>…</strong>`.
     *
     * Une phrase coupée par un `<strong>` produit trois littéraux dont aucun ne
     * se traduit seul — l'ordre des mots change d'une langue à l'autre, et
     * « Permet aux assistants de » n'a pas d'équivalent isolé en anglais. La
     * phrase reste donc ENTIÈRE dans le dictionnaire, et porte ses marqueurs.
     *
     * Les balises sont injectées APRÈS l'échappement : le texte traduit est
     * échappé comme n'importe quel autre, seules NOS balises passent.
     */
    function teb(string $source, array $vars = [], string $ctx = ''): string
    {
        return str_replace(
            ['{b}', '{/b}'],
            ['<strong>', '</strong>'],
            htmlspecialchars(I18n::t($source, $vars, $ctx), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Littéral JavaScript, GUILLEMETS COMPRIS — à écrire SANS quotes autour :
     *     alert(<?= tj('…') ?>);
     * Les drapeaux JSON_HEX_* neutralisent aussi `</script>` et les apostrophes
     * typographiques, très fréquentes dans le corpus français.
     */
    function tj(string $source, array $vars = [], string $ctx = ''): string
    {
        return (string) json_encode(
            I18n::t($source, $vars, $ctx),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }
}
