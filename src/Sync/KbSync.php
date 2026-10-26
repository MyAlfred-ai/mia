<?php

declare(strict_types=1);

/**
 * Synchronisation d'une base de connaissances avec une source distante —
 * Google Drive (clé API, dossier partagé « lien »), Microsoft SharePoint
 * (application Azure, Microsoft Graph) ou Google Agenda (clé API, agenda
 * public, documents mensuels virtuels) : moteur de diff (ajouts /
 * modifications / renommages / suppressions) + déclencheur paresseux sur
 * trafic HTTP.
 *
 * Une base = UNE source distante à la fois (knowledge_bases.sync_provider).
 * Principe miroir : les documents source <> 'upload' de la base reflètent
 * exactement la sélection retenue du dossier (fichiers supportés, ≤ 50 Mo,
 * tri nom puis id, plafond MAX_DOCS_PER_SYNCED_KB). Le diff porte sur TOUS
 * les documents distants : au changement de fournisseur, le premier cycle du
 * nouveau retire naturellement les documents de l'ancien. Les documents
 * uploadés à la main (source='upload') ne sont JAMAIS touchés.
 *
 * Détection de modification : hash de contenu (md5 Drive, quickXorHash Graph,
 * sha256 des documents mensuels Google Agenda) quand les deux côtés en ont un,
 * sinon horodatage distant comparé comme chaîne opaque.
 *
 * Aucune méthode publique ne lève : tout échec est loggé et reflété dans
 * sync_status / sync_error de la base (visible en admin).
 */
class KbSync
{
    /** Fournisseurs de sources distantes supportés. */
    public const PROVIDERS = ['drive', 'sharepoint', 'gcalendar', 'onedrive'];

    /**
     * Fournisseurs lus avec la connexion PERSONNELLE d'un utilisateur
     * (sync_owner_user_id) plutôt qu'avec des identifiants globaux.
     * 'drive' l'accepte (au choix, clé API globale sinon) ; 'onedrive'
     * l'EXIGE — l'application Microsoft n'a aucune permission d'application,
     * elle ne sait agir qu'au nom de quelqu'un.
     */
    public const OWNER_PROVIDERS    = ['drive', 'onedrive'];
    public const OWNER_ONLY_PROVIDERS = ['onedrive'];

    /** Plafond de documents synchronisés par base (les uploads manuels ont leur propre quota). */
    public const MAX_DOCS_PER_SYNCED_KB = 100;

    /** Profondeur maximale de parcours des sous-dossiers. */
    public const MAX_DEPTH = 10;

    /** Fichiers non repris NOMMÉS dans le rapport d'un passage (au-delà, seulement comptés). */
    public const MAX_REPORT_ISSUES = 30;

    /** Intervalle par défaut entre deux cycles (minutes), modifiable en admin. */
    public const DEFAULT_INTERVAL_MIN = 10;

    /** Clés Settings — Google Drive : clé API chiffrée (Encryption). */
    public const SETTING_DRIVE_API_KEY = 'google_drive_api_key_enc';

    /** Clés Settings — SharePoint : identifiants d'app Azure (secret chiffré). */
    public const SETTING_SP_TENANT = 'sharepoint_tenant_id';
    public const SETTING_SP_CLIENT = 'sharepoint_client_id';
    public const SETTING_SP_SECRET = 'sharepoint_client_secret_enc';

    /** Clés Settings — Google Agenda : clé API chiffrée (distincte de la clé Drive). */
    public const SETTING_GCAL_API_KEY = 'google_calendar_api_key_enc';

    /** Intervalle commun aux deux fournisseurs (nom legacy conservé — données existantes). */
    public const SETTING_INTERVAL = 'drive_sync_interval_min';

    /** Verrou du déclencheur (une seule requête HTTP dispatche les workers). */
    private const TRIGGER_LOCK = 'kb_sync_tick';

    /** Les identifiants d'un fournisseur sont-ils renseignés ? (Settings = cache mémoire, appel gratuit.) */
    public static function providerConfigured(string $provider): bool
    {
        if ($provider === 'drive') {
            return (string)Settings::get(self::SETTING_DRIVE_API_KEY, '') !== '';
        }
        if ($provider === 'sharepoint') {
            return (string)Settings::get(self::SETTING_SP_TENANT, '') !== ''
                && (string)Settings::get(self::SETTING_SP_CLIENT, '') !== ''
                && (string)Settings::get(self::SETTING_SP_SECRET, '') !== '';
        }
        if ($provider === 'gcalendar') {
            return (string)Settings::get(self::SETTING_GCAL_API_KEY, '') !== '';
        }
        if ($provider === 'onedrive') {
            // Pas de secret « de source » : ce qu'il faut, c'est l'application
            // Azure qui autorise les utilisateurs à connecter LEUR OneDrive.
            return MicrosoftOAuth::isConfigured();
        }
        return false;
    }

    /**
     * État de configuration par fournisseur (pour l'UI admin).
     *
     * @return array{drive:bool, sharepoint:bool, gcalendar:bool, onedrive:bool}
     */
    public static function configuredProviders(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $p) {
            $out[$p] = self::providerConfigured($p);
        }
        return $out;
    }

    /** Ce fournisseur peut-il être lu via la connexion personnelle d'un utilisateur ? */
    public static function supportsOwnerAuth(string $provider): bool
    {
        return in_array($provider, self::OWNER_PROVIDERS, true);
    }

    /** Ce fournisseur EXIGE-t-il une connexion personnelle (aucun accès global) ? */
    public static function requiresOwnerAuth(string $provider): bool
    {
        return in_array($provider, self::OWNER_ONLY_PROVIDERS, true);
    }

    /** Libellé d'affichage d'un fournisseur. */
    public static function providerLabel(string $provider): string
    {
        return match ($provider) {
            'drive'      => 'Google Drive',
            'sharepoint' => 'SharePoint',
            'gcalendar'  => 'Google Agenda',
            'onedrive'   => 'OneDrive',
            default      => $provider,
        };
    }

    /**
     * Identifiants manquants d'un fournisseur → message utilisateur (sans le
     * suffixe d'action). Les messages de cette classe sont TRADUITS à
     * l'écriture : ceux d'un bouton « Tester » dans la langue de l'écran, ceux
     * qu'un passage de synchronisation consigne (`sync_error`) dans la langue
     * du job. Seuls les motifs des fichiers non repris (`noteIssue`) restent
     * en français : enregistrés dans le rapport, ils sont traduits à
     * l'affichage (kb-status.php), clé = le motif lui-même.
     */
    public static function missingCredentialsLabel(string $provider): string
    {
        return match ($provider) {
            'sharepoint' => t('Identifiants Azure (SharePoint) non configurés'),
            'gcalendar'  => t('Clé API Google (Agenda) non configurée'),
            'onedrive'   => t('Application Microsoft (OneDrive) non configurée'),
            default      => t('Clé API Google non configurée'),
        };
    }

    /**
     * Identifiants PRÉSENTS mais indéchiffrables → message utilisateur.
     *
     * Cas vécu : `ENCRYPTION_KEY` régénérée (`.env` recréé, assistant
     * d'installation rejoué) — toutes les valeurs chiffrées deviennent
     * illisibles d'un coup. Le message « non configurée » envoyait alors
     * l'administrateur vérifier un réglage pourtant bien renseigné, et le
     * vrai problème (les AUTRES secrets chiffrés sont morts aussi) restait
     * invisible. D'où un message distinct, qui nomme la cause.
     */
    public static function unreadableCredentialsMessage(string $provider): string
    {
        $what = match ($provider) {
            'sharepoint' => t('Le secret client Azure (SharePoint) est illisible'),
            'gcalendar'  => t('La clé API Google (Agenda) est illisible'),
            'onedrive'   => t('Le secret client Microsoft (OneDrive) est illisible'),
            default      => t('La clé API Google est illisible'),
        };
        return t('{quoi} : elle a été chiffrée avec une autre clé de chiffrement de l\'application (ENCRYPTION_KEY). Re-saisissez-la dans Administration → Capacités → Sources synchronisées — les autres secrets chiffrés (clés LLM, connecteurs) sont probablement à re-saisir aussi.', ['quoi' => $what]);
    }

    /** Saisie de dossier/agenda illisible → message utilisateur par fournisseur. */
    public static function invalidFolderInputMessage(string $provider): string
    {
        return match ($provider) {
            'sharepoint' => t('URL non reconnue. Collez l\'URL complète du dossier SharePoint (https://votretenant.sharepoint.com/…).'),
            'gcalendar'  => t('Agenda non reconnu. Collez l\'adresse de l\'agenda (xxx@group.calendar.google.com) ou son URL publique.'),
            'onedrive'   => t('Dossier non reconnu. Utilisez « Parcourir… » pour choisir un dossier de votre OneDrive, ou collez le lien de partage du dossier.'),
            default      => t('Lien non reconnu. Collez l\'URL du dossier (https://drive.google.com/drive/folders/…) ou son identifiant.'),
        };
    }

    /** Client du fournisseur depuis les Settings ; null si non configuré/illisible. */
    public static function client(string $provider): ?RemoteFolderProvider
    {
        return self::resolveClient($provider)['client'];
    }

    /**
     * Client du fournisseur ET motif précis quand il est indisponible —
     * SOURCE UNIQUE des messages d'identifiants (cycle de sync, bouton
     * « Tester »). Distingue trois situations qui n'appellent pas le même
     * geste : réglage absent (le renseigner), réglage présent mais
     * indéchiffrable (le re-saisir, et se méfier des autres secrets), et
     * fournisseur inconnu.
     *
     * @return array{client:?RemoteFolderProvider, error:?string}
     */
    public static function resolveClient(string $provider): array
    {
        try {
            if ($provider === 'drive' || $provider === 'gcalendar') {
                $stored = self::storedSecret(
                    $provider === 'drive' ? self::SETTING_DRIVE_API_KEY : self::SETTING_GCAL_API_KEY,
                    $provider
                );
                if ($stored['error'] !== null) {
                    return ['client' => null, 'error' => $stored['error']];
                }
                if ($stored['value'] === '') {
                    return self::credentialsError($provider);
                }
                return [
                    'client' => $provider === 'drive'
                        ? new GoogleDriveProvider($stored['value'])
                        : new GoogleCalendarProvider($stored['value']),
                    'error'  => null,
                ];
            }
            if ($provider === 'sharepoint') {
                $tenant = (string)Settings::get(self::SETTING_SP_TENANT, '');
                $client = (string)Settings::get(self::SETTING_SP_CLIENT, '');
                $stored = self::storedSecret(self::SETTING_SP_SECRET, $provider);
                if ($stored['error'] !== null) {
                    return ['client' => null, 'error' => $stored['error']];
                }
                if ($tenant === '' || $client === '' || $stored['value'] === '') {
                    return self::credentialsError($provider);
                }
                return ['client' => new SharePointProvider($tenant, $client, $stored['value']), 'error' => null];
            }
            if ($provider === 'onedrive') {
                // Aucun client « global » possible : OneDrive se lit toujours
                // au nom d'un utilisateur (cf. resolveClientForKb).
                return [
                    'client' => null,
                    'error'  => t('OneDrive se lit avec la connexion Microsoft d\'un utilisateur — rouvrez la base et choisissez « Ma connexion OneDrive ».'),
                ];
            }
        } catch (\Throwable $e) {
            // Le détail (qui peut porter la valeur chiffrée) reste dans les logs.
            error_log('[KB Sync] identifiants ' . $provider . ' illisibles : ' . $e->getMessage());
            return ['client' => null, 'error' => self::unreadableCredentialsMessage($provider)];
        }
        return ['client' => null, 'error' => t('Source de synchronisation inconnue.')];
    }

    /**
     * Client pour le cycle d'UNE base : mode « connexion Google Drive d'un
     * utilisateur » (sync_owner_user_id, dossier partagé nominativement) ou
     * identifiants globaux du fournisseur. Jamais de repli silencieux du
     * premier vers le second : le dossier d'un partage nominatif n'est
     * probablement pas public, l'erreur doit nommer la connexion manquante.
     *
     * @return array{client:?RemoteFolderProvider, error:?string}
     */
    public static function resolveClientForKb(array $kb): array
    {
        $provider = (string)($kb['sync_provider'] ?? '');
        $ownerId  = (int)($kb['sync_owner_user_id'] ?? 0);

        if ($ownerId > 0 && self::supportsOwnerAuth($provider)) {
            return self::ownerClient($provider, $ownerId);
        }
        if (self::requiresOwnerAuth($provider)) {
            // Base OneDrive sans propriétaire : ne JAMAIS retomber sur un
            // accès global (il n'en existe pas) — dire ce qui manque.
            return [
                'client' => null,
                'error'  => t('Cette base OneDrive n\'a plus de connexion Microsoft associée — rouvrez-la et enregistrez-la avec votre connexion OneDrive.'),
            ];
        }
        return self::resolveClient($provider);
    }

    /**
     * Client porté par la connexion personnelle d'un utilisateur.
     * SOURCE UNIQUE (cycle de sync, bouton « Tester », explorateur).
     *
     * @return array{client:?RemoteFolderProvider, error:?string}
     */
    public static function ownerClient(string $provider, int $userId): array
    {
        if ($provider === 'drive') {
            $svc = UserDriveService::forUser($userId);
            return $svc === null
                ? ['client' => null, 'error' => self::ownerConnectionMissingMessage($provider, $userId)]
                : ['client' => $svc->syncFolderProvider(), 'error' => null];
        }
        if ($provider === 'onedrive') {
            $svc = UserOneDriveService::forUser($userId);
            return $svc === null
                ? ['client' => null, 'error' => self::ownerConnectionMissingMessage($provider, $userId)]
                : ['client' => $svc->folderProvider(), 'error' => null];
        }
        return ['client' => null, 'error' => t('Cette source ne se lit pas via une connexion personnelle.')];
    }

    /** Connexion personnelle du porteur de la sync indisponible → message utilisateur. */
    public static function ownerConnectionMissingMessage(string $provider, int $ownerId): string
    {
        $owner = User::findById($ownerId);
        $who   = $owner !== null
            ? (string)($owner['name'] ?? t('l\'utilisateur #{id}', ['id' => $ownerId]))
            : t('un utilisateur supprimé');
        return t('Cette base se synchronise via la connexion {service} de {qui}, qui n\'est plus disponible (déconnectée, révoquée ou compte supprimé). Reconnectez-la dans Réglages → Comptes connectés, ou rouvrez la base pour choisir un autre accès.', [
            'service' => t(self::providerLabel($provider)),
            'qui'     => $who,
        ]);
    }

    /** @return array{client:null, error:string} */
    private static function credentialsError(string $provider): array
    {
        return [
            'client' => null,
            'error'  => t('{manque} (Administration → Capacités → Sources synchronisées).', [
                'manque' => self::missingCredentialsLabel($provider),
            ]),
        ];
    }

    /**
     * Secret chiffré des Settings, en clair. Réglage ABSENT et réglage
     * INDÉCHIFFRABLE sont deux issues distinctes : `value` vide + `error` null
     * pour le premier, `error` renseigné pour le second. Confondre les deux
     * était précisément le défaut corrigé ici.
     *
     * @return array{value:string, error:?string}
     */
    private static function storedSecret(string $settingKey, string $provider): array
    {
        $enc = (string)Settings::get($settingKey, '');
        if ($enc === '') {
            return ['value' => '', 'error' => null];
        }
        $plain = Encryption::decrypt($enc);
        if ($plain === '') {
            return ['value' => '', 'error' => self::unreadableCredentialsMessage($provider)];
        }
        return ['value' => $plain, 'error' => null];
    }

    /** Valide la saisie selon le fournisseur (id Drive / URL SharePoint / adresse d'agenda). */
    public static function parseFolderInput(string $provider, string $raw): ?string
    {
        return match ($provider) {
            'drive'      => GoogleDriveProvider::parseFolderInput($raw),
            'sharepoint' => SharePointProvider::parseFolderInput($raw),
            'gcalendar'  => GoogleCalendarProvider::parseFolderInput($raw),
            'onedrive'   => OneDriveProvider::parseFolderInput($raw),
            default      => null,
        };
    }

    /**
     * Un fichier distant (format normalisé) sera-t-il ingéré ? Même verdict
     * que selectSyncable, exposé à l'unité pour que l'explorateur montre
     * AVANT le choix ce qui sera repris et ce qui sera ignoré. Pure, testable.
     */
    public static function isSyncable(array $file): bool
    {
        if (($file['export'] ?? null) !== null) {
            return true; // natif Google exportable : taille connue à l'export
        }
        $mime = (string)($file['mime'] ?? '');
        return isset(KnowledgeBaseDocument::ALLOWED_MIMES[$mime])
            && (int)($file['size'] ?? 0) > 0
            && (int)$file['size'] <= KnowledgeBaseDocument::MAX_SYNC_FILE_SIZE;
    }

    /** Intervalle courant entre deux cycles, borné à [1, 1440] minutes. */
    public static function intervalMinutes(): int
    {
        $raw = (int)Settings::get(self::SETTING_INTERVAL, (string)self::DEFAULT_INTERVAL_MIN);
        return max(1, min(1440, $raw ?: self::DEFAULT_INTERVAL_MIN));
    }

    // ===================== Déclencheur paresseux =====================

    /**
     * Appelé à chaque requête HTTP (public/index.php) : si des bases sont
     * dues, une seule requête prend le verrou et dispatche un worker par base.
     * Les bases d'un fournisseur sans identifiants sont exclues dès le SELECT
     * (une base Drive due sans clé Google ne fait jamais prendre le verrou).
     * No-op sans fork POSIX (une sync de 100 fichiers n'a rien à faire dans
     * une requête utilisateur — seul le bouton « Synchroniser maintenant »
     * accepte le secours synchrone). Ne lève jamais.
     */
    public static function maybeRunDue(): void
    {
        try {
            // Rien à enfiler si personne ne pourra l'exécuter hors requête :
            // une sync de 100 fichiers n'a rien à faire dans une requête
            // utilisateur (seul le bouton « Synchroniser maintenant » accepte
            // le repli synchrone).
            if (!JobQueue::canRunOutOfBand()) {
                return;
            }
            $providers = array_keys(array_filter(self::configuredProviders()));
            // Les bases Drive en mode « connexion du propriétaire » tournent
            // SANS clé API globale : il suffit que le client OAuth Google soit
            // déclaré (refresh des jetons).
            $driveOwner = GoogleDriveOAuth::isConfigured();
            if ($providers === [] && !$driveOwner) {
                return;
            }
            $interval = self::intervalMinutes();
            if (KnowledgeBase::dueForSync($interval, $providers, 1, $driveOwner) === []) {
                return; // garde cheap : 1 SELECT indexé par requête
            }
            // Budget IA du mois atteint (mode « suspendre ») : la synchro
            // AUTOMATIQUE est la seule dépense qui part sans geste humain, elle
            // se met en pause. Rien n'est horodaté : elle reprend d'elle-même
            // dès le déblocage (rallonge, budget relevé, 1er du mois). Le
            // bouton « Synchroniser maintenant » passe, lui (geste explicite).
            if (AiBudget::blocksAutomaticSync()) {
                return;
            }
            if (!self::acquireLock(self::TRIGGER_LOCK)) {
                return;
            }
            try {
                foreach (KnowledgeBase::dueForSync($interval, $providers, 10, $driveOwner) as $kb) {
                    // Horodatage posé AVANT le dispatch : ferme la fenêtre où une
                    // autre requête re-dispatcherait la même base (le worker n'a
                    // peut-être pas encore démarré).
                    KnowledgeBase::setSyncState((int)$kb['id'], ['touch_last_sync' => true]);
                    JobQueue::enqueueMaintenance(
                        'kb_sync',
                        (int)$kb['id'],
                        'Synchronisation base #' . (int)$kb['id'],
                        false // jamais en ligne : on est dans une requête utilisateur
                    );
                }
            } finally {
                self::releaseLock(self::TRIGGER_LOCK);
            }
        } catch (\Throwable $e) {
            error_log('[KB Sync] déclencheur : ' . $e->getMessage());
        }
    }

    // ===================== Moteur de synchronisation =====================

    /**
     * Cycle complet d'une base (worker CLI ou secours synchrone du bouton).
     * Verrouillé par base : un clic « Synchroniser maintenant » pendant un
     * cycle lazy repart sans doubler le travail. Ne lève jamais.
     *
     * `$heartbeat` (job de maintenance) est appelé à chaque étape du passage :
     * un cycle de 100 fichiers dépasse largement les 120 s au-delà desquelles
     * la file prend un job muet pour mort et le rejoue.
     *
     * @param (callable():mixed)|null $heartbeat
     */
    public static function syncKb(int $kbId, ?callable $heartbeat = null): void
    {
        $lockName = 'kb_sync_' . $kbId;
        try {
            $kb = KnowledgeBase::findById($kbId);
            if (!$kb
                || (int)($kb['sync_enabled'] ?? 0) !== 1
                || (string)($kb['sync_folder_ref'] ?? '') === ''
                || !in_array((string)($kb['sync_provider'] ?? ''), self::PROVIDERS, true)
                || (int)($kb['is_system'] ?? 0) === 1) {
                return;
            }
            if (!self::acquireLock($lockName)) {
                return; // synchronisation déjà en cours
            }
            try {
                self::runSync($kb, $heartbeat);
            } finally {
                // Jamais de progression orpheline, même sur une exception : un
                // écran qui afficherait « 12 / 40 » après la fin du passage
                // mentirait pire qu'un écran muet.
                KnowledgeBase::setActivity($kbId, null);
                self::releaseLock($lockName);
            }
        } catch (\Throwable $e) {
            error_log('[KB Sync] sync base ' . $kbId . ' : ' . $e->getMessage());
            try {
                KnowledgeBase::setSyncState($kbId, [
                    'status' => 'error',
                    'error'  => t('Erreur interne de synchronisation — détail dans les logs.'),
                ]);
            } catch (\Throwable) {
                // Base supprimée pendant le cycle : rien à refléter.
            }
        }
    }

    /**
     * Bouton « Tester » : valide la saisie et vérifie l'accès au dossier.
     * $ownerUserId non nul (Drive seulement) = tester avec la connexion
     * Google Drive de cet utilisateur au lieu de la clé API globale.
     */
    public static function testFolder(string $provider, string $rawInput, ?int $ownerUserId = null): array
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            return ['ok' => false, 'error' => t('Source de synchronisation inconnue.')];
        }
        $folderRef = self::parseFolderInput($provider, $rawInput);
        if ($folderRef === null) {
            return ['ok' => false, 'error' => self::invalidFolderInputMessage($provider)];
        }
        if ($ownerUserId !== null && self::supportsOwnerAuth($provider)) {
            $owned  = self::ownerClient($provider, $ownerUserId);
            $client = $owned['client'];
            if ($client === null) {
                return ['ok' => false, 'error' => t(
                    '{service} n\'est pas connecté sur votre compte — connectez-le dans Réglages → Comptes connectés.',
                    ['service' => t(self::providerLabel($provider))]
                )];
            }
        } else {
            $resolved = self::resolveClient($provider);
            $client   = $resolved['client'];
            if ($client === null) {
                return ['ok' => false, 'error' => (string)$resolved['error']];
            }
        }
        try {
            $folder = $client->resolveFolder($folderRef);
            return ['ok' => true, 'folder_ref' => $folderRef, 'name' => (string)($folder['name'] ?? $folderRef)];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Bouton « Tester la connexion » des Paramètres admin : vérifie les
     * identifiants globaux d'un fournisseur, sans dossier. Les valeurs saisies
     * dans le formulaire (non enregistrées) priment sur celles stockées — un
     * admin teste AVANT d'enregistrer. Ne lève jamais.
     *
     * @param array{api_key?:string, tenant_id?:string, client_id?:string, client_secret?:string} $override
     * @return array{ok:bool, error?:string, detail?:string}
     */
    public static function testConnection(string $provider, array $override = []): array
    {
        try {
            if ($provider === 'drive') {
                $key = trim((string)($override['api_key'] ?? ''));
                if ($key === '') {
                    $stored = self::storedSecret(self::SETTING_DRIVE_API_KEY, $provider);
                    if ($stored['error'] !== null) {
                        return ['ok' => false, 'error' => $stored['error']];
                    }
                    $key = $stored['value'];
                }
                if ($key === '') {
                    return ['ok' => false, 'error' => t('Aucune clé API Google : saisissez-la dans le champ ci-dessus ou enregistrez-la d\'abord.')];
                }
                return (new GoogleDriveProvider($key))->testConnection();
            }
            if ($provider === 'sharepoint') {
                $tenant = trim((string)($override['tenant_id'] ?? ''));
                $client = trim((string)($override['client_id'] ?? ''));
                $secret = trim((string)($override['client_secret'] ?? ''));
                if ($tenant === '') {
                    $tenant = (string)Settings::get(self::SETTING_SP_TENANT, '');
                }
                if ($client === '') {
                    $client = (string)Settings::get(self::SETTING_SP_CLIENT, '');
                }
                if ($secret === '') {
                    $stored = self::storedSecret(self::SETTING_SP_SECRET, $provider);
                    if ($stored['error'] !== null) {
                        return ['ok' => false, 'error' => $stored['error']];
                    }
                    $secret = $stored['value'];
                }
                $missing = array_keys(array_filter([
                    'ID de l\'annuaire (tenant)' => $tenant === '',
                    'ID d\'application (client)' => $client === '',
                    'secret client'              => $secret === '',
                ]));
                if ($missing !== []) {
                    return [
                        'ok'    => false,
                        'error' => t('Champ(s) manquant(s) : {champs}. Saisissez-le(s) ci-dessus ou enregistrez-le(s) d\'abord.', [
                            'champs' => implode(', ', array_map(static fn (string $champ): string => t($champ), $missing)),
                        ]),
                    ];
                }
                return (new SharePointProvider($tenant, $client, $secret))->testConnection();
            }
            if ($provider === 'gcalendar') {
                $key = trim((string)($override['calendar_api_key'] ?? ''));
                if ($key === '') {
                    $stored = self::storedSecret(self::SETTING_GCAL_API_KEY, $provider);
                    if ($stored['error'] !== null) {
                        return ['ok' => false, 'error' => $stored['error']];
                    }
                    $key = $stored['value'];
                }
                if ($key === '') {
                    return ['ok' => false, 'error' => t('Aucune clé API Google (Agenda) : saisissez-la dans le champ ci-dessus ou enregistrez-la d\'abord.')];
                }
                return (new GoogleCalendarProvider($key))->testConnection();
            }
            if ($provider === 'onedrive') {
                // Rien de global à tester : l'application Azure n'a aucune
                // permission propre, elle sert à ce que CHAQUE utilisateur
                // connecte son compte. Le vrai test est le bouton « Tester »
                // de la base, avec la connexion de l'utilisateur.
                // L'application retenue est la dédiée dès que son ID est saisi,
                // sinon celle de la connexion Microsoft (MicrosoftOAuth).
                if (!MicrosoftOAuth::isConfigured()) {
                    return ['ok' => false, 'error' => MicrosoftOAuth::usesDedicatedApp()
                        ? t('Inscription dédiée incomplète : renseignez aussi son secret client, puis enregistrez. La connexion Microsoft n\'est pas utilisée à sa place.')
                        : t('Aucune application Microsoft : renseignez celle de la connexion Microsoft (Organisation → Authentification), ou une inscription dédiée ci-dessus.')];
                }
                return ['ok' => true, 'detail' => MicrosoftOAuth::usesDedicatedApp()
                    ? t('Application Microsoft renseignée. Chaque utilisateur connecte ensuite son OneDrive dans Réglages → Comptes connectés ; l\'accès réel se vérifie avec le bouton « Tester » de la base.')
                    : t('OneDrive utilise l\'application de la connexion Microsoft : déclarez-y la permission déléguée Files.Read.All. Chaque utilisateur connecte ensuite son OneDrive dans Réglages → Comptes connectés ; l\'accès réel se vérifie avec le bouton « Tester » de la base.')];
            }
            return ['ok' => false, 'error' => t('Source de synchronisation inconnue.')];
        } catch (\Throwable $e) {
            error_log('[KB Sync] test connexion ' . $provider . ' : ' . $e->getMessage());
            return ['ok' => false, 'error' => t('Erreur interne pendant le test — détail dans les logs serveur.')];
        }
    }

    /**
     * Déroulé d'un cycle : listing → sélection → diff → état + rapport.
     *
     * La progression (`KnowledgeBase::setActivity`) n'est ÉCRITE que lorsqu'un
     * fichier demande du travail (nouveau ou modifié) ou toutes les 2 s : un
     * passage sur un dossier inchangé, toutes les 10 minutes, ne doit pas
     * coûter cent UPDATE pour dire « rien à faire ». Le battement de cœur, lui,
     * part à chaque étape (JobContext le limite déjà à deux par seconde).
     *
     * @param (callable():mixed)|null $heartbeat
     */
    private static function runSync(array $kb, ?callable $heartbeat = null): void
    {
        $kbId     = (int)$kb['id'];
        $provider = (string)$kb['sync_provider'];
        $started  = time();

        $lastWrite = 0.0;
        $progress  = static function (array $step, bool $force) use ($kbId, $heartbeat, $started, &$lastWrite): void {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $now = microtime(true);
            if (!$force && $now - $lastWrite < 2.0) {
                return;
            }
            $lastWrite = $now;
            KnowledgeBase::setActivity($kbId, $step + ['kind' => 'sync', 'started' => $started]);
        };

        // Horodatage posé immédiatement, MÊME si le cycle échoue ensuite :
        // une erreur persistante ne re-tente qu'au prochain intervalle.
        KnowledgeBase::setSyncState($kbId, ['status' => 'syncing', 'touch_last_sync' => true]);
        $progress(['phase' => 'listing'], true);

        $resolved = self::resolveClientForKb($kb);
        $client   = $resolved['client'];
        if ($client === null) {
            KnowledgeBase::setSyncState($kbId, ['status' => 'error', 'error' => $resolved['error']]);
            return;
        }

        try {
            $folder = $client->resolveFolder((string)$kb['sync_folder_ref']);
            $listed = $client->listFolderRecursive((string)$kb['sync_folder_ref'], self::MAX_DEPTH);
        } catch (\RuntimeException $e) {
            KnowledgeBase::setSyncState($kbId, ['status' => 'error', 'error' => $e->getMessage()]);
            return;
        }

        $report = [
            'listed' => count($listed), 'selected' => 0,
            'added' => 0, 'updated' => 0, 'renamed' => 0, 'deleted' => 0, 'failed' => 0,
            'ignored_unsupported' => 0, 'ignored_too_big' => 0, 'ignored_over_cap' => 0,
            'issues' => [],
        ];
        $selection          = self::selectSyncable($listed, $report);
        $report['selected'] = count($selection);
        $total              = count($selection);

        $dir    = KnowledgeBase::ensureStorageDir($kbId);
        $locaux = KnowledgeBaseDocument::syncedDocsForKb($kbId);

        foreach (array_values($selection) as $i => $f) {
            $loc = $locaux[$f['id']] ?? null;
            unset($locaux[$f['id']]);
            $modified = $loc !== null && self::isModified($f, $loc);

            $progress(
                ['phase' => 'files', 'done' => $i, 'total' => $total, 'current' => (string)$f['name']],
                $loc === null || $modified
            );

            if ($loc === null) {
                // Nouveau fichier : téléchargement puis ingestion complète,
                // SÉQUENTIELLE (pas un fork par document : 100 fichiers = 100
                // process PHP et autant de sessions d'embeddings simultanées).
                $fetched = self::fetchToStorage($client, $f, $dir, $report);
                if ($fetched === null) {
                    continue;
                }
                $newId = KnowledgeBaseDocument::create([
                    'kb_id'            => $kbId,
                    'file_name'        => $fetched['file_name'],
                    'stored_name'      => $fetched['stored_name'],
                    'mime_type'        => $fetched['mime_type'],
                    'file_size'        => $fetched['file_size'],
                    'source'           => $provider,
                    'sync_file_id'     => $f['id'],
                    'sync_hash'        => $f['hash'],
                    'sync_modified_at' => $f['modified'],
                ]);
                KnowledgeBaseDocument::processIngestion($newId);
                $report['added']++;
                continue;
            }

            if ($modified) {
                // Modifié : nouveau fichier stocké (jamais d'écrasement en
                // place — pas de fichier semi-écrit lisible pendant le
                // téléchargement), puis réingestion. vectorizeDocument purge
                // déjà les anciens points Qdrant du document avant ré-upsert.
                $fetched = self::fetchToStorage($client, $f, $dir, $report);
                if ($fetched === null) {
                    continue; // échec : la version précédente reste servie
                }
                $oldPath = $dir . '/' . (string)$loc['stored_name'];
                KnowledgeBaseDocument::updateSyncedVersion((int)$loc['id'], [
                    'file_name'        => $fetched['file_name'],
                    'stored_name'      => $fetched['stored_name'],
                    'mime_type'        => $fetched['mime_type'],
                    'file_size'        => $fetched['file_size'],
                    'sync_hash'        => $f['hash'],
                    'sync_modified_at' => $f['modified'],
                ]);
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
                KnowledgeBaseDocument::processIngestion((int)$loc['id']);
                $report['updated']++;
            } elseif ((string)$f['name'] !== (string)$loc['file_name']) {
                // Renommage pur : aucun re-traitement.
                KnowledgeBaseDocument::updateFileName((int)$loc['id'], (string)$f['name']);
                $report['renamed']++;
            }
        }

        // Reliquat = disparus du dossier (supprimés, mis à la corbeille,
        // déplacés hors du dossier, dossier changé, ou documents d'un ANCIEN
        // fournisseur après bascule de source) : purge Qdrant AVANT la
        // suppression MySQL (les ids de chunks partent avec la ligne).
        if ($locaux !== []) {
            $progress(['phase' => 'cleanup', 'done' => $total, 'total' => $total], true);
            $store      = new QdrantStore();
            $collection = KnowledgeBase::collectionName($kbId);
            foreach ($locaux as $doc) {
                try {
                    $store->deletePointsByDocument($collection, (int)$doc['id']);
                } catch (\Throwable $e) {
                    error_log('[KB Sync] purge points doc ' . $doc['id'] . ' : ' . $e->getMessage());
                }
                KnowledgeBaseDocument::delete((int)$doc['id']);
                $report['deleted']++;
            }
        }

        // Fin du passage, en horloge PHP comme `started` : « synchronisée il y
        // a 4 min » se lit sur la FIN d'un passage réussi. `sync_last_at`, lui,
        // est posé au DÉBUT de chaque tentative (il rythme les passages) et ne
        // saurait pas le dire.
        $report['started']  = $started;
        $report['finished'] = time();

        KnowledgeBase::setSyncState($kbId, [
            'status'      => 'ok',
            'error'       => null,
            'folder_name' => (string)($folder['name'] ?? ''),
            'report'      => json_encode($report, JSON_UNESCAPED_UNICODE) ?: null,
        ]);
    }

    /**
     * Retient un fichier non repris et son motif dans le rapport — borné : un
     * dossier de 500 images n'a pas à produire un rapport de 500 lignes, les
     * compteurs disent le total. Le motif s'adresse à l'utilisateur : c'est ce
     * qu'il lira sous « fichiers non repris ».
     */
    private static function noteIssue(array &$report, string $name, string $reason): void
    {
        $report['issues'] ??= [];
        if (count($report['issues']) < self::MAX_REPORT_ISSUES) {
            $report['issues'][] = ['name' => $name, 'reason' => $reason];
        }
    }

    /**
     * Filtre les fichiers synchronisables (format normalisé) et applique le
     * plafond de façon DÉTERMINISTE (tri nom puis id avant la coupe) : sans
     * tri, les « 100 premiers » changeraient d'un listing à l'autre et la base
     * battrait en ajouts/suppressions à chaque cycle. Publique : logique pure,
     * couverte par les tests unitaires.
     */
    public static function selectSyncable(array $files, array &$report): array
    {
        $out = [];
        foreach ($files as $f) {
            if (($f['export'] ?? null) !== null) {
                // Natif exportable (Google Docs) : taille inconnue avant export
                // (limite ~10 Mo appliquée par Google, comptée en ignored_too_big
                // à l'export).
                $out[] = $f;
                continue;
            }
            $mime = (string)$f['mime'];
            if (!isset(KnowledgeBaseDocument::ALLOWED_MIMES[$mime]) || (int)$f['size'] <= 0) {
                $report['ignored_unsupported']++;
                self::noteIssue(
                    $report,
                    (string)$f['name'],
                    isset(KnowledgeBaseDocument::ALLOWED_MIMES[$mime]) ? 'fichier vide' : 'format non pris en charge'
                );
                continue;
            }
            if ((int)$f['size'] > KnowledgeBaseDocument::MAX_SYNC_FILE_SIZE) {
                $report['ignored_too_big']++;
                self::noteIssue($report, (string)$f['name'], self::tooBigReason());
                continue;
            }
            $out[] = $f;
        }

        usort($out, static fn(array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);

        if (count($out) > self::MAX_DOCS_PER_SYNCED_KB) {
            $report['ignored_over_cap'] = count($out) - self::MAX_DOCS_PER_SYNCED_KB;
            foreach (array_slice($out, self::MAX_DOCS_PER_SYNCED_KB) as $f) {
                self::noteIssue(
                    $report,
                    (string)$f['name'],
                    'au-delà des ' . self::MAX_DOCS_PER_SYNCED_KB . ' fichiers repris'
                );
            }
            $out = array_slice($out, 0, self::MAX_DOCS_PER_SYNCED_KB);
        }
        return $out;
    }

    /** Motif « trop gros », partagé par la sélection et le téléchargement. */
    private static function tooBigReason(): string
    {
        return 'plus de ' . intdiv(KnowledgeBaseDocument::MAX_SYNC_FILE_SIZE, 1024 * 1024) . ' Mo';
    }

    /**
     * Un fichier distant (format normalisé) a-t-il changé par rapport au
     * document local ? Hash de contenu quand les deux côtés en ont un, sinon
     * horodatage distant comparé comme chaîne opaque. Publique : logique pure,
     * couverte par les tests unitaires.
     */
    public static function isModified(array $remote, array $local): bool
    {
        $remoteHash = $remote['hash'] ?? null;
        $localHash  = $local['sync_hash'] ?? null;
        if ($remoteHash !== null && $localHash !== null) {
            return (string)$remoteHash !== (string)$localHash;
        }
        return (string)$remote['modified'] !== (string)($local['sync_modified_at'] ?? '');
    }

    /**
     * Télécharge (ou exporte) un fichier distant vers le stockage de la base :
     * écriture en .part puis rename atomique (même volume). Échec → null,
     * compteur du rapport incrémenté, jamais bloquant pour les autres fichiers.
     *
     * @return array{file_name:string,stored_name:string,mime_type:string,file_size:int}|null
     */
    private static function fetchToStorage(RemoteFolderProvider $client, array $f, string $dir, array &$report): ?array
    {
        $export = $f['export'] ?? null;
        $ext    = $export !== null
            ? (string)$export['ext']
            : (string)KnowledgeBaseDocument::ALLOWED_MIMES[(string)$f['mime']];

        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $tmpPath    = $dir . '/' . $storedName . '.part';

        try {
            $client->fetchTo($f, $tmpPath);

            $size = (int)(@filesize($tmpPath) ?: 0);
            if ($size <= 0 || $size > KnowledgeBaseDocument::MAX_SYNC_FILE_SIZE) {
                @unlink($tmpPath);
                $report[$size > 0 ? 'ignored_too_big' : 'failed']++;
                self::noteIssue($report, (string)$f['name'], $size > 0 ? self::tooBigReason() : 'téléchargé vide');
                return null;
            }
            if (!@rename($tmpPath, $dir . '/' . $storedName)) {
                @unlink($tmpPath);
                $report['failed']++;
                self::noteIssue($report, (string)$f['name'], 'écriture impossible sur le stockage du serveur');
                error_log('[KB Sync] rename impossible pour ' . $storedName);
                return null;
            }
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            if ((int)$e->getCode() === GoogleDriveProvider::CODE_EXPORT_TOO_BIG) {
                $report['ignored_too_big']++;
                self::noteIssue($report, (string)$f['name'], 'document Google trop volumineux pour l\'export (~10 Mo)');
            } else {
                $report['failed']++;
                // Les fournisseurs lèvent des RuntimeException à message
                // utilisateur (sans URL ni secret) : on peut le montrer. Tout
                // le reste est un imprévu, dont le détail reste dans les logs.
                self::noteIssue(
                    $report,
                    (string)$f['name'],
                    $e instanceof \RuntimeException ? mb_substr($e->getMessage(), 0, 200) : 'téléchargement impossible'
                );
                error_log('[KB Sync] téléchargement « ' . $f['name'] . ' » : ' . $e->getMessage());
            }
            return null;
        }

        return [
            'file_name'   => (string)$f['name'],
            'stored_name' => $storedName,
            'mime_type'   => $export !== null ? (string)$export['mime'] : (string)$f['mime'],
            'file_size'   => $size,
        ];
    }

    // ===================== Verrous =====================

    /** Verrou MySQL non bloquant, relâché à la fermeture de la connexion. */
    private static function acquireLock(string $name): bool
    {
        $stmt = Database::getInstance()->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private static function releaseLock(string $name): void
    {
        Database::getInstance()->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
    }
}
