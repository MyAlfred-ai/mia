<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Connecteur Outlook : logique PURE du service (aucun réseau, aucune base) et
 * câblage du connecteur dans l'application.
 *
 * Les gardes visent les pièges réels de Microsoft Graph :
 *  - `Mail.Read` est un PRÉFIXE de `Mail.ReadWrite` ET de `Mail.ReadBasic` :
 *    une comparaison par sous-chaîne annoncerait « Lecture ✓ » sur une
 *    connexion incapable de lire un corps de message ;
 *  - `$search` et `$orderby` sont INCOMPATIBLES : les combiner rend un 400 ;
 *  - l'envoi n'a qu'UN chemin : `POST /me/messages/{id}/send` sur le brouillon
 *    que l'application vient de créer — jamais `/me/sendMail` —, et le droit
 *    `Mail.Send` ne sert qu'au bouton « Envoyer » de la carte de relecture.
 */
final class UserOutlookServiceTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    // ===================== Scopes =====================

    public function testScopeGrantedCompareLesJetonsExactement(): void
    {
        // Forme courte et forme préfixée d'URI : Microsoft renvoie les deux.
        $this->assertTrue(UserOutlookService::scopeGranted('Mail.Read User.Read', 'Mail.Read'));
        $this->assertTrue(UserOutlookService::scopeGranted(
            'https://graph.microsoft.com/Mail.ReadWrite https://graph.microsoft.com/User.Read',
            'Mail.ReadWrite'
        ));

        // LE piège : Mail.Read est un préfixe des deux autres.
        $this->assertFalse(UserOutlookService::scopeGranted('Mail.ReadBasic', 'Mail.Read'));
        $this->assertFalse(UserOutlookService::scopeGranted('Mail.Read', 'Mail.ReadWrite'));
        $this->assertTrue(UserOutlookService::scopeGranted('Mail.ReadWrite', 'Mail.ReadWrite'));

        $this->assertFalse(UserOutlookService::scopeGranted('', 'Mail.Read'));
        $this->assertFalse(UserOutlookService::scopeGranted('offline_access User.Read', 'Mail.Read'));
    }

    // ===================== Recherche =====================

    public function testRequeteVideRendLesDerniersMessagesRecus(): void
    {
        $url = UserOutlookService::searchUrl('   ', 10);

        $this->assertStringContainsString('/mailFolders/inbox/messages', $url);
        $this->assertStringContainsString('$top=10', $url);
        $this->assertStringContainsString(rawurlencode('receivedDateTime desc'), $url);
        $this->assertStringNotContainsString('$search', $url);
    }

    public function testRechercheNeCombineJamaisSearchEtOrderby(): void
    {
        // Graph refuse la combinaison en HTTP 400 : la garde est ici, pas dans
        // un commentaire.
        $url = UserOutlookService::searchUrl('from:alice@ex.fr', 5);

        $this->assertStringContainsString('$search=', $url);
        $this->assertStringNotContainsString('$orderby', $url);
        $this->assertStringContainsString('$top=5', $url);
        // La valeur de $search est encadrée de guillemets (syntaxe KQL).
        $this->assertStringContainsString(rawurlencode('"from:alice@ex.fr"'), $url);
    }

    public function testLimiteDeResultatsBornee(): void
    {
        $this->assertStringContainsString('$top=1', UserOutlookService::searchUrl('x', 0));
        $this->assertStringContainsString('$top=20', UserOutlookService::searchUrl('x', 999));
    }

    public function testSanitizeSearchNeutraliseLesGuillemets(): void
    {
        // Un guillemet fermerait la chaîne KQL → 400.
        $this->assertSame('objet contrat', UserOutlookService::sanitizeSearch('objet "contrat"'));
        $this->assertSame('a b', UserOutlookService::sanitizeSearch("a\n\tb"));
    }

    // ===================== Identifiants =====================

    public function testIdentifiantDeMessageValide(): void
    {
        $id = 'AAMkAGI2NGVhZDNlLTk1ODUtNDg5MC1iZmZmLTBlMzZkYjkyNmE0NwBGAAAAAAA=';
        $this->assertSame($id, UserOutlookService::assertMessageId(' ' . $id . ' '));
    }

    public function testIdentifiantDeMessageInvalideRefuse(): void
    {
        foreach (['', 'court', 'abc def ghi jkl mno', '../../etc/passwd-aaaaaaaaaa'] as $mauvais) {
            try {
                UserOutlookService::assertMessageId($mauvais);
                $this->fail('Identifiant accepté à tort : « ' . $mauvais . ' »');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('rechercher_outlook', $e->getMessage());
            }
        }
    }

    // ===================== Brouillon =====================

    public function testChargeUtileDuBrouillon(): void
    {
        $payload = UserOutlookService::buildDraftPayload(
            'alice@ex.fr, bob@ex.fr',
            'Compte rendu',
            "Bonjour,\n\nVoici le compte rendu.",
            'chef@ex.fr'
        );

        $this->assertSame('Compte rendu', $payload['subject']);
        // HTML : le modèle écrit du Markdown, qui arrivait en toutes lettres
        // chez le destinataire. La conversion passe par [[MailHtml]], seul
        // endroit où du HTML est fabriqué — et où le texte du modèle est
        // échappé avant toute transformation.
        $this->assertSame('HTML', $payload['body']['contentType']);
        $this->assertStringContainsString('compte rendu', $payload['body']['content']);
        $this->assertStringContainsString('<p>', $payload['body']['content']);
        $this->assertSame(
            ['alice@ex.fr', 'bob@ex.fr'],
            array_column(array_column($payload['toRecipients'], 'emailAddress'), 'address')
        );
        $this->assertSame(
            ['chef@ex.fr'],
            array_column(array_column($payload['ccRecipients'], 'emailAddress'), 'address')
        );
    }

    public function testBrouillonSansCopieNAPasDeChampCc(): void
    {
        $payload = UserOutlookService::buildDraftPayload('alice@ex.fr', 'Objet', 'Corps', '');
        $this->assertArrayNotHasKey('ccRecipients', $payload);
    }

    public function testAdresseInvalideRefusee(): void
    {
        $this->expectException(\RuntimeException::class);
        UserOutlookService::buildDraftPayload('pas-une-adresse', 'Objet', 'Corps');
    }

    public function testDestinataireVideRefuse(): void
    {
        $this->expectException(\RuntimeException::class);
        UserOutlookService::buildDraftPayload('   ', 'Objet', 'Corps');
    }

    public function testValeurDEnTeteNettoyee(): void
    {
        $this->assertSame(
            'Objet suspect Bcc: pirate@ex.fr',
            UserOutlookService::cleanHeaderValue("Objet suspect\r\nBcc: pirate@ex.fr")
        );
    }

    // ===================== Corps du message =====================

    public function testCorpsTextePrefere(): void
    {
        $this->assertSame('Bonjour', UserOutlookService::extractBodyText([
            'body'        => ['contentType' => 'text', 'content' => "Bonjour\n"],
            'bodyPreview' => 'ignoré',
        ]));
    }

    public function testCorpsHtmlStrippe(): void
    {
        $texte = UserOutlookService::extractBodyText([
            'body' => ['contentType' => 'html', 'content' => '<p>Bonjour</p><p>Merci &amp; bonne journ&eacute;e</p>'],
        ]);
        $this->assertStringContainsString('Bonjour', $texte);
        $this->assertStringContainsString('Merci & bonne journée', $texte);
        $this->assertStringNotContainsString('<p>', $texte);
    }

    public function testHtmlToTextGardeLesSautsDeLigneEtRetireLesScripts(): void
    {
        $texte = UserOutlookService::htmlToText('<div>Un</div><div>Deux</div><script>alert(1)</script>');
        $this->assertStringContainsString("Un\nDeux", $texte);
        $this->assertStringNotContainsString('alert', $texte);
    }

    public function testApercuEnDernierRecours(): void
    {
        // Message 100 % image : aucun corps exploitable.
        $this->assertSame('Aperçu', UserOutlookService::extractBodyText([
            'body'        => ['contentType' => 'html', 'content' => '<img src="x">'],
            'bodyPreview' => 'Aperçu',
        ]));
        $this->assertSame('', UserOutlookService::extractBodyText([]));
    }

    // ===================== Mise en forme =====================

    public function testFormatDesDestinataires(): void
    {
        $this->assertSame(
            'Alice <alice@ex.fr>',
            UserOutlookService::formatRecipient(['emailAddress' => ['name' => 'Alice', 'address' => 'alice@ex.fr']])
        );
        // Nom identique à l'adresse : ne pas l'écrire deux fois.
        $this->assertSame(
            'bob@ex.fr',
            UserOutlookService::formatRecipient(['emailAddress' => ['name' => 'bob@ex.fr', 'address' => 'bob@ex.fr']])
        );
        $this->assertSame('', UserOutlookService::formatRecipient([]));
        $this->assertSame(
            'a@ex.fr, b@ex.fr',
            UserOutlookService::formatRecipients([
                ['emailAddress' => ['address' => 'a@ex.fr']],
                ['emailAddress' => ['address' => 'b@ex.fr']],
            ])
        );
    }

    public function testHorodatageConvertiDansLeFuseauDuServeur(): void
    {
        $avant = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');
        try {
            $this->assertSame('15/01/2026 10:32', UserOutlookService::formatDateTime('2026-01-15T09:32:00Z'));
            // Chaîne illisible rendue telle quelle plutôt qu'une date fausse.
            $this->assertSame('n/a', UserOutlookService::formatDateTime('n/a'));
            $this->assertSame('', UserOutlookService::formatDateTime('  '));
        } finally {
            date_default_timezone_set($avant);
        }
    }

    public function testResumeDeMessage(): void
    {
        $resume = UserOutlookService::summarizeMessage([
            'id'             => 'AAA',
            'from'           => ['emailAddress' => ['name' => 'Alice', 'address' => 'alice@ex.fr']],
            'subject'        => 'Contrat',
            'bodyPreview'    => ' extrait ',
            'isRead'         => false,
            'hasAttachments' => true,
        ]);

        $this->assertSame('AAA', $resume['id']);
        $this->assertSame('Alice <alice@ex.fr>', $resume['de']);
        $this->assertSame('extrait', $resume['extrait']);
        $this->assertFalse($resume['lu']);
        $this->assertTrue($resume['pieces_jointes']);
    }

    // ===================== Carte d'approbation =====================

    public function testApercuDApprobationNommeLesDeuxIssuesBrouillonEtEnvoi(): void
    {
        $apercu = UserOutlookService::actionPreview('creer_brouillon_outlook', [
            'destinataire' => 'alice@ex.fr',
            'objet'        => 'Contrat',
            'cc'           => 'chef@ex.fr',
            'corps'        => str_repeat('a', 400),
        ]);

        $this->assertSame('Nouvel email Outlook', $apercu['action']);
        $this->assertStringContainsString('alice@ex.fr', $apercu['fichier']);
        $this->assertStringContainsString('chef@ex.fr', $apercu['fichier']);
        $this->assertSame('/assets/img/outlook.svg', $apercu['icone']);
        // La carte porte deux issues d'exécution : les impacts disent ce que
        // fait chacune — personne ne doit découvrir au clic que l'une expédie.
        $impacts = implode(' ', $apercu['impacts']);
        $this->assertStringContainsString('« Créer le brouillon »', $impacts);
        $this->assertStringContainsString('sans rien envoyer', $impacts);
        $this->assertStringContainsString('« Envoyer »', $impacts);
        $this->assertStringContainsString('sans retour possible', $impacts);
        // Extrait borné : la carte ne déroule pas un email entier.
        $this->assertSame(281, mb_strlen((string)$apercu['apercu']));
    }

    // ===================== Erreurs Graph =====================

    public function testBoiteInexistanteReconnue(): void
    {
        $this->assertTrue(MicrosoftUserApiService::isMailboxMissing('MailboxNotEnabledForRESTAPI', ''));
        $this->assertTrue(MicrosoftUserApiService::isMailboxMissing('', '{"message":"MailboxNotHostedInExchangeOnline"}'));
        $this->assertFalse(MicrosoftUserApiService::isMailboxMissing('ErrorAccessDenied', '{}'));
    }

    public function testPermissionRefuseeReconnue(): void
    {
        $this->assertTrue(MicrosoftUserApiService::isPermissionDenied('Authorization_RequestDenied'));
        $this->assertTrue(MicrosoftUserApiService::isPermissionDenied('ErrorAccessDenied'));
        $this->assertFalse(MicrosoftUserApiService::isPermissionDenied('ErrorItemNotFound'));
        $this->assertFalse(MicrosoftUserApiService::isPermissionDenied(''));
    }

    // ===================== Invariant : un seul chemin d'envoi, aucune destruction =====================

    /**
     * Le scope Mail.ReadWrite ouvre côté API la modification et la suppression
     * de messages, et Mail.Send l'envoi de n'importe quelle charge utile. La
     * promesse « MIA ne fait que lire et préparer, et n'envoie que ce que vous
     * avez relu, sur votre clic » ne tient que si AUCUN code n'appelle autre
     * chose : c'est ce que garde ce test, pas la consigne du system prompt.
     */
    public function testUnSeulCheminDEnvoiEtAucuneSuppressionDansLeConnecteur(): void
    {
        // Le CODE seul : les commentaires de ces fichiers citent justement
        // /sendMail pour expliquer pourquoi il est absent.
        $service = self::codeSansCommentaires('src/Outlook/UserOutlookService.php');

        $this->assertContains('offline_access', MicrosoftOutlookOAuth::SCOPES, 'Sans offline_access, aucun refresh token.');
        $this->assertContains('Mail.ReadWrite', MicrosoftOutlookOAuth::SCOPES, 'Sans Mail.ReadWrite, aucun brouillon.');
        $this->assertContains('Mail.Send', MicrosoftOutlookOAuth::SCOPES, 'Sans Mail.Send, pas de bouton « Envoyer ».');

        // Un SEUL point d'envoi : `/messages/{id}/send` sur le brouillon que
        // l'application vient de créer ($draftId) — jamais `/me/sendMail`, qui
        // expédierait une charge utile assemblée à part et ne sait pas
        // répondre dans un fil. Et aucune destruction.
        $this->assertStringNotContainsString('sendMail', $service);
        $this->assertSame(1, substr_count($service, "/send'"), 'un seul chemin d\'envoi');
        $this->assertStringContainsString("rawurlencode(\$draftId) . '/send'", $service);
        $this->assertStringNotContainsString("'DELETE'", $service);

        // Une SEULE écriture est légitime : le PATCH du brouillon que Graph
        // vient de créer pour nous (`createReply`). La garde ne peut donc plus
        // être « aucun PATCH » — elle vérifie que chaque PATCH vise `$draftId`,
        // c'est-à-dire un message que l'application a elle-même fait naître.
        // Un PATCH sur un identifiant venu du modèle modifierait un message
        // existant : exactement ce que le connecteur promet de ne pas faire.
        $patchs = [];
        preg_match_all("/apiJson\(\s*'PATCH'\s*,\s*([^,]+),/", $service, $patchs);
        $this->assertNotEmpty($patchs[1], 'Le PATCH du brouillon de réponse a disparu.');
        foreach ($patchs[1] as $cible) {
            $this->assertStringContainsString(
                '$draftId',
                $cible,
                'Un PATCH Outlook vise autre chose que le brouillon créé par createReply : ' . trim($cible)
            );
        }
    }

    /** Source d'un fichier PHP, commentaires retirés (garde textuelle honnête). */
    private static function codeSansCommentaires(string $chemin): string
    {
        $out = '';
        foreach (token_get_all(self::lire($chemin)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * Câblage complet : un connecteur à moitié branché n'échoue pas, il
     * n'apparaît nulle part — la panne la plus difficile à voir.
     */
    public function testLeConnecteurEstBrancheDeBoutEnBout(): void
    {
        $ligne = ConnectorRegistry::row(ConnectorRegistry::OUTLOOK);
        $this->assertIsArray($ligne);
        $this->assertSame('microsoft', $ligne['family']);
        $this->assertSame(ConnectorRegistry::SURFACE_CHAT, $ligne['surface']);
        $this->assertSame(UserDriveToken::PROVIDER_OUTLOOK, $ligne['token']);
        $this->assertSame(UserOutlookService::SETTING_ENABLED, $ligne['setting']);
        $this->assertContains(ConnectorRegistry::OUTLOOK, ConnectorRegistry::keys());

        // Outils exposés au modèle + dispatch de la file d'approbation.
        $chat = self::lire('src/Controllers/ChatController.php');
        foreach (['rechercher_outlook', 'lire_outlook', 'creer_brouillon_outlook'] as $outil) {
            $this->assertStringContainsString("'" . $outil . "'", $chat, "Outil $outil non branché.");
        }
        $this->assertStringContainsString(
            "UserOutlookService::actionPreview(",
            $chat,
            'La carte d\'approbation du brouillon Outlook n\'est pas branchée.'
        );

        // Menu « + », interrupteur, suggestion d'intention, pont natif.
        $this->assertStringContainsString('plusMenuOutlookRow', self::lire('templates/chat/index.php'));
        $this->assertStringContainsString('outlookAccessToggle', self::lire('public/assets/js/chat.js'));
        $this->assertStringContainsString("connectorRule('outlook'", self::lire('public/assets/js/tool-suggest.js'));
        $this->assertStringContainsString('/settings/outlook/connect', self::lire('public/assets/js/native-bridge.js'));

        // Page Connexions.
        $this->assertStringContainsString('/settings/outlook/disconnect', self::lire('templates/settings/connections.php'));

        // Expressions déclencheuses : jamais un mot générique seul.
        $triggers = ConnectorRegistry::defaultTriggers(ConnectorRegistry::OUTLOOK);
        $this->assertNotEmpty($triggers);
        foreach (['mail', 'email', 'messagerie', 'boîte'] as $generique) {
            $this->assertNotContains($generique, $triggers, "« $generique » seul sur-déclencherait.");
        }
        $this->assertContains('outlook', $triggers);
    }

    // ===================== Réponse dans le fil =====================

    /**
     * Un POST sur /me/messages ouvre une conversation NEUVE : répondre exige
     * `createReply`, qui pose le `conversationId` ET les en-têtes RFC
     * (In-Reply-To / References) que Graph n'expose pas en écriture. C'est le
     * défaut déjà payé côté Gmail — il ne se voit nulle part, la réponse part
     * simplement hors du fil.
     */
    public function testLaReponsePasseParCreateReply(): void
    {
        $service = self::codeSansCommentaires('src/Outlook/UserOutlookService.php');
        $this->assertStringContainsString('/createReply', $service);

        // Le corps est converti par MailHtml, jamais posé via `comment` (dont
        // l'interprétation HTML n'est pas garantie : le Markdown converti
        // risquerait de s'afficher en toutes lettres).
        $this->assertStringContainsString('MailHtml::toHtml(', $service);
        $this->assertStringNotContainsString("'comment' => MailHtml", $service);

        // L'objet n'est jamais réécrit en réponse : Graph le dérive de
        // l'original, le changer scinde le fil chez certains clients.
        $this->assertStringNotContainsString("'subject' => ", $service === '' ? 'x' : substr($service, strpos($service, 'createReplyDraft')));
    }

    /**
     * Notre texte va AU-DESSUS de la citation, et le document rendu par Graph
     * est conservé entier : concaténer deux documents imbriquerait un <html>
     * dans un corps, et ne garder que le corps intérieur jetterait les styles
     * de la citation.
     */
    public function testLeCorpsDeReponseSInsereDansLeDocumentDeGraph(): void
    {
        $graph = '<html><head><style>.q{color:#666}</style></head><body><div id="q">Original</div></body></html>';
        $fusion = UserOutlookService::mergeReplyBody('<p>Ma réponse</p>', $graph);

        $this->assertStringContainsString('<style>.q{color:#666}</style>', $fusion, 'Styles de la citation perdus.');
        $this->assertStringContainsString('Original', $fusion, 'Citation perdue.');
        $this->assertLessThan(
            strpos($fusion, 'Original'),
            strpos($fusion, 'Ma réponse'),
            'La réponse doit précéder la citation.'
        );
        $this->assertSame(1, substr_count($fusion, '<html'), 'Un seul document HTML.');

        // Fragment sans <body> : concaténation simple, jamais une perte.
        $this->assertSame('<p>A</p><br><div>frag</div>', UserOutlookService::mergeReplyBody('<p>A</p>', '<div>frag</div>'));
        $this->assertSame('<p>A</p>', UserOutlookService::mergeReplyBody('<p>A</p>', '   '));
    }

    /**
     * `preg_replace` interprète `$1` et `\1` dans le REMPLACEMENT : un corps
     * de mail contenant « $1 » (un prix, une référence) aurait été remplacé par
     * la capture, donc mutilé en silence.
     */
    public function testUnCorpsPortantUnMotifDeRemplacementSurvit(): void
    {
        $fusion = UserOutlookService::mergeReplyBody(
            '<p>Le devis passe de $1 000 à $2 000 \\1</p>',
            '<html><body>Original</body></html>'
        );
        $this->assertStringContainsString('$1 000', $fusion);
        $this->assertStringContainsString('$2 000', $fusion);
        $this->assertStringContainsString('\\1', $fusion);
    }

    /**
     * Le modèle ne peut répondre que s'il connaît l'identifiant du message
     * qu'il vient de lire : `readMessage` doit le rendre.
     */
    public function testLaLectureRendLIdentifiantDuMessage(): void
    {
        $service = self::lire('src/Outlook/UserOutlookService.php');
        $this->assertMatchesRegularExpression(
            "/'id'\s*=>\s*\(string\)\(\\\$data\['id'\]/",
            $service,
            'readMessage doit rendre l\'id du message.'
        );

        // Et l'outil doit l'accepter, en n'exigeant plus destinataire ni objet.
        $chat = AgentSource::chat();
        $this->assertStringContainsString("'repondre_a'   => ['type' => 'string', 'description' => \"ID du message Outlook", $chat);
        $this->assertStringContainsString("(string)(\$action['args']['repondre_a'] ?? '')", $chat);
    }
}
