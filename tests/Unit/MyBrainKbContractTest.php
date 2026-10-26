<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bases « en direct » (MyBrain) — le CÂBLAGE, et les deux verdicts purs qui
 * vont avec (état affiché, contrôle du Diagnostic).
 *
 * Assertions textuelles sur le source, comme ConversationKnowledgeBaseTest :
 * c'est le seul moyen de garantir qu'un des chemins réservés aux documents
 * (dépôt, réindexation, synchronisation, Qdrant) ne s'est pas rouvert à une
 * base qui n'en a pas, ou que la porte par personne n'a pas été contournée par
 * un appel direct à forProfile().
 */
final class MyBrainKbContractTest extends TestCase
{
    protected function setUp(): void
    {
        // Hors du bootstrap (dépendances lourdes) : lus pour leurs constantes.
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        require_once ROOT . '/src/Helpers/Requirements.php';
        require_once ROOT . '/src/Helpers/Diagnostics.php';
    }

    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    /** Corps d'une méthode (jusqu'à la méthode suivante) — assez pour un contrat. */
    private static function methode(string $source, string $nom): string
    {
        $debut = strpos($source, 'function ' . $nom . '(');
        self::assertNotFalse($debut, $nom . ' introuvable');
        $suite = preg_match('/\n    (?:public|private|protected) (?:static )?function /', $source, $m, PREG_OFFSET_CAPTURE, $debut + 10);
        return substr($source, $debut, $suite ? $m[0][1] - $debut : null);
    }

    // =================== Schéma et chargement ===================

    public function testLaMigrationPoseLaNatureLaConnexionEtLeDefaut(): void
    {
        $sql = self::lire('database/migrations/073_kb_mybrain.sql');
        $this->assertMatchesRegularExpression("/ADD COLUMN backend ENUM\\('local',\\s*'mybrain'\\) NOT NULL DEFAULT 'local'/", $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS knowledge_base_remotes', $sql);
        $this->assertStringContainsString('api_key_enc', $sql);
        $this->assertMatchesRegularExpression('/FOREIGN KEY \(kb_id\) REFERENCES knowledge_bases\(id\) ON DELETE CASCADE/', $sql);
        $this->assertMatchesRegularExpression('/ALTER TABLE knowledge_base_shares\s+ADD COLUMN default_on/', $sql);
    }

    public function testLesClassesSontChargeesPartout(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $src = self::lire($liste);
            foreach (['KnowledgeBaseRemote.php', 'MyBrain/MyBrainException.php', 'MyBrain/MyBrainClient.php', 'MyBrain/MyBrainContext.php'] as $f) {
                $this->assertStringContainsString($f, $src, "$f absent de $liste (pas d'autoloader : l'oubli ne casse que le worker).");
            }
        }
    }

    public function testLaRouteDeTestEstDeclaree(): void
    {
        $this->assertStringContainsString(
            "\$router->post('/admin/knowledge-bases/mybrain/test',    [AdminController::class, 'testMyBrainConnection']);",
            self::lire('public/index.php')
        );
    }

    /** La clé ne sort de knowledge_base_remotes que pour un appel. */
    public function testLesLecturesDeListeNeJoignentJamaisLaCle(): void
    {
        $src = self::lire('src/Models/KnowledgeBase.php');
        $this->assertMatchesRegularExpression("/REMOTE_COLUMNS =\n\\s+'r\\.base_url/", $src);
        $colonnes = substr($src, (int)strpos($src, 'REMOTE_COLUMNS ='), 400);
        $this->assertStringNotContainsString('api_key_enc', $colonnes);
    }

    // =================== Chemins réservés aux documents ===================

    public function testLesCheminsDeDocumentsRefusentUneBaseEnDirect(): void
    {
        $admin = self::lire('src/Controllers/AdminController.php');
        foreach (['reindexKnowledgeBase', 'reindexKnowledgeBaseAjax', 'uploadKnowledgeBaseDocument'] as $m) {
            $this->assertStringContainsString('self::REMOTE_KB_NO_DOCUMENTS', self::methode($admin, $m), $m);
        }
        // Pas de collection Qdrant à supprimer, ni de synchronisation à écrire.
        $this->assertStringContainsString('!KnowledgeBase::isRemote($kbRow)', self::methode($admin, 'deleteKnowledgeBase'));
        $update = self::methode($admin, 'updateKnowledgeBase');
        $this->assertLessThan(
            strpos($update, 'applyKbSyncConfigFromPost'),
            strpos($update, 'KnowledgeBase::isRemote($kbRow)'),
            'Une base en direct doit sortir AVANT la configuration de synchronisation.'
        );

        $this->assertStringContainsString('if (!$kb || self::isRemote($kb))', self::methode(self::lire('src/Models/KnowledgeBase.php'), 'reindex'));
        $this->assertStringContainsString("WHERE backend = 'local'", self::lire('src/Backup/BackupService.php'));
    }

    public function testLaConnexionEstReserveeAuxAdministrateurs(): void
    {
        $admin = self::lire('src/Controllers/AdminController.php');
        // Réservée à qui gère TOUTES les bases (droit « Bases de connaissances
        // — Gérer », Personnes → Rôles) : l'administrateur, par défaut
        // seul — un créateur ne l'a pas.
        $this->assertStringContainsString("AuthMiddleware::requirePermission('knowledge_bases.manage');", self::methode($admin, 'testMyBrainConnection'));
        $this->assertStringContainsString("if (!Session::can('knowledge_bases.manage'))", self::methode($admin, 'createMyBrainKnowledgeBase'));
        $this->assertNotContains('knowledge_bases.manage', Permissions::DEFAULTS['creator']);

        $tpl  = self::lire('templates/admin/knowledge-bases/edit.php');
        $card = strpos($tpl, 'data-provider="mybrain"');
        $this->assertNotFalse($card);
        $this->assertNotFalse(strrpos(substr($tpl, 0, $card), '<?php if ($isAdminManager): ?>'));
    }

    /** Une clé à moitié collée n'a rien à faire en base : les deux champs sont hors de l'enregistrement automatique. */
    public function testLesChampsDeConnexionEchappentALEnregistrementAutomatique(): void
    {
        $tpl = self::lire('templates/admin/knowledge-bases/edit.php');
        $this->assertMatchesRegularExpression('/name="mybrain_url"[^>]*data-autosave-skip/', $tpl);
        $this->assertMatchesRegularExpression('/type="password" name="mybrain_key"[^>]*autocomplete="new-password"[^>]*data-autosave-skip/', $tpl);
        // Le test est le seul chemin qui écrit la connexion d'une base existante.
        $this->assertStringContainsString("'/admin/knowledge-bases/mybrain/test'", self::lire('public/assets/js/kb-form.js'));
    }

    // =================== Porte par personne ===================

    /** forProfile() seul ouvrirait le cerveau à quiconque ouvre l'assistant. */
    public function testLeChatPasseToujoursParLaPorte(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertStringNotContainsString('KnowledgeBase::forProfile(', $chat);
        $this->assertSame(7, substr_count($chat, 'KnowledgeBase::forProfileAndUser('),
            'envoi, régénération, présentation, sélecteur (page + JSON), validation de la sélection, images de référence de l\'outil Image');
    }

    public function testLePointDEntreeUniqueInterrogeLesBasesEnDirect(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $ctx  = self::methode($chat, 'buildKnowledgeBaseContext');
        $this->assertStringContainsString('KnowledgeBase::partitionByBackend($kbs)', $ctx);
        $this->assertStringContainsString('MyBrainContext::query(', $ctx);
        $this->assertStringContainsString("'mybrain_search'", $ctx);
        $this->assertStringContainsString('KnowledgeBase::markUsed([(int)$rkb[\'id\']])', $ctx);
        // Les trois sites passent l'historique (questions de suite) ; seuls
        // l'envoi et la régénération posent la trace.
        $this->assertSame(3, substr_count($chat, "'history' =>"));
        $this->assertSame(2, substr_count($chat, "'trace'   => true"));
        $this->assertArrayHasKey('mybrain_search', ChatController::ACTION_LABELS);
        $this->assertSame('kb', ChatController::ACTION_ICONS['mybrain_search']);
    }

    // =================== Outils du cerveau (lot 2) ===================

    /**
     * Le contexte compact n'a de sens que si le modèle peut creuser : la
     * capacité d'outils doit donc être connue AVANT l'appel des bases — et
     * c'est la même liste qui décide de la compaction et des outils.
     */
    public function testLaCompactionEtLesOutilsViennentDeLaMemeDecision(): void
    {
        $chat = AgentSource::chat();
        $send = self::methode($chat, 'runSend');

        $capacite = strpos($send, '$supportsTools = $adapter->supportsToolUse();');
        $bases    = strpos($send, '$this->buildKnowledgeBaseContext(');
        $this->assertNotFalse($capacite);
        $this->assertLessThan($bases, $capacite, 'la capacité d\'outils précède l\'appel des bases');
        $this->assertSame(1, substr_count($send, 'supportsToolUse()'), 'une seule mesure de la capacité');

        $this->assertStringContainsString(
            'array_filter(KnowledgeBase::partitionByBackend($kbs)[0], [MyBrainContext::class, \'isCallable\'])',
            $send,
            'une base refusée ou en pause n\'expose pas ses outils'
        );
        $this->assertMatchesRegularExpression('/\$liveKbs\s+= \$supportsTools\s+\?/', $send);
        $this->assertStringContainsString("'compact' => \$liveKbs !== [],", $send);
        $this->assertStringContainsString('if ($liveKbs !== []) {', $send);
        // Les outils eux-mêmes vivent dans leur fournisseur (src/Agent/), qui
        // reçoit EXACTEMENT la même liste de bases que la compaction.
        $this->assertMatchesRegularExpression('/\$registry->add\(new MyBrainTools\(\s*\$turn,\s*\$liveKbs,/', $send);
        $outils = AgentSource::tools('MyBrainTools');
        $this->assertStringContainsString('MyBrainContext::toolDefinitions($this->liveKbs)', $outils);
        $this->assertStringContainsString("\$toolHandlers['chercher_dans_mybrain']", $outils);
        $this->assertStringContainsString("\$toolHandlers['lire_fiche_mybrain']", $outils);
        // Régénération et présentation n'ont pas d'outils : elles gardent le rendu complet.
        $this->assertSame(1, substr_count($chat, "'compact' =>"));

        $ctx = self::methode($chat, 'buildKnowledgeBaseContext');
        $this->assertStringContainsString('min($maxChars, MyBrainContext::COMPACT_CHARS)', $ctx);
        $this->assertStringContainsString('withTools: $compact', $ctx);
    }

    public function testLesOutilsVisentUneBaseEngageeEtRelancentUneRechercheVide(): void
    {
        $outils = AgentSource::tools('MyBrainTools');
        $this->assertSame(2, substr_count($outils, 'MyBrainContext::pickBase($liveKbs,'), 'jamais une base hors de ce message');
        $this->assertStringContainsString('MyBrainContext::searchForTool($kb,', $outils);
        $this->assertStringContainsString('MyBrainContext::readForTool($kb,', $outils);
        $this->assertMatchesRegularExpression("/self::hintIfEmpty\\(\\s*MyBrainContext::searchForTool/", $outils);
    }

    public function testChaqueOutilDuCerveauALaTraceEtSaPhase(): void
    {
        $this->assertSame(
            ['t' => 'mybrain_dig', 'd' => 'chantiers chez Exemple'],
            ChatController::actionEntryForTool('chercher_dans_mybrain', ['question' => 'chantiers chez Exemple'], [])
        );
        // Le titre RÉSOLU prime sur ce que le modèle a passé (un lien, un chemin).
        $this->assertSame(
            ['t' => 'mybrain_read', 'd' => 'Exemple'],
            ChatController::actionEntryForTool('lire_fiche_mybrain', ['fiche' => 'https://c.fr/cerveau?f=x'], ['titre' => 'Exemple'])
        );
        $this->assertSame(
            ['t' => 'mybrain_read', 'd' => 'wiki/x.md'],
            ChatController::actionEntryForTool('lire_fiche_mybrain', ['fiche' => 'wiki/x.md'], ['error' => 'introuvable'])
        );
        foreach (['mybrain_dig', 'mybrain_read'] as $type) {
            $this->assertArrayHasKey($type, ChatController::ACTION_LABELS);
            $this->assertArrayHasKey($type, ChatController::ACTION_ICONS);
            $this->assertStringContainsString($type . ':', self::lire('public/assets/js/chat.js'), "phase $type sans libellé");
        }
    }

    // =================== Nourrir le cerveau (lot 3) ===================

    public function testLaMigrationPosePeutNourrirEtLesClassesSontChargees(): void
    {
        $this->assertMatchesRegularExpression(
            '/ALTER TABLE knowledge_base_shares\s+ADD COLUMN can_feed TINYINT\(1\) NOT NULL DEFAULT 0/',
            self::lire('database/migrations/074_kb_mybrain_feed.sql')
        );
        $classes = self::lire('config/classes.php');
        $this->assertStringContainsString('MyBrain/MyBrainFeed.php', $classes);
        $this->assertStringContainsString('Jobs/MyBrainFeedJob.php', $classes, "sans lui, le worker ne suit aucun dépôt");
        $this->assertStringContainsString('MyBrain/MyBrainFeed.php', self::lire('tests/bootstrap.php'));
    }

    /** L'outil n'existe que pour qui peut nourrir, et seulement parmi les bases engagées pour CE message. */
    public function testLOutilNourrirSuitLaPersonneEtNeFaitQueDeposerUneCarte(): void
    {
        $send = self::methode(AgentSource::chat(), 'runSend');
        $this->assertStringContainsString('MyBrainFeed::feedableBases($liveKbs, $user)', $send);
        $this->assertLessThan(
            strpos($send, 'MyBrainFeed::feedableBases($liveKbs, $user)'),
            strpos($send, 'if ($liveKbs !== []) {'),
            'nourrir exige une base en direct engagée pour ce message'
        );
        $outils = AgentSource::tools('MyBrainTools');
        $this->assertStringContainsString("if (\$feedKbs !== []) {\n            \$toolHandlers['nourrir_mybrain']", $outils,
            'pas de droit de nourrir, pas d\'outil');
        $this->assertMatchesRegularExpression("/\\\$queueConnectorWrite\\(\\s*'nourrir_mybrain',/", $outils,
            'rien n\'est déposé pendant le stream : une carte d\'approbation');
        $this->assertStringContainsString('MyBrainFeed::normalizeArgs($args)', $outils);
    }

    /** Au clic « Approuver », tout est re-vérifié : la personne, la base, la clé. */
    public function testLApprobationReverifieLesDroitsEtDeposeAuNomDeLaPersonne(): void
    {
        $chat   = self::lire('src/Controllers/ChatController.php');
        // Corps de la décision, partagé par la carte et l'approbation groupée.
        $action = self::methode($chat, 'decideAction');
        $bloc   = substr($action, (int)strpos($action, "if ((string)\$action['tool'] === 'nourrir_mybrain')"));
        $this->assertStringContainsString('KnowledgeBase::canFeed($kb, $user)', $bloc);
        $this->assertStringContainsString("MyBrainFeed::deposit(\$kb, \$args, (string)(\$user['email'] ?? ''))", $bloc);
        $this->assertStringContainsString("JobQueue::enqueue('mybrain_feed'", $bloc);
        $this->assertStringContainsString("'job_type' => 'mybrain_feed'", $bloc);
        $this->assertStringContainsString('$this->driveRespondAndDispatch(', $bloc,
            'la réponse part avant le suivi — ou est gardée pour le récapitulatif d\'un lot');

        $feed = self::lire('src/MyBrain/MyBrainFeed.php');
        $this->assertStringContainsString('in_array(MyBrainClient::RIGHT_FEED, KnowledgeBaseRemote::rights($row), true)', $feed,
            'la clé fixe le maximum : sans le droit « nourrir », aucun dépôt');

        $this->assertSame(['t' => 'nourrir_mybrain', 'd' => 'Réunion'],
            ChatController::actionEntryForTool('nourrir_mybrain', ['titre' => 'Réunion'], []));
        $this->assertArrayHasKey('nourrir_mybrain', ChatController::ACTION_LABELS);
        $this->assertSame('kb', ChatController::ACTION_ICONS['nourrir_mybrain']);
    }

    public function testLeSuiviEstUnJobRejouableHorsQuota(): void
    {
        $queue = self::lire('src/Jobs/JobQueue.php');
        $this->assertStringContainsString("'mybrain_feed' => new MyBrainFeedJob()", $queue);
        $this->assertMatchesRegularExpression("/QUOTA_EXEMPT = \\[[^\\]]*'mybrain_feed'/", $queue);
        $this->assertDoesNotMatchRegularExpression("/NON_IDEMPOTENT = \\[[^\\]]*'mybrain_feed'/", $queue,
            'le suivi ne fait que lire : le reaper peut le rejouer sans redéposer');

        $job = self::lire('src/Jobs/MyBrainFeedJob.php');
        $this->assertStringContainsString('$ctx->shouldStop()', $job, 'le battement de cœur, à chaque tour');
        $this->assertStringContainsString('MyBrainFeed::follow(', $job);

        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("type: String(data.job_type || 'gamma')", $js, 'le type vient du serveur');
        $this->assertStringContainsString('JOB_TYPES.mybrain_feed = {', $js);
        $this->assertStringContainsString("mybrain_feed: t('Apport au cerveau MyBrain')", $js);
    }

    public function testLEcranProposePeutNourrirPourLesSeulesBasesEnDirect(): void
    {
        $tpl = self::lire('templates/admin/knowledge-bases/edit.php');
        $this->assertStringContainsString('name="share_group_feed[]"', $tpl);
        $this->assertStringContainsString('name="share_user_feed[]"', $tpl);
        $this->assertStringContainsString("if (feedWrap)   feedWrap.hidden   = !live;", self::lire('public/assets/js/kb-form.js'));

        $admin = self::lire('src/Controllers/AdminController.php');
        $this->assertStringContainsString("\$_POST['share_group_feed'] ?? []", $admin);
        $this->assertStringContainsString('KnowledgeBase::feedersFor((int)$kb[\'id\'])', $admin);
        $this->assertStringContainsString('.kb-share-feed[hidden]', self::lire('public/assets/css/main.css'));
    }

    // =================== Cochée par défaut ===================

    public function testLeDefautAmorceLeBrouillonEtLaCreationSansScript(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $create = self::methode($chat, 'create');
        $this->assertStringContainsString("isset(\$_POST['kb_draft'])", $create);
        $this->assertStringContainsString('KnowledgeBase::defaultOnIdsForUser($user)', $create);

        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString("fd.append('kb_draft', '1');", $js);
        $this->assertStringContainsString('(CFG.defaults || [])', $js);
        $this->assertStringContainsString("'defaults' => array_values(array_map('intval', \$chatDefaultKbIds ?? []))",
            self::lire('templates/chat/index.php'));
    }

    // =================== Verdicts purs ===================

    private function remoteKb(array $extra): array
    {
        return $extra + ['id' => 7, 'backend' => 'mybrain', 'name' => 'Cerveau', 'remote_url' => 'https://cerveau.exemple.fr'];
    }

    public function testLEtatDUneBaseEnDirectEstCeluiDeSaConnexion(): void
    {
        $vide = KbStatus::countDocuments([]);
        $ok   = KbStatus::summarizeRemote($this->remoteKb(['remote_status' => 'ok', 'remote_app_name' => 'MIA', 'remote_rights' => 'lire,nourrir', 'remote_checked_age' => 30]), $vide);
        $this->assertSame([KbStatus::OK, KbStatus::TONE_OK, 'En direct'], [$ok['state'], $ok['tone'], $ok['title']]);
        $this->assertSame(['lire', 'nourrir'], $ok['remote_rights']);
        $this->assertSame('cerveau.exemple.fr', $ok['remote_host']);
        $this->assertSame('vérifiée à l\'instant', $ok['meta']);

        $refus = KbStatus::summarizeRemote($this->remoteKb(['remote_status' => 'refused', 'remote_error' => 'Clé refusée.']), $vide);
        $this->assertSame([KbStatus::ERROR, KbStatus::TONE_ERROR], [$refus['state'], $refus['tone']]);

        $panne = KbStatus::summarizeRemote($this->remoteKb(['remote_status' => 'unreachable']), $vide);
        $this->assertSame(KbStatus::TONE_WARN, $panne['tone']);

        $jamais = KbStatus::summarizeRemote($this->remoteKb(['remote_status' => null]), $vide);
        $this->assertSame(KbStatus::TONE_IDLE, $jamais['tone']);
        foreach ([$ok, $refus, $panne, $jamais] as $st) {
            $this->assertFalse($st['busy'], 'Rien n\'arrive tout seul : pas de rafraîchissement rapide.');
            $this->assertFalse($st['syncable'], 'Pas de « Synchroniser maintenant » sur un cerveau.');
        }
        // summarize() aiguille d'elle-même.
        $this->assertSame('En direct', KbStatus::summarize($this->remoteKb(['remote_status' => 'ok']), $vide, [])['title']);
    }

    public function testLeDiagnosticAvertitSansJamaisEchouer(): void
    {
        $this->assertSame(Diagnostics::INFO, Diagnostics::controleBasesMyBrain([])['statut']);
        $this->assertSame(Diagnostics::OK, Diagnostics::controleBasesMyBrain([['nom' => 'Cerveau', 'erreur' => null]])['statut']);
        $warn = Diagnostics::controleBasesMyBrain([
            ['nom' => 'Cerveau', 'erreur' => null],
            ['nom' => 'Ancien', 'erreur' => 'Clé refusée par MyBrain'],
        ]);
        $this->assertSame(Diagnostics::WARN, $warn['statut']);
        $this->assertSame('1 / 2 en panne', $warn['valeur']);
        $this->assertStringContainsString('« Ancien » : Clé refusée par MyBrain', $warn['message']);
        $this->assertNotSame([], Diagnostics::actions($warn));
    }
}
