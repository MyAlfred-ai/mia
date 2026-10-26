<?php

declare(strict_types=1);

/**
 * Accès à la boîte Gmail PERSONNELLE d'un utilisateur (OAuth par utilisateur,
 * tokens UserDriveToken provider 'gmail') : recherche et lecture d'emails
 * (scope gmail.readonly), création de BROUILLONS et, sur le seul bouton
 * « Envoyer » de la carte de relecture, ENVOI du message relu (scope
 * gmail.compose, que Google décrit « Manage drafts and send emails »).
 *
 * Aucun outil du modèle n'envoie quoi que ce soit : `sendMessage` n'est
 * appelée que par ChatController::driveAction sur la décision `send`, après
 * confirmation de l'utilisateur. Le droit existe côté API dès qu'un brouillon
 * est possible — c'est donc le CODE qui tient la promesse « rien ne part
 * sans votre clic », comme pour la corbeille du Drive ou la suppression des
 * tâches ; `gmail.send` n'est jamais demandé, il n'ajouterait rien.
 *
 * Plomberie commune (refresh lazy, retry 401, erreurs FR sans fuite) :
 * GoogleUserApiService — ce fichier ne porte que le métier Gmail.
 */
class UserGmailService extends GoogleUserApiService
{
    private const API_BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/gmail.readonly';
    public const SCOPE_COMPOSE  = 'https://www.googleapis.com/auth/gmail.compose';
    /**
     * Lecture SEULE des réglages de la boîte — sert uniquement à récupérer la
     * signature par défaut de l'utilisateur. Aucun droit d'écriture : le scope
     * `gmail.settings.basic` couvre l'écriture des réglages, mais MIA n'appelle
     * que `sendAs.list`, et aucune méthode de cette classe n'écrit un réglage.
     */
    public const SCOPE_SETTINGS = 'https://www.googleapis.com/auth/gmail.settings.basic';

    /**
     * Frontière des parts MIME. Constante (donc le MIME produit est
     * reproductible et testable) sans risque de collision : les deux parts
     * sont encodées en base64, dont l'alphabet ne contient ni `=` ni `_`.
     */
    private const MIME_MIXED_BOUNDARY = 'mia-mixed-7d1c0e5b9a';
    private const MIME_BOUNDARY = '=_mia_alt_boundary_=';
    /** Corps + images intégrées (`cid`) : multipart/related. */
    private const MIME_RELATED_BOUNDARY = 'mia-related-3f8a1c2e6b';

    /** Clé Settings : interrupteur global admin du Gmail utilisateur dans le chat. */
    public const SETTING_ENABLED = 'user_gmail_enabled';

    public const MSG_RECONNECT = 'Connexion Gmail expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter son Gmail dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_DRAFT = 'Autorisation de création de brouillons non accordée. '
        . 'L\'utilisateur doit reconnecter son Gmail dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_SEND = 'Autorisation d\'envoi non accordée. '
        . 'L\'utilisateur doit reconnecter son Gmail dans Réglages → Comptes connectés.';

    protected const OAUTH_CLASS       = GoogleGmailOAuth::class;
    protected const TOKEN_PROVIDER    = UserDriveToken::PROVIDER_GMAIL;
    protected const API_LABEL         = 'Gmail';
    protected const LOG_LABEL         = '[Gmail user]';
    protected const MSG_NOT_FOUND     = 'Message introuvable dans votre boîte Gmail (supprimé ou identifiant invalide).';
    protected const MSG_BAD_REQUEST   = 'Requête refusée par Gmail (HTTP 400) — vérifiez la syntaxe de recherche ou les adresses fournies.';
    protected const MSG_ACCESS_DENIED = 'Accès refusé par Gmail';
    protected const MSG_SCOPE_INSUFFICIENT = self::MSG_RECONNECT_DRAFT;

    /** Instance pour l'utilisateur, ou null si Gmail non connecté (ou révoqué). */
    public static function forUser(int $userId): ?self
    {
        $row = self::tokenRowForUser($userId);
        if ($row === null) {
            return null;
        }
        return new self(
            $userId,
            (string)$row['refresh_token'],
            $row['access_token'] !== null ? (string)$row['access_token'] : null,
            $row['access_token_expires_at'] !== null ? (string)$row['access_token_expires_at'] : null,
            (string)($row['scope'] ?? ''),
            (string)($row['google_email'] ?? '')
        );
    }

    /** Scope lecture accordé lors du consentement ? */
    public function canRead(): bool
    {
        return str_contains($this->grantedScope, self::SCOPE_READONLY);
    }

    /** Scope création de brouillons accordé lors du consentement ? */
    public function canDraft(): bool
    {
        return str_contains($this->grantedScope, self::SCOPE_COMPOSE);
    }

    /**
     * Envoi possible ? Le MÊME scope que le brouillon : `gmail.compose`
     * couvre `messages.send` (tableau des scopes Gmail : « Manage drafts and
     * send emails »). La page Connexions l'affiche à part (badge « Envoi »)
     * pour que la personne sache ce que son consentement permet.
     */
    public function canSend(): bool
    {
        return $this->canDraft();
    }

    /** Signature déjà lue pour cette instance ('' = lue et vide, null = pas encore). */
    private ?string $signatureCache = null;

    /**
     * La boîte peut-elle être interrogée pour sa signature ?
     *
     * Comparaison EXACTE (socle) plutôt que `str_contains` — la règle vaut pour
     * tous les connecteurs depuis le piège de préfixe payé sur Tasks et
     * Outlook, et une exception « parce qu'ici c'est sans danger » est
     * exactement ainsi qu'elle revient.
     *
     * Faux pour toute connexion antérieure à l'arrivée de la signature : le
     * brouillon part alors sans elle, jamais en erreur.
     */
    public function canReadSettings(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_SETTINGS);
    }

    /**
     * Signature HTML par défaut de la boîte, '' si aucune (ou si le scope n'a
     * pas été accordé). Best-effort ABSOLU : une signature manquante ne doit
     * jamais faire échouer la création d'un brouillon.
     *
     * `sendAs.list` renvoie toutes les identités d'envoi ; on retient celle du
     * compte connecté, sinon celle marquée par défaut — sur une boîte à alias,
     * prendre la première venue signerait du mauvais nom.
     */
    public function defaultSignatureHtml(): string
    {
        if ($this->signatureCache !== null) {
            return $this->signatureCache;
        }
        $this->signatureCache = '';
        if (!$this->canReadSettings()) {
            return '';
        }
        try {
            $data = $this->apiJson('GET', self::API_BASE . '/settings/sendAs');
        } catch (\RuntimeException $e) {
            error_log('[Gmail user] signature illisible : ' . $e->getMessage());
            return '';
        }
        $parDefaut = '';
        foreach ((array)($data['sendAs'] ?? []) as $identite) {
            $sig = trim((string)($identite['signature'] ?? ''));
            if ($sig === '') {
                continue;
            }
            if ($this->accountEmail !== ''
                && strcasecmp((string)($identite['sendAsEmail'] ?? ''), $this->accountEmail) === 0) {
                $this->signatureCache = $sig;
                return $sig;
            }
            if (!empty($identite['isDefault']) && $parDefaut === '') {
                $parDefaut = $sig;
            }
        }
        $this->signatureCache = $parDefaut;
        return $parDefaut;
    }

    /**
     * Recherche des emails (syntaxe Gmail native : from:, subject:, after:…).
     * Retour : liste `{id, de, objet, date, extrait}` (ordre Gmail = plus
     * récents d'abord). Throw RuntimeException à message FR.
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \RuntimeException('Requête de recherche vide.');
        }
        $limit = max(1, min(20, $limit));

        $list = $this->apiJson(
            'GET',
            self::API_BASE . '/messages?maxResults=' . $limit . '&q=' . rawurlencode($query)
        );

        $out = [];
        foreach (($list['messages'] ?? []) as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            // metadataHeaders répété : http_build_query produirait
            // metadataHeaders[0]= → query construite à la main.
            $meta = $this->apiJson(
                'GET',
                self::API_BASE . '/messages/' . rawurlencode($id)
                    . '?format=metadata'
                    . '&metadataHeaders=From&metadataHeaders=To&metadataHeaders=Subject&metadataHeaders=Date'
            );
            $headers = self::headerMap($meta['payload']['headers'] ?? []);
            $out[] = [
                'id'      => $id,
                'de'      => $headers['from'] ?? '',
                'objet'   => $headers['subject'] ?? '',
                'date'    => $headers['date'] ?? '',
                'extrait' => html_entity_decode((string)($meta['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }
        return $out;
    }

    /**
     * Lit un email complet : expéditeur, destinataires, objet, date et corps
     * texte (part text/plain préférée, fallback HTML strippé), rendu PAR
     * PARTIES au budget (≈ tokens × 4 caractères, TextPager) : `partie` lit la
     * suite d'un long email.
     *
     * @return array{de:string,a:string,objet:string,date:string,contenu:string,tronque:bool,partie:int,parties:int}
     */
    public function readMessage(string $messageId, int $tokenBudget, int $part = 1): array
    {
        $messageId = self::assertMessageId($messageId);

        $data    = $this->apiJson('GET', self::API_BASE . '/messages/' . rawurlencode($messageId) . '?format=full');
        $headers = self::headerMap($data['payload']['headers'] ?? []);
        $text    = self::extractTextFromPayload($data['payload'] ?? []);
        // Pièces jointes : listées pour que le modèle sache qu'elles existent
        // et puisse en lire une (lire_piece_jointe_gmail), par numéro ou nom.
        $attachments = MailAttachments::fromGmailPayload(is_array($data['payload'] ?? null) ? $data['payload'] : []);
        if (trim($text) === '') {
            // Dernier recours : l'extrait Gmail (email 100 % image, etc.).
            $text = html_entity_decode((string)($data['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (trim($text) === '' && $attachments !== []) {
            // Message réduit à ses pièces jointes : ce n'est pas une erreur,
            // l'information est dans les fichiers.
            $text = '(message sans texte : voir les pièces jointes)';
        }
        if (trim($text) === '') {
            throw new \RuntimeException('Aucun texte extractible dans ce message.');
        }

        $out = [
            // L'identifiant EST la clé d'une réponse dans le fil : sans lui,
            // `creer_brouillon_gmail` ne peut que fabriquer une conversation
            // neuve, ce qui était précisément le symptôme constaté.
            'id'      => (string)($data['id'] ?? $messageId),
            'fil'     => (string)($data['threadId'] ?? ''),
            'de'      => $headers['from'] ?? '',
            'a'       => $headers['to'] ?? '',
            'objet'   => $headers['subject'] ?? '',
            'date'    => $headers['date'] ?? '',
        ] + TextPager::page($text, max(1000, $tokenBudget * 4), $part);
        if ($attachments !== []) {
            $out['pieces_jointes'] = MailAttachments::forModel($attachments);
        }
        return $out;
    }

    /**
     * Recherche LONGUE (boucles de workflow) : jusqu'à `$limit` emails
     * (100 au plus), mêmes résultats que search(). Une lecture des en-têtes
     * par email : la boucle est un job, elle peut attendre.
     *
     * @return list<array{id:string,de:string,objet:string,date:string,extrait:string}>
     */
    public function searchMany(string $query, int $limit): array
    {
        $query = trim($query) !== '' ? trim($query) : 'in:inbox';
        $limit = max(1, min(100, $limit));
        $list = $this->apiJson('GET', self::API_BASE . '/messages?maxResults=' . $limit . '&q=' . rawurlencode($query));
        $out = [];
        foreach (($list['messages'] ?? []) as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $this->apiJson(
                'GET',
                self::API_BASE . '/messages/' . rawurlencode($id)
                    . '?format=metadata&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=Date'
            );
            $headers = self::headerMap($meta['payload']['headers'] ?? []);
            $out[] = [
                'id'      => $id,
                'de'      => $headers['from'] ?? '',
                'objet'   => $headers['subject'] ?? '',
                'date'    => $headers['date'] ?? '',
                'extrait' => html_entity_decode((string)($meta['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }
        return $out;
    }

    /**
     * Pièces jointes d'un message, avec son objet et son expéditeur (boucle
     * « pour chaque pièce jointe »). Inventaire seulement : aucun octet.
     *
     * @return array{objet:string,de:string,pieces:list<array>}
     */
    public function listAttachments(string $messageId): array
    {
        $messageId = self::assertMessageId($messageId);
        $data = $this->apiJson('GET', self::API_BASE . '/messages/' . rawurlencode($messageId) . '?format=full');
        $headers = self::headerMap($data['payload']['headers'] ?? []);
        $found = MailAttachments::fromGmailPayload(is_array($data['payload'] ?? null) ? $data['payload'] : []);
        foreach ($found as $i => $a) {
            $found[$i]['data'] = '';
        }
        return ['objet' => (string)($headers['subject'] ?? ''), 'de' => (string)($headers['from'] ?? ''), 'pieces' => $found];
    }

    /**
     * Les OCTETS d'une pièce jointe, désignée par son numéro ou son nom (le
     * message est relu : l'identifiant Gmail d'une pièce jointe change d'une
     * lecture à l'autre).
     *
     * @return array{nom:string,type:string,bytes:string}
     */
    public function downloadAttachment(string $messageId, string $wanted): array
    {
        $messageId = self::assertMessageId($messageId);
        $data = $this->apiJson('GET', self::API_BASE . '/messages/' . rawurlencode($messageId) . '?format=full');
        $attachment = MailAttachments::pick(
            MailAttachments::fromGmailPayload(is_array($data['payload'] ?? null) ? $data['payload'] : []),
            $wanted
        );
        if ($attachment['data'] !== '') {
            $bytes = MailAttachments::decodeBase64Url($attachment['data']);
        } else {
            $raw = $this->apiJson(
                'GET',
                self::API_BASE . '/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachment['ref'])
            );
            $bytes = MailAttachments::decodeBase64Url((string)($raw['data'] ?? ''));
        }
        return ['nom' => $attachment['nom'], 'type' => $attachment['type'], 'bytes' => $bytes];
    }

    /**
     * Lit le TEXTE d'une pièce jointe, désignée par son numéro ou son nom.
     *
     * Le message est relu à chaque fois : l'identifiant d'une pièce jointe
     * Gmail change d'une lecture à l'autre, le garder d'un appel au suivant
     * (ou le faire recopier au modèle) finirait en 404. Lecture paginée
     * (TextPager) : un long PDF se lit partie par partie.
     *
     * @return array{message:string,objet:string,nom:string,type:string,taille:string,contenu:string,partie:int,parties:int,tronque:bool}
     */
    public function readAttachment(string $messageId, string $wanted, int $tokenBudget, int $part = 1): array
    {
        $messageId = self::assertMessageId($messageId);
        $data      = $this->apiJson('GET', self::API_BASE . '/messages/' . rawurlencode($messageId) . '?format=full');
        $headers   = self::headerMap($data['payload']['headers'] ?? []);

        $attachment = MailAttachments::pick(
            MailAttachments::fromGmailPayload(is_array($data['payload'] ?? null) ? $data['payload'] : []),
            $wanted
        );
        $reason = MailAttachments::unreadableReason($attachment);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        // Petites pièces jointes : les octets sont déjà dans le message.
        if ($attachment['data'] !== '') {
            $bytes = MailAttachments::decodeBase64Url($attachment['data']);
        } else {
            $raw   = $this->apiJson(
                'GET',
                self::API_BASE . '/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachment['ref'])
            );
            $bytes = MailAttachments::decodeBase64Url((string)($raw['data'] ?? ''));
        }
        $text = MailAttachments::extractText($bytes, $attachment['type'], $attachment['nom']);

        return [
            'message' => $messageId,
            'objet'   => (string)($headers['subject'] ?? ''),
            'nom'     => $attachment['nom'],
            'type'    => MailAttachments::humanType($attachment['type'], $attachment['nom']),
            'taille'  => MailAttachments::humanSize(strlen($bytes)),
        ] + TextPager::page($text, max(1000, $tokenBudget * 4), $part);
    }

    /**
     * Éléments nécessaires pour répondre DANS le fil d'un message existant.
     *
     * Gmail ne rattache un brouillon à une conversation que si DEUX conditions
     * sont réunies : le `threadId` dans la charge utile ET les en-têtes
     * `In-Reply-To` / `References` dans le MIME. L'un sans l'autre produit un
     * fil neuf — sans la moindre erreur, ce qui était exactement le symptôme
     * observé (« un nouveau brouillon à chaque fois »).
     *
     * @return array{thread_id:string,message_id:string,references:string,objet:string,repondre_a:string}
     */
    public function replyContext(string $messageId): array
    {
        $messageId = self::assertMessageId($messageId);
        // metadataHeaders se répète : `http_build_query` ne sait pas produire
        // un paramètre répété, la query est donc écrite à la main (même raison
        // que dans search()).
        $url = self::API_BASE . '/messages/' . rawurlencode($messageId) . '?format=metadata'
            . '&metadataHeaders=Message-ID&metadataHeaders=References&metadataHeaders=Subject'
            . '&metadataHeaders=From&metadataHeaders=Reply-To';
        $data = $this->apiJson('GET', $url);
        $h    = self::headerMap($data['payload']['headers'] ?? []);

        $idHeader = trim((string)($h['message-id'] ?? ''));
        return [
            'thread_id'  => (string)($data['threadId'] ?? ''),
            'message_id' => $idHeader,
            // La chaîne References se CUMULE : elle porte tout l'historique du
            // fil, et c'est elle que les autres clients suivent pour rattacher
            // la réponse. La tronquer casserait le fil chez le destinataire.
            'references' => trim(trim((string)($h['references'] ?? '')) . ' ' . $idHeader),
            'objet'      => (string)($h['subject'] ?? ''),
            'repondre_a' => (string)($h['reply-to'] ?? ($h['from'] ?? '')),
        ];
    }

    /**
     * Crée un BROUILLON (jamais d'envoi). Appelé uniquement depuis la file
     * d'approbation (clic « Approuver » de l'utilisateur).
     *
     * $replyToMessageId non vide → réponse DANS le fil du message visé :
     * destinataire, objet « Re: … » et en-têtes de chaînage sont déduits du
     * message d'origine plutôt que demandés au modèle, qui n'a aucun moyen de
     * connaître le `Message-ID` RFC.
     *
     * @return array{id:string,url:string,destinataire:string,objet:string}
     */
    public function createDraft(
        string $to,
        string $subject,
        string $body,
        string $cc = '',
        string $replyToMessageId = '',
        array $attachments = []
    ): array {
        if (!$this->canDraft()) {
            throw new \RuntimeException(self::MSG_RECONNECT_DRAFT);
        }
        $msg   = $this->composeMessage($to, $subject, $body, $cc, $replyToMessageId, $attachments);
        $draft = $this->apiJson('POST', self::API_BASE . '/drafts', [
            'message' => self::rawPayload($msg),
        ]);
        if ((string)($draft['id'] ?? '') === '') {
            throw new \RuntimeException('Création du brouillon refusée par Gmail.');
        }
        // Trace de succès (id seul, jamais de contenu) — diagnostic staging.
        error_log('[Gmail user] brouillon créé id=' . (string)$draft['id']
            . ($msg['thread_id'] !== '' ? ' fil=' . $msg['thread_id'] : ' fil=nouveau')
            . ($this->accountEmail !== '' ? ' boîte=' . $this->accountEmail : ''));
        return [
            'id'           => (string)$draft['id'],
            // Lien vers le dossier Brouillons DU COMPTE CONNECTÉ : l'URL
            // générique /mail/#drafts ouvre le compte Google par défaut du
            // navigateur — pas forcément celui-ci (multi-comptes) → brouillon
            // « introuvable ». /mail/u/<email>/ force le bon compte.
            'url'          => self::mailboxUrl($this->accountEmail) . '#drafts',
            'destinataire' => self::cleanHeaderValue($msg['to']),
            'objet'        => self::cleanHeaderValue($msg['subject']),
            'compte'       => $this->accountEmail,
        ];
    }

    /**
     * ENVOIE l'email — la seule écriture de ce connecteur visible hors de la
     * boîte, et sans retour possible. Appelée uniquement par la file
     * d'approbation, sur le clic « Envoyer » de la carte de relecture puis sa
     * confirmation (ChatController::driveAction, décision `send`) — jamais
     * par un outil du modèle.
     *
     * Même assemblage que le brouillon (composeMessage : contexte de réponse,
     * signature, MIME) : ce qui part est EXACTEMENT ce que la carte montrait
     * et ce que « Créer le brouillon » aurait déposé. `messages.send` avec le
     * `threadId` range aussi la réponse dans le fil côté Gmail.
     *
     * @return array{id:string,fil:string,url:string,destinataire:string,objet:string,compte:string}
     */
    public function sendMessage(
        string $to,
        string $subject,
        string $body,
        string $cc = '',
        string $replyToMessageId = '',
        array $attachments = []
    ): array {
        if (!$this->canSend()) {
            throw new \RuntimeException(self::MSG_RECONNECT_SEND);
        }
        $msg  = $this->composeMessage($to, $subject, $body, $cc, $replyToMessageId, $attachments);
        $sent = $this->apiJson('POST', self::API_BASE . '/messages/send', self::rawPayload($msg));
        if ((string)($sent['id'] ?? '') === '') {
            throw new \RuntimeException('Envoi refusé par Gmail.');
        }
        $threadId = (string)($sent['threadId'] ?? $msg['thread_id']);
        // Trace de succès (id seul, jamais de contenu) — diagnostic staging.
        error_log('[Gmail user] email envoyé id=' . (string)$sent['id']
            . ($threadId !== '' ? ' fil=' . $threadId : '')
            . ($this->accountEmail !== '' ? ' boîte=' . $this->accountEmail : ''));
        return [
            'id'           => (string)$sent['id'],
            'fil'          => $threadId,
            'url'          => self::sentUrl($this->accountEmail, $threadId),
            'destinataire' => self::cleanHeaderValue($msg['to']),
            'objet'        => self::cleanHeaderValue($msg['subject']),
            'compte'       => $this->accountEmail,
        ];
    }

    /**
     * Assemble le message d'un brouillon OU d'un envoi : contexte de réponse
     * (fil, en-têtes de chaînage, destinataire et objet déduits du message
     * d'origine quand le modèle ne les a pas fournis — c'est l'original qui
     * fait foi, pas sa reconstitution de mémoire), signature de la boîte
     * quand elle est lisible, MIME. Un seul chemin pour les deux gestes, pour
     * qu'ils ne divergent jamais.
     *
     * @return array{mime:string,thread_id:string,to:string,subject:string}
     */
    private function composeMessage(string $to, string $subject, string $body, string $cc, string $replyToMessageId, array $attachments = []): array
    {
        $reply    = [];
        $threadId = '';
        if (trim($replyToMessageId) !== '') {
            $ctx      = $this->replyContext($replyToMessageId);
            $threadId = $ctx['thread_id'];
            $reply    = ['message_id' => $ctx['message_id'], 'references' => $ctx['references']];
            if (trim($to) === '') {
                $to = self::extractAddress($ctx['repondre_a']);
            }
            $subject = self::replySubject($subject, $ctx['objet']);
        }
        // Signature de la boîte, quand l'utilisateur en a une et que le scope
        // de lecture des réglages a été accordé. Silencieusement absente sinon.
        $mime = self::buildDraftMime($to, $subject, $body, $cc, $reply, $this->defaultSignatureHtml(), $attachments);
        return ['mime' => $mime, 'thread_id' => $threadId, 'to' => $to, 'subject' => $subject];
    }

    /** Charge utile `{raw[, threadId]}` d'un message assemblé (brouillon comme envoi). */
    private static function rawPayload(array $msg): array
    {
        return $msg['thread_id'] !== ''
            ? ['raw' => self::base64UrlEncode($msg['mime']), 'threadId' => $msg['thread_id']]
            : ['raw' => self::base64UrlEncode($msg['mime'])];
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /**
     * Message RFC 2822 UTF-8 du brouillon. Anti-injection d'en-têtes : les
     * champs to/cc/objet sont nettoyés de tout CR/LF/NUL AVANT usage, chaque
     * adresse est validée (FILTER_VALIDATE_EMAIL), l'objet est toujours encodé
     * =?UTF-8?B?…?= et le corps isolé en base64. Throw si adresse invalide.
     */
    public static function buildDraftMime(
        string $to,
        string $subject,
        string $body,
        string $cc = '',
        array $reply = [],
        string $signatureHtml = '',
        array $attachments = []
    ): string {
        $toAddresses = self::validateAddressList(self::cleanHeaderValue($to));
        if ($toAddresses === []) {
            throw new \RuntimeException('Aucun destinataire fourni.');
        }
        $ccClean     = self::cleanHeaderValue($cc);
        $ccAddresses = $ccClean !== '' ? self::validateAddressList($ccClean) : [];

        $headers   = [];
        $headers[] = 'To: ' . implode(', ', $toAddresses);
        if ($ccAddresses !== []) {
            $headers[] = 'Cc: ' . implode(', ', $ccAddresses);
        }
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode(self::cleanHeaderValue($subject)) . '?=';

        // Chaînage RFC 5322 : c'est lui que suivent les clients mail du
        // destinataire. Le `threadId` de l'API Gmail, lui, ne range le
        // brouillon que dans NOTRE boîte — les deux sont nécessaires.
        $msgId = self::sanitizeMessageIds((string)($reply['message_id'] ?? ''));
        $refs  = self::sanitizeMessageIds((string)($reply['references'] ?? ''));
        if ($msgId !== '') {
            $headers[] = 'In-Reply-To: ' . $msgId;
            $headers[] = 'References: ' . ($refs !== '' ? $refs : $msgId);
        }

        $attachments = OutgoingAttachments::normalize($attachments);
        // Images intégrées au corps (repères posés par le serveur, `cid`) et
        // pièces jointes ordinaires : deux familles, deux enveloppes.
        $inline = array_values(array_filter($attachments, static fn(array $a): bool => isset($a['cid'])));
        $files  = array_values(array_filter($attachments, static fn(array $a): bool => !isset($a['cid'])));
        $images = OutgoingAttachments::inlineImages($inline);

        $html  = MailHtml::avecSignature(MailHtml::withInlineImages(MailHtml::toHtml($body), $images), $signatureHtml);
        $texte = MailHtml::avecSignatureTexte(MailHtml::plainImages(MailHtml::toPlain($body), $images), $signatureHtml);

        $headers[] = 'MIME-Version: 1.0';
        $headers[] = match (true) {
            $files !== []  => 'Content-Type: multipart/mixed; boundary="' . self::MIME_MIXED_BOUNDARY . '"',
            $inline !== [] => 'Content-Type: multipart/related; type="multipart/alternative"; boundary="' . self::MIME_RELATED_BOUNDARY . '"',
            default        => 'Content-Type: multipart/alternative; boundary="' . self::MIME_BOUNDARY . '"',
        };

        // multipart/alternative plutôt que text/html seul : les deux versions
        // voyagent, le client choisit. Une part texte absente dégrade
        // l'affichage dans les lecteurs qui refusent le HTML, et pèse dans les
        // filtres anti-spam.
        $corps = '--' . self::MIME_BOUNDARY . "\r\n"
            . "Content-Type: text/plain; charset=\"UTF-8\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($texte), 76, "\r\n")) . "\r\n"
            . '--' . self::MIME_BOUNDARY . "\r\n"
            . "Content-Type: text/html; charset=\"UTF-8\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($html), 76, "\r\n")) . "\r\n"
            . '--' . self::MIME_BOUNDARY . "--";

        // Type de la partie qui porte le texte et le HTML, une fois enveloppée.
        $bodyType = 'multipart/alternative; boundary="' . self::MIME_BOUNDARY . '"';
        if ($inline !== []) {
            // Images intégrées : l'alternative et les images (Content-ID) dans
            // un multipart/related — le HTML les affiche par `cid:`.
            $related = '--' . self::MIME_RELATED_BOUNDARY . "\r\n"
                . 'Content-Type: ' . $bodyType . "\r\n\r\n"
                . $corps . "\r\n";
            foreach ($inline as $a) {
                $related .= OutgoingAttachments::mimePart($a, self::MIME_RELATED_BOUNDARY);
            }
            $corps = $related . '--' . self::MIME_RELATED_BOUNDARY . '--';
            $bodyType = 'multipart/related; type="multipart/alternative"; boundary="' . self::MIME_RELATED_BOUNDARY . '"';
        }
        if ($files !== []) {
            // Pièces jointes : le corps (alternative, ou related avec ses
            // images) enveloppé dans un multipart/mixed avec les fichiers
            // (base64 : une ligne ne commence jamais par « -- », pas de collision).
            $mixed = '--' . self::MIME_MIXED_BOUNDARY . "\r\n"
                . 'Content-Type: ' . $bodyType . "\r\n\r\n"
                . $corps . "\r\n";
            foreach ($files as $a) {
                $mixed .= OutgoingAttachments::mimePart($a, self::MIME_MIXED_BOUNDARY);
            }
            $corps = $mixed . '--' . self::MIME_MIXED_BOUNDARY . '--';
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $corps;
    }

    /**
     * Objet d'une réponse : « Re: » posé UNE fois.
     *
     * L'objet proposé par le modèle est ignoré s'il ne fait que reformuler
     * celui d'origine — un fil dont l'objet change à chaque réponse se scinde
     * chez certains clients.
     */
    public static function replySubject(string $propose, string $original): string
    {
        $base = trim($original) !== '' ? trim($original) : trim($propose);
        if ($base === '') {
            return 'Re:';
        }
        // « Re: », « RE : », « Ré: », « Fwd: »… déjà présent → on n'empile pas.
        return preg_match('/^\s*(re|ré|fwd|tr)\s*:/iu', $base) === 1 ? $base : 'Re: ' . $base;
    }

    /**
     * Adresse seule d'un en-tête « Nom <adresse> ». Les en-têtes From/Reply-To
     * arrivent presque toujours sous cette forme, que FILTER_VALIDATE_EMAIL
     * rejette.
     */
    public static function extractAddress(string $header): string
    {
        $header = self::cleanHeaderValue($header);
        if (preg_match('/<([^>]+)>/', $header, $m) === 1) {
            return trim($m[1]);
        }
        return $header;
    }

    /**
     * Ne garde que des identifiants RFC bien formés `<…>`.
     *
     * `cleanHeaderValue` suffit à empêcher l'injection d'en-tête (elle écrase
     * les CR/LF), mais laisserait passer du texte libre DANS la valeur : une
     * chaîne References mal formée n'injecte rien et casse quand même le fil
     * chez le destinataire. Ici la valeur ne peut contenir que ce qu'elle doit
     * contenir.
     */
    public static function sanitizeMessageIds(string $raw): string
    {
        if (preg_match_all('/<[^<>\s]{1,500}>/', self::cleanHeaderValue($raw), $m) < 1) {
            return '';
        }
        return implode(' ', $m[0]);
    }

    /** Identifiant de message Gmail valide, ou exception FR. */
    public static function assertMessageId(string $messageId): string
    {
        $messageId = trim($messageId);
        if (preg_match('/^[A-Za-z0-9_-]{8,}$/', $messageId) !== 1) {
            throw new \RuntimeException('Identifiant de message Gmail invalide — utilise rechercher_gmail d\'abord.');
        }
        return $messageId;
    }

    /** Neutralise toute injection d'en-tête : CR, LF et NUL remplacés par un espace. */
    public static function cleanHeaderValue(string $value): string
    {
        return trim((string)preg_replace('/[\r\n\x00]+/', ' ', $value));
    }

    /**
     * Liste d'adresses séparées par des virgules → adresses validées.
     * Throw RuntimeException sur la première adresse invalide.
     */
    private static function validateAddressList(string $list): array
    {
        $out = [];
        foreach (explode(',', $list) as $addr) {
            $addr = trim($addr);
            if ($addr === '') {
                continue;
            }
            if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Adresse email invalide : « ' . $addr . ' »');
            }
            $out[] = $addr;
        }
        return $out;
    }

    /**
     * Walk récursif des parts MIME d'un message Gmail : première part
     * text/plain non vide préférée, fallback text/html strippé. Corps en
     * body.data base64url.
     */
    public static function extractTextFromPayload(array $payload): string
    {
        $plain = '';
        $html  = '';
        $walk = static function (array $part) use (&$walk, &$plain, &$html): void {
            $mime = (string)($part['mimeType'] ?? '');
            $data = (string)($part['body']['data'] ?? '');
            if ($data !== '') {
                $decoded = base64_decode(strtr($data, '-_', '+/')) ?: '';
                if ($plain === '' && str_starts_with($mime, 'text/plain')) {
                    $plain = $decoded;
                } elseif ($html === '' && str_starts_with($mime, 'text/html')) {
                    $html = $decoded;
                }
            }
            foreach (($part['parts'] ?? []) as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($payload);

        if (trim($plain) !== '') {
            return trim($plain);
        }
        if (trim($html) !== '') {
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim((string)preg_replace('/[ \t]*\n[ \t]*\n[\s]*/', "\n\n", (string)preg_replace('/[ \t]+/', ' ', $text)));
        }
        return '';
    }

    /** base64url sans padding (format `raw` de l'API Gmail). */
    public static function base64UrlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /**
     * Racine de l'interface Gmail DU COMPTE CONNECTÉ (`/mail/u/<email>/`) : la
     * racine générique ouvre le compte Google par défaut du navigateur, pas
     * forcément celui-ci (multi-comptes).
     */
    public static function mailboxUrl(string $accountEmail): string
    {
        return $accountEmail !== ''
            ? 'https://mail.google.com/mail/u/' . rawurlencode($accountEmail) . '/'
            : 'https://mail.google.com/mail/';
    }

    /**
     * Lien vers le message envoyé : le dossier Envoyés, ouvert sur le fil
     * quand son identifiant est connu (Gmail emploie le même identifiant
     * hexadécimal dans ses adresses que dans son API). Un identifiant qui n'a
     * pas cette forme n'est pas mis dans l'adresse : le dossier suffit.
     */
    public static function sentUrl(string $accountEmail, string $threadId): string
    {
        $fil = preg_match('/^[a-f0-9]{8,}$/i', $threadId) === 1 ? '/' . $threadId : '';
        return self::mailboxUrl($accountEmail) . '#sent' . $fil;
    }

    /**
     * Aperçu HUMAIN de l'email préparé, pour la carte de relecture (mêmes
     * clés que UserDriveService::actionPreview + icone + bloc `mail`). La
     * carte offre deux issues d'exécution — enregistrer en brouillon ou
     * ENVOYER —, les impacts les nomment toutes les deux : personne ne doit
     * découvrir au clic que l'une expédie réellement.
     *
     * @return array{action:string,fichier:string,url:?string,impacts:string[],apercu:?string,icone:string,mail:array}
     */
    public static function actionPreview(string $tool, array $args): array
    {
        $objet = self::cleanHeaderValue((string)($args['objet'] ?? ''));
        $dest  = self::cleanHeaderValue((string)($args['destinataire'] ?? ''));
        $cc    = self::cleanHeaderValue((string)($args['cc'] ?? ''));
        $corps = trim((string)($args['corps'] ?? ''));
        $apercu = $corps === '' ? null
            : (mb_strlen($corps) > 280 ? mb_substr($corps, 0, 280) . '…' : $corps);

        $reponse = trim((string)($args['repondre_a'] ?? '')) !== '';

        return [
            'action'  => $reponse ? t('Réponse à un email (Gmail)') : t('Nouvel email Gmail'),
            'fichier' => ($objet !== '' ? $objet : ($reponse ? t('(objet repris du message)') : t('(sans objet)')))
                . ($dest !== '' ? ' → ' . $dest : ($reponse ? ' → ' . t('expéditeur du message') : ''))
                . ($cc !== '' ? ' ' . t('(cc : {destinataires})', ['destinataires' => $cc]) : ''),
            'url'     => null,
            'impacts' => array_values(array_filter([
                t('« Créer le brouillon » le range dans vos Brouillons Gmail sans rien envoyer ; « Envoyer » l\'expédie immédiatement à ses destinataires, sans retour possible.'),
                $reponse ? t('Il sera rattaché à la conversation d\'origine (objet « Re: … » et fil conservé).') : null,
            ])),
            'apercu'  => $apercu,
            'icone'   => '/assets/img/gmail.svg',
            // Carte de RELECTURE : le message tel qu'il partira (À, Cc, Objet,
            // corps rendu par MailHtml) et les préréglages d'amélioration —
            // source unique partagée avec Outlook (cf. MailDraftReview).
            'mail'    => MailDraftReview::block('gmail', $args),
        ];
    }

    /** Headers Gmail (payload.headers) → map name minuscule => value. */
    private static function headerMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $h) {
            $name = strtolower((string)($h['name'] ?? ''));
            if ($name !== '' && !isset($map[$name])) {
                $map[$name] = (string)($h['value'] ?? '');
            }
        }
        return $map;
    }
}
