<?php

declare(strict_types=1);

/**
 * Accès à la boîte Outlook PERSONNELLE d'un utilisateur (OAuth Microsoft par
 * utilisateur, jetons UserDriveToken provider 'outlook') : recherche et
 * lecture d'emails, création de BROUILLONS, et — sur le seul bouton
 * « Envoyer » de la carte de relecture — ENVOI du brouillon que l'application
 * vient de créer.
 *
 * Aucun outil du modèle n'envoie : `sendMessage` n'est appelée que par
 * ChatController::driveAction sur la décision `send`, après confirmation de
 * l'utilisateur, et le seul point d'envoi est `POST /me/messages/{id}/send`
 * sur ce brouillon — jamais `/me/sendMail`, qui expédierait une charge utile
 * assemblée à part et ne sait pas répondre dans un fil. Aucun outil de
 * modification ni de suppression de message n'existe : `Mail.ReadWrite` les
 * autorise côté API (Graph n'a pas d'équivalent de `gmail.compose`), ce sont
 * donc des garde-fous applicatifs qui tiennent la promesse — cf.
 * MicrosoftOutlookOAuth pour le raisonnement complet.
 *
 * Plomberie commune (refresh lazy, jetons roulants, retry 401, erreurs FR sans
 * fuite) : MicrosoftUserApiService — ce fichier ne porte que le métier Outlook.
 */
class UserOutlookService extends MicrosoftUserApiService
{
    private const MAIL_BASE = self::GRAPH_BASE . '/me';

    /** Scopes Graph, en forme courte : `scopeGranted` gère le préfixe d'URI. */
    public const SCOPE_READ      = 'Mail.Read';
    public const SCOPE_READWRITE = 'Mail.ReadWrite';
    public const SCOPE_SEND      = 'Mail.Send';

    /** Clé Settings : interrupteur global admin de l'Outlook utilisateur dans le chat. */
    public const SETTING_ENABLED = 'user_outlook_enabled';

    public const MSG_RECONNECT = 'Connexion Outlook expirée ou révoquée. '
        . 'L\'utilisateur doit reconnecter son Outlook dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_DRAFT = 'Autorisation de création de brouillons non accordée. '
        . 'L\'utilisateur doit reconnecter son Outlook dans Réglages → Comptes connectés.';
    public const MSG_RECONNECT_SEND = 'Autorisation d\'envoi (Mail.Send) non accordée. '
        . 'L\'utilisateur doit reconnecter son Outlook dans Réglages → Comptes connectés ; si le refus persiste, '
        . 'un administrateur de l\'annuaire Microsoft doit consentir à cette permission pour l\'application.';
    /** Ajouté à toute erreur survenue APRÈS la création du brouillon, pendant l'envoi. */
    public const MSG_DRAFT_KEPT = 'Le message est resté dans vos Brouillons Outlook : rien n\'est parti.';

    protected const OAUTH_CLASS       = MicrosoftOutlookOAuth::class;
    protected const TOKEN_PROVIDER    = UserDriveToken::PROVIDER_OUTLOOK;
    protected const API_LABEL         = 'Outlook';
    protected const LOG_LABEL         = '[Outlook user]';
    protected const MSG_NOT_FOUND     = 'Message introuvable dans votre boîte Outlook (supprimé ou identifiant invalide).';
    protected const MSG_BAD_REQUEST   = 'Requête refusée par Outlook (HTTP 400) — vérifiez la syntaxe de recherche ou les adresses fournies.';
    protected const MSG_ACCESS_DENIED = 'Accès refusé par Outlook';
    protected const MSG_SCOPE_INSUFFICIENT = 'Permission Outlook non accordée. L\'utilisateur doit reconnecter '
        . 'son Outlook dans Réglages → Comptes connectés ; si le refus persiste, un administrateur de l\'annuaire '
        . 'Microsoft doit consentir à la permission Mail.ReadWrite pour l\'application.';
    protected const MSG_NO_MAILBOX    = 'Ce compte Microsoft n\'a pas de boîte aux lettres Exchange Online '
        . '(licence absente, ou compte d\'annuaire sans messagerie). Aucune reconnexion n\'y changera rien : '
        . 'il faut un compte disposant réellement d\'une boîte Outlook.';

    /** Instance pour l'utilisateur, ou null si Outlook non connecté (ou révoqué). */
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

    /** Scope lecture accordé ? (l'écriture l'implique) */
    public function canRead(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_READ)
            || self::scopeGranted($this->grantedScope, self::SCOPE_READWRITE);
    }

    /** Scope création de brouillons accordé ? */
    public function canDraft(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_READWRITE);
    }

    /**
     * Envoi possible ? Il faut les DEUX droits : `Mail.Send` pour expédier,
     * `Mail.ReadWrite` parce que l'envoi passe par un brouillon créé d'abord
     * (un seul chemin d'assemblage, réponse dans le fil comprise). Un annuaire
     * qui n'accorde que Mail.Send ne pourrait rien assembler.
     */
    public function canSend(): bool
    {
        return self::scopeGranted($this->grantedScope, self::SCOPE_SEND) && $this->canDraft();
    }

    /**
     * Recherche des emails. Requête vide = les derniers messages reçus (boîte
     * de réception, du plus récent au plus ancien) ; requête non vide =
     * recherche `$search` de Graph, qui accepte les restrictions KQL
     * (`from:`, `subject:`, `received>=2026-01-15`, `hasAttachments:true`…).
     *
     * Retour : liste `{id, de, objet, date, extrait, lu, pieces_jointes}`.
     * Throw RuntimeException à message FR.
     */
    public function search(string $query, int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        $list  = $this->apiJson('GET', self::searchUrl($query, $limit));

        $out = [];
        foreach (($list['value'] ?? []) as $m) {
            if (is_array($m)) {
                $out[] = self::summarizeMessage($m);
            }
        }
        return $out;
    }

    /**
     * Lit un email complet : expéditeur, destinataires, objet, date et corps
     * texte (HTML strippé au besoin), rendu PAR PARTIES au budget (≈ tokens
     * × 4 caractères, TextPager) : `partie` lit la suite d'un long email.
     *
     * @return array{id:string,de:string,a:string,cc:string,objet:string,date:string,contenu:string,tronque:bool,partie:int,parties:int,url:string}
     */
    public function readMessage(string $messageId, int $tokenBudget, int $part = 1): array
    {
        $id   = self::assertMessageId($messageId);
        $data = $this->apiJson(
            'GET',
            self::MAIL_BASE . '/messages/' . rawurlencode($id)
                . '?$select=' . rawurlencode('id,subject,from,toRecipients,ccRecipients,receivedDateTime,body,bodyPreview,webLink,hasAttachments')
        );

        // Pièces jointes : un second appel, seulement si Graph en annonce, et
        // best-effort — un inventaire en échec ne doit pas faire perdre la
        // lecture du message lui-même.
        $attachments = [];
        if (!empty($data['hasAttachments'])) {
            try {
                $attachments = $this->listAttachments($id);
            } catch (\RuntimeException $e) {
                error_log('[Outlook user] pièces jointes non listées : ' . $e->getMessage());
            }
        }

        $text = self::extractBodyText($data);
        if (trim($text) === '' && $attachments !== []) {
            $text = '(message sans texte : voir les pièces jointes)';
        }
        if (trim($text) === '') {
            throw new \RuntimeException('Aucun texte extractible dans ce message.');
        }

        $page = TextPager::page($text, max(1000, $tokenBudget * 4), $part);

        $out = [
            // L'identifiant est RENDU, et ce n'est pas cosmétique : c'est lui
            // que le modèle repasse à `creer_brouillon_outlook` pour répondre
            // DANS LE FIL, et lui que ConnectorContext mémorise pour le tour
            // suivant. Sans lui, « réponds à ce mail » n'avait rien à viser.
            'id'      => (string)($data['id'] ?? $id),
            'de'      => self::formatRecipient($data['from'] ?? []),
            'a'       => self::formatRecipients($data['toRecipients'] ?? []),
            'cc'      => self::formatRecipients($data['ccRecipients'] ?? []),
            'objet'   => (string)($data['subject'] ?? ''),
            'date'    => self::formatDateTime((string)($data['receivedDateTime'] ?? '')),
            'url'     => (string)($data['webLink'] ?? ''),
        ] + $page;
        if ($attachments !== []) {
            $out['pieces_jointes'] = MailAttachments::forModel($attachments);
        }
        return $out;
    }

    /**
     * Inventaire des pièces jointes d'un message, SANS leurs octets : le
     * `$select` écarte `contentBytes`, que Graph joindrait sinon pour chaque
     * fichier — trois PDF de 5 Mo pour simplement savoir qu'ils existent.
     *
     * @return list<array{numero:int, nom:string, type:string, taille:int, ref:string, data:string, en_ligne:bool, piece:string}>
     */
    public function listAttachments(string $messageId): array
    {
        $id   = self::assertMessageId($messageId);
        $data = $this->apiJson(
            'GET',
            self::MAIL_BASE . '/messages/' . rawurlencode($id) . '/attachments'
                . '?$select=' . rawurlencode('id,name,contentType,size,isInline')
        );
        return MailAttachments::fromGraphList((array)($data['value'] ?? []));
    }

    /**
     * Recherche LONGUE (boucles de workflow) : jusqu'à `$limit` emails (100
     * au plus), mêmes résultats que search().
     *
     * @return list<array{id:string,de:string,objet:string,date:string,extrait:string,lu:bool,pieces_jointes:bool}>
     */
    public function searchMany(string $query, int $limit): array
    {
        $list = $this->apiJson('GET', self::searchUrl($query, $limit, 100));
        $out = [];
        foreach (($list['value'] ?? []) as $m) {
            if (is_array($m)) {
                $out[] = self::summarizeMessage($m);
            }
        }
        return $out;
    }

    /**
     * Les OCTETS d'une pièce jointe (fichier joint seulement), désignée par
     * son numéro ou son nom.
     *
     * @return array{nom:string,type:string,bytes:string}
     */
    public function downloadAttachment(string $messageId, string $wanted): array
    {
        $id = self::assertMessageId($messageId);
        $attachment = MailAttachments::pick($this->listAttachments($id), $wanted);
        $reason = MailAttachments::unreadableReason($attachment);
        if ($reason !== null && $attachment['piece'] !== 'fichier') {
            throw new \RuntimeException($reason);
        }
        $file = $this->apiJson(
            'GET',
            self::MAIL_BASE . '/messages/' . rawurlencode($id) . '/attachments/' . rawurlencode($attachment['ref'])
        );
        $bytes = base64_decode((string)($file['contentBytes'] ?? ''), true);
        return ['nom' => $attachment['nom'], 'type' => $attachment['type'], 'bytes' => is_string($bytes) ? $bytes : ''];
    }

    /** Objet et expéditeur d'un message (boucle « pour chaque pièce jointe »). @return array{objet:string,de:string} */
    public function messageHeadline(string $messageId): array
    {
        $id = self::assertMessageId($messageId);
        $meta = $this->apiJson('GET', self::MAIL_BASE . '/messages/' . rawurlencode($id) . '?$select=subject,from');
        return ['objet' => (string)($meta['subject'] ?? ''), 'de' => self::formatRecipient((array)($meta['from'] ?? []))];
    }

    /**
     * Lit le TEXTE d'une pièce jointe, désignée par son numéro ou son nom.
     * Seuls les fichiers joints ont des octets (`fileAttachment`) : un email
     * joint ou un lien de partage le disent au lieu d'échouer. Lecture paginée
     * (TextPager).
     *
     * @return array{message:string,objet:string,nom:string,type:string,taille:string,contenu:string,partie:int,parties:int,tronque:bool}
     */
    public function readAttachment(string $messageId, string $wanted, int $tokenBudget, int $part = 1): array
    {
        $id         = self::assertMessageId($messageId);
        $attachment = MailAttachments::pick($this->listAttachments($id), $wanted);
        $reason     = MailAttachments::unreadableReason($attachment);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        $file  = $this->apiJson(
            'GET',
            self::MAIL_BASE . '/messages/' . rawurlencode($id) . '/attachments/' . rawurlencode($attachment['ref'])
        );
        $bytes = base64_decode((string)($file['contentBytes'] ?? ''), true);
        $text  = MailAttachments::extractText(is_string($bytes) ? $bytes : '', $attachment['type'], $attachment['nom']);

        // Objet du message : il nomme la pièce jointe dans la trace et dans la
        // mémoire de conversation. Best-effort — la lecture vaut sans lui.
        $objet = '';
        try {
            $meta  = $this->apiJson('GET', self::MAIL_BASE . '/messages/' . rawurlencode($id) . '?$select=subject');
            $objet = (string)($meta['subject'] ?? '');
        } catch (\RuntimeException) {
            // Sans objet, la lecture reste valable.
        }

        return [
            'message' => $id,
            'objet'   => $objet,
            'nom'     => $attachment['nom'],
            'type'    => MailAttachments::humanType($attachment['type'], $attachment['nom']),
            'taille'  => MailAttachments::humanSize(strlen((string)$bytes)),
        ] + TextPager::page($text, max(1000, $tokenBudget * 4), $part);
    }

    /**
     * Crée un BROUILLON. Appelé depuis la file d'approbation (clic « Créer le
     * brouillon » de l'utilisateur) et par sendMessage, qui l'expédie ensuite.
     *
     * Un POST sur /me/messages dépose le message dans les Brouillons ; rien
     * ne part d'ici.
     *
     * $replyToMessageId non vide → RÉPONSE DANS LE FIL (cf. createReplyDraft).
     *
     * @return array{id:string,url:string,destinataire:string,objet:string,compte:string,reponse:bool}
     */
    public function createDraft(string $to, string $subject, string $body, string $cc = '', string $replyToMessageId = '', array $attachments = []): array
    {
        if (!$this->canDraft()) {
            throw new \RuntimeException(self::MSG_RECONNECT_DRAFT);
        }
        // Bornes vérifiées AVANT de créer quoi que ce soit dans la boîte.
        $attachments = OutgoingAttachments::normalize($attachments);
        // Images intégrées au corps (repères posés par le serveur) : `cid:`
        // dans le HTML, pièce jointe `isInline` (addAttachments).
        $images = OutgoingAttachments::inlineImages($attachments);
        if (trim($replyToMessageId) !== '') {
            $reply = $this->createReplyDraft($replyToMessageId, $body, $to, $cc, $images);
            $this->addAttachments((string)$reply['id'], $attachments);
            return $reply;
        }

        $payload = self::buildDraftPayload($to, $subject, $body, $cc, $images);
        $draft   = $this->apiJson('POST', self::MAIL_BASE . '/messages', $payload);
        if ((string)($draft['id'] ?? '') === '') {
            throw new \RuntimeException('Création du brouillon refusée par Outlook.');
        }
        $this->addAttachments((string)$draft['id'], $attachments);
        // Trace de succès (id seul, jamais de contenu) — diagnostic staging.
        error_log('[Outlook user] brouillon créé id=' . mb_substr((string)$draft['id'], 0, 24) . '…'
            . ($this->accountEmail !== '' ? ' boîte=' . $this->accountEmail : ''));

        return [
            'id'           => (string)$draft['id'],
            // webLink ouvre le brouillon DANS LE BON COMPTE et le bon domaine
            // (outlook.office.com pour un compte professionnel,
            // outlook.live.com pour un compte personnel) — le déduire nous-mêmes
            // enverrait la moitié des utilisateurs sur une page vide.
            'url'          => self::draftUrl($draft),
            'destinataire' => implode(', ', self::parseAddressList($to)),
            'objet'        => self::cleanHeaderValue($subject),
            'compte'       => $this->accountEmail,
            'reponse'      => false,
        ];
    }

    /**
     * Répond DANS LE FIL d'un message existant.
     *
     * ── POURQUOI PASSER PAR `createReply` ──────────────────────────────────
     * Un simple POST sur /me/messages ouvre une conversation NEUVE : chaque
     * « réponds à ce mail » repartait de zéro chez le destinataire, sans la
     * moindre erreur — exactement le défaut déjà payé côté Gmail. Rattacher un
     * message à un fil demande le `conversationId` côté Outlook ET les en-têtes
     * `In-Reply-To` / `References` côté destinataire ; ces en-têtes ne sont pas
     * exposés en écriture par Graph, et le `Message-ID` RFC de l'original n'est
     * de toute façon connu de personne ici. `POST /me/messages/{id}/createReply`
     * fait les deux pour nous : c'est la SEULE façon correcte.
     *
     * En DEUX temps, et non en un via le paramètre `comment` : `comment` est
     * documenté comme une chaîne, son interprétation en HTML n'est pas garantie
     * — un corps rédigé en Markdown converti risquerait de s'afficher en
     * toutes lettres, précisément le bug que MailHtml corrige. On récupère donc
     * le brouillon créé par Graph (déjà rattaché, destinataire et objet « RE: »
     * remplis, original cité) puis on PATCHe son corps.
     *
     * L'OBJET n'est jamais réécrit : Graph le dérive de l'original, et un objet
     * modifié scinde le fil chez certains clients. Le destinataire de Graph est
     * conservé sauf si l'utilisateur en a nommé un explicitement.
     *
     * @return array{id:string,url:string,destinataire:string,objet:string,compte:string,reponse:bool}
     */
    private function createReplyDraft(string $replyToMessageId, string $body, string $to = '', string $cc = '', array $images = []): array
    {
        $original = self::assertMessageId($replyToMessageId);

        // Corps non vide exigé AVANT l'appel : sinon Graph crée un brouillon
        // vide dans la boîte de l'utilisateur, qu'il faudrait aller supprimer.
        if (trim($body) === '') {
            throw new \RuntimeException('Le corps de la réponse est vide.');
        }
        // Adresses validées AVANT, pour la même raison.
        $toAddresses = self::parseAddressList($to);
        $ccAddresses = self::parseAddressList($cc);

        // `comment` vide plutôt qu'aucun corps : garantit un Content-Type JSON
        // et un corps de requête bien formé sur ce POST sans charge utile.
        $draft = $this->apiJson(
            'POST',
            self::MAIL_BASE . '/messages/' . rawurlencode($original) . '/createReply',
            ['comment' => '']
        );
        $draftId = (string)($draft['id'] ?? '');
        if ($draftId === '') {
            throw new \RuntimeException('Création de la réponse refusée par Outlook.');
        }

        $patch = [
            'body' => [
                'contentType' => 'HTML',
                'content'     => self::mergeReplyBody(MailHtml::withInlineImages(MailHtml::toHtml($body), $images), (string)($draft['body']['content'] ?? '')),
            ],
        ];
        if ($toAddresses !== []) {
            $patch['toRecipients'] = array_map(
                static fn(string $a): array => ['emailAddress' => ['address' => $a]],
                $toAddresses
            );
        }
        if ($ccAddresses !== []) {
            $patch['ccRecipients'] = array_map(
                static fn(string $a): array => ['emailAddress' => ['address' => $a]],
                $ccAddresses
            );
        }
        $final = $this->apiJson('PATCH', self::MAIL_BASE . '/messages/' . rawurlencode($draftId), $patch);

        error_log('[Outlook user] réponse en brouillon id=' . mb_substr($draftId, 0, 24) . '…'
            . ' fil=' . mb_substr((string)($draft['conversationId'] ?? ''), 0, 16) . '…'
            . ($this->accountEmail !== '' ? ' boîte=' . $this->accountEmail : ''));

        $destinataires = $toAddresses !== []
            ? implode(', ', $toAddresses)
            : self::formatRecipients($draft['toRecipients'] ?? []);

        return [
            'id'           => $draftId,
            'url'          => self::draftUrl($final !== [] ? $final : $draft),
            'destinataire' => $destinataires,
            'objet'        => (string)($draft['subject'] ?? ''),
            'compte'       => $this->accountEmail,
            'reponse'      => true,
        ];
    }

    /**
     * ENVOIE l'email — la seule écriture de ce connecteur visible hors de la
     * boîte, et sans retour possible. Appelée uniquement par la file
     * d'approbation, sur le clic « Envoyer » de la carte de relecture puis sa
     * confirmation (ChatController::driveAction, décision `send`) — jamais
     * par un outil du modèle.
     *
     * Deux temps, UN chemin : le brouillon est créé exactement comme par
     * « Créer le brouillon » (réponse dans le fil comprise : conversationId et
     * en-têtes posés par `createReply`), puis `POST /me/messages/{id}/send`
     * l'expédie — Graph le déplace dans Éléments envoyés. Ce qui part est
     * donc exactement ce que la carte montrait.
     *
     * Échec APRÈS la création (Mail.Send refusé au consentement alors que la
     * colonne scope disait l'inverse, panne) : le brouillon reste dans les
     * Brouillons de l'utilisateur et le message le dit — rien n'est perdu,
     * rien n'est parti.
     *
     * @return array{id:string,url:string,destinataire:string,objet:string,compte:string,reponse:bool}
     */
    public function sendMessage(string $to, string $subject, string $body, string $cc = '', string $replyToMessageId = '', array $attachments = []): array
    {
        if (!$this->canSend()) {
            throw new \RuntimeException(self::MSG_RECONNECT_SEND);
        }
        $draft   = $this->createDraft($to, $subject, $body, $cc, $replyToMessageId, $attachments);
        $draftId = (string)$draft['id'];
        try {
            // Corps `[]` = objet JSON vide : l'action n'a aucun paramètre, mais
            // un POST sans corps ni Content-Length est refusé par le front
            // Azure (411) — cf. ConnectorApiService::apiRaw.
            $this->apiJson('POST', self::MAIL_BASE . '/messages/' . rawurlencode($draftId) . '/send', []);
        } catch (\RuntimeException $e) {
            // Le 403 « permission » du socle nomme Mail.ReadWrite : ici c'est
            // Mail.Send qui manque, le remède doit le nommer.
            $raison = $e->getMessage() === self::MSG_SCOPE_INSUFFICIENT ? self::MSG_RECONNECT_SEND : $e->getMessage();
            throw new \RuntimeException($raison . ' ' . self::MSG_DRAFT_KEPT);
        }
        error_log('[Outlook user] email envoyé id=' . mb_substr($draftId, 0, 24) . '…'
            . (!empty($draft['reponse']) ? ' (réponse dans le fil)' : '')
            . ($this->accountEmail !== '' ? ' boîte=' . $this->accountEmail : ''));

        return [
            'id'           => $draftId,
            // Le brouillon n'existe plus sous cet identifiant une fois parti
            // (Graph le déplace) : on renvoie vers Éléments envoyés, sur le
            // bon domaine (cf. sentItemsUrl).
            'url'          => self::sentItemsUrl((string)$draft['url']),
            'destinataire' => (string)$draft['destinataire'],
            'objet'        => (string)$draft['objet'],
            'compte'       => $this->accountEmail,
            'reponse'      => (bool)($draft['reponse'] ?? false),
        ];
    }

    /**
     * Joint des fichiers à un brouillon (une requête par fichier, ≤ 3 Mo :
     * OutgoingAttachments les a bornés). Une pièce refusée : le brouillon
     * reste, sans elle, et l'erreur le dit.
     */
    private function addAttachments(string $draftId, array $attachments): void
    {
        foreach ($attachments as $a) {
            try {
                $this->apiJson('POST', self::MAIL_BASE . '/messages/' . rawurlencode($draftId) . '/attachments', OutgoingAttachments::graphPayload($a));
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Pièce jointe « ' . $a['name'] . ' » refusée par Outlook : ' . $e->getMessage() . ' ' . self::MSG_DRAFT_KEPT);
            }
        }
    }

    // ===================== Statiques pures (testables sans réseau) =====================

    /**
     * Dossier Éléments envoyés, sur le domaine du compte. Le `webLink` du
     * brouillon (rendu par Graph) dit lequel : outlook.live.com pour un compte
     * personnel, outlook.office.com pour un compte professionnel — le deviner
     * enverrait la moitié des utilisateurs sur une page vide.
     */
    public static function sentItemsUrl(string $draftWebLink): string
    {
        $host = strtolower((string)(parse_url($draftWebLink, PHP_URL_HOST) ?: ''));
        return $host === 'outlook.live.com'
            ? 'https://outlook.live.com/mail/0/sentitems'
            : 'https://outlook.office.com/mail/sentitems';
    }

    // `scopeGranted()` vit sur MicrosoftUserApiService : le piège de préfixe
    // (`Mail.Read` ⊂ `Mail.ReadWrite` ET `Mail.ReadBasic`) se rejoue à
    // l'identique sur `Calendars.*`, et deux comparaisons divergentes
    // afficheraient « Lecture ✓ » sur une connexion qui ne lit rien. Elle reste
    // adressable en `UserOutlookService::scopeGranted()` (héritage) — c'est
    // ainsi que la page Connexions l'appelle.

    /**
     * URL de recherche Graph. Requête vide → derniers messages reçus de la
     * boîte de réception, triés du plus récent au plus ancien. Requête non
     * vide → `$search`, qui INTERDIT `$orderby` (Graph classe alors par
     * pertinence) : les combiner produirait un HTTP 400.
     */
    public static function searchUrl(string $query, int $limit, int $cap = 20): string
    {
        $limit  = max(1, min($cap, $limit));
        $select = 'id,subject,from,receivedDateTime,bodyPreview,isRead,hasAttachments';
        $query  = trim($query);

        if ($query === '') {
            return self::MAIL_BASE . '/mailFolders/inbox/messages'
                . '?$top=' . $limit
                . '&$select=' . rawurlencode($select)
                . '&$orderby=' . rawurlencode('receivedDateTime desc');
        }

        return self::MAIL_BASE . '/messages'
            . '?$top=' . $limit
            . '&$select=' . rawurlencode($select)
            . '&$search=' . rawurlencode('"' . self::sanitizeSearch($query) . '"');
    }

    /**
     * Nettoie une requête `$search` : les guillemets fermeraient la chaîne KQL
     * (400), les caractères de contrôle n'y ont rien à faire.
     */
    public static function sanitizeSearch(string $query): string
    {
        $q = (string)preg_replace('/[\x00-\x1F\x7F"]+/u', ' ', $query);
        return trim((string)preg_replace('/\s+/u', ' ', $q));
    }

    /**
     * Lien d'ouverture d'un brouillon. `webLink` vient de Graph et pointe le
     * BON compte et le BON domaine (outlook.office.com pour un compte
     * professionnel, outlook.live.com pour un compte personnel) : le déduire
     * nous-mêmes enverrait la moitié des utilisateurs sur une page vide.
     */
    public static function draftUrl(array $draft): string
    {
        $lien = trim((string)($draft['webLink'] ?? ''));
        return $lien !== '' ? $lien : 'https://outlook.office.com/mail/drafts';
    }

    /**
     * Corps d'une réponse : notre texte AU-DESSUS de l'original cité par Graph.
     *
     * Le brouillon rendu par `createReply` est un document HTML complet, dont
     * l'en-tête porte les styles de la citation. On INSÈRE donc après la balise
     * `<body>` au lieu de concaténer deux documents : concaténer imbriquerait un
     * `<html>` dans un corps (rendu imprévisible d'un client à l'autre), et ne
     * garder que le corps intérieur jetterait les styles de la citation.
     *
     * Pur, testable sans réseau.
     */
    public static function mergeReplyBody(string $ourHtml, string $draftHtml): string
    {
        $draftHtml = trim($draftHtml);
        if ($draftHtml === '') {
            return $ourHtml;
        }
        // Insertion par OFFSET, jamais par preg_replace : la chaîne de
        // remplacement y interprète `$1` et `\1`, donc un corps parlant d'un
        // prix (« $1 000 ») aurait été mutilé en silence. `preg_match` avec
        // PREG_OFFSET_CAPTURE donne la position, `substr_replace` colle le
        // texte tel quel.
        if (preg_match('/<body\b[^>]*>/i', $draftHtml, $m, PREG_OFFSET_CAPTURE) === 1) {
            $apres = (int)$m[0][1] + strlen((string)$m[0][0]);
            return substr_replace($draftHtml, $ourHtml . '<br>', $apres, 0);
        }
        // Fragment sans <body> (Graph peut en rendre un) : concaténation simple.
        return $ourHtml . '<br>' . $draftHtml;
    }

    /**
     * Identifiant de message Graph valide. Renvoie l'identifiant nettoyé,
     * throw sinon : sans ce garde, un id inventé par le modèle partirait en
     * requête et rendrait un 400 illisible plutôt qu'une consigne claire.
     *
     * Jeu de caractères restreint au base64 URL-SAFE (`-` et `_`, jamais `+`
     * ni `/`), qui est précisément la forme des identifiants REST Outlook —
     * ils sont faits pour voyager dans une URL. Ni `.` ni `/` : ils n'y
     * apparaissent pas, et les accepter laisserait passer un `../..` qui n'a
     * rien à faire dans un segment de chemin (même si rawurlencode le
     * neutralise ensuite, le garde doit refuser à la source).
     */
    public static function assertMessageId(string $id): string
    {
        $id = trim($id);
        if (mb_strlen($id) < 16 || mb_strlen($id) > 1024
            || preg_match('#^[A-Za-z0-9_\-=]+$#', $id) !== 1) {
            throw new \RuntimeException(
                'Identifiant de message Outlook invalide — utilise rechercher_outlook d\'abord.'
            );
        }
        return $id;
    }

    /**
     * Charge utile Graph d'un brouillon. Le corps part en texte brut (jamais
     * de HTML fabriqué à partir du texte du modèle), les adresses sont
     * validées une à une : une adresse fantaisiste produirait un 400 opaque.
     *
     * Pas d'assemblage MIME ici, contrairement à Gmail : Graph reçoit du JSON,
     * il n'y a donc aucun en-tête à protéger d'une injection CR/LF. Les
     * valeurs restent nettoyées pour l'objet, qui s'affiche tel quel.
     */
    public static function buildDraftPayload(string $to, string $subject, string $body, string $cc = '', array $images = []): array
    {
        $toAddresses = self::parseAddressList($to);
        if ($toAddresses === []) {
            throw new \RuntimeException('Aucun destinataire fourni.');
        }
        $ccAddresses = self::parseAddressList($cc);

        $payload = [
            'subject'      => self::cleanHeaderValue($subject),
            // HTML plutôt que Text : le modèle écrit spontanément du Markdown,
            // qui atterrissait EN TOUTES LETTRES chez le destinataire (même
            // symptôme que côté Gmail). Conversion par [[MailHtml]], source
            // unique partagée par les deux messageries.
            'body'         => ['contentType' => 'HTML', 'content' => MailHtml::withInlineImages(MailHtml::toHtml($body), $images)],
            'toRecipients' => array_map(
                static fn(string $a): array => ['emailAddress' => ['address' => $a]],
                $toAddresses
            ),
        ];
        if ($ccAddresses !== []) {
            $payload['ccRecipients'] = array_map(
                static fn(string $a): array => ['emailAddress' => ['address' => $a]],
                $ccAddresses
            );
        }
        return $payload;
    }

    /**
     * Liste d'adresses séparées par des virgules → adresses validées.
     * Throw RuntimeException sur la première adresse invalide.
     */
    public static function parseAddressList(string $list): array
    {
        $out = [];
        foreach (explode(',', self::cleanHeaderValue($list)) as $addr) {
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

    /** Retire CR, LF et NUL d'une valeur destinée à un champ d'en-tête. */
    public static function cleanHeaderValue(string $value): string
    {
        return trim((string)preg_replace('/[\r\n\x00]+/', ' ', $value));
    }

    /**
     * Corps texte d'un message Graph : `body` en text/plain tel quel, sinon
     * HTML strippé, sinon l'aperçu (`bodyPreview`) — dernier recours pour un
     * message 100 % image.
     */
    public static function extractBodyText(array $message): string
    {
        $type    = strtolower((string)($message['body']['contentType'] ?? ''));
        $content = (string)($message['body']['content'] ?? '');

        if ($content !== '') {
            $text = $type === 'html' ? self::htmlToText($content) : trim($content);
            if ($text !== '') {
                return $text;
            }
        }
        return trim((string)($message['bodyPreview'] ?? ''));
    }

    /** HTML → texte lisible : balises retirées, entités décodées, blancs normalisés. */
    public static function htmlToText(string $html): string
    {
        // <br> et fins de bloc deviennent des sauts de ligne AVANT strip_tags,
        // sinon tout le message s'écrase en un seul paragraphe illisible.
        $html = (string)preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])[^>]*>#i', "\n", $html);
        $html = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        return trim((string)preg_replace('/[ \t]*\n[ \t]*(\n[ \t]*)+/', "\n\n", $text));
    }

    /** Ligne de résultat de recherche (mêmes clés FR que Gmail, + Outlook). */
    public static function summarizeMessage(array $m): array
    {
        return [
            'id'             => (string)($m['id'] ?? ''),
            'de'             => self::formatRecipient($m['from'] ?? []),
            'objet'          => (string)($m['subject'] ?? ''),
            'date'           => self::formatDateTime((string)($m['receivedDateTime'] ?? '')),
            'extrait'        => trim((string)($m['bodyPreview'] ?? '')),
            'lu'             => (bool)($m['isRead'] ?? false),
            'pieces_jointes' => (bool)($m['hasAttachments'] ?? false),
        ];
    }

    /** `{emailAddress:{name,address}}` → « Nom <adresse> », ou l'un des deux. */
    public static function formatRecipient(array $recipient): string
    {
        $mail = trim((string)($recipient['emailAddress']['address'] ?? ''));
        $nom  = trim((string)($recipient['emailAddress']['name'] ?? ''));
        if ($mail === '') {
            return $nom;
        }
        return $nom !== '' && strcasecmp($nom, $mail) !== 0 ? $nom . ' <' . $mail . '>' : $mail;
    }

    /** Liste de destinataires Graph → « a@x.fr, b@y.fr » (adresses seules). */
    public static function formatRecipients(array $recipients): string
    {
        $out = [];
        foreach ($recipients as $r) {
            if (is_array($r) && ($ligne = self::formatRecipient($r)) !== '') {
                $out[] = $ligne;
            }
        }
        return implode(', ', $out);
    }

    /**
     * Horodatage ISO 8601 UTC de Graph → « 15/01/2026 09:32 » dans le fuseau
     * du serveur. Une chaîne illisible est rendue telle quelle plutôt que
     * remplacée par une date fausse.
     */
    public static function formatDateTime(string $iso): string
    {
        if (trim($iso) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return $iso;
        }
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

        // En réponse, destinataire et objet sont repris du message d'origine à
        // l'exécution : la carte doit le DIRE plutôt que d'afficher « (sans
        // objet) → », qui se lirait comme un brouillon incomplet.
        $reponse = trim((string)($args['repondre_a'] ?? '')) !== '';

        return [
            'action'  => $reponse ? t('Réponse à un email (Outlook)') : t('Nouvel email Outlook'),
            'fichier' => ($objet !== '' ? $objet : ($reponse ? t('(objet repris du message)') : t('(sans objet)')))
                . ($dest !== '' ? ' → ' . $dest : ($reponse ? ' → ' . t('expéditeur du message') : ''))
                . ($cc !== '' ? ' ' . t('(cc : {destinataires})', ['destinataires' => $cc]) : ''),
            'url'     => null,
            'impacts' => array_values(array_filter([
                t('« Créer le brouillon » le range dans vos Brouillons Outlook sans rien envoyer ; « Envoyer » l\'expédie immédiatement à ses destinataires, sans retour possible.'),
                $reponse ? t('Il sera rattaché à la conversation d\'origine (objet « RE: … » et fil conservé).') : null,
            ])),
            'apercu'  => $apercu,
            'icone'   => '/assets/img/outlook.svg',
            // Carte de RELECTURE : même bloc que Gmail (cf. MailDraftReview).
            'mail'    => MailDraftReview::block('outlook', $args),
        ];
    }
}
