<?php

declare(strict_types=1);

require_once ROOT . '/src/Auth/GoogleDriveOAuth.php';
require_once ROOT . '/src/Auth/GoogleGmailOAuth.php';
require_once ROOT . '/src/Auth/GoogleCalendarOAuth.php';
require_once ROOT . '/src/Auth/GoogleTasksOAuth.php';
// Socle Microsoft AVANT ses sous-classes : pas d'autoloader.
require_once ROOT . '/src/Auth/MicrosoftUserOAuth.php';
require_once ROOT . '/src/Auth/MicrosoftOutlookOAuth.php';
require_once ROOT . '/src/Auth/MicrosoftCalendarOAuth.php';
require_once ROOT . '/src/Auth/MicrosoftTodoOAuth.php';
require_once ROOT . '/src/Auth/MicrosoftTeamsOAuth.php';
require_once ROOT . '/src/Auth/NativeOauthState.php';

class UserSettingsController
{
    // ---- Affichage de la page profil ----
    public function profile(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $user    = Session::currentUser();
        $success = Session::get('settings_success');
        $error   = Session::get('settings_error');
        Session::remove('settings_success');
        Session::remove('settings_error');

        // « MIA pour vous » ne se règle plus ici : la carte vit sur /settings/ai,
        // sous « Votre contexte », et Mon profil y renvoie (une seule liste, une
        // seule porte).
        require ROOT . '/templates/settings/profile.php';
    }

    // ---- Galerie « Mes créations » ----
    public function creations(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $user = Session::currentUser();
        $rows = Message::creationsForUser((int)$user['id']);

        $creations = $this->buildCreationItems($rows);

        // Compteur par type pour le filtre (seuls les types présents sont proposés).
        $typeCounts = [];
        foreach ($creations as $item) {
            $typeCounts[$item['type']] = ($typeCounts[$item['type']] ?? 0) + 1;
        }

        require ROOT . '/templates/settings/creations.php';
    }

    /**
     * Lignes SQL de Message::creationsForUser → cartes de la galerie.
     * Un message riche = une carte ; un message porteur d'images = une carte
     * PAR image (la prose du function-calling peut en contenir plusieurs).
     * Les colonnes content/files_json arrivent TRONQUÉES (LEFT SQL) : les
     * helpers de titre tolèrent le JSON incomplet, et creationType() lit un
     * files_json assistant toujours < 600 chars.
     */
    private function buildCreationItems(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            $type      = Message::creationType($row);
            $content   = (string)($row['content'] ?? '');
            $convTitle = trim((string)($row['conversation_title'] ?? ''));
            $base      = [
                'message_id'      => (int)$row['id'],
                'conversation_id' => (int)$row['conversation_id'],
                'conv_title'      => $convTitle !== '' ? $convTitle : 'Conversation',
                'date'            => Format::dateFr((string)($row['created_at'] ?? '')),
                // Le partage porte sur le MESSAGE : mêmes routes que le chat,
                // donc mêmes conditions — ShareController::create refuse un
                // message dont creationType() est null (422).
                'shareable'       => $type !== null,
                'share_token'     => (string)($row['share_token'] ?? ''),
            ];

            if ($type === 'html_page') {
                $title   = Message::extractHtmlTitle($content) ?? $base['conv_title'];
                $items[] = $base + [
                    'type'      => 'html_page',
                    'title'     => $title,
                    'download'  => 'html',
                    'file_name' => self::downloadSlug($title, 'page') . '.html',
                ];
                continue;
            }

            if ($type === 'slide_deck') {
                $deck    = DeckSpec::decode($content);
                $title   = $deck !== null
                    ? (string)$deck['title']
                    : (Message::extractJsonTitle($content) ?? 'Présentation');
                $items[] = $base + [
                    'type'   => 'slide_deck',
                    'title'  => $title,
                    'slides' => $deck !== null ? count($deck['slides']) : null,
                    // Trois formats comme le menu « Exporter » du volet deck :
                    // le nom est sans extension, le format la choisit.
                    'download'  => 'deck',
                    'file_name' => self::downloadSlug($title, 'presentation'),
                ];
                continue;
            }

            if ($type === 'map_route') {
                $spec  = json_decode($content, true);
                $steps = is_array($spec) && isset($spec['waypoints']) && is_array($spec['waypoints'])
                    ? $spec['waypoints']
                    : [];
                $items[] = $base + [
                    'type'  => 'map_route',
                    'title' => Message::extractJsonTitle($content) ?? $base['conv_title'],
                    // Spec tronquée par le LEFT SQL → json_decode échoue : on
                    // compte les étapes à la ficelle et la vignette retombe sur
                    // la tuile d'icône (RouteThumbnail exige des coordonnées).
                    'waypoints' => $steps !== [] ? count($steps) : substr_count($content, '"query"'),
                    'route'     => $steps,
                    'route_ends' => self::routeEnds($steps),
                    // Pas de téléchargement : l'export PDF d'un itinéraire exige
                    // une carte Leaflet vivante (capture d'écran), comme dans le chat.
                    'download'  => '',
                ];
                continue;
            }

            if ($type === 'video_file') {
                $files = json_decode((string)($row['files_json'] ?? ''), true);
                $url   = '';
                $name  = '';
                foreach (is_array($files) ? $files : [] as $file) {
                    if (($file['type'] ?? '') === 'video_file') {
                        $url  = (string)($file['path'] ?? '');
                        $name = (string)($file['name'] ?? '');
                        break;
                    }
                }
                if ($url === '') {
                    continue; // marqueur incomplet — carte inutilisable
                }
                $title   = $name !== '' ? $name : $base['conv_title'];
                $items[] = $base + [
                    'type'      => 'video_file',
                    'title'     => $title,
                    'url'       => $url,
                    'download'  => 'direct', // fichier statique same-origin
                    'file_name' => $name !== '' ? $name : self::downloadSlug($title, 'video') . '.mp4',
                ];
                continue;
            }

            // Pas de type riche : toutes les images générées du contenu (une
            // carte par image). Le partage ne vaut que pour un message
            // image-seule — le token pointe le MESSAGE, pas une image précise.
            $imageShareable = ($type === 'generated_image');
            foreach (Message::extractGeneratedImages($content) as $img) {
                $alt   = $img['alt'];
                $title = ($alt !== '' && $alt !== 'Image générée') ? $alt : $base['conv_title'];
                $ext   = strtolower((string)pathinfo($img['url'], PATHINFO_EXTENSION)) ?: 'png';
                $items[] = [
                    'type'        => 'generated_image',
                    'title'       => $title,
                    'url'         => $img['url'],
                    'shareable'   => $imageShareable,
                    'share_token' => $imageShareable ? $base['share_token'] : '',
                    'download'    => 'direct', // fichier statique same-origin
                    'file_name'   => self::downloadSlug($title, 'image') . '.' . $ext,
                ] + $base;
            }
        }

        return Message::withoutRepeatedImages($items);
    }

    /**
     * « Départ → Arrivée » d'un itinéraire : la forme du tracé identifie le
     * trajet, ses extrémités le nomment. Chaîne vide si les étapes manquent.
     */
    private static function routeEnds(array $waypoints): string
    {
        $names = [];
        foreach ($waypoints as $wp) {
            $name = trim((string)($wp['name'] ?? $wp['query'] ?? ''));
            if ($name !== '') {
                $names[] = mb_substr($name, 0, 40);
            }
        }
        if (count($names) < 2) {
            return '';
        }
        return $names[0] . ' → ' . $names[count($names) - 1];
    }

    /**
     * Titre → nom de fichier sûr (même recette que l'export de deck,
     * `ChatController::exportDeck`) : lettres/chiffres conservés, le reste
     * devient un tiret, minuscules, borné à 60 caractères.
     */
    private static function downloadSlug(string $title, string $fallback): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', trim($title)) ?? '';
        $slug = trim(mb_strtolower(mb_substr($slug, 0, 60)), '-');
        return $slug !== '' ? $slug : $fallback;
    }

    /**
     * Sortie des écrans de paramètres, en JSON pour l'auto-save et en
     * message de session + redirection sinon.
     *
     * Pourquoi un helper local plutôt que `AjaxResponse::finish()` : cette
     * page transporte ses messages par `Session::set` + `Session::remove`
     * (lus par `profile()` / `aiPreferences()`), pas par `Session::flash`.
     * Passer par le helper générique les rendrait invisibles.
     *
     * @param array<string, mixed> $extra
     */
    private function respondSettings(
        bool $asJson,
        bool $success,
        string $message,
        string $location,
        array $extra = []
    ): void {
        if ($asJson) {
            if ($success) {
                AjaxResponse::ok(['message' => $message] + $extra);
            }
            AjaxResponse::fail($message);
        }
        Session::set($success ? 'settings_success' : 'settings_error', $message);
        header('Location: ' . $location);
        exit;
    }

    // ---- Mise à jour des informations du profil ----
    public function updateProfile(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $asJson = AjaxResponse::wanted();
        $user   = Session::currentUser();
        $id     = (int)$user['id'];

        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '') {
            $this->respondSettings($asJson, false, t('Le nom ne peut pas être vide.'), '/settings');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respondSettings($asJson, false, t('Adresse e-mail invalide.'), '/settings');
        }

        // Vérifie que l'email n'est pas déjà utilisé par un autre utilisateur
        $existing = Database::getInstance()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $existing->execute([$email, $id]);
        if ($existing->fetch()) {
            $this->respondSettings(
                $asJson,
                false,
                t('Cette adresse e-mail est déjà utilisée par un autre compte.'),
                '/settings'
            );
        }

        // Photo de profil : un fichier choisi, une adresse collée ou un retrait
        // (AvatarStorage::requestedAction). Le formulaire entier repart à chaque
        // enregistrement automatique : un champ d'adresse vide ou inchangé veut
        // dire « garder la photo ». Une photo refusée n'empêche pas le reste de
        // s'enregistrer : le nom et l'e-mail partent, la raison s'affiche.
        $current       = (string)($user['avatar_url'] ?? '');
        $avatarUrl     = $current;
        $avatarWarning = null;
        try {
            $avatarUrl = match (AvatarStorage::requestedAction($_POST, $_FILES['avatar_file'] ?? null, $current)) {
                AvatarStorage::ACTION_UPLOAD => AvatarStorage::storeUpload($id, $_FILES['avatar_file']),
                AvatarStorage::ACTION_IMPORT => AvatarStorage::importFromUrl($id, (string)$_POST['avatar_url']),
                AvatarStorage::ACTION_REMOVE => '',
                default                      => $current,
            };
        } catch (\RuntimeException $e) {
            $avatarWarning = $e->getMessage();
        }

        Database::getInstance()->prepare(
            'UPDATE users SET name=?, email=?, avatar_url=? WHERE id=?'
        )->execute([$name, $email, $avatarUrl, $id]);

        // L'ancienne photo part APRÈS l'écriture : un échec de la base ne doit
        // pas laisser un compte pointer vers un fichier effacé.
        if ($avatarUrl !== $current) {
            AvatarStorage::deleteUserFile($current, $id);
        }

        // Rafraîchit la session
        AuthMiddleware::refreshUser();

        if ($avatarWarning !== null && !$asJson) {
            $this->respondSettings(false, false, $avatarWarning, '/settings');
        }

        // `avatar_url` renvoyée : un fichier tout juste téléversé porte un nom
        // aléatoire connu du seul serveur, et la page ne se recharge pas.
        // `avatar_failed` : la page garde l'adresse saisie pour qu'on la corrige.
        $extra = ['avatar_url' => $avatarUrl];
        if ($avatarWarning !== null) {
            $extra += ['warning' => $avatarWarning, 'avatar_failed' => true];
        }
        $this->respondSettings($asJson, true, t('Profil mis à jour.'), '/settings', $extra);
    }

    // ---- Affichage de la page Préférences IA ----
    public function aiPreferences(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $user     = Session::currentUser();
        // Libellés et aides TRADUITS (l'accesseur passe par t()) : le gabarit
        // n'affiche jamais le fragment de prompt d'un style.
        $styles   = UserPreferences::styles();
        $maxLen   = UserPreferences::MAX_CONTEXT_LEN;
        $memories = UserMemory::allForUser((int)$user['id']);
        // Carte « Voix » : rendue seulement si la conversation vocale est
        // proposée — un réglage de lecture à voix haute n'a rien à régler
        // quand personne ne peut l'entendre. Mêmes deux conditions que le
        // bouton onde sonore du chat (réglage d'administration ET clé du
        // fournisseur), sinon l'écran proposerait un réglage mort. Corollaire
        // côté handler : les champs absents du POST ne sont pas écrits (cf.
        // updateAiPreferences).
        $voiceEnabled = SpeechSynthesizer::isEnabled() && !empty(ApiKey::getDecrypted('openai'));
        $voiceRates   = VoicePreferences::rateOptions();
        $voiceLengths = VoicePreferences::lengths();
        $voiceRate    = VoicePreferences::rateOf($user);
        $voiceLength  = VoicePreferences::lengthOf($user);
        $success = Session::get('settings_success');
        $error   = Session::get('settings_error');
        Session::remove('settings_success');
        Session::remove('settings_error');

        require ROOT . '/templates/settings/ai.php';
    }

    // ---- Mise à jour des préférences IA ----
    public function updateAiPreferences(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $asJson = AjaxResponse::wanted();
        $user   = Session::currentUser();
        $id     = (int)$user['id'];

        $style = trim((string)($_POST['ai_style'] ?? ''));
        if (!UserPreferences::isValidStyle($style)) {
            $this->respondSettings($asJson, false, 'Style de réponse invalide.', '/settings/ai');
        }

        $context = UserPreferences::sanitizeContext((string)($_POST['ai_user_context'] ?? ''));
        if (mb_strlen($context) > UserPreferences::MAX_CONTEXT_LEN) {
            $this->respondSettings(
                $asJson,
                false,
                'Le contexte ne peut pas dépasser ' . UserPreferences::MAX_CONTEXT_LEN . ' caractères.',
                '/settings/ai'
            );
        }

        UserPreferences::save($id, $style, $context);

        // Réglages de voix : écrits SEULEMENT s'ils sont dans le POST. La
        // carte n'est rendue que si la conversation vocale est proposée ;
        // lire des champs absents comme « valeur par défaut » effacerait le
        // choix de l'utilisateur le jour où l'administrateur coupe la
        // fonctionnalité — le piège maison « champ absent = décoché ».
        $rate   = array_key_exists('voice_rate', $_POST)   ? VoicePreferences::sanitizeRate($_POST['voice_rate']) : null;
        $length = array_key_exists('voice_length', $_POST) ? VoicePreferences::sanitizeLength($_POST['voice_length']) : null;
        VoicePreferences::save($id, $rate, $length);

        AuthMiddleware::refreshUser();

        $this->respondSettings($asJson, true, 'Préférences IA enregistrées.', '/settings/ai');
    }

    // ---- Affichage de la page Affichage (thème, taille, contraste, touche d'envoi) ----
    public function display(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $user          = Session::currentUser();
        $instanceTheme = Settings::theme();
        $themes        = DisplayPreferences::themes($instanceTheme);
        $scaleLabels   = DisplayPreferences::scaleLabels();
        $currentTheme  = DisplayPreferences::themeOf($user);
        $currentScale  = DisplayPreferences::scaleOf($user);
        $contrasts     = DisplayPreferences::contrasts();
        $sendKeys      = DisplayPreferences::sendKeys();
        $currentContrast = DisplayPreferences::contrastOf($user);
        $currentSendKey  = DisplayPreferences::sendKeyOf($user);
        $success = Session::get('settings_success');
        $error   = Session::get('settings_error');
        Session::remove('settings_success');
        Session::remove('settings_error');

        require ROOT . '/templates/settings/display.php';
    }

    /**
     * POST /settings/display — formulaire auto-enregistré de la page Affichage.
     *
     * N'écrit QUE les réglages présents dans le POST (DisplayPreferences::save) :
     * un réglage ajouté demain à la page ne remettra pas à zéro celui d'avant
     * chez qui enregistrerait depuis un onglet resté ouvert sur l'ancienne
     * version. La réponse rend l'état ENREGISTRÉ : la page s'y réaligne si
     * l'enregistrement est refusé (cf. le script de templates/settings/display.php).
     */
    public function updateDisplayPrefs(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $asJson = AjaxResponse::wanted();
        $id     = (int)Session::currentUser()['id'];

        $theme = array_key_exists('ui_theme', $_POST) ? DisplayPreferences::sanitizeTheme($_POST['ui_theme']) : null;
        $scale = array_key_exists('ui_scale', $_POST) ? DisplayPreferences::sanitizeScale($_POST['ui_scale']) : null;
        $contrast = array_key_exists('ui_contrast', $_POST) ? DisplayPreferences::sanitizeContrast($_POST['ui_contrast']) : null;
        $sendKey  = array_key_exists('ui_send_key', $_POST) ? DisplayPreferences::sanitizeSendKey($_POST['ui_send_key']) : null;
        DisplayPreferences::save($id, $theme, $scale, $contrast, $sendKey);

        AuthMiddleware::refreshUser();
        $fresh = Session::currentUser();

        $this->respondSettings(
            $asJson,
            true,
            t('Affichage enregistré.'),
            '/settings/display',
            [
                'ui_theme'    => DisplayPreferences::themeOf($fresh),
                'ui_scale'    => DisplayPreferences::scaleOf($fresh),
                'ui_contrast' => DisplayPreferences::contrastOf($fresh),
                'ui_send_key' => DisplayPreferences::sendKeyOf($fresh),
            ]
        );
    }

    /**
     * POST /settings/voice — un réglage de voix, depuis le bandeau de la
     * conversation vocale (JSON, CSRF par header).
     *
     * Même colonne que la carte des Paramètres : UNE source de vérité, donc
     * aucun état à faire diverger entre l'écran de réglages et le chat. Chaque
     * appel ne porte QUE ce qui change (le bandeau ne règle que la longueur),
     * et la réponse renvoie l'état ENREGISTRÉ — le front ne bascule son
     * affichage qu'en le lisant, jamais par anticipation.
     */
    public function updateVoicePrefs(): void
    {
        AuthMiddleware::requireAuth();
        $user = Session::currentUser();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!SpeechSynthesizer::isEnabled()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => t('La conversation vocale est désactivée par l\'administrateur.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $rate   = array_key_exists('rate', $_POST)   ? VoicePreferences::sanitizeRate($_POST['rate']) : null;
        $length = array_key_exists('length', $_POST) ? VoicePreferences::sanitizeLength($_POST['length']) : null;
        VoicePreferences::save((int)$user['id'], $rate, $length);
        AuthMiddleware::refreshUser();

        $fresh = Session::currentUser();
        echo json_encode([
            'ok'     => true,
            'rate'   => VoicePreferences::rateOf($fresh),
            'length' => VoicePreferences::lengthOf($fresh),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * POST /settings/lang — bascule FR/EN du menu de compte.
     *
     * PAS de requireAuth, volontairement : la même route sert la PAGE DE
     * CONNEXION, où il n'y a aucun compte à qui rattacher le choix — il ne peut
     * alors vivre qu'en session. Le CSRF, lui, est vérifié pour TOUS les POST
     * dans public/index.php : la route n'est pas pour autant ouverte.
     *
     * Connecté, on écrit les deux : la colonne (le choix suit le compte d'un
     * appareil à l'autre) ET la session, qui fait le pont jusqu'au prochain
     * refreshUser. La session ne survit PAS à la déconnexion (Session::destroy),
     * et c'est le comportement voulu sur un poste partagé : le suivant retombe
     * sur Accept-Language, puis récupère SA langue en se connectant.
     */
    public function updateLang(): void
    {
        $lang = strtolower(trim((string)($_POST['lang'] ?? '')));
        if (!I18n::isSupported($lang)) {
            AjaxResponse::fail(t('Langue non prise en charge.'));
        }

        Session::set('lang', $lang);

        if (Session::isAuthenticated()) {
            UserPreferences::saveLang((int)Session::currentUser()['id'], $lang);
            AuthMiddleware::refreshUser();
        }

        // Pose la locale pour CETTE réponse : sans quoi un message d'erreur
        // ultérieur repartirait dans l'ancienne langue.
        I18n::use($lang);

        AjaxResponse::ok(['lang' => $lang]);
    }

    // -----------------------------------------------------------------------
    // Mémoire long terme « MIA pour vous » (AJAX JSON)
    //
    // Carte AJAX autonome (partial memory-card.php, inclus par /settings/ai
    // sous « Votre contexte ») : ses champs ne sont rattachés à aucun
    // formulaire, un formulaire imbriqué serait invalide en HTML.
    // -----------------------------------------------------------------------

    public function addMemory(): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        $result = UserMemory::add($userId, (string)($_POST['content'] ?? ''), UserMemory::SOURCE_MANUAL);
        $this->respondMemory($userId, $result['status'], match ($result['status']) {
            'ok'        => null,
            'empty'     => 'Le souvenir ne peut pas être vide.',
            'duplicate' => 'Ce souvenir est déjà enregistré.',
            'full'      => 'Mémoire pleine (' . UserMemory::MAX_ENTRIES . ' souvenirs). Supprimez-en un avant d\'en ajouter.',
            default     => 'Enregistrement impossible.',
        });
    }

    public function updateMemory(string $id): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        $result = UserMemory::update($userId, (int)$id, (string)($_POST['content'] ?? ''));
        $this->respondMemory($userId, $result['status'], match ($result['status']) {
            'ok'        => null,
            'empty'     => 'Le souvenir ne peut pas être vide.',
            'duplicate' => 'Ce souvenir fait doublon avec un autre.',
            'not_found' => 'Souvenir introuvable.',
            default     => 'Modification impossible.',
        });
    }

    public function deleteMemory(string $id): void
    {
        AuthMiddleware::requireAuth();
        $userId = (int)Session::currentUser()['id'];

        $ok = UserMemory::delete($userId, (int)$id);
        $this->respondMemory($userId, $ok ? 'ok' : 'not_found', $ok ? null : 'Souvenir introuvable.');
    }

    /**
     * Réponse JSON commune : statut + liste à jour, pour que le front re-rende
     * la carte depuis la source de vérité plutôt que d'imaginer l'état final.
     */
    private function respondMemory(int $userId, string $status, ?string $error): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($status !== 'ok') {
            http_response_code($status === 'not_found' ? 404 : 422);
        }
        echo json_encode([
            'ok'       => $status === 'ok',
            'error'    => $error,
            'memories' => UserMemory::allForUser($userId),
            'max'      => UserMemory::MAX_ENTRIES,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Page Usage ----
    public function usage(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $user   = Session::currentUser();
        $userId = (int)$user['id'];

        $monthStart = date('Y-m-01');
        $monthEnd   = date('Y-m-t');
        $nextMonth  = date('Y-m-01', strtotime('+1 month'));

        // Résumé du mois (catégories, tokens, hors quota) + quota : source
        // unique partagée avec la barre latérale et le chat (CreditQuota).
        $breakdown = UsageLog::creditsBreakdownThisMonth($userId);
        $quotaStatus = CreditQuota::status($user);
        $quota       = $quotaStatus['quota_max'];

        // Répartitions du mois, calculées par le grand livre (UsageLog) —
        // aucune requête SQL ici. `byModel` porte TOUS les modèles appelés
        // (chat, embeddings, dictée) : anneau « par modèle » et détail technique.
        $llmByModel = UsageLog::byModel($monthStart, $monthEnd, $userId);

        $byProfile  = UsageLog::byProfile($monthStart, $monthEnd, $userId);
        $byTool     = UsageLog::byTool($monthStart, $monthEnd, $userId);
        $byCategory = UsageLog::byCategory($monthStart, $monthEnd, $userId);

        // Nombre de jours restants avant réinitialisation
        $resetDate    = $nextMonth;
        $daysUntilReset = (int)ceil((strtotime($resetDate) - time()) / 86400);

        // Version « solde et répartition » : prévision, anneaux, coûts unitaires,
        // conseils — formes pures (PersonalUsage), aucun euro.
        $now       = new DateTimeImmutable('now');
        $forecast  = PersonalUsage::forecast($quota !== null ? (int)$quota : null, (int)$breakdown['quota_credits'], $now);
        $modelLabel = static function (string $provider, string $model): string {
            $m = $model !== '' ? LlmCatalog::model($provider, $model) : null;
            if ($m !== null && !empty($m['label'])) {
                return (string)$m['label'];
            }
            // Hors catalogue LLM : dire à quoi le modèle a servi plutôt que son identifiant.
            return match (true) {
                str_contains($model, 'whisper') || str_contains($model, 'transcribe') => t('Dictée vocale'),
                str_contains($model, 'tts') || str_contains($model, 'speech')          => t('Lecture à voix haute'),
                str_contains($model, 'embed')                                           => t('Recherche dans les bases'),
                default => $model !== '' ? $model : ucfirst($provider),
            };
        };
        // Anneaux et top 10 sur la MÊME base que le solde : ce qui entame le quota.
        // La maintenance hors quota (vectorisation des bases) reste dans la note du solde.
        $split   = UsageLog::quotaCreditsSplit($monthStart, $monthEnd, $userId);
        $top10   = UsageLog::topOperations($monthStart, $monthEnd, $userId, 10, true);
        $palette = ['#6366f1', '#10a37f', '#f59e0b', '#0ea5e9', '#ec4899', '#8b5cf6'];
        $ringUsage = PersonalUsage::ring(array_map(static fn(array $c): array => [
            'label' => t($c['label']), 'value' => (int)$c['credits'], 'color' => (string)$c['color'],
        ], PersonalUsage::quotaCategories($byCategory)), t('Autres'));
        $profileItems = [];
        foreach ($split['profiles'] as $i => $p) {
            $profileItems[] = [
                'label' => $p['profile_id'] === null ? t('Sans assistant') : ($p['name'] ?? t('Assistant supprimé')),
                'value' => $p['credits'],
                'color' => $p['profile_id'] === null ? '#94a3b8'
                    : (preg_match('/^#[0-9a-fA-F]{6}$/', (string)$p['color']) ? (string)$p['color'] : $palette[$i % count($palette)]),
            ];
        }
        $ringAssistants = PersonalUsage::ring($profileItems, t('Autres'));
        $modelItems = [];
        foreach ($split['models'] as $row) {
            $label = $modelLabel($row['provider'], $row['model']);
            $modelItems[$label] = ['label' => $label, 'value' => ($modelItems[$label]['value'] ?? 0) + $row['credits'], 'color' => ''];
        }
        $modelItems = array_values($modelItems);
        usort($modelItems, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
        foreach ($modelItems as $i => &$m) {
            $m['color'] = $palette[$i % count($palette)];
        }
        unset($m);
        $ringModels = PersonalUsage::ring($modelItems, t('Autres'));
        $unitCosts  = PersonalUsage::unitCosts($byCategory, $byTool);
        $tips       = PersonalUsage::tips($byCategory, UsageLog::responsesByModel($monthStart, $monthEnd, $userId), $modelLabel);

        require ROOT . '/templates/settings/usage.php';
    }

    // ---- Page Connexions (services externes) ----
    public function connections(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $user    = Session::currentUser();
        $success = Session::get('settings_success');
        $error   = Session::get('settings_error');
        Session::remove('settings_success');
        Session::remove('settings_error');

        // Visibilité décidée par l'administrateur, connecteur par connecteur
        // (page Connecteurs). Un connecteur masqué n'a plus de carte ici : la
        // proposer ferait relier un compte qui ne servirait à rien. Seule
        // survivance, volontaire : la carte de qui a DÉJÀ relié son compte,
        // pour qu'il garde le moyen de se déconnecter (cf. registre).
        // Droit « Connecteurs — Utiliser » du rôle (Personnes → Rôles) :
        // sans lui, aucune carte à relier — seulement celles des comptes DÉJÀ
        // reliés, en lecture seule, pour garder le moyen de se déconnecter.
        $connectorsAllowed = ConnectorRegistry::usableBy($user);
        $cardState = ConnectorRegistry::connectionCardState((int)$user['id'], [], $connectorsAllowed);
        $visible   = static fn(string $key): bool => !empty($cardState[$key]['visible']);

        $oauthConfigured = GoogleDriveOAuth::isConfigured();
        $driveFeatureEnabled = $connectorsAllowed && ConnectorRegistry::isEnabled(ConnectorRegistry::DRIVE);
        $driveVisible        = $visible(ConnectorRegistry::DRIVE);
        // Ligne épurée pour l'affichage : les tokens déchiffrés ne sortent
        // JAMAIS du contrôleur.
        $driveToken = UserDriveToken::forUser((int)$user['id']);
        $driveCanRead = $driveCanWriteDocs = $driveCanWriteSheets = $driveCanOrganize = $driveCanCopy = false;
        if ($driveToken !== null) {
            $scope = (string)($driveToken['scope'] ?? '');
            // La LECTURE aussi : elle était affichée comme acquise, alors
            // qu'elle se décoche sur l'écran Google comme les autres. Sans ce
            // badge, la seule manifestation d'une lecture non accordée était
            // un Drive qui paraît vide, sans la moindre explication.
            $driveCanRead        = UserDriveService::grantsRead($scope);
            // Comparaison EXACTE, déléguée au service (source unique) : chez
            // Google un scope est toujours le préfixe d'un scope plus étroit —
            // `drive.metadata` préfixe `drive.metadata.readonly`, et un
            // `str_contains` afficherait « Renommer ✓ » sur une connexion qui
            // ne peut rien ranger.
            $driveCanWriteDocs   = UserDriveService::grantsDocs($scope);
            $driveCanWriteSheets = UserDriveService::grantsSheets($scope);
            $driveCanOrganize    = UserDriveService::grantsOrganize($scope);
            $driveCanCopy        = UserDriveService::grantsCopy($scope);
            unset($driveToken['refresh_token'], $driveToken['access_token']);
        }

        $gmailFeatureEnabled = $connectorsAllowed && ConnectorRegistry::isEnabled(ConnectorRegistry::GMAIL);
        $gmailVisible        = $visible(ConnectorRegistry::GMAIL);
        $gmailToken = UserDriveToken::forUser((int)$user['id'], UserDriveToken::PROVIDER_GMAIL);
        $gmailCanRead = $gmailCanDraft = $gmailCanSend = $gmailCanSignature = false;
        if ($gmailToken !== null) {
            $scope = (string)($gmailToken['scope'] ?? '');
            $gmailCanRead  = str_contains($scope, UserGmailService::SCOPE_READONLY);
            $gmailCanDraft = str_contains($scope, UserGmailService::SCOPE_COMPOSE);
            // Le même scope couvre l'envoi (Google : « Manage drafts and send
            // emails ») — affiché à part pour que la personne le sache.
            $gmailCanSend  = $gmailCanDraft;
            // Comparaison EXACTE, partagée avec le service : deux comparaisons
            // divergentes afficheraient « Signature ✓ » sur une connexion qui
            // ne peut pas la lire.
            $gmailCanSignature = UserGmailService::scopeGranted($scope, UserGmailService::SCOPE_SETTINGS);
            unset($gmailToken['refresh_token'], $gmailToken['access_token']);
        }

        $calendarFeatureEnabled = $connectorsAllowed && ConnectorRegistry::isEnabled(ConnectorRegistry::CALENDAR);
        $calendarVisible        = $visible(ConnectorRegistry::CALENDAR);
        $calendarToken = UserDriveToken::forUser((int)$user['id'], UserDriveToken::PROVIDER_CALENDAR);
        $calendarCanRead = $calendarCanWrite = false;
        if ($calendarToken !== null) {
            $scope = (string)($calendarToken['scope'] ?? '');
            $calendarCanRead  = str_contains($scope, UserCalendarService::SCOPE_READONLY)
                || str_contains($scope, UserCalendarService::SCOPE_EVENTS);
            $calendarCanWrite = str_contains($scope, UserCalendarService::SCOPE_EVENTS);
            unset($calendarToken['refresh_token'], $calendarToken['access_token']);
        }

        $tasksFeatureEnabled = $connectorsAllowed && ConnectorRegistry::isEnabled(ConnectorRegistry::TASKS);
        $tasksVisible        = $visible(ConnectorRegistry::TASKS);
        $tasksToken = UserDriveToken::forUser((int)$user['id'], UserDriveToken::PROVIDER_TASKS);
        $tasksCanRead = $tasksCanWrite = false;
        if ($tasksToken !== null) {
            $scope = (string)($tasksToken['scope'] ?? '');
            // Comparaison EXACTE (cf. UserTasksService::scopeGranted) :
            // `…/auth/tasks` est un préfixe de `…/auth/tasks.readonly`.
            $tasksCanWrite = UserTasksService::scopeGranted($scope, UserTasksService::SCOPE_WRITE);
            $tasksCanRead  = $tasksCanWrite
                || UserTasksService::scopeGranted($scope, UserTasksService::SCOPE_READONLY);
            unset($tasksToken['refresh_token'], $tasksToken['access_token']);
        }
        // Préférences du connecteur : toujours chargées (elles survivent à une
        // déconnexion, l'écran doit pouvoir les ré-afficher).
        $tasksPrefs      = UserTasksService::prefsForUser((int)$user['id']);
        $tasksLists      = [];
        $tasksListsError = '';
        if ($tasksCanRead && ($tasksToken['status'] ?? '') === 'ok') {
            // Un <select> vaut mieux qu'un id à recopier à la main. L'appel est
            // best-effort : Google injoignable ne doit pas casser la page, le
            // gabarit retombe alors sur la valeur déjà enregistrée.
            try {
                $tasksService = UserTasksService::forUser((int)$user['id']);
                $tasksLists   = $tasksService !== null ? $tasksService->listTaskLists() : [];
            } catch (\Throwable $e) {
                $tasksListsError = $e->getMessage();
            }
        }

        // Outlook : famille Microsoft, mais exactement le même contrat que les
        // connecteurs Google — visibilité décidée par l'admin, jeton par
        // utilisateur, scopes réellement accordés relus depuis la colonne.
        $outlookFeatureEnabled = $connectorsAllowed && ConnectorRegistry::isEnabled(ConnectorRegistry::OUTLOOK);
        $outlookVisible        = $visible(ConnectorRegistry::OUTLOOK);
        $outlookToken = UserDriveToken::forUser((int)$user['id'], UserDriveToken::PROVIDER_OUTLOOK);
        $outlookCanRead = $outlookCanDraft = $outlookCanSend = false;
        if ($outlookToken !== null) {
            $scope = (string)($outlookToken['scope'] ?? '');
            // Comparaison EXACTE (cf. UserOutlookService::scopeGranted) :
            // `Mail.Read` est un préfixe de `Mail.ReadWrite` ET de
            // `Mail.ReadBasic`, qui n'ouvre pas le corps des messages.
            $outlookCanDraft = UserOutlookService::scopeGranted($scope, UserOutlookService::SCOPE_READWRITE);
            $outlookCanRead  = $outlookCanDraft
                || UserOutlookService::scopeGranted($scope, UserOutlookService::SCOPE_READ);
            // L'envoi exige Mail.Send ET le brouillon (même règle que
            // UserOutlookService::canSend) : une connexion antérieure à
            // l'arrivée du bouton « Envoyer » n'a pas Mail.Send.
            $outlookCanSend  = $outlookCanDraft
                && UserOutlookService::scopeGranted($scope, UserOutlookService::SCOPE_SEND);
            unset($outlookToken['refresh_token'], $outlookToken['access_token']);
        }

        // Calendrier Outlook : connecteur DISTINCT d'Outlook (autre jeton, autre
        // consentement) — un utilisateur peut n'avoir relié que son agenda.
        $msCalendarFeatureEnabled = $connectorsAllowed && ConnectorRegistry::isEnabled(ConnectorRegistry::MSCALENDAR);
        $msCalendarVisible        = $visible(ConnectorRegistry::MSCALENDAR);
        $msCalendarToken = UserDriveToken::forUser((int)$user['id'], UserDriveToken::PROVIDER_MSCALENDAR);
        $msCalendarCanRead = $msCalendarCanWrite = false;
        if ($msCalendarToken !== null) {
            $scope = (string)($msCalendarToken['scope'] ?? '');
            // Comparaison EXACTE (cf. MicrosoftUserApiService::scopeGranted) :
            // `Calendars.Read` est un préfixe de `Calendars.ReadWrite` ET de
            // `Calendars.ReadBasic`, qui ne rend que des créneaux occupé/libre.
            $msCalendarCanWrite = UserOutlookCalendarService::scopeGranted(
                $scope,
                UserOutlookCalendarService::SCOPE_READWRITE
            );
            $msCalendarCanRead  = $msCalendarCanWrite
                || UserOutlookCalendarService::scopeGranted($scope, UserOutlookCalendarService::SCOPE_READ);
            unset($msCalendarToken['refresh_token'], $msCalendarToken['access_token']);
        }

        // Microsoft To Do : connecteur DISTINCT (autre jeton, autre consentement).
        $msTodoFeatureEnabled = $connectorsAllowed && ConnectorRegistry::isEnabled(ConnectorRegistry::MSTODO);
        $msTodoVisible        = $visible(ConnectorRegistry::MSTODO);
        $msTodoToken = UserDriveToken::forUser((int)$user['id'], UserDriveToken::PROVIDER_MSTODO);
        $msTodoCanRead = $msTodoCanWrite = false;
        if ($msTodoToken !== null) {
            $scope = (string)($msTodoToken['scope'] ?? '');
            // Comparaison EXACTE (cf. MicrosoftUserApiService::scopeGranted) :
            // `Tasks.Read` est un préfixe de `Tasks.ReadWrite` ET de
            // `Tasks.Read.Shared`.
            $msTodoCanWrite = UserMicrosoftTodoService::scopeGranted($scope, UserMicrosoftTodoService::SCOPE_READWRITE);
            $msTodoCanRead  = $msTodoCanWrite
                || UserMicrosoftTodoService::scopeGranted($scope, UserMicrosoftTodoService::SCOPE_READ);
            unset($msTodoToken['refresh_token'], $msTodoToken['access_token']);
        }

        // Microsoft Teams : connecteur DISTINCT, dont la lecture des canaux
        // dépend d'un consentement ADMINISTRATEUR — d'où des capacités lues une
        // à une (les conversations peuvent marcher sans les canaux). Mêmes
        // statiques que le service (comparaison exacte des scopes).
        $teamsFeatureEnabled = $connectorsAllowed && ConnectorRegistry::isEnabled(ConnectorRegistry::TEAMS);
        $teamsVisible        = $visible(ConnectorRegistry::TEAMS);
        $teamsToken = UserDriveToken::forUser((int)$user['id'], UserDriveToken::PROVIDER_TEAMS);
        $teamsCanReadChats = $teamsCanReadChannels = $teamsCanSend = false;
        if ($teamsToken !== null) {
            $scope = (string)($teamsToken['scope'] ?? '');
            $teamsCanReadChats    = UserTeamsService::grantsReadChats($scope);
            $teamsCanReadChannels = UserTeamsService::grantsReadChannels($scope);
            $teamsCanSend         = UserTeamsService::grantsSendChats($scope) || UserTeamsService::grantsSendChannels($scope);
            unset($teamsToken['refresh_token'], $teamsToken['access_token']);
        }

        // Slack : troisième famille, même contrat que les deux autres. Les
        // capacités sont lues sur la colonne `scope`, avec la comparaison de
        // SlackUserApiService — les scopes Slack sont séparés par des VIRGULES,
        // et `chat:write` est un préfixe de `chat:write.public`.
        $slackFeatureEnabled = $connectorsAllowed && ConnectorRegistry::isEnabled(ConnectorRegistry::SLACK);
        $slackVisible        = $visible(ConnectorRegistry::SLACK);
        $slackToken = UserDriveToken::forUser((int)$user['id'], UserDriveToken::PROVIDER_SLACK);
        $slackCanRead = $slackCanSearch = $slackCanPost = false;
        if ($slackToken !== null) {
            $scope = (string)($slackToken['scope'] ?? '');
            $slackCanSearch = UserSlackService::scopeGranted($scope, UserSlackService::SCOPE_SEARCH);
            $slackCanRead   = UserSlackService::anyScopeGranted($scope, UserSlackService::SCOPES_HISTORY);
            $slackCanPost   = UserSlackService::scopeGranted($scope, UserSlackService::SCOPE_WRITE);
            unset($slackToken['refresh_token'], $slackToken['access_token']);
        }

        // OneDrive : connexion Microsoft déléguée, utilisée par les bases de
        // connaissances (navigation + miroir d'un dossier). Comme les autres,
        // l'administrateur peut la masquer ; s'y ajoute son prérequis propre,
        // l'application Azure (Capacités → Sources synchronisées).
        $oneDriveFeatureEnabled = ConnectorRegistry::isEnabled(ConnectorRegistry::ONEDRIVE)
            && MicrosoftOAuth::isConfigured();
        $oneDriveToken = UserDriveToken::forUser((int)$user['id'], UserDriveToken::PROVIDER_ONEDRIVE);
        if ($oneDriveToken !== null) {
            unset($oneDriveToken['refresh_token'], $oneDriveToken['access_token']);
        }
        // Cette connexion ne sert qu'à bâtir une base de connaissances : la
        // proposer à quelqu'un qui n'en gère aucune serait une option morte.
        // Elle reste visible pour qui l'a déjà connectée (droit retiré depuis)
        // — sinon plus aucun moyen de se déconnecter.
        $oneDriveManager = ConnectorRegistry::oneDriveUsefulFor((string)($user['role'] ?? ''));
        $oneDriveVisible = ($oneDriveFeatureEnabled && $oneDriveManager) || $oneDriveToken !== null;

        // Aucune carte à montrer : le dire, plutôt que laisser une page nue.
        $anyConnectionVisible = $driveVisible || $gmailVisible || $calendarVisible
            || $tasksVisible || $outlookVisible || $msCalendarVisible || $msTodoVisible || $teamsVisible || $slackVisible || $oneDriveVisible;

        require ROOT . '/templates/settings/connections.php';
    }

    // ---- Connecteurs personnels (Drive, Gmail, Agenda, Tasks, Outlook, Calendrier Outlook) ----
    //
    // La mécanique connect / callback / disconnect est STRICTEMENT la même
    // pour tous, Google comme Microsoft : classe OAuth, provider de jeton et
    // libellé viennent de ConnectorRegistry (source unique), et les deux
    // socles OAuth exposent la même API publique. Les méthodes publiques par
    // connecteur subsistent en délégués d'une ligne — ce sont elles que les
    // routes de public/index.php nomment.

    /** Lancement du consentement OAuth d'un connecteur personnel. */
    private function connectorConnect(string $key): void
    {
        AuthMiddleware::requireAuth();

        // Relier un compte demande le droit « Connecteurs — Utiliser » du rôle.
        if (!ConnectorRegistry::usableBy(Session::currentUser())) {
            Session::set('settings_error', t('Votre rôle ne vous permet pas de relier vos comptes à MIA. Un administrateur peut l’ouvrir dans Personnes → Rôles.'));
            header('Location: /settings/connections');
            exit;
        }

        $oauth = ConnectorRegistry::oauthClass($key);
        if ($oauth === null || !$oauth::isConfigured()) {
            Session::set(
                'settings_error',
                'Connexion ' . ConnectorRegistry::label($key) . ' non configurée sur cette instance.'
            );
            header('Location: /settings/connections');
            exit;
        }

        // App mobile : le consentement doit s'ouvrir dans le NAVIGATEUR
        // SYSTÈME (WebView interdite par Google, et bloquée par Microsoft sur
        // certains comptes) → state porteur signé, et mode JSON pour que le
        // pont natif récupère l'URL et l'ouvre lui-même.
        if (NativeApp::isNative()) {
            $user = Session::currentUser();
            $url  = $oauth::getAuthUrlNative((int)$user['id']);
            if (($_GET['format'] ?? '') === 'json') {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(['url' => $url]);
                exit;
            }
            header('Location: ' . $url);
            exit;
        }

        header('Location: ' . $oauth::getAuthUrl());
        exit;
    }

    /** Retour du consentement OAuth d'un connecteur personnel. */
    private function connectorCallback(string $key): void
    {
        $oauth = ConnectorRegistry::oauthClass($key);
        $label = ConnectorRegistry::label($key);

        // Flux natif (app mobile) : retour dans le navigateur SYSTÈME, sans
        // session — l'utilisateur est identifié par le state signé.
        $state = (string)($_GET['state'] ?? '');
        if (NativeOauthState::isNative($oauth::STATE_PREFIX, $state)) {
            $this->nativeOauthCallback($key, $state);
            return;
        }

        AuthMiddleware::requireAuth();

        $user = Session::currentUser();

        if (($_GET['error'] ?? '') !== '') {
            // access_denied = refus utilisateur sur l'écran de consentement.
            // Côté Microsoft, error_description porte un code AADSTS qui nomme
            // la vraie cause (URI non déclarée, permission refusée par
            // l'annuaire…) là où « annulée » enverrait chercher au mauvais
            // endroit — le socle Microsoft sait le traduire, Google n'a pas
            // d'équivalent.
            $desc = (string)($_GET['error_description'] ?? '');
            Session::set(
                'settings_error',
                str_contains($desc, 'AADSTS') && method_exists($oauth, 'authErrorMessage')
                    ? $oauth::authErrorMessage($desc)
                    : 'Connexion ' . $label . ' annulée.'
            );
            header('Location: /settings/connections');
            exit;
        }

        $result = $oauth::handleCallback((string)($_GET['code'] ?? ''), $state);
        if ($result === null) {
            // Échange refusé par Microsoft (secret invalide ou expiré, URI non
            // déclarée…) : la cause lue au endpoint token plutôt qu'un « échec ».
            $cause = method_exists($oauth, 'lastError') ? $oauth::lastError() : null;
            Session::set('settings_error', $cause ?? 'Échec de la connexion ' . $label . '. Réessayez.');
            header('Location: /settings/connections');
            exit;
        }

        UserDriveToken::saveConnection(
            (int)$user['id'],
            $result['refresh_token'],
            $result['access_token'],
            $result['expires_in'],
            $result['scope'],
            $result['email'],
            (string)ConnectorRegistry::tokenProvider($key)
        );

        Session::set(
            'settings_success',
            $label . ' connecté' . ($result['email'] !== null ? ' (' . $result['email'] . ')' : '') . '.'
        );
        header('Location: /settings/connections');
        exit;
    }

    /**
     * Déconnexion d'un connecteur personnel : révocation best-effort du
     * refresh token puis suppression du jeton. $message remplace le
     * « <label> déconnecté. » par défaut (Tasks y annonce ses préférences
     * conservées).
     *
     * La révocation est un no-op côté Microsoft (aucun endpoint de révocation
     * de jeton délégué n'existe) : c'est la suppression en base qui coupe
     * l'accès, et l'utilisateur retire l'autorisation depuis son compte
     * Microsoft s'il le souhaite.
     */
    private function connectorDisconnect(string $key, ?string $message = null): void
    {
        AuthMiddleware::requireAuth();

        $user     = Session::currentUser();
        $provider = (string)ConnectorRegistry::tokenProvider($key);
        $row      = UserDriveToken::forUser((int)$user['id'], $provider);
        if ($row !== null && (string)$row['refresh_token'] !== '') {
            $oauth = ConnectorRegistry::oauthClass($key);
            $oauth::revoke((string)$row['refresh_token']); // best-effort
        }
        UserDriveToken::delete((int)$user['id'], $provider);

        Session::set('settings_success', $message ?? (ConnectorRegistry::label($key) . ' déconnecté.'));
        header('Location: /settings/connections');
        exit;
    }

    public function driveConnect(): void
    {
        $this->connectorConnect(ConnectorRegistry::DRIVE);
    }

    public function driveCallback(): void
    {
        $this->connectorCallback(ConnectorRegistry::DRIVE);
    }

    // ---- Lancement du consentement OAuth Microsoft (OneDrive) ----
    public function oneDriveConnect(): void
    {
        AuthMiddleware::requireAuth();

        // OneDrive ne sert qu'à alimenter une base : qui relie ses comptes ET
        // crée ou modifie des bases (Personnes → Rôles).
        if (!ConnectorRegistry::oneDriveUsefulFor((string)(Session::currentUser()['role'] ?? ''))) {
            Session::set('settings_error', t('Votre rôle ne vous permet pas de relier OneDrive : il ne sert qu’à alimenter une base de connaissances.'));
            header('Location: /settings/connections');
            exit;
        }

        if (!MicrosoftOAuth::isConfigured()) {
            Session::set('settings_error', 'Connexion Microsoft non configurée sur cette instance.');
            header('Location: /settings/connections');
            exit;
        }

        // App mobile : le consentement s'ouvre dans le NAVIGATEUR SYSTÈME, qui
        // n'a pas la session de la WebView — d'où le state PORTEUR signé, exactement
        // comme les connecteurs Google (Microsoft bloque d'ailleurs les WebView
        // embarquées sur certains comptes).
        if (NativeApp::isNative()) {
            $user = Session::currentUser();
            $url  = MicrosoftOAuth::getAuthUrlNative((int)$user['id']);
            if (($_GET['format'] ?? '') === 'json') {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(['url' => $url]);
                exit;
            }
            header('Location: ' . $url);
            exit;
        }

        header('Location: ' . MicrosoftOAuth::getAuthUrl());
        exit;
    }

    // ---- Retour du consentement OAuth Microsoft (OneDrive) ----
    public function oneDriveCallback(): void
    {
        // Flux natif (app mobile) : retour dans le navigateur SYSTÈME, sans
        // session — l'utilisateur est identifié par le state signé.
        $state = (string)($_GET['state'] ?? '');
        if (NativeOauthState::isNative(MicrosoftOAuth::STATE_PREFIX, $state)) {
            $this->nativeOauthCallback('onedrive', $state);
            return;
        }

        AuthMiddleware::requireAuth();

        $user = Session::currentUser();

        if (($_GET['error'] ?? '') !== '') {
            // Le corps d'erreur Microsoft porte le code AADSTS : il nomme la
            // cause réelle (URI de redirection non déclarée, consentement
            // refusé…) là où « annulée » enverrait chercher au mauvais endroit.
            $desc = (string)($_GET['error_description'] ?? '');
            Session::set(
                'settings_error',
                str_contains($desc, 'AADSTS')
                    ? MicrosoftOAuth::authErrorMessage($desc)
                    : 'Connexion OneDrive annulée.'
            );
            header('Location: /settings/connections');
            exit;
        }

        $result = MicrosoftOAuth::handleCallback(
            (string)($_GET['code'] ?? ''),
            (string)($_GET['state'] ?? '')
        );
        if ($result === null) {
            Session::set('settings_error', MicrosoftOAuth::lastError() ?? 'Échec de la connexion OneDrive. Réessayez.');
            header('Location: /settings/connections');
            exit;
        }

        UserDriveToken::saveConnection(
            (int)$user['id'],
            $result['refresh_token'],
            $result['access_token'],
            $result['expires_in'],
            $result['scope'],
            $result['email'],
            UserDriveToken::PROVIDER_ONEDRIVE
        );

        Session::set(
            'settings_success',
            'OneDrive connecté' . ($result['email'] !== null ? ' (' . $result['email'] . ')' : '') . '.'
        );
        header('Location: /settings/connections');
        exit;
    }

    // ---- Déconnexion OneDrive ----
    public function oneDriveDisconnect(): void
    {
        AuthMiddleware::requireAuth();

        $user = Session::currentUser();
        UserDriveToken::delete((int)$user['id'], UserDriveToken::PROVIDER_ONEDRIVE);

        Session::set(
            'settings_success',
            'OneDrive déconnecté. Les bases de connaissances qui lisaient un dossier avec cette '
            . 'connexion cesseront de se synchroniser.'
        );
        header('Location: /settings/connections');
        exit;
    }

    public function driveDisconnect(): void
    {
        $this->connectorDisconnect(ConnectorRegistry::DRIVE);
    }

    public function gmailConnect(): void
    {
        $this->connectorConnect(ConnectorRegistry::GMAIL);
    }

    /**
     * Retour OAuth du flux NATIF (connecteur Google ou OneDrive) : s'exécute
     * dans le navigateur système, sans session. L'utilisateur vient du state
     * signé (usage unique + TTL). Rend une page publique de confirmation — pas
     * de redirection vers /settings/connections (elle exigerait la session).
     *
     * $provider = clé ConnectorRegistry (drive, gmail, calendar, tasks,
     * outlook — tous résolus par le registre, quel que soit leur fournisseur)
     * OU 'onedrive', dont le libellé et le jeton sont nommés en dur : sa carte
     * vit hors du menu « + » et son OAuth (MicrosoftOAuth) n'est pas une
     * sous-classe du socle des connecteurs.
     */
    private function nativeOauthCallback(string $provider, string $state): void
    {
        if ($provider === 'onedrive') {
            [$oauth, $label, $tokenProvider] = [MicrosoftOAuth::class, 'OneDrive', UserDriveToken::PROVIDER_ONEDRIVE];
        } else {
            $oauth         = (string)ConnectorRegistry::oauthClass($provider);
            $label         = ConnectorRegistry::label($provider);
            $tokenProvider = (string)ConnectorRegistry::tokenProvider($provider);
        }

        $userId = NativeOauthState::consume($oauth::STATE_PREFIX, $state);
        if ($userId === null) {
            $this->renderOauthDone(false, 'Lien de connexion expiré ou invalide. Relancez la connexion depuis l\'application.');
            return;
        }
        if (($_GET['error'] ?? '') !== '') {
            // Même traduction que le flux web : chez Microsoft, la description
            // porte un code AADSTS qui nomme la cause (consentement interdit,
            // compte non attribué…) — « annulée » enverrait chercher au mauvais
            // endroit. Google n'a pas d'équivalent.
            $desc = (string)($_GET['error_description'] ?? '');
            $this->renderOauthDone(
                false,
                str_contains($desc, 'AADSTS') && method_exists($oauth, 'authErrorMessage')
                    ? $oauth::authErrorMessage($desc)
                    : 'Connexion ' . $label . ' annulée.'
            );
            return;
        }

        $result = $oauth::handleCallbackNative((string)($_GET['code'] ?? ''));
        if ($result === null) {
            $cause = method_exists($oauth, 'lastError') ? $oauth::lastError() : null;
            $this->renderOauthDone(false, $cause ?? 'Échec de la connexion ' . $label . '. Réessayez depuis l\'application.');
            return;
        }

        UserDriveToken::saveConnection(
            $userId,
            $result['refresh_token'],
            $result['access_token'],
            $result['expires_in'],
            $result['scope'],
            $result['email'],
            $tokenProvider
        );

        error_log('[' . $label . ' OAuth] connexion native réussie pour user #' . $userId);
        $this->renderOauthDone(
            true,
            $label . ' connecté' . ($result['email'] !== null ? ' (' . $result['email'] . ')' : '') . '.'
        );
    }

    /** Page publique de fin de flux OAuth natif (navigateur système). */
    private function renderOauthDone(bool $ok, string $message): void
    {
        require ROOT . '/templates/settings/oauth-done.php';
    }

    public function gmailCallback(): void
    {
        $this->connectorCallback(ConnectorRegistry::GMAIL);
    }

    public function gmailDisconnect(): void
    {
        $this->connectorDisconnect(ConnectorRegistry::GMAIL);
    }

    public function calendarConnect(): void
    {
        $this->connectorConnect(ConnectorRegistry::CALENDAR);
    }

    public function calendarCallback(): void
    {
        $this->connectorCallback(ConnectorRegistry::CALENDAR);
    }

    public function calendarDisconnect(): void
    {
        $this->connectorDisconnect(ConnectorRegistry::CALENDAR);
    }

    public function tasksConnect(): void
    {
        $this->connectorConnect(ConnectorRegistry::TASKS);
    }

    public function tasksCallback(): void
    {
        $this->connectorCallback(ConnectorRegistry::TASKS);
    }

    public function tasksDisconnect(): void
    {
        // Les PRÉFÉRENCES survivent volontairement à la déconnexion : elles
        // vivent dans une table à part, et les retrouver après une reconnexion
        // (souvent faite pour élargir un scope) évite de tout re-saisir.
        $this->connectorDisconnect(
            ConnectorRegistry::TASKS,
            'Google Tasks déconnecté. Vos préférences sont conservées.'
        );
    }

    // ---- Connecteur Outlook (famille Microsoft, même socle de contrôleur) ----

    public function outlookConnect(): void
    {
        $this->connectorConnect(ConnectorRegistry::OUTLOOK);
    }

    public function outlookCallback(): void
    {
        $this->connectorCallback(ConnectorRegistry::OUTLOOK);
    }

    public function outlookDisconnect(): void
    {
        // Microsoft n'offre aucune révocation de jeton délégué : le dire, sinon
        // l'utilisateur croit avoir tout coupé côté Microsoft aussi.
        $this->connectorDisconnect(
            ConnectorRegistry::OUTLOOK,
            'Outlook déconnecté. MIA n\'a plus accès à votre messagerie. Pour retirer aussi '
            . 'l\'autorisation côté Microsoft, rendez-vous sur myaccount.microsoft.com.'
        );
    }

    // ---- Connecteur Calendrier Outlook (famille Microsoft) ----

    public function mscalendarConnect(): void
    {
        $this->connectorConnect(ConnectorRegistry::MSCALENDAR);
    }

    public function mscalendarCallback(): void
    {
        $this->connectorCallback(ConnectorRegistry::MSCALENDAR);
    }

    public function mscalendarDisconnect(): void
    {
        // Même remarque que pour Outlook : rien à révoquer côté Microsoft.
        $this->connectorDisconnect(
            ConnectorRegistry::MSCALENDAR,
            'Calendrier Outlook déconnecté. MIA n\'a plus accès à vos évènements. Pour retirer aussi '
            . 'l\'autorisation côté Microsoft, rendez-vous sur myaccount.microsoft.com.'
        );
    }

    // ---- Connecteur Microsoft To Do (famille Microsoft) ----

    public function mstodoConnect(): void
    {
        $this->connectorConnect(ConnectorRegistry::MSTODO);
    }

    public function mstodoCallback(): void
    {
        $this->connectorCallback(ConnectorRegistry::MSTODO);
    }

    public function mstodoDisconnect(): void
    {
        // Même remarque que pour Outlook : rien à révoquer côté Microsoft.
        $this->connectorDisconnect(
            ConnectorRegistry::MSTODO,
            'Microsoft To Do déconnecté. MIA n\'a plus accès à vos tâches. Pour retirer aussi '
            . 'l\'autorisation côté Microsoft, rendez-vous sur myaccount.microsoft.com.'
        );
    }

    // ---- Connecteur Microsoft Teams (famille Microsoft) ----

    public function teamsConnect(): void
    {
        $this->connectorConnect(ConnectorRegistry::TEAMS);
    }

    public function teamsCallback(): void
    {
        $this->connectorCallback(ConnectorRegistry::TEAMS);
    }

    public function teamsDisconnect(): void
    {
        // Même remarque que pour Outlook : rien à révoquer côté Microsoft.
        $this->connectorDisconnect(
            ConnectorRegistry::TEAMS,
            'Microsoft Teams déconnecté. MIA n\'a plus accès à vos conversations ni à vos canaux. Pour retirer '
            . 'aussi l\'autorisation côté Microsoft, rendez-vous sur myaccount.microsoft.com.'
        );
    }

    // ---- Connecteur Slack (famille slack, même socle de contrôleur) ----

    public function slackConnect(): void
    {
        $this->connectorConnect(ConnectorRegistry::SLACK);
    }

    public function slackCallback(): void
    {
        $this->connectorCallback(ConnectorRegistry::SLACK);
    }

    public function slackDisconnect(): void
    {
        // Slack, lui, révoque vraiment (auth.revoke, appelé par
        // connectorDisconnect) : contrairement à Microsoft, il n'y a rien à
        // aller retirer ailleurs — le dire évite une démarche inutile.
        $this->connectorDisconnect(
            ConnectorRegistry::SLACK,
            'Slack déconnecté. L\'autorisation a été retirée côté Slack : MIA ne peut plus ni lire vos '
            . 'conversations ni publier en votre nom.'
        );
    }

    /**
     * Préférences utilisateur du connecteur Google Tasks (liste par défaut,
     * tâches terminées, horizon d'échéance, suppression autorisée, consignes
     * libres). Écran auto-enregistré : double sortie JSON / flash + redirect.
     */
    public function saveTasksPrefs(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $asJson = AjaxResponse::wanted();
        $user   = Session::currentUser();

        // La liste par défaut est choisie dans un <select> dont les options
        // portent l'id ET le nom : on ne fait pas confiance au nom envoyé, mais
        // il n'est qu'un libellé d'affichage (le service travaille sur l'id).
        $prefs = UserTasksService::savePrefsForUser((int)$user['id'], [
            UserTasksService::PREF_LIST_ID      => (string)($_POST['liste_defaut_id'] ?? ''),
            UserTasksService::PREF_LIST_NAME    => (string)($_POST['liste_defaut_nom'] ?? ''),
            // Cases absentes du POST = décochées (convention maison `?? 0`).
            UserTasksService::PREF_HIDE_DONE    => (string)($_POST['masquer_terminees'] ?? '0'),
            UserTasksService::PREF_ALLOW_DELETE => (string)($_POST['autoriser_suppression'] ?? '0'),
            UserTasksService::PREF_HORIZON      => (string)($_POST['horizon_jours'] ?? '0'),
            // Règles : la map id => texte du formulaire (une règle vidée = une
            // règle supprimée), plus l'éventuelle nouvelle saisie. Un seul POST,
            // pas de route de suppression à part.
            UserTasksService::PREF_RULES        => array_merge(
                is_array($_POST['regles'] ?? null) ? $_POST['regles'] : [],
                [(string)($_POST['nouvelle_regle'] ?? '')]
            ),
            // Champ hérité : encore posté par une page ouverte avant la bascule
            // vers les règles — absorbé plutôt que perdu.
            UserTasksService::PREF_NOTES        => (string)($_POST['consignes'] ?? ''),
        ]);

        $this->respondSettings(
            $asJson,
            true,
            'Préférences Google Tasks enregistrées.',
            '/settings/connections',
            ['prefs' => $prefs]
        );
    }

    // ---- Changement de mot de passe ----
    public function updatePassword(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();

        $user = Session::currentUser();
        $id   = (int)$user['id'];

        $current    = $_POST['current_password'] ?? '';
        $new        = $_POST['new_password'] ?? '';
        $confirm    = $_POST['confirm_password'] ?? '';

        // Utilisateurs Google sans mot de passe local
        if (empty($user['password_hash'])) {
            Session::set('settings_error', 'Votre compte est lié à Google. Vous ne pouvez pas définir de mot de passe local ici.');
            header('Location: /settings');
            exit;
        }

        if (!password_verify($current, $user['password_hash'])) {
            Session::set('settings_error', 'Mot de passe actuel incorrect.');
            header('Location: /settings');
            exit;
        }

        if (strlen($new) < 8) {
            Session::set('settings_error', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
            header('Location: /settings');
            exit;
        }

        if ($new !== $confirm) {
            Session::set('settings_error', 'Les deux mots de passe ne correspondent pas.');
            header('Location: /settings');
            exit;
        }

        User::updatePassword($id, $new);

        Session::set('settings_success', 'Mot de passe modifié avec succès.');
        header('Location: /settings');
        exit;
    }
}
