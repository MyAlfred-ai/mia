<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT . '/src/Controllers/ChatController.php';

/**
 * Carte de RELECTURE d'un email préparé par un connecteur (Gmail, Outlook).
 *
 * Le problème résolu : la carte d'approbation d'un brouillon montrait une
 * ligne « objet → destinataire » et 280 caractères repliés ; pour corriger
 * un mot, il fallait le dire à l'assistant et attendre une nouvelle carte —
 * pendant que le modèle recopiait le corps entier dans sa réponse, juste
 * au-dessus. Désormais la carte montre le message tel qu'il partira, et
 * l'utilisateur le modifie à la main, le fait améliorer par MIA ou
 * l'abandonne ; chaque révision est enregistrée AVANT d'être affichée.
 *
 * Formes pures de MailDraftReview, puis câblage lu dans le code.
 */
final class MailDraftReviewTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    // ---------------------------------------------------------------------
    // 1. Bloc `mail` de l'aperçu
    // ---------------------------------------------------------------------

    public function testLeBlocMontreLeMessageTelQuIlPartira(): void
    {
        $bloc = MailDraftReview::block('gmail', [
            'destinataire' => 'alice@ex.fr',
            'cc'           => 'bob@ex.fr',
            'objet'        => "Point projet\r\nBcc: espion@ex.fr",
            'corps'        => "Bonjour,\r\n\r\nVoici le **point** :\r\n\r\n- premier\r\n- second",
        ]);

        $this->assertSame('gmail', $bloc['service']);
        $this->assertFalse($bloc['reponse']);
        $this->assertSame('alice@ex.fr', $bloc['destinataire']);
        $this->assertSame('bob@ex.fr', $bloc['cc']);
        $this->assertSame('Point projet Bcc: espion@ex.fr', $bloc['objet'], 'CR/LF neutralisés comme dans les services.');
        $this->assertStringNotContainsString("\r", $bloc['corps']);
        // Le corps rendu est celui du brouillon : MÊME convertisseur (MailHtml).
        $this->assertSame(MailHtml::toHtml($bloc['corps']), $bloc['corps_html']);
        $this->assertStringContainsString('<strong>point</strong>', $bloc['corps_html']);
        $this->assertStringContainsString('<li>premier</li>', $bloc['corps_html']);
        $this->assertFalse($bloc['tronque']);
        $this->assertSame(1, $bloc['version']);
        $this->assertSame('', $bloc['revision']);
    }

    public function testLeBlocSignaleUneReponseEtSonServiceOutlook(): void
    {
        $bloc = MailDraftReview::block('outlook', ['repondre_a' => 'AAMkAGI2THVSAAA=', 'corps' => 'Merci.']);
        $this->assertSame('outlook', $bloc['service']);
        $this->assertTrue($bloc['reponse']);
        $this->assertSame('', $bloc['destinataire'], 'Repris du message d\'origine à l\'exécution.');
    }

    public function testLeRenduHtmlDuBlocEstSurParConstruction(): void
    {
        // Markdown du modèle : tout est échappé, un lien au schéma dangereux perd son href.
        $md = MailDraftReview::block('gmail', [
            'destinataire' => 'a@ex.fr',
            'corps'        => 'Voir <script>alert(1)</script> et [ici](javascript:alert(1))',
        ]);
        $this->assertStringNotContainsString('<script', $md['corps_html']);
        $this->assertStringContainsString('&lt;script&gt;', $md['corps_html']);
        $this->assertStringNotContainsString('javascript:', $md['corps_html']);
        $this->assertStringContainsString('ici', $md['corps_html'], 'Le libellé survit en texte.');

        // HTML fourni par le modèle : liste blanche, seul href survit sur <a>.
        $html = MailDraftReview::block('outlook', [
            'destinataire' => 'a@ex.fr',
            'corps'        => '<p onclick="x()">Voir <a href="https://ex.fr" target="_blank" onclick="y()">là</a></p><script>alert(1)</script>',
        ]);
        $this->assertStringNotContainsString('<script', $html['corps_html']);
        $this->assertStringNotContainsString('alert(1)', $html['corps_html']);
        $this->assertStringNotContainsString('onclick', $html['corps_html']);
        $this->assertStringContainsString('<a href="https://ex.fr">là</a>', $html['corps_html']);
    }

    public function testUnCorpsTropLongEstRaccourciDansLApercuSeulement(): void
    {
        $long = str_repeat('a', MailDraftReview::CORPS_APERCU_MAX + 10);
        $bloc = MailDraftReview::block('gmail', ['destinataire' => 'a@ex.fr', 'corps' => $long]);
        $this->assertTrue($bloc['tronque']);
        $this->assertSame(MailDraftReview::CORPS_APERCU_MAX, mb_strlen($bloc['corps']));
        $this->assertSame('', $bloc['corps_html'], 'Au-delà de la borne, le texte brut suffit à relire.');

        // Entre les deux bornes : corps entier, sans HTML (preview_json est un TEXT de 64 Ko).
        $moyen = str_repeat('b', MailDraftReview::CORPS_HTML_MAX + 1);
        $bloc  = MailDraftReview::block('gmail', ['destinataire' => 'a@ex.fr', 'corps' => $moyen]);
        $this->assertFalse($bloc['tronque']);
        $this->assertSame('', $bloc['corps_html']);
        $this->assertLessThan(MailDraftReview::CORPS_APERCU_MAX, MailDraftReview::CORPS_HTML_MAX);
    }

    public function testLesPreregagesSontExposesAvecLeurLibelle(): void
    {
        $presets = MailDraftReview::presetsForClient();
        $this->assertSame(array_keys(MailDraftReview::PRESETS), array_column($presets, 'key'));
        foreach ($presets as $p) {
            $this->assertNotSame('', $p['label']);
        }
        foreach (MailDraftReview::PRESETS as $key => $p) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $key, 'La clé voyage dans le POST.');
            $this->assertGreaterThan(40, mb_strlen($p['instruction']), "consigne trop courte : $key");
        }
    }

    public function testLesDeuxServicesPortentLeBlocMail(): void
    {
        $gmail = UserGmailService::actionPreview('creer_brouillon_gmail', ['destinataire' => 'a@ex.fr', 'objet' => 'X', 'corps' => 'Bonjour']);
        $this->assertSame('gmail', $gmail['mail']['service']);
        $this->assertSame('Bonjour', $gmail['mail']['corps']);
        $outlook = UserOutlookService::actionPreview('creer_brouillon_outlook', ['destinataire' => 'a@ex.fr', 'objet' => 'X', 'corps' => 'Bonjour']);
        $this->assertSame('outlook', $outlook['mail']['service']);
        // Les clés historiques restent (cartes déposées avant, autres consommateurs).
        foreach (['action', 'fichier', 'impacts', 'apercu', 'icone'] as $cle) {
            $this->assertArrayHasKey($cle, $gmail);
            $this->assertArrayHasKey($cle, $outlook);
        }
    }

    public function testLaRevisionEstNumeroteeDepuisLApercuPrecedent(): void
    {
        $premier = ['mail' => MailDraftReview::block('gmail', ['destinataire' => 'a@ex.fr', 'corps' => 'v1'])];
        $second  = MailDraftReview::withRevision(
            ['mail' => MailDraftReview::block('gmail', ['destinataire' => 'a@ex.fr', 'corps' => 'v2'])],
            $premier,
            MailDraftReview::revisionNote('edit')
        );
        $this->assertSame(2, $second['mail']['version']);
        $this->assertSame('Modifié à la main', $second['mail']['revision']);

        $troisieme = MailDraftReview::withRevision(
            ['mail' => MailDraftReview::block('gmail', ['destinataire' => 'a@ex.fr', 'corps' => 'v3'])],
            $second,
            MailDraftReview::revisionNote('improve', 'plus court')
        );
        $this->assertSame(3, $troisieme['mail']['version']);
        $this->assertSame('Amélioré par MIA : plus court', $troisieme['mail']['revision']);
        $this->assertSame('Version précédente restaurée', MailDraftReview::revisionNote('restore'));

        // Un aperçu sans bloc mail (autre connecteur) est rendu tel quel.
        $this->assertSame(['action' => 'x'], MailDraftReview::withRevision(['action' => 'x'], null, 'n'));
    }

    // ---------------------------------------------------------------------
    // 2. Modification à la main
    // ---------------------------------------------------------------------

    public function testLesChampsDeLEditeurRemplacentLesArguments(): void
    {
        $args = MailDraftReview::applyEdit(
            ['destinataire' => 'a@ex.fr', 'objet' => 'Ancien', 'corps' => 'Ancien corps'],
            ['destinataire' => 'Marie Durand <marie@ex.fr>; bob@ex.fr, marie@ex.fr', 'cc' => '', 'objet' => "Nouveau\r\nBcc: x@ex.fr", 'corps' => "Nouveau\r\ncorps  \n"]
        );
        $this->assertSame('marie@ex.fr, bob@ex.fr', $args['destinataire'], 'Points-virgules tolérés, « Nom <adresse> » réduit à l\'adresse, doublons retirés.');
        $this->assertSame('', $args['cc']);
        $this->assertSame('Nouveau Bcc: x@ex.fr', $args['objet']);
        $this->assertSame("Nouveau\ncorps", $args['corps']);
    }

    public function testUnChampAbsentDuPostEstInchange(): void
    {
        // L'aperçu d'un corps raccourci n'offre pas le corps à l'édition : le
        // renvoyer tronqué amputerait le brouillon.
        $args = MailDraftReview::applyEdit(
            ['destinataire' => 'a@ex.fr', 'objet' => 'Objet', 'corps' => 'Corps entier', 'repondre_a' => ''],
            ['objet' => 'Autre']
        );
        $this->assertSame('Corps entier', $args['corps']);
        $this->assertSame('a@ex.fr', $args['destinataire']);
        $this->assertSame('Autre', $args['objet']);
    }

    public function testUneAdresseInvalideEstRefuseeAvantLeClic(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pas-une-adresse');
        MailDraftReview::applyEdit(['destinataire' => 'a@ex.fr', 'corps' => 'x'], ['destinataire' => 'pas-une-adresse']);
    }

    public function testUnBrouillonNeufExigeUnDestinataireUneReponseNon(): void
    {
        $reponse = MailDraftReview::applyEdit(['repondre_a' => 'abc12345', 'corps' => 'x'], ['destinataire' => '']);
        $this->assertSame('', $reponse['destinataire']);

        $this->expectException(RuntimeException::class);
        MailDraftReview::applyEdit(['corps' => 'x'], ['destinataire' => '']);
    }

    public function testUnCorpsVideEstRefuse(): void
    {
        $this->expectException(RuntimeException::class);
        MailDraftReview::applyEdit(['destinataire' => 'a@ex.fr', 'corps' => 'x'], ['corps' => "  \n "]);
    }

    public function testUnCorpsDemesureEstRefuse(): void
    {
        $this->expectException(RuntimeException::class);
        MailDraftReview::applyEdit(['destinataire' => 'a@ex.fr', 'corps' => 'x'], ['corps' => str_repeat('a', MailDraftReview::CORPS_MAX + 1)]);
    }

    // ---------------------------------------------------------------------
    // 3. Amélioration par le modèle
    // ---------------------------------------------------------------------

    public function testLaConsigneVientDUnPreregageOuDuTexteLibre(): void
    {
        $preset = MailDraftReview::instruction(['preset' => 'plus_court']);
        $this->assertSame(MailDraftReview::PRESETS['plus_court']['instruction'], $preset['texte']);
        $this->assertSame('plus court', $preset['libelle']);

        $libre = MailDraftReview::instruction(['instruction' => "  propose   un créneau\njeudi matin  "]);
        $this->assertSame('propose un créneau jeudi matin', $libre['texte']);
        $this->assertSame('propose un créneau jeudi matin', $libre['libelle']);

        $long = MailDraftReview::instruction(['instruction' => str_repeat('mot ', 200)]);
        $this->assertLessThanOrEqual(MailDraftReview::INSTRUCTION_MAX, mb_strlen($long['texte']));
        $this->assertLessThanOrEqual(MailDraftReview::LIBELLE_MAX, mb_strlen($long['libelle']));
        $this->assertStringEndsWith('…', $long['libelle']);
    }

    public function testUneConsigneVideOuUnPreregageInconnuSontRefuses(): void
    {
        try {
            MailDraftReview::instruction(['preset' => 'inconnu']);
            $this->fail('préréglage inconnu accepté');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Préréglage', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        MailDraftReview::instruction(['instruction' => '   ']);
    }

    public function testLePromptPorteLeBrouillonLeContexteEtLaConsigne(): void
    {
        $prompt = MailDraftReview::improvementPrompt(
            'gmail',
            ['destinataire' => 'marie@ex.fr', 'cc' => 'bob@ex.fr', 'objet' => 'Devis', 'corps' => 'Bonjour Marie,', 'repondre_a' => 'abc12345'],
            'plus court',
            "Utilisateur : réponds-lui qu'on valide",
            "De : Marie <marie@ex.fr>\nObjet : Devis\n\nPouvez-vous valider ?"
        );
        $user = $prompt['user'];
        foreach (['marie@ex.fr', 'bob@ex.fr', 'Devis', 'Bonjour Marie,', 'réponse à un message reçu', 'Pouvez-vous valider ?', "réponds-lui qu'on valide", '« plus court »'] as $attendu) {
            $this->assertStringContainsString($attendu, $user);
        }
        $sys = $prompt['system'];
        $this->assertStringContainsString('signature de la boîte est ajoutée automatiquement', $sys);
        $this->assertStringContainsString('Ne modifie ni les destinataires ni les copies', $sys);
        $this->assertStringContainsString('{"objet": "…", "corps": "…"}', $sys);
        $this->assertStringContainsString("n'invente rien", $sys);

        // Sans contexte : pas de section vide qui ferait croire à un message d'origine absent.
        $nu = MailDraftReview::improvementPrompt('outlook', ['destinataire' => 'a@ex.fr', 'corps' => 'x'], 'plus formel');
        $this->assertStringNotContainsString("Message d'origine", $nu['user']);
        $this->assertStringNotContainsString('Conversation qui a conduit', $nu['user']);
        $this->assertStringContainsString('(Outlook)', $nu['user']);
    }

    public function testLeResumeDuMessageDOrigineEstBorne(): void
    {
        $resume = MailDraftReview::originSummary([
            'de' => 'Marie <marie@ex.fr>', 'objet' => "Devis\r\nX", 'date' => '2026-09-01',
            'contenu' => str_repeat('c', MailDraftReview::ORIGINE_MAX + 100),
        ]);
        $this->assertStringStartsWith("De : Marie <marie@ex.fr>\nObjet : Devis X\nDate : 2026-09-01\n\n", $resume);
        $this->assertStringEndsWith('…', $resume);
        $this->assertLessThan(MailDraftReview::ORIGINE_MAX + 120, mb_strlen($resume));
    }

    public function testLaReponseDuModeleEstAnalyseeAvecTolerance(): void
    {
        $ok = MailDraftReview::parseImprovedJson("Voici :\n```json\n{\"objet\": \"Re: Devis\", \"corps\": \"Bonjour,\\n\\nValidé.\"}\n```");
        $this->assertSame(['objet' => 'Re: Devis', 'corps' => "Bonjour,\n\nValidé."], $ok);

        // Sauts de ligne laissés BRUTS dans la chaîne (JSON invalide strict) : réparés.
        $brut = MailDraftReview::parseImprovedJson("{\"objet\": \"X\", \"corps\": \"Ligne 1\nLigne 2\"}");
        $this->assertSame("Ligne 1\nLigne 2", $brut['corps']);

        // Objet absent ou vide → null (l'appelant garde l'objet courant).
        $sansObjet = MailDraftReview::parseImprovedJson('{"corps": "Corps seul"}');
        $this->assertNull($sansObjet['objet']);
        $this->assertSame('Corps seul', $sansObjet['corps']);

        $this->assertNull(MailDraftReview::parseImprovedJson(''));
        $this->assertNull(MailDraftReview::parseImprovedJson('pas de json'));
        $this->assertNull(MailDraftReview::parseImprovedJson('{"objet": "X"}'), 'Un corps vide n\'est pas une réécriture.');
    }

    // ---------------------------------------------------------------------
    // 4. Câblage
    // ---------------------------------------------------------------------

    public function testLaRouteEtLeControleurSontBranches(): void
    {
        $this->assertStringContainsString(
            "\$router->post('/chat/{id}/mail-draft/revise', [ChatController::class, 'reviseMailDraft']);",
            self::lire('public/index.php')
        );
        $chat  = self::lire('src/Controllers/ChatController.php');
        $debut = strpos($chat, 'public function reviseMailDraft(');
        $fin   = strpos($chat, 'public function driveAction(');
        $this->assertNotFalse($debut);
        $this->assertNotFalse($fin);
        $this->assertLessThan($fin, $debut, 'la relecture précède l\'approbation, qui ferme le bloc lu ici');
        $bloc  = substr($chat, (int)$debut, (int)$fin - (int)$debut);

        // Rien n'est créé ici : l'action reste en attente, relue sans réservation.
        $this->assertStringContainsString('DrivePendingAction::findPending(', $bloc);
        $this->assertStringContainsString('DrivePendingAction::updatePending(', $bloc);
        $this->assertStringNotContainsString('DrivePendingAction::claim(', $bloc);
        $this->assertStringNotContainsString('createDraft(', $bloc);
        // Seuls les outils mail se relisent ici.
        $this->assertStringContainsString('MailDraftReview::serviceForTool(', $bloc);
        // Les champs de l'éditeur s'appliquent AVANT une consigne d'amélioration.
        $this->assertLessThan(
            strpos($bloc, 'MailDraftReview::instruction($_POST)'),
            strpos($bloc, 'MailDraftReview::applyEdit($action[\'args\'], $_POST)')
        );
        // Quota AVANT l'appel payant, et l'appel compté au grand livre.
        $this->assertLessThan(strpos($bloc, '$this->improveMailDraft('), strpos($bloc, '$this->isCreditQuotaExceeded($quotaStatus)'));
        $this->assertStringContainsString("'quota_exceeded' => true", $bloc);
        $this->assertStringContainsString('UsageSource::MAIL_ASSIST', $bloc);
        $this->assertStringContainsString('session_write_close();', $bloc);
        $this->assertStringContainsString('LlmErrorPresenter::parse($raw)', $bloc, 'une erreur fournisseur ne part jamais brute dans la carte');
        // En réponse, l'objet n'est pas réécrit (repris du message d'origine par les deux services).
        $this->assertStringContainsString("if (\$parsed['objet'] !== null && trim((string)(\$args['repondre_a'] ?? '')) === '')", $bloc);
        // Message d'origine relu pour le contexte, sans jamais bloquer.
        $this->assertStringContainsString('->readMessage($messageId, 900)', $chat);
    }

    public function testLeModeleNeRecopiePlusLeCorpsDansSaReponse(): void
    {
        $chat = AgentSource::chat();
        // Message rendu à l'outil pour un email (la carte affiche déjà le corps) :
        // conditionné au service mail résolu depuis l'outil déposé.
        $this->assertStringContainsString('$mailService = MailDraftReview::serviceForTool($tool);', $chat);
        $this->assertStringContainsString('self::pendingWriteMessage($mailService !== null, $voiceMode)', $chat);
        $this->assertStringContainsString('NE RECOPIE PAS', ChatController::pendingWriteMessage(true));
        $this->assertStringNotContainsString('NE RECOPIE PAS', ChatController::pendingWriteMessage(false));
        $this->assertStringContainsString('NE RECOPIE PAS le corps', $chat);
        // Et les deux consignes de connecteur le rappellent.
        $this->assertSame(2, substr_count($chat, 'SANS recopier le corps du message'), 'Gmail et Outlook');
    }

    public function testLActionEnAttenteSeReliEtSeReviseSansSeReserver(): void
    {
        $src = self::lire('src/Models/DrivePendingAction.php');
        foreach (['findPending', 'updatePending'] as $fn) {
            $debut = strpos($src, 'public static function ' . $fn . '(');
            $this->assertNotFalse($debut, $fn);
            $corps = substr($src, (int)$debut, 1200);
            $this->assertStringContainsString("status = \\'pending\\'", $corps, "$fn ne touche qu'une action en attente");
            $this->assertStringContainsString('self::TTL_MINUTES', $corps, "$fn respecte l'expiration");
            $this->assertStringContainsString('user_id = ? AND conversation_id = ?', $corps, "$fn est borné à l'utilisateur et à la conversation");
        }
        $this->assertStringContainsString('SET args_json = ?, preview_json = ?', $src, 'arguments ET aperçu réécrits ensemble');
    }

    public function testLaCarteDeRelectureEstBrancheeCoteClient(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('return renderMailDraftCard(target, data);', $js);
        $this->assertStringContainsString('function paintMailDraftCard(card, mode = \'preview\', opts = {})', $js);
        $this->assertStringContainsString('`/chat/${convId()}/mail-draft/revise`', $js);
        // Les issues (la correction à la main se fait dans l'aperçu, sans bouton).
        foreach (["data-act=\"approve\"", "data-act=\"improve\"", "data-act=\"cancel\"", "data-act=\"undo\""] as $act) {
            $this->assertStringContainsString($act, $js);
        }
        // Un corps raccourci n'est jamais renvoyé au serveur.
        $this->assertStringContainsString('if (!prev.tronque) fields.corps = prev.corps;', $js);
        // Liens du corps hors de l'onglet de la conversation.
        $this->assertStringContainsString("a.target = '_blank'; a.rel = 'noopener';", $js);
        // Cartes en attente rendues aussi au changement de conversation.
        $this->assertStringContainsString("'pending_actions' => DrivePendingAction::pendingForConversation(\$convId, (int)\$user['id'])", self::lire('src/Controllers/ChatController.php'));
        $this->assertStringContainsString('renderPendingDriveActions(Array.isArray(data.pending_actions)', $js);

        $css = self::lire('public/assets/css/main.css');
        foreach (['.mail-draft-card', '.mail-draft-envelope', '.mail-draft-meta', '.mail-draft-body', '.mail-draft-chip', '.mail-draft-editable'] as $sel) {
            $this->assertStringContainsString($sel . ' ', $css, $sel);
        }
    }

    /**
     * L'aperçu EST l'éditeur, et une correction tapée dans la carte part au
     * serveur AVANT toute issue : envoyer ou créer le brouillon exécutent la
     * version enregistrée, jamais celle d'avant la frappe.
     */
    public function testLesCorrectionsDansLApercuSontEnregistreesAvantToutesIssues(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringNotContainsString('data-act="edit"', $js, 'plus de mode « Modifier » séparé');
        $this->assertStringContainsString('contenteditable="true"', $js);
        $this->assertStringContainsString('function mailHtmlToMarkdown(root)', $js);

        // `extra` : la conversation vocale passe par la MÊME fonction (préréglage
        // ou consigne dictée à la place du bouton) — donc par le même enregistrement.
        $debut = strpos($js, 'async function runMailDraftAct(card, act, btn, extra)');
        $this->assertNotFalse($debut);
        $corps = substr($js, $debut, 2200);
        $flush = strpos($corps, "if (!(await flushMailDraftEdits(card))) return 'refused';");
        $this->assertNotFalse($flush, 'un enregistrement refusé arrête l\'issue');
        foreach (["case 'approve':", "case 'send':", "case 'preset':", "case 'instruct':", "case 'apply':", "case 'undo':"] as $issue) {
            $this->assertGreaterThan($flush, strpos($corps, $issue), "$issue passe après l'enregistrement");
        }
        // Le focus qui quitte la carte enregistre aussi.
        $this->assertStringContainsString("card.addEventListener('focusout', onMailDraftCardFocusout);", $js);
        // Plus rien d'éditable pendant l'exécution.
        $this->assertStringContainsString("if (card.classList.contains('mail-draft-card')) lockMailDraftEditor(card);", $js);
        // Un corps raccourci n'est jamais relu depuis l'aperçu.
        $this->assertStringContainsString('if (body && card.__bodyDirty && !saved.tronque) fields.corps = mailHtmlToMarkdown(body);', $js);
    }

    public function testLaClasseEstChargeeApresLeConvertisseurHtml(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $f) {
            $src = self::lire($f);
            $this->assertStringContainsString('Connectors/MailDraftReview.php', $src, $f);
            $this->assertLessThan(strpos($src, 'Connectors/MailDraftReview.php'), strpos($src, 'Connectors/MailHtml.php'), $f);
        }
    }

    public function testLaSourceDUsageEstDeclaree(): void
    {
        $this->assertTrue(UsageSource::isKnown(UsageSource::MAIL_ASSIST));
        $this->assertSame(UsageSource::CAT_CHAT_ASSIST, UsageSource::category(UsageSource::MAIL_ASSIST));
        $this->assertFalse(UsageSource::isQuotaExempt(UsageSource::MAIL_ASSIST), 'c\'est l\'utilisateur qui demande la réécriture, pour lui');
    }

    public function testLesGuidesEtLesNouveautesLeDisent(): void
    {
        $guide = self::lire('docs/GUIDE-UTILISATEUR.md');
        foreach (['**Créer le brouillon**', '**Améliorer**', '**Version précédente**'] as $mot) {
            $this->assertStringContainsString($mot, $guide);
        }
        $this->assertStringContainsString('relecture-brouillon-email', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('## Relire et retoucher un email', self::lire('docs/user/fonctionnalites/connecteurs/utiliser-un-connecteur.md'));
    }
}
