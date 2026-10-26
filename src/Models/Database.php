<?php

declare(strict_types=1);

/** Base injoignable, identifiants refusés, schéma absent. Message déjà en français. */
class DatabaseUnavailableException extends \RuntimeException
{
}

/** Une migration a échoué : le schéma est peut-être partiellement appliqué. */
class MigrationFailedException extends \RuntimeException
{
}

class Database
{
    private static ?PDO $instance = null;

    /**
     * Mémorise l'échec de migration pour le REJOUER à chaque appel suivant.
     * Sans cela, le premier appelant reçoit l'exception, mais les suivants —
     * dont plusieurs l'avalent dans un `catch (\Throwable)` — récupèrent une
     * connexion parfaitement fonctionnelle sur un schéma incomplet, et la panne
     * ressort bien plus loin, sous une forme incompréhensible.
     */
    private static ?MigrationFailedException $migrationEchouee = null;

    public static function getInstance(): PDO
    {
        if (self::$migrationEchouee !== null) {
            throw self::$migrationEchouee;
        }

        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    self::initCommandAttribute() => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]);
            } catch (PDOException $e) {
                // Le message brut de PDO est journalisé (il porte le détail
                // utile) mais n'est JAMAIS renvoyé au client.
                error_log('[db] connexion impossible : ' . $e->getMessage());
                throw new DatabaseUnavailableException(self::messageConnexion($e), 0, $e);
            }

            try {
                self::migrate();
            } catch (MigrationFailedException $e) {
                self::$migrationEchouee = $e;
                throw $e;
            }
        }

        return self::$instance;
    }

    /** Verrou nommé MySQL : une seule migration à la fois, tous conteneurs confondus. */
    private const MIGRATE_LOCK = 'mia_schema_migrate';

    private static function migrate(): void
    {
        self::$instance->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                filename   VARCHAR(255) NOT NULL,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Cas nominal, de très loin le plus fréquent : rien à appliquer. On sort
        // AVANT de prendre le verrou — inutile de payer un aller-retour GET_LOCK
        // à chaque requête HTTP pour découvrir qu'il n'y a rien à faire.
        if (self::migrationsEnAttente() === []) {
            return;
        }

        // L'app et le(s) worker(s) démarrent EN MÊME TEMPS et migrent tous les
        // deux : sans verrou, chacun lit la même liste « à appliquer » et rejoue
        // le même fichier — un ALTER … DROP FOREIGN KEY échoue alors sur le
        // second (« Can't DROP … »), laissant un schéma à moitié migré.
        $lock = self::$instance->prepare('SELECT GET_LOCK(?, 60)');
        $lock->execute([self::MIGRATE_LOCK]);

        if ((int) $lock->fetchColumn() !== 1) {
            // Verrou non obtenu en 60 s. Si l'autre process a terminé le travail
            // entre-temps, il n'y a plus rien à faire. Sinon on refuse de
            // continuer sur un schéma incomplet — et on le DIT, au lieu de
            // laisser l'application tourner sur une base périmée dont les
            // erreurs SQL ressortiront plus tard, ailleurs.
            if (self::migrationsEnAttente() === []) {
                return;
            }

            throw new MigrationFailedException(
                'Les migrations de base de données n\'ont pas pu être appliquées : le verrou '
                . '« ' . self::MIGRATE_LOCK . ' » est resté occupé plus de 60 secondes. '
                . 'Un autre conteneur migre peut-être encore — réessayez dans une minute. '
                . 'Si le problème persiste, libérez le verrou : '
                . 'SELECT RELEASE_LOCK(\'' . self::MIGRATE_LOCK . '\');'
            );
        }

        try {
            // Re-lecture SOUS verrou : un autre process a pu appliquer tout ou
            // partie de la liste pendant l'attente.
            foreach (self::migrationsEnAttente() as $fichier) {
                $nom = basename($fichier);

                try {
                    self::$instance->exec((string) file_get_contents($fichier));
                } catch (PDOException $e) {
                    error_log('[migrate] échec sur ' . $nom . ' : ' . $e->getMessage());

                    // Pas de transaction : MySQL 8 valide implicitement tout DDL
                    // (CREATE / ALTER / DROP). Envelopper la migration donnerait
                    // une illusion d'atomicité. Le bon contrat est d'échouer
                    // bruyamment, de nommer le fichier, et de donner la reprise.
                    throw new MigrationFailedException(
                        'La migration « ' . $nom . ' » a échoué : ' . $e->getMessage() . ' '
                        . 'Le schéma est peut-être partiellement appliqué. Corrigez la base, puis marquez '
                        . 'la migration comme faite : '
                        . 'INSERT INTO schema_migrations (filename) VALUES (\'' . $nom . '\');',
                        0,
                        $e
                    );
                }

                self::$instance->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$nom]);
            }
        } finally {
            self::$instance->prepare('SELECT RELEASE_LOCK(?)')->execute([self::MIGRATE_LOCK]);
        }
    }

    /**
     * Migrations restant à appliquer, pour le diagnostic.
     *
     * En fonctionnement normal la liste est vide : `getInstance()` vient de les
     * appliquer. Un contenu non vide signale un schéma en retard — c'est
     * exactement le signal recherché par `/healthz` et par doctor.php.
     *
     * @return list<string> noms de fichiers
     */
    public static function pendingMigrations(): array
    {
        self::getInstance();

        return array_map('basename', self::migrationsEnAttente());
    }

    /**
     * Fichiers de migration présents sur disque et absents de schema_migrations.
     *
     * @return list<string> chemins absolus, triés par nom
     */
    private static function migrationsEnAttente(): array
    {
        $fichiers = glob(ROOT . '/database/migrations/*.sql') ?: [];
        sort($fichiers);

        $appliquees = self::$instance
            ->query('SELECT filename FROM schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(
            $fichiers,
            static fn (string $f): bool => !in_array(basename($f), $appliquees, true)
        ));
    }

    /**
     * Attribut « commande initiale » du pilote MySQL. PHP 8.5 déprécie
     * `PDO::MYSQL_ATTR_INIT_COMMAND` au profit de `Pdo\Mysql::ATTR_INIT_COMMAND`
     * (apparu en 8.4) : lire l'ancienne constante journaliserait un « Obsolète »
     * à CHAQUE connexion. L'ancienne n'est lue que là où la nouvelle manque
     * (PHP 8.3, plancher du projet) — même valeur des deux côtés.
     */
    private static function initCommandAttribute(): int
    {
        return defined('Pdo\Mysql::ATTR_INIT_COMMAND')
            ? (int)constant('Pdo\Mysql::ATTR_INIT_COMMAND')
            : (int)constant('PDO::MYSQL_ATTR_INIT_COMMAND');
    }

    /**
     * Traduit une panne de connexion en message actionnable, SANS jamais
     * renvoyer le message brut de PDO au client (il contient l'utilisateur, le
     * DSN complet, et selon les pilotes des fragments d'identifiants).
     */
    private static function messageConnexion(PDOException $e): string
    {
        $cible = DB_HOST . ':' . DB_PORT;
        $code  = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) ? (int) $m[1] : 0;

        return match ($code) {
            1045 => 'La base de données a refusé les identifiants (utilisateur « ' . DB_USER . '» sur '
                . $cible . '). Vérifiez DB_USER et DB_PASS.',
            1049 => 'La base « ' . DB_NAME . ' » n\'existe pas sur ' . $cible . '. '
                . 'Créez-la, ou corrigez DB_NAME.',
            2002, 2003 => 'Le serveur MySQL est injoignable sur ' . $cible . '. '
                . 'Vérifiez qu\'il est démarré et que DB_HOST et DB_PORT sont corrects '
                . '(sous Docker, l\'hôte est le nom du service : « db »).',
            2006, 2013 => 'La connexion à MySQL (' . $cible . ') a été interrompue. '
                . 'Le serveur redémarre peut-être — réessayez dans quelques secondes.',
            default => 'La base de données est injoignable sur ' . $cible . '. '
                . 'Consultez storage/logs/errors.log pour le détail.',
        };
    }
}
