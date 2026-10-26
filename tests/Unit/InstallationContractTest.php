<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contrat d'installation : les invariants que rien ne vérifiait.
 *
 * Ces contrôles sont purement statiques (deux secondes, aucune base, aucun
 * conteneur) et gardent le chemin d'installation — celui qui casse le plus
 * silencieusement, parce que personne ne le rejoue au quotidien.
 *
 * Chacun correspond à un défaut RÉEL constaté dans ce dépôt :
 *   - `bootstrap.sh` ouvrait un heredoc jamais fermé : le script écrivait
 *     `docker compose up -d` DANS le .env au lieu de l'exécuter, et
 *     l'installation officielle ne démarrait rien. `sh -n` ne le voit pas.
 *   - `.env.example` était indenté de deux espaces sur ses 46 lignes.
 *   - Cinq variables de compose n'étaient documentées nulle part.
 *   - `GoogleDriveOAuth`, appelé par scripts/worker.php, n'était pas dans
 *     config/classes.php : le worker ne survivait que par ricochet.
 */
final class InstallationContractTest extends TestCase
{
    /**
     * Numéros de migration dupliqués AVANT la mise en place de ce contrôle.
     *
     * On ne les renomme pas : `schema_migrations` stocke le nom de fichier,
     * renommer une migration déjà appliquée la ferait rejouer sur toutes les
     * installations existantes. Ils sont donc tolérés, et seuls les NOUVEAUX
     * doublons font échouer le test.
     */
    private const DOUBLONS_HISTORIQUES = ['018', '021', '022', '035', '050'];

    /** Répertoires sans intérêt pour le contrat d'installation. */
    private const EXCLUS = ['/vendor/', '/node_modules/', '/.git/', '/.claude/', '/mobile/'];

    // ---------------------------------------------------------------- Shell

    public function testLesScriptsShellSontSyntaxiquementValides(): void
    {
        foreach ($this->scriptsShell() as $chemin) {
            $sortie = [];
            $code   = 0;
            // Les scripts bash (kit/) se vérifient avec bash, les autres avec sh.
            $shell = str_contains((string) strtok((string) file_get_contents($chemin), "\n"), 'bash') ? 'bash' : 'sh';
            exec($shell . ' -n ' . escapeshellarg($chemin) . ' 2>&1', $sortie, $code);

            $this->assertSame(
                0,
                $code,
                'Erreur de syntaxe dans ' . $this->relatif($chemin) . " :\n" . implode("\n", $sortie)
            );
        }
    }

    /**
     * `sh -n` accepte un heredoc non terminé (POSIX autorise la fin de fichier
     * à le clore) : c'est exactement pour cela que le heredoc cassé de
     * bootstrap.sh a survécu à tous les contrôles. On vérifie donc à la main
     * que chaque `<<DELIM` a bien sa ligne de fermeture.
     */
    public function testLesHeredocsSontFermes(): void
    {
        foreach ($this->scriptsShell() as $chemin) {
            $lignes    = file($chemin, FILE_IGNORE_NEW_LINES) ?: [];
            $delimiteur = null;   // délimiteur attendu, null hors heredoc
            $tabulable  = false;  // ouvert avec <<- : tabulations tolérées devant
            $ligneOuverture = 0;

            foreach ($lignes as $numero => $ligne) {
                if ($delimiteur !== null) {
                    $candidat = $tabulable ? ltrim($ligne, "\t") : $ligne;
                    if ($candidat === $delimiteur) {
                        $delimiteur = null;
                    }
                    continue;
                }

                if (str_starts_with(ltrim($ligne), '#')) {
                    continue;
                }

                // `<<<` est une here-string (bash), pas un heredoc.
                if (preg_match('/<<(-?)\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\2/', $ligne, $m) !== 1
                    || str_contains($ligne, '<<<')) {
                    continue;
                }

                $tabulable      = $m[1] === '-';
                $delimiteur     = $m[3];
                $ligneOuverture = $numero + 1;
            }

            $this->assertNull(
                $delimiteur,
                $this->relatif($chemin) . " : le heredoc « {$delimiteur} » ouvert ligne {$ligneOuverture} "
                . "n'est jamais fermé. Tout ce qui suit est écrit dans la sortie du heredoc au lieu "
                . "d'être exécuté. Le délimiteur de fermeture doit être seul sur sa ligne, en colonne 0 "
                . '(ou précédé de tabulations uniquement si le heredoc est ouvert avec `<<-`).'
            );
        }
    }

    // ---------------------------------------------------------------- .env.example

    public function testEnvExampleEstLisibleParLeLecteurDeConfig(): void
    {
        $chemin = ROOT . '/.env.example';
        $this->assertFileExists($chemin);

        foreach (file($chemin, FILE_IGNORE_NEW_LINES) ?: [] as $numero => $ligne) {
            $ligneHumaine = $numero + 1;

            if (trim($ligne) === '') {
                continue;
            }

            $this->assertSame(
                $ligne,
                ltrim($ligne),
                ".env.example ligne {$ligneHumaine} : ligne indentée. `docker compose` et "
                . 'config/app.php ne retirent pas systématiquement les espaces de tête.'
            );

            if (str_starts_with($ligne, '#')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^[A-Z][A-Z0-9_]*=/',
                $ligne,
                ".env.example ligne {$ligneHumaine} : ni commentaire, ni affectation CLE=valeur "
                . "(« {$ligne} »)."
            );

            $valeur = substr($ligne, strpos($ligne, '=') + 1);
            $this->assertDoesNotMatchRegularExpression(
                '/^["\'].*["\']$/',
                $valeur,
                ".env.example ligne {$ligneHumaine} : valeur entre guillemets. Le lecteur de "
                . 'config/app.php ne les retire pas — ils feraient partie de la valeur.'
            );
        }
    }

    /**
     * Toute variable de compose SANS valeur par défaut doit être documentée,
     * sinon on ne peut pas la deviner : c'était le cas de DOMAIN, APP_PORT et
     * des cinq TRAEFIK_*, absentes de tout fichier du dépôt.
     */
    public function testLesVariablesComposeObligatoiresSontDocumentees(): void
    {
        $documentees = $this->clesDeEnvExample();
        $manquantes  = [];

        foreach ($this->fichiersCompose() as $compose) {
            $contenu = (string) file_get_contents($compose);

            preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*)((:?[-?])[^}]*)?\}/', $contenu, $trouvees, PREG_SET_ORDER);

            foreach ($trouvees as $t) {
                $nom    = $t[1];
                $modif  = $t[3] ?? '';
                $aDefaut = $modif === ':-' || $modif === '-';

                if ($aDefaut || isset($documentees[$nom])) {
                    continue;
                }
                $manquantes[$nom] = $this->relatif($compose);
            }
        }

        $this->assertSame(
            [],
            $manquantes,
            "Variables obligatoires des fichiers compose absentes de .env.example :\n  "
            . implode("\n  ", array_map(
                static fn (string $f, string $v): string => $v . ' (utilisée dans ' . $f . ')',
                $manquantes,
                array_keys($manquantes)
            ))
        );
    }

    // ---------------------------------------------------------------- Chargement des classes

    public function testTousLesRequiresDeConfigClassesPointentSurUnFichier(): void
    {
        $contenu = (string) file_get_contents(ROOT . '/config/classes.php');
        preg_match_all('/require(?:_once)?\s+ROOT\s*\.\s*\'([^\']+)\'/', $contenu, $m);

        $this->assertNotEmpty($m[1], 'Aucun require détecté dans config/classes.php.');

        foreach ($m[1] as $relatif) {
            $this->assertFileExists(
                ROOT . $relatif,
                "config/classes.php référence {$relatif}, qui n'existe pas."
            );
        }
    }

    /**
     * Toute classe du dépôt appelée par un script CLI doit figurer dans
     * config/classes.php. `GoogleDriveOAuth` n'y était pas : scripts/worker.php
     * l'appelle au démarrage et ne fonctionnait que parce qu'un contrôleur sans
     * rapport la chargeait au passage. Retirer ce contrôleur de la liste aurait
     * cassé le worker — en production uniquement.
     */
    public function testLesClassesUtiliseesParLesScriptsSontChargees(): void
    {
        $chargees   = $this->cheminsDeConfigClasses();
        $parNom     = $this->classesDuDepot();
        $manquantes = [];

        foreach (glob(ROOT . '/scripts/*.php') ?: [] as $script) {
            $contenu = (string) file_get_contents($script);

            preg_match_all('/\b([A-Z][A-Za-z0-9_]*)\s*(?:::|\()/', $contenu, $m);

            foreach (array_unique($m[1]) as $nom) {
                if (!isset($parNom[$nom]) || isset($chargees[$parNom[$nom]])) {
                    continue;
                }
                // Chargée explicitement par le script lui-même : acceptable.
                if (str_contains($contenu, $parNom[$nom])) {
                    continue;
                }
                $manquantes[$nom] = $nom . ' (utilisée par ' . basename($script) . ')';
            }
        }

        $this->assertSame(
            [],
            array_values($manquantes),
            "Classes appelées par un script CLI mais absentes de config/classes.php :\n  "
            . implode("\n  ", $manquantes)
        );
    }

    // ---------------------------------------------------------------- Migrations

    public function testLesNumerosDeMigrationSontUniques(): void
    {
        $parNumero = [];
        foreach (glob(ROOT . '/database/migrations/*.sql') ?: [] as $fichier) {
            $nom = basename($fichier);
            $this->assertMatchesRegularExpression(
                '/^\d{3}_[a-z0-9_]+\.sql$/',
                $nom,
                "Migration mal nommée : {$nom}. Attendu : 0XX_nom_en_minuscules.sql — "
                . "les migrations sont appliquées dans l'ordre alphabétique."
            );
            $parNumero[substr($nom, 0, 3)][] = $nom;
        }

        $doublons = [];
        foreach ($parNumero as $numero => $fichiers) {
            if (count($fichiers) > 1 && !in_array($numero, self::DOUBLONS_HISTORIQUES, true)) {
                $doublons[$numero] = implode(' / ', $fichiers);
            }
        }

        $this->assertSame(
            [],
            $doublons,
            "Numéros de migration en double :\n  " . implode("\n  ", $doublons)
            . "\nPrenez le numéro suivant le plus élevé. Ne renommez JAMAIS une migration déjà "
            . 'publiée : schema_migrations stocke son nom de fichier, elle serait rejouée partout.'
        );
    }

    /**
     * Tout service qui NE fait pas tourner Apache doit déclarer
     * `stop_signal: SIGTERM`.
     *
     * L'image de base `php:*-apache` porte `STOPSIGNAL SIGWINCH` (arrêt
     * gracieux d'Apache). Le worker, lui, exécute `php scripts/worker.php` :
     * il recevait SIGWINCH — que PHP IGNORE par défaut. Son handler pcntl
     * n'était donc jamais appelé, Docker attendait les 90 s de
     * `stop_grace_period` PLEINES puis l'abattait au SIGKILL. Deux
     * conséquences, toutes deux silencieuses : l'arrêt propre annoncé en
     * commentaire n'existait pas (job en cours tué net), et CHAQUE
     * déploiement payait 90 s — les deux tiers de son temps total.
     */
    public function testLesServicesSansApacheForcentSigterm(): void
    {
        $manquants = [];
        $verifies  = [];
        foreach ($this->fichiersCompose() as $chemin) {
            $contenu = (string) file_get_contents($chemin);
            // Bloc du service `worker` : de sa déclaration au service suivant
            // de même indentation (ou la fin du fichier).
            if (preg_match('/^(\s*)worker:\s*$(.*?)(?=^\1[a-z_-]+:\s*$|\z)/ms', $contenu, $m) !== 1) {
                continue;
            }
            // Seul le bloc qui LANCE réellement le worker est concerné : les
            // fichiers d'override (db/dev/traefik) n'y ajoutent qu'un réseau ou
            // un volume et héritent du signal déclaré à la définition.
            if (!str_contains($m[2], 'worker.php')) {
                continue;
            }
            $verifies[] = basename($chemin);
            if (!str_contains($m[2], 'stop_signal: SIGTERM')) {
                $manquants[] = basename($chemin);
            }
        }

        // Sans cette assertion, un renommage du service viderait la boucle et le
        // test passerait au vert en ne gardant plus rien.
        $this->assertNotEmpty($verifies, 'Aucune définition de service `worker` trouvée dans les compose.');

        $this->assertSame(
            [],
            $manquants,
            "Service `worker` sans `stop_signal: SIGTERM` dans : " . implode(', ', $manquants)
            . ".\nL'image php:*-apache impose STOPSIGNAL SIGWINCH, que PHP ignore : sans cette "
            . "ligne le worker est tué au SIGKILL après stop_grace_period, et chaque déploiement "
            . 'attend ce délai en entier.'
        );
    }

    // ---------------------------------------------------------------- Compte administrateur, réinstallation

    /**
     * L'installeur juge l'adresse de l'administrateur en shell, au moment de la
     * question — PHP n'est pas encore là. Il doit dire comme FILTER_VALIDATE_EMAIL,
     * qui tranche ensuite dans scripts/admin-create.php : il proposait
     * admin@localhost, que PHP refuse, et le compte n'était pas créé en toute fin
     * d'installation.
     *
     * La fonction est EXTRAITE d'install.sh et exécutée par `sh` : c'est le
     * script livré qui est confronté à PHP, pas une copie de son motif. Les deux
     * formes rares que PHP accepte en plus (partie locale entre guillemets,
     * adresse IP entre crochets) sont refusées par l'installeur, qui redemande —
     * le seul sens d'écart sans conséquence. Elles restent hors de cette liste.
     */
    public function testLAdresseDeLAdministrateurEstJugeeCommePhp(): void
    {
        $code   = $this->extraitInstallSh('ATOME_EMAIL', 'MOTIF_EMAIL', 'email_valide()') . 'email_valide "$1"';
        $ecarts = [];

        foreach ($this->adressesEprouvees() as $adresse) {
            $php   = filter_var($adresse, FILTER_VALIDATE_EMAIL) !== false;
            $shell = $this->sh($code, $adresse)[0] === 0;
            if ($php !== $shell) {
                $ecarts[] = json_encode($adresse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . ' : PHP ' . ($php ? 'accepte' : 'refuse') . ', install.sh ' . ($shell ? 'accepte' : 'refuse');
            }
        }

        $this->assertSame([], $ecarts, "install.sh ne juge pas l'adresse comme FILTER_VALIDATE_EMAIL :\n  "
            . implode("\n  ", $ecarts));
    }

    /**
     * Réinstallation (--force) : les secrets de l'ancien .env sont repris tels
     * qu'écrits. En tirer de nouveaux fermait la base embarquée (« Access
     * denied ») et rendait les clés d'API illisibles. Ici, la lecture du .env
     * sur ses cas piégeux ; scripts/smoke-install.sh rejoue la réinstallation
     * complète.
     */
    public function testLaReinstallationRepriseLesSecretsDeLAncienEnv(): void
    {
        $dossier = sys_get_temp_dir() . '/mia-reprise-' . bin2hex(random_bytes(4));
        mkdir($dossier);
        file_put_contents($dossier . '/.env', implode("\n", [
            '# ENCRYPTION_KEY protège les clés API en base',
            'APP_SECRET=a1b2c3',
            'ENCRYPTION_KEY=ancienne',
            "ENCRYPTION_KEY=la-bonne\r",
            'DB_PASS=""',
            'DB_ROOT_PASS=change_me_32_chars_minimum_secret',
        ]) . "\n");

        // Valeur encadrée : exec() rogne les blancs de fin de ligne, un retour
        // chariot resté collé à la valeur passerait inaperçu.
        $code = $this->extraitInstallSh('ancien()', 'reprendre()')
            . 'cd "$1" || exit 9; REPRIS=""; reprendre "$2" VALEUR || exit 3; printf "[%s]" "$VALEUR"';

        try {
            $this->assertSame([0, '[a1b2c3]'], $this->sh($code, $dossier, 'APP_SECRET'));
            $this->assertSame([0, '[la-bonne]'], $this->sh($code, $dossier, 'ENCRYPTION_KEY'),
                'Compose retient la DERNIÈRE occurrence ; le retour chariot n\'appartient pas à la valeur.');
            $this->assertSame(3, $this->sh($code, $dossier, 'DB_PASS')[0], 'Vide entre guillemets : à tirer.');
            $this->assertSame(3, $this->sh($code, $dossier, 'DB_ROOT_PASS')[0], 'Valeur de démonstration : à tirer.');
            $this->assertSame(3, $this->sh($code, $dossier, 'QDRANT_URL')[0], 'Absente : à tirer.');
        } finally {
            @unlink($dossier . '/.env');
            @rmdir($dossier);
        }

        // Et l'installeur passe bien par là : un tirage sans tentative de reprise
        // referait le défaut. Le smoke, lui, ne tourne que vers `main`.
        $script = (string) file_get_contents(ROOT . '/install.sh');
        foreach (['APP_SECRET', 'ENCRYPTION_KEY'] as $cle) {
            preg_match_all('/^.*\b' . $cle . '=\$\(aleatoire\b.*$/m', $script, $tirages);
            $this->assertNotEmpty($tirages[0], "install.sh ne tire plus {$cle} : test à revoir.");
            foreach ($tirages[0] as $ligne) {
                $this->assertMatchesRegularExpression(
                    '/^reprendre ' . $cle . ' ' . $cle . '\s*\|\|/',
                    $ligne,
                    "install.sh tire {$cle} sans tenter de reprendre celle de l'ancien .env : « {$ligne} »."
                );
            }
        }
    }

    // ---------------------------------------------------------------- Outils

    /**
     * Adresses où une règle recopiée diverge d'abord : longueurs maximales (64
     * avant @, 63 par étiquette, 254 en tout), points mal placés, domaine sans
     * point, caractères hors ASCII.
     *
     * @return list<string>
     */
    private function adressesEprouvees(): array
    {
        $x = static fn (int $n, string $c): string => str_repeat($c, $n);

        return [
            // Acceptées par PHP.
            'alex@myalfred.ai', 'Alex.Dupont@MyAlfred.AI', 'prenom.nom+mia@exemple.fr', "o'brien@exemple.ie",
            'a@b.c', 'admin@mia.exemple.fr', 'x@xn--80akhbyknj4f.xn--p1ai', 'a-b_c@d-e.fr', 'alex@exemple.f1r',
            'alex@exemple.c-d', '!#$%&*+/=?^_`{|}~-@exemple.fr',
            $x(64, 'a') . '@exemple.fr',
            'a@' . $x(63, 'b') . '.fr',
            $x(64, 'a') . '@' . $x(63, 'b') . '.' . $x(63, 'c') . '.' . $x(58, 'd') . '.fr',   // 254
            // Refusées par PHP.
            'admin@localhost', '', 'alex', 'alex@', '@exemple.fr', 'alex@@exemple.fr', 'alex@exemple',
            'alex@exemple.', 'alex@.exemple.fr', 'alex@exemple..fr', '.alex@exemple.fr', 'alex.@exemple.fr',
            'ax..el@exemple.fr', 'alex@exemple.1fr', 'alex@-exemple.fr', 'alex@exemple-.fr', 'alex@exe_mple.fr',
            'ax el@exemple.fr', 'alex@exemple.fr ', "alex@exemple.fr\nx@y.fr", 'axél@exemple.fr',
            'alex@127.0.0.1', '"a b"@exemple.fr', 'a(b)@exemple.fr', 'a,b@exemple.fr',
            $x(65, 'a') . '@exemple.fr',
            'a@' . $x(64, 'b') . '.fr',
            $x(64, 'a') . '@' . $x(63, 'b') . '.' . $x(63, 'c') . '.' . $x(59, 'd') . '.fr',   // 255
        ];
    }

    /**
     * Extrait d'install.sh, dans l'ordre donné, des affectations d'une ligne
     * (`NOM`) et des fonctions (`nom()`), pour les exécuter à part.
     */
    private function extraitInstallSh(string ...$noms): string
    {
        $script = (string) file_get_contents(ROOT . '/install.sh');
        $blocs  = [];

        foreach ($noms as $nom) {
            $motif = str_ends_with($nom, '()')
                ? '/^' . preg_quote(substr($nom, 0, -2), '/') . '\(\) \{.*?^\}$/ms'
                : '/^' . preg_quote($nom, '/') . '=.*$/m';
            $this->assertSame(1, preg_match($motif, $script, $m), "install.sh : « {$nom} » introuvable.");
            $blocs[] = $m[0];
        }

        return implode("\n", $blocs) . "\n";
    }

    /**
     * `sh -c <code> <arguments…>` : code de sortie et sortie standard.
     *
     * @return array{0:int,1:string}
     */
    private function sh(string $code, string ...$arguments): array
    {
        $commande = 'sh -c ' . escapeshellarg($code) . ' install-sh';
        foreach ($arguments as $argument) {
            $commande .= ' ' . escapeshellarg($argument);
        }

        $sortie = [];
        $statut = 0;
        exec($commande, $sortie, $statut);

        return [$statut, implode("\n", $sortie)];
    }

    /** @return list<string> */
    private function scriptsShell(): array
    {
        $trouves = [];
        $iterateur = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ROOT, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterateur as $fichier) {
            $chemin = $fichier->getPathname();
            if (!str_ends_with($chemin, '.sh')) {
                continue;
            }
            // Exclusions évaluées SOUS la racine du dépôt, jamais sur le chemin
            // absolu : un dépôt extrait dans un worktree de
            // `.claude/worktrees/…` porte `/.claude/` dans CHAQUE chemin, et
            // tous ses scripts étaient écartés — le test échouait alors sur
            // « aucun script trouvé » et bloquait le push (hook pre-push).
            $relatif = substr($chemin, strlen(ROOT));
            foreach (self::EXCLUS as $exclu) {
                if (str_contains($relatif, $exclu)) {
                    continue 2;
                }
            }
            $trouves[] = $chemin;
        }

        sort($trouves);
        $this->assertNotEmpty($trouves, 'Aucun script shell trouvé — le test ne garde plus rien.');

        return $trouves;
    }

    /** @return list<string> */
    private function fichiersCompose(): array
    {
        return array_values(array_filter(array_merge(
            glob(ROOT . '/docker-compose*.yml') ?: [],
            glob(ROOT . '/docker/compose/*.yml') ?: []
        )));
    }

    /** @return array<string,true> clés déclarées, actives ou commentées */
    private function clesDeEnvExample(): array
    {
        $cles = [];
        foreach (file(ROOT . '/.env.example', FILE_IGNORE_NEW_LINES) ?: [] as $ligne) {
            if (preg_match('/^#?\s*([A-Z][A-Z0-9_]*)=/', $ligne, $m) === 1) {
                $cles[$m[1]] = true;
            }
        }

        return $cles;
    }

    /** @return array<string,true> chemins relatifs requis par config/classes.php */
    private function cheminsDeConfigClasses(): array
    {
        $contenu = (string) file_get_contents(ROOT . '/config/classes.php');
        preg_match_all('/require(?:_once)?\s+ROOT\s*\.\s*\'([^\']+)\'/', $contenu, $m);

        return array_fill_keys($m[1], true);
    }

    /** @return array<string,string> nom de classe => chemin relatif dans src/ */
    private function classesDuDepot(): array
    {
        $parNom = [];
        $iterateur = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ROOT . '/src', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterateur as $fichier) {
            $chemin = $fichier->getPathname();
            if (!str_ends_with($chemin, '.php')) {
                continue;
            }
            if (preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m',
                    (string) file_get_contents($chemin), $m) === 1) {
                $parNom[$m[1]] = str_replace(ROOT, '', $chemin);
            }
        }

        return $parNom;
    }

    private function relatif(string $chemin): string
    {
        return ltrim(str_replace(ROOT, '', $chemin), '/');
    }
}
