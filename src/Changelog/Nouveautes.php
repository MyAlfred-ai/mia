<?php

declare(strict_types=1);

/**
 * Nouveautés de MIA — ce que l'utilisateur lit quand il clique le numéro de
 * version, SOURCE UNIQUE de leur forme.
 *
 * Le problème résolu : `public/changelog.json` ne portait que des SUJETS DE
 * COMMIT (« feat(kb): choisir ses bases de connaissances depuis le chat »).
 * Écrits pour des développeurs, à raison d'une ligne par commit, ils
 * découpaient une même fonctionnalité en trois entrées illisibles et
 * annonçaient des refactors qui ne changent rien pour personne.
 *
 * Trois règles portent la différence :
 *
 *  1. UNE ENTRÉE = UNE FONCTIONNALITÉ, pas un commit. Le fichier source
 *     `docs/NOUVEAUTES.md` est écrit à la main : le commit suivant qui
 *     complète la même fonctionnalité ENRICHIT l'entrée existante au lieu
 *     d'en créer une seconde. C'est précisément ce qu'aucune génération
 *     automatique depuis git ne sait faire.
 *  2. LE TEXTE PARLE DE CE QU'ON PEUT FAIRE, jamais de ce qui a été codé :
 *     aucun nom de fichier, de route ni de colonne ; un correctif se raconte
 *     par son symptôme (« le bouton passait pour désactivé »).
 *  3. LA DATE AFFICHÉE EST CELLE DE LA CRÉATION de l'entrée, pas de sa
 *     dernière retouche — sinon enrichir une nouveauté d'il y a trois
 *     semaines la ferait remonter devant celles d'hier.
 *
 * Cette classe ne fait que la FORME (analyse, validation, rendu). La datation
 * et la numérotation de version vivent dans `scripts/build_changelog.php`, qui
 * les lit dans l'historique git ; elles n'ont donc rien à faire ici, où elles
 * ne seraient pas testables sans dépôt.
 */
class Nouveautes
{
    /** Fichier source, écrit à la main, relatif à ROOT. */
    public const SOURCE = 'docs/NOUVEAUTES.md';

    /** Fichier publié, lu par la modale (et par personne d'autre). */
    public const PUBLIE = 'public/changelog.json';

    /** Nombre d'entrées publiées. Au-delà, l'historique reste dans le .md. */
    public const MAX_PUBLIEES = 20;

    /** Sujets de commit conservés pour le détail technique (staff). */
    public const MAX_COMMITS = 30;

    public const MAX_TITRE = 140;
    public const MAX_CORPS = 700;

    /**
     * Genres. La pastille dit à quoi s'attendre : du neuf, du mieux, ou une
     * gêne qui disparaît. Volontairement TROIS et pas huit — « refactor » et
     * « perf » n'ont rien à annoncer à qui pose une question à un assistant.
     */
    public const GENRES = [
        'nouveaute'    => 'Nouveauté',
        'amelioration' => 'Amélioration',
        'correctif'    => 'Correctif',
    ];

    /**
     * Public concerné. Il ne FILTRE rien : tout le monde voit toute la liste
     * (une nouveauté d'administration dit au passage à qui elle s'adresse, et
     * cacher la moitié du produit à un utilisateur curieux dessert MIA). Il
     * ne sert qu'à poser une pastille de contexte — d'où le libellé vide pour
     * « tous », qui n'a rien à préciser.
     */
    public const PUBLICS = [
        'tous'            => '',
        'createurs'       => 'Création d\'assistants',
        'administrateurs' => 'Administration',
    ];

    /** Champs de la ligne de métadonnées, dans l'ordre attendu à l'écriture. */
    private const META_CLES = ['id', 'genre', 'pour', 'date', 'version'];

    // ===================== Analyse =====================

    /**
     * `docs/NOUVEAUTES.md` → entrées, dans l'ordre du fichier (le plus récent
     * en tête). Analyse PURE : aucune lecture disque, aucun appel git — c'est
     * ce qui la rend testable sur une chaîne.
     *
     * Forme attendue par entrée :
     *
     *     ## Titre lisible par un utilisateur
     *     <!-- id: slug-stable | genre: nouveaute | pour: tous -->
     *
     *     Une à trois phrases.
     *
     * `date` et `version` sont facultatives : sans elles, le générateur les
     * pose depuis l'historique git (premier commit où l'id apparaît). Les
     * écrire à la main sert aux entrées ANTÉRIEURES à la mise en place de ce
     * système, qui apparaissent toutes dans le même commit et seraient sinon
     * datées du même jour.
     *
     * @return list<array{id:string,titre:string,genre:string,pour:string,date:string,version:string,corps:string}>
     */
    public static function parse(string $markdown): array
    {
        $lignes  = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $entrees = [];
        $courante = null;
        $corps    = [];

        $cloturer = static function () use (&$entrees, &$courante, &$corps): void {
            if ($courante === null) {
                return;
            }
            $courante['corps'] = self::nettoyerCorps($corps);
            $entrees[] = $courante;
            $courante  = null;
            $corps     = [];
        };

        foreach ($lignes as $ligne) {
            // `## ` ouvre une entrée. `#` (titre du document) et `###`
            // (sous-titre éventuel dans un corps) ne sont pas des frontières.
            if (preg_match('/^##\s+(?!#)(.+?)\s*$/', $ligne, $m) === 1) {
                $cloturer();
                $courante = [
                    'id'      => '',
                    'titre'   => trim($m[1]),
                    'genre'   => 'nouveaute',
                    'pour'    => 'tous',
                    'date'    => '',
                    'version' => '',
                    'corps'   => '',
                ];
                continue;
            }

            if ($courante === null) {
                continue; // préambule du fichier : instructions de rédaction
            }

            $meta = self::parseMeta($ligne);
            if ($meta !== null) {
                $courante = array_merge($courante, $meta);
                continue;
            }

            $corps[] = $ligne;
        }
        $cloturer();

        return $entrees;
    }

    /**
     * `<!-- id: x | genre: nouveaute | pour: tous -->` → tableau de champs
     * connus. Renvoie null si la ligne n'est pas une ligne de métadonnées :
     * un commentaire HTML quelconque reste du corps, il sera retiré au rendu.
     *
     * @return array<string,string>|null
     */
    private static function parseMeta(string $ligne): ?array
    {
        if (preg_match('/^\s*<!--\s*(id\s*:.*?)\s*-->\s*$/i', $ligne, $m) !== 1) {
            return null;
        }
        $champs = [];
        foreach (explode('|', $m[1]) as $morceau) {
            $paire = explode(':', $morceau, 2);
            if (count($paire) !== 2) {
                continue;
            }
            $cle = strtolower(trim($paire[0]));
            if (in_array($cle, self::META_CLES, true)) {
                $champs[$cle] = trim($paire[1]);
            }
        }
        return $champs === [] ? null : $champs;
    }

    /**
     * Lignes brutes → une prose d'un seul tenant. Les commentaires HTML sont
     * retirés (ils servent à l'outillage, jamais à l'utilisateur) et les
     * paragraphes sont recollés par une ligne vide : le corps est affiché dans
     * une modale et lu par un assistant, ni l'un ni l'autre ne rend du
     * Markdown de mise en page.
     */
    private static function nettoyerCorps(array $lignes): string
    {
        $texte = implode("\n", $lignes);
        $texte = preg_replace('/<!--.*?-->/s', '', $texte) ?? $texte;
        $texte = preg_replace("/\n{3,}/", "\n\n", trim($texte)) ?? $texte;
        return trim($texte);
    }

    // ===================== Validation =====================

    /**
     * Ce qui casse en silence si personne ne regarde : deux entrées portant le
     * même id (la seconde hériterait de la date de la première, et l'une des
     * deux disparaîtrait d'un dédoublonnage futur), un genre inventé (pastille
     * vide), un corps de 40 lignes qui déborde la modale.
     *
     * @param list<array<string,string>> $entrees
     * @return list<string> messages d'erreur, vide si tout va bien
     */
    public static function valider(array $entrees): array
    {
        $erreurs = [];
        $vus     = [];

        foreach ($entrees as $i => $e) {
            $ref = ($e['titre'] ?? '') !== '' ? '« ' . $e['titre'] . ' »' : 'entrée #' . ($i + 1);

            $id = (string) ($e['id'] ?? '');
            if ($id === '') {
                $erreurs[] = "$ref : identifiant manquant (<!-- id: … -->).";
            } elseif (preg_match('/^[a-z0-9][a-z0-9-]{1,48}$/', $id) !== 1) {
                $erreurs[] = "$ref : identifiant « $id » invalide (minuscules, chiffres et tirets).";
            } elseif (isset($vus[$id])) {
                $erreurs[] = "$ref : identifiant « $id » déjà utilisé.";
            } else {
                $vus[$id] = true;
            }

            if (trim((string) ($e['titre'] ?? '')) === '') {
                $erreurs[] = 'Une entrée sans titre (identifiant « ' . $id . ' »).';
            } elseif (mb_strlen((string) $e['titre']) > self::MAX_TITRE) {
                $erreurs[] = "$ref : titre de plus de " . self::MAX_TITRE . ' caractères.';
            }

            if (trim((string) ($e['corps'] ?? '')) === '') {
                $erreurs[] = "$ref : aucun texte — une nouveauté sans explication n'en est pas une.";
            } elseif (mb_strlen((string) $e['corps']) > self::MAX_CORPS) {
                $erreurs[] = "$ref : texte de plus de " . self::MAX_CORPS . ' caractères.';
            }

            if (!isset(self::GENRES[(string) ($e['genre'] ?? '')])) {
                $erreurs[] = "$ref : genre « " . ($e['genre'] ?? '') . ' » inconnu ('
                    . implode(', ', array_keys(self::GENRES)) . ').';
            }
            if (!isset(self::PUBLICS[(string) ($e['pour'] ?? '')])) {
                $erreurs[] = "$ref : public « " . ($e['pour'] ?? '') . ' » inconnu ('
                    . implode(', ', array_keys(self::PUBLICS)) . ').';
            }

            $date = (string) ($e['date'] ?? '');
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                $erreurs[] = "$ref : date « $date » hors format AAAA-MM-JJ.";
            }
        }

        return $erreurs;
    }

    // ===================== Rendu =====================

    /**
     * Entrées → document Markdown ingéré par la base de connaissances système
     * (4e document de l'assistant « MIA : mode d'emploi », aux côtés des trois
     * guides). Les métadonnées d'outillage sont retirées : l'assistant ne doit
     * jamais pouvoir citer un identifiant technique, son prompt le lui
     * interdit et il n'a aucune raison de les voir.
     *
     * Le public et la date, eux, RESTENT : « depuis le 21 août, réservé aux
     * administrateurs » est exactement ce qu'on attend d'une réponse à « quoi
     * de neuf ? ».
     *
     * @param list<array<string,string>> $entrees
     */
    public static function renderForKb(array $entrees, string $appName = 'MIA'): string
    {
        $out = "# Nouveautés de $appName\n\n"
            . "Liste des évolutions de $appName, de la plus récente à la plus ancienne. "
            . "Chaque entrée indique la date de mise à disposition, la version, "
            . "s'il s'agit d'une nouveauté, d'une amélioration ou d'un correctif, "
            . "et le public concerné.\n";

        foreach ($entrees as $e) {
            $genre  = self::GENRES[(string) ($e['genre'] ?? '')] ?? 'Nouveauté';
            $public = self::PUBLICS[(string) ($e['pour'] ?? '')] ?? '';

            $meta = [$genre];
            if (($e['date'] ?? '') !== '') {
                $meta[] = self::dateLisible((string) $e['date']);
            }
            if (($e['version'] ?? '') !== '') {
                $meta[] = 'version ' . $e['version'];
            }
            if ($public !== '') {
                $meta[] = $public;
            }

            $out .= "\n## " . (string) $e['titre'] . "\n"
                . '*' . implode(' · ', $meta) . "*\n\n"
                . (string) $e['corps'] . "\n";
        }

        return $out;
    }

    /**
     * `2026-08-21` → `21 août 2026`. Pas d'IntlDateFormatter : l'extension
     * intl n'est pas garantie sur l'image, et un mois en anglais dans la
     * modale se verrait tout de suite.
     */
    public static function dateLisible(string $iso): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) !== 1) {
            return $iso;
        }
        $mois = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $numMois = (int) $m[2];
        if (!isset($mois[$numMois])) {
            return $iso;
        }
        return ((int) $m[3]) . ' ' . $mois[$numMois] . ' ' . $m[1];
    }

    /**
     * Entrées validées → charge utile publiée, bornée. L'ordre du fichier fait
     * foi (le plus récent en tête) : trier ici sur la date ferait dériver la
     * liste de ce qu'un rédacteur voit dans son fichier, et une entrée sans
     * date encore posée passerait en queue sans raison visible.
     *
     * @param list<array<string,string>> $entrees
     * @return list<array<string,string>>
     */
    public static function publiables(array $entrees, int $max = self::MAX_PUBLIEES): array
    {
        $out = [];
        foreach (array_slice($entrees, 0, max(0, $max)) as $e) {
            $out[] = [
                'id'      => (string) ($e['id'] ?? ''),
                'titre'   => (string) ($e['titre'] ?? ''),
                'corps'   => (string) ($e['corps'] ?? ''),
                'genre'   => (string) ($e['genre'] ?? 'nouveaute'),
                'pour'    => (string) ($e['pour'] ?? 'tous'),
                'date'    => (string) ($e['date'] ?? ''),
                'version' => (string) ($e['version'] ?? ''),
            ];
        }
        return $out;
    }

    // ===================== Lecture disque =====================

    /**
     * Charge utile publiée (`version`, `nouveautes`, `commits`), ou une
     * structure vide. Sert au gabarit à connaître l'identifiant de la
     * nouveauté la plus récente SANS requête réseau : la pastille « non lu »
     * doit s'afficher au chargement de la page, un fetch par page pour un
     * point de 6 px serait payer cher un détail.
     *
     * @return array{version:string,nouveautes:list<array<string,string>>,commits:list<array<string,string>>}
     */
    public static function chargePubliee(string $racine): array
    {
        $vide = ['version' => '', 'nouveautes' => [], 'commits' => []];
        $chemin = rtrim($racine, '/') . '/' . self::PUBLIE;
        if (!is_file($chemin)) {
            return $vide;
        }
        $brut = @file_get_contents($chemin);
        if ($brut === false) {
            return $vide;
        }
        $data = json_decode($brut, true);
        if (!is_array($data) || !isset($data['nouveautes']) || !is_array($data['nouveautes'])) {
            return $vide; // format d'avant les nouveautés rédigées (tableau nu)
        }
        return [
            'version'    => (string) ($data['version'] ?? ''),
            'nouveautes' => array_values($data['nouveautes']),
            'commits'    => is_array($data['commits'] ?? null) ? array_values($data['commits']) : [],
        ];
    }

    /** Identifiant de la nouveauté la plus récente, '' s'il n'y en a aucune. */
    public static function dernierId(string $racine): string
    {
        $charge = self::chargePubliee($racine);
        return (string) ($charge['nouveautes'][0]['id'] ?? '');
    }

    /**
     * Entrées du fichier source, ou [] s'il est absent (installation partielle,
     * dépôt sans docs/). Ne lève jamais : la modale se passe de nouveautés,
     * elle ne casse pas la page.
     *
     * @return list<array<string,string>>
     */
    public static function depuisFichier(string $racine): array
    {
        $chemin = rtrim($racine, '/') . '/' . self::SOURCE;
        if (!is_file($chemin)) {
            return [];
        }
        $brut = @file_get_contents($chemin);
        return $brut === false ? [] : self::parse($brut);
    }
}
