<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Brouillons d'email : réponse DANS LE FIL, corps en HTML, signature.
 *
 * TROIS DÉFAUTS CORRIGÉS, tous invisibles jusqu'à l'ouverture de la boîte :
 *
 *  1. « Réponds à ce mail » créait un fil NEUF à chaque fois. `createDraft`
 *     n'a jamais posé de `threadId` ni d'en-têtes `In-Reply-To`/`References`,
 *     et `readMessage` ne rendait même pas l'identifiant du message — rien,
 *     en aval, ne POUVAIT rattacher quoi que ce soit.
 *  2. Le corps partait en `text/plain` : le Markdown que le modèle écrit
 *     spontanément arrivait en toutes lettres chez le destinataire.
 *  3. Aucune signature, alors que la boîte en porte une.
 *
 * Tout est vérifié sans réseau : fonctions pures et lecture du code source.
 */
final class MailDraftTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    /** Contrôleur + registre d'outils (src/Agent/) : les handlers y vivent. */
    private function chat(): string
    {
        return AgentSource::chat();
    }

    // ---------------------------------------------------------------------
    // 1. Markdown → HTML
    // ---------------------------------------------------------------------

    public function testLeMarkdownDuModeleDevientDuVraiHtml(): void
    {
        $html = MailHtml::toHtml("Bonjour,\n\nVoici le **point** sur le dossier :\n\n- premier\n- second\n\nMerci.");

        $this->assertStringContainsString('<strong>point</strong>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>premier</li>', $html);
        $this->assertStringContainsString('<p>Bonjour,</p>', $html);
        $this->assertStringNotContainsString('**', $html, 'Les marqueurs Markdown ne doivent plus apparaître.');
        $this->assertStringNotContainsString('- premier', $html);
    }

    public function testUnSautDeLigneSimpleResteUnSautDeLigne(): void
    {
        // Une adresse postale ou une liste de points sur des lignes contiguës
        // serait recollée en un seul bloc sans <br>.
        $html = MailHtml::toHtml("Ligne A\nLigne B");
        $this->assertStringContainsString('Ligne A<br>Ligne B', $html);
    }

    public function testLesListesNumeroteesEtLesTitresSontRendus(): void
    {
        $html = MailHtml::toHtml("## Résumé\n\n1. Un\n2. Deux");
        $this->assertStringContainsString('<h4>Résumé</h4>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>Deux</li>', $html);
    }

    public function testLesLiensMarkdownEtLesUrlNuesDeviennentCliquables(): void
    {
        $html = MailHtml::toHtml('Voir [le devis](https://ex.fr/devis) ou https://ex.fr/autre');
        $this->assertStringContainsString('<a href="https://ex.fr/devis">le devis</a>', $html);
        $this->assertStringContainsString('<a href="https://ex.fr/autre">', $html);
    }

    public function testLeTexteDuModeleEstEchappeAvantToutTraitement(): void
    {
        // Le corps vient d'un modèle : il ne doit jamais pouvoir introduire de
        // balise dans le message envoyé au destinataire.
        $html = MailHtml::toHtml('Attention <script>alert(1)</script> et a < b');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testUnLienDeSchemaDangereuxPerdSonHref(): void
    {
        $html = MailHtml::toHtml('[clique ici](javascript:alert(1))');
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('clique ici', $html, 'Le libellé survit en texte.');
    }

    public function testDuHtmlFourniParLeModeleEstNettoyeEtNonEchappe(): void
    {
        $html = MailHtml::toHtml('<p onclick="x()">Bonjour <b>Marie</b></p><script>alert(1)</script>');

        // Échapper afficherait « <p> » en clair ; laisser passer ouvrirait la
        // porte aux scripts et aux styles.
        $this->assertStringContainsString('<p>Bonjour <b>Marie</b></p>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function testUnLienHtmlGardeSonHrefSiLeSchemaEstSain(): void
    {
        $this->assertStringContainsString(
            '<a href="https://ex.fr">ici</a>',
            MailHtml::toHtml('<p>Voir <a href="https://ex.fr" target="_blank">ici</a></p>')
        );
        $this->assertStringNotContainsString('target', MailHtml::toHtml('<p><a href="https://ex.fr" target="_blank">ici</a></p>'));
    }

    public function testLaPartTexteResteLisible(): void
    {
        $this->assertSame("Bonjour\n\nMerci", MailHtml::toPlain("Bonjour\r\n\r\nMerci"));
        $this->assertStringContainsString('Bonjour Marie', MailHtml::toPlain('<p>Bonjour <b>Marie</b></p>'));
        $this->assertStringNotContainsString('<b>', MailHtml::toPlain('<p>Bonjour <b>Marie</b></p>'));
    }

    // ---------------------------------------------------------------------
    // 2. Signature
    // ---------------------------------------------------------------------

    public function testLaSignatureEstAjouteeApresUnSeparateurStandard(): void
    {
        $avec = MailHtml::avecSignature('<p>Bonjour</p>', '<div>Alex — MyAlfred</div>');
        $this->assertStringContainsString('<p>Bonjour</p>', $avec);
        $this->assertStringContainsString('--<br>', $avec);
        $this->assertStringContainsString('Alex — MyAlfred', $avec);
    }

    public function testUneSignatureVideNeLaissePasDeSeparateurOrphelin(): void
    {
        $this->assertSame('<p>Bonjour</p>', MailHtml::avecSignature('<p>Bonjour</p>', '   '));
        $this->assertSame('Bonjour', MailHtml::avecSignatureTexte('Bonjour', ''));
    }

    public function testLaSignatureHtmlSeRetrouveAussiDansLaPartTexte(): void
    {
        $txt = MailHtml::avecSignatureTexte('Bonjour', '<div>Alex<br>MyAlfred</div>');
        $this->assertStringContainsString("--\nAlex\nMyAlfred", $txt);
    }

    public function testLaSignatureNEstLueQueSiLeScopeEstAccorde(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Gmail/UserGmailService.php');

        // Comparaison EXACTE partagée avec la page Connexions : une connexion
        // antérieure n'a pas ce scope et doit simplement partir sans signature.
        $this->assertStringContainsString('scopeGranted($this->grantedScope, self::SCOPE_SETTINGS)', $src);
        $this->assertStringContainsString('if (!$this->canReadSettings()) {', $src);
        // Aucune écriture de réglage : seul sendAs.list est appelé.
        $this->assertStringContainsString("'GET', self::API_BASE . '/settings/sendAs'", $src);
        $this->assertSame(1, substr_count($src, "/settings/sendAs"));

        $this->assertContains(
            UserGmailService::SCOPE_SETTINGS,
            GoogleGmailOAuth::SCOPES,
            'Le scope doit être demandé au consentement, sinon la signature n\'est jamais lisible.'
        );
        $this->assertNotContains('https://www.googleapis.com/auth/gmail.send', GoogleGmailOAuth::SCOPES);
    }

    // ---------------------------------------------------------------------
    // 3. Réponse dans le fil
    // ---------------------------------------------------------------------

    public function testLeMimeDUneReponsePorteLesEnTetesDeChainage(): void
    {
        $mime = UserGmailService::buildDraftMime(
            'marie@ex.fr',
            'Re: Devis',
            'Bonjour',
            '',
            ['message_id' => '<abc@mail.gmail.com>', 'references' => '<x@ex.fr> <abc@mail.gmail.com>']
        );

        $this->assertStringContainsString("In-Reply-To: <abc@mail.gmail.com>\r\n", $mime);
        $this->assertStringContainsString("References: <x@ex.fr> <abc@mail.gmail.com>\r\n", $mime);
    }

    public function testUnBrouillonNormalNePorteAucunEnTeteDeChainage(): void
    {
        $mime = UserGmailService::buildDraftMime('marie@ex.fr', 'Devis', 'Bonjour');
        $this->assertStringNotContainsString('In-Reply-To', $mime);
        $this->assertStringNotContainsString('References', $mime);
    }

    public function testLeThreadIdEtLesEnTetesVoyagentENSEMBLE(): void
    {
        // Gmail ne rattache le brouillon que si les DEUX sont présents : le
        // threadId le range dans notre boîte, les en-têtes font le fil chez le
        // destinataire. L'un sans l'autre produit un fil neuf, sans erreur.
        $src = (string)file_get_contents(ROOT . '/src/Gmail/UserGmailService.php');
        // Le fil voyage dans la charge utile `raw` — la même pour le brouillon
        // et pour l'envoi (rawPayload), assemblée par composeMessage.
        $this->assertStringContainsString("'threadId' => \$msg['thread_id']", $src);
        $this->assertStringContainsString("\$reply    = ['message_id' => \$ctx['message_id'], 'references' => \$ctx['references']];", $src);
    }

    public function testLaChaineReferencesCumuleLHistoriqueDuFil(): void
    {
        $src   = (string)file_get_contents(ROOT . '/src/Gmail/UserGmailService.php');
        $debut = strpos($src, 'function replyContext(');
        $chunk = substr($src, (int)$debut, 2500);
        // Remplacer References par le seul Message-ID casserait le fil chez les
        // clients qui suivent la chaîne complète.
        $this->assertStringContainsString("trim((string)(\$h['references'] ?? '')) . ' ' . \$idHeader", $chunk);
    }

    public function testLObjetDUneReponseNeCumulePasLesRe(): void
    {
        $this->assertSame('Re: Devis', UserGmailService::replySubject('', 'Devis'));
        $this->assertSame('Re: Devis', UserGmailService::replySubject('Re: Devis', 'Re: Devis'));
        $this->assertSame('RE : Devis', UserGmailService::replySubject('', 'RE : Devis'));
        $this->assertSame('Fwd: Devis', UserGmailService::replySubject('', 'Fwd: Devis'));
        // Objet d'origine prioritaire : un fil dont l'objet change à chaque
        // réponse se scinde chez certains clients.
        $this->assertSame('Re: Devis', UserGmailService::replySubject('Ma réponse', 'Devis'));
    }

    public function testLAdresseEstExtraiteDUnEnTeteNomEtChevrons(): void
    {
        // From arrive presque toujours en « Nom <adresse> », forme que
        // FILTER_VALIDATE_EMAIL rejette.
        $this->assertSame('marie@ex.fr', UserGmailService::extractAddress('Marie Durand <marie@ex.fr>'));
        $this->assertSame('marie@ex.fr', UserGmailService::extractAddress('marie@ex.fr'));
    }

    public function testUnIdentifiantDeMessageInvalideEstRefuseAvantToutAppel(): void
    {
        $this->expectException(RuntimeException::class);
        UserGmailService::assertMessageId('non !');
    }

    public function testLaLectureRendLIdentifiantEtLeFil(): void
    {
        // Sans eux, rien en aval ne PEUT répondre dans le fil.
        $src   = (string)file_get_contents(ROOT . '/src/Gmail/UserGmailService.php');
        $debut = strpos($src, 'function readMessage(');
        $chunk = substr($src, (int)$debut, 3000);
        $this->assertStringContainsString("'id'      => (string)(\$data['id'] ?? \$messageId)", $chunk);
        $this->assertStringContainsString("'fil'     => (string)(\$data['threadId'] ?? '')", $chunk);
    }

    // ---------------------------------------------------------------------
    // 4. MIME multipart
    // ---------------------------------------------------------------------

    public function testLeBrouillonPorteLesDeuxVersionsDuCorps(): void
    {
        $mime = UserGmailService::buildDraftMime('marie@ex.fr', 'Devis', "Bonjour,\n\n- un point");

        $this->assertStringContainsString('Content-Type: multipart/alternative; boundary="', $mime);
        $this->assertStringContainsString('Content-Type: text/plain; charset="UTF-8"', $mime);
        $this->assertStringContainsString('Content-Type: text/html; charset="UTF-8"', $mime);

        // Les deux parts portent bien le contenu attendu, une fois décodées.
        // On décode chaque bloc base64 du corps plutôt que de re-parser le
        // MIME : le test doit vérifier le CONTENU, pas re-coder un parseur.
        preg_match_all("/\r\n\r\n([A-Za-z0-9+\/=\r\n]+?)\r\n--/", $mime, $m);
        $tout = implode('', array_map(
            static fn(string $b64): string => (string)base64_decode((string)preg_replace('/\s+/', '', $b64), true),
            $m[1]
        ));
        $this->assertStringContainsString('<li>un point</li>', $tout);
        $this->assertStringContainsString('- un point', $tout, 'La part texte doit rester lisible.');
    }

    public function testLaProtectionContreLInjectionDEnTetesEstIntacte(): void
    {
        $mime = UserGmailService::buildDraftMime(
            'marie@ex.fr',
            "Devis\r\nBcc: espion@ex.fr",
            'Bonjour',
            '',
            ['message_id' => "<abc@x>\r\nBcc: espion@ex.fr"]
        );

        // Aucun en-tête supplémentaire n'a pu naître : l'objet est encodé en
        // base64 (donc inerte), et In-Reply-To ne garde QUE l'identifiant.
        $this->assertStringContainsString("In-Reply-To: <abc@x>\r\n", $mime);
        $this->assertStringNotContainsString("\r\nBcc:", $mime);
        $this->assertSame('<abc@x>', UserGmailService::sanitizeMessageIds("<abc@x>\r\nBcc: espion@ex.fr"));
        $this->assertSame('', UserGmailService::sanitizeMessageIds('pas un identifiant'));
    }

    public function testUneAdresseInvalideLeveToujours(): void
    {
        $this->expectException(RuntimeException::class);
        UserGmailService::buildDraftMime('pas-une-adresse', 'Objet', 'Corps');
    }

    // ---------------------------------------------------------------------
    // 5. Câblage
    // ---------------------------------------------------------------------

    public function testLOutilExposeLeParametreDeReponseEtLeTransmetJusquALExecution(): void
    {
        $src = $this->chat();
        $this->assertStringContainsString("'repondre_a'   => ['type' => 'string'", $src);
        // Le paramètre doit survivre à la file d'approbation, sinon le fil est
        // perdu entre la carte et le clic.
        $this->assertStringContainsString("(string)(\$action['args']['repondre_a'] ?? '')", $src);
        $this->assertStringContainsString('UserGmailService::assertMessageId($repondreA);', $src);
    }

    public function testEnReponseLeDestinataireNEstPlusObligatoire(): void
    {
        $src = $this->chat();
        // Exiger le destinataire ferait échouer « réponds à ce mail », le cas
        // le plus courant : il est déduit du message d'origine.
        $this->assertStringContainsString("'required'   => ['corps'],", $src);
        $this->assertStringContainsString('$to = self::extractAddress($ctx[\'repondre_a\']);',
            (string)file_get_contents(ROOT . '/src/Gmail/UserGmailService.php'));
    }

    public function testLaConsigneInterditAuModeleDEcrireSaPropreSignature(): void
    {
        $src = $this->chat();
        $this->assertStringContainsString('repondre_a', $src);
        $this->assertStringContainsString('signature de la boîte est ajoutée automatiquement', $src);
        $this->assertStringContainsString('Markdown simple', $src);
    }

    public function testOutlookEnvoieAussiDuHtml(): void
    {
        // Même symptôme, même remède, même convertisseur : deux conversions
        // séparées divergeraient au premier ajustement.
        $src = (string)file_get_contents(ROOT . '/src/Outlook/UserOutlookService.php');
        $this->assertStringContainsString("'contentType' => 'HTML', 'content' => MailHtml::withInlineImages(MailHtml::toHtml(\$body), \$images)", $src);
        $this->assertStringNotContainsString("'contentType' => 'Text'", $src);
    }

    public function testLeConvertisseurEstChargeParLesDeuxListesDeRequire(): void
    {
        foreach (['/config/classes.php', '/tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString('Connectors/MailHtml.php', (string)file_get_contents(ROOT . $f), $f);
        }
    }

    public function testLaPageConnexionsAnnonceLaSignature(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/settings/connections.php');
        // Le libellé est traduit : on ancre sur la CLÉ et sur la condition, qui
        // sont ce que le test protège réellement.
        $this->assertStringContainsString("te('Signature') ?> <?= !empty(\$gmailCanSignature)", $tpl);
        // Sans cette condition, une connexion antérieure ne proposerait jamais
        // la reconnexion qui débloque la signature.
        $this->assertStringContainsString('empty($gmailCanSignature)) && !empty($gmailFeatureEnabled)', $tpl);
    }
}
