<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Outil n8n — partie pure (aucune base, aucun réseau).
 *
 * Ce qui est vérifié ici est exactement ce qui casse en silence en production :
 * une URL recopiée depuis la barre d'adresse, un workflow sans nœud Webhook,
 * un tag oublié (= toute l'instance exposée), un modèle qui sérialise ses
 * arguments en chaîne JSON — et le câblage du type d'outil, dont un maillon
 * manquant ne produit aucune erreur, juste un assistant qui « ne peut pas ».
 */
final class N8nServiceTest extends TestCase
{
    // ---------- URL de l'instance ----------

    public function testUrlSansSchemaEstCompleteeEnHttps(): void
    {
        $this->assertSame('https://n8n.exemple.fr', N8nService::normalizeBaseUrl('n8n.exemple.fr'));
        $this->assertSame('https://n8n.exemple.fr', N8nService::normalizeBaseUrl('  n8n.exemple.fr/  '));
    }

    public function testUrlConserveHttpEtPort(): void
    {
        $this->assertSame('http://192.168.1.20:5678', N8nService::normalizeBaseUrl('http://192.168.1.20:5678/'));
    }

    /**
     * Erreurs de saisie réelles : on colle l'URL de l'API ou celle de
     * l'interface. Sans nettoyage, tous les appels partent en /api/v1/api/v1/…
     */
    public function testCheminsDeCopierCollerSontRetires(): void
    {
        $this->assertSame('https://n8n.exemple.fr', N8nService::normalizeBaseUrl('https://n8n.exemple.fr/api/v1'));
        $this->assertSame('https://n8n.exemple.fr', N8nService::normalizeBaseUrl('https://n8n.exemple.fr/home/workflows'));
        $this->assertSame('https://n8n.exemple.fr', N8nService::normalizeBaseUrl('https://n8n.exemple.fr/signin'));
    }

    public function testSousCheminLegitimeConserve(): void
    {
        $this->assertSame('https://outils.exemple.fr/n8n', N8nService::normalizeBaseUrl('https://outils.exemple.fr/n8n/'));
    }

    public function testSchemaNonHttpRefuse(): void
    {
        $this->assertSame('', N8nService::normalizeBaseUrl('ftp://n8n.exemple.fr'));
        $this->assertSame('', N8nService::normalizeBaseUrl('file:///etc/passwd'));
        $this->assertSame('', N8nService::normalizeBaseUrl(''));
    }

    // ---------- Tag d'exposition ----------

    public function testTagVideRetombeSurLeDefaut(): void
    {
        $this->assertSame(N8nService::DEFAULT_TAG, N8nService::sanitizeTag(''));
        $this->assertSame(N8nService::DEFAULT_TAG, N8nService::sanitizeTag('   '));
        $this->assertSame(N8nService::DEFAULT_TAG, N8nService::sanitizeTag('###'));
    }

    public function testTagNormaliseEnMinuscules(): void
    {
        $this->assertSame('assistant-mia', N8nService::sanitizeTag('  Assistant-MIA '));
    }

    // ---------- En-tête d'authentification des webhooks ----------

    public function testNomEnteteInvalideRefuse(): void
    {
        $this->assertSame('X-Mia-Token', N8nService::sanitizeHeaderName('X-Mia-Token'));
        // Un CR/LF permettrait d'injecter des en-têtes arbitraires.
        $this->assertSame('', N8nService::sanitizeHeaderName("X-Token\r\nX-Autre: 1"));
        $this->assertSame('', N8nService::sanitizeHeaderName('X Token'));
        $this->assertSame('', N8nService::sanitizeHeaderName(''));
    }

    public function testValeurEnteteNettoyeeDesRetoursLigne(): void
    {
        $this->assertSame('secretinjecte', N8nService::sanitizeHeaderValue("secret\r\ninjecte"));
    }

    // ---------- Périmètre : tag + workflow actif ----------

    private function workflow(array $overrides = []): array
    {
        return array_merge([
            'id'     => 'AbCd1234',
            'name'   => 'Créer un ticket support',
            'active' => true,
            'tags'   => [['id' => '1', 'name' => 'mia']],
            'nodes'  => [[
                'name'       => 'Webhook',
                'type'       => 'n8n-nodes-base.webhook',
                'notes'      => 'Crée un ticket. Champs : sujet (obligatoire), priorite (haute|normale).',
                'parameters' => ['path' => 'ticket-support', 'httpMethod' => 'POST', 'responseMode' => 'lastNode'],
            ]],
        ], $overrides);
    }

    public function testWorkflowInactifJamaisExpose(): void
    {
        $this->assertTrue(N8nService::isExposed($this->workflow(), 'mia'));
        $this->assertFalse(N8nService::isExposed($this->workflow(['active' => false]), 'mia'));
    }

    public function testWorkflowSansLeTagJamaisExpose(): void
    {
        $autre = $this->workflow(['tags' => [['name' => 'interne']]]);
        $this->assertFalse(N8nService::isExposed($autre, 'mia'));
        $this->assertFalse(N8nService::isExposed($this->workflow(['tags' => []]), 'mia'));
    }

    public function testTagInsensibleALaCasse(): void
    {
        $this->assertTrue(N8nService::isExposed($this->workflow(['tags' => [['name' => 'MIA']]]), 'mia'));
    }

    public function testTagDeLectureMarqueLeWorkflowSansEffetDeBord(): void
    {
        $lecture = $this->workflow(['tags' => [['name' => 'mia'], ['name' => 'mia-lecture']]]);
        $this->assertTrue(N8nService::isReadOnly($lecture, 'mia'));
        $this->assertFalse(N8nService::isReadOnly($this->workflow(), 'mia'));
    }

    // ---------- Nœud Webhook ----------

    public function testWebhookLuDepuisLeNoeud(): void
    {
        $hook = N8nService::webhookFromWorkflow($this->workflow());
        $this->assertNotNull($hook);
        $this->assertSame('POST', $hook['method']);
        $this->assertSame('ticket-support', $hook['path']);
        $this->assertSame('lastNode', $hook['response_mode']);
        $this->assertStringContainsString('sujet', $hook['notes']);
    }

    /** Chemin vide : n8n sert le webhook sur l'identifiant technique du nœud. */
    public function testCheminVideRetombeSurWebhookId(): void
    {
        $wf = $this->workflow(['nodes' => [[
            'type'       => 'n8n-nodes-base.webhook',
            'webhookId'  => 'f4c2-uuid',
            'parameters' => ['path' => ''],
        ]]]);
        $hook = N8nService::webhookFromWorkflow($wf);
        $this->assertSame('f4c2-uuid', $hook['path']);
        // Défaut n8n quand le verbe n'est pas précisé.
        $this->assertSame('GET', $hook['method']);
    }

    public function testNoeudDesactiveIgnore(): void
    {
        $wf = $this->workflow(['nodes' => [[
            'type'       => 'n8n-nodes-base.webhook',
            'disabled'   => true,
            'parameters' => ['path' => 'inactif'],
        ]]]);
        $this->assertNull(N8nService::webhookFromWorkflow($wf));
    }

    public function testWorkflowSansDeclencheurWebhookNestPasLancable(): void
    {
        $wf = $this->workflow(['nodes' => [
            ['type' => 'n8n-nodes-base.scheduleTrigger', 'parameters' => []],
            ['type' => 'n8n-nodes-base.set', 'parameters' => []],
        ]]);
        $this->assertNull(N8nService::webhookFromWorkflow($wf));

        $row = N8nService::summarizeWorkflow($wf, 'mia');
        $this->assertFalse($row['declenchable']);
        $this->assertArrayHasKey('note', $row);
    }

    public function testMethodesMultiplesPreferentPost(): void
    {
        $wf = $this->workflow(['nodes' => [[
            'type'       => 'n8n-nodes-base.webhook',
            'parameters' => ['path' => 'x', 'multipleMethods' => true, 'httpMethod' => ['GET', 'POST']],
        ]]]);
        $this->assertSame('POST', N8nService::webhookFromWorkflow($wf)['method']);
    }

    public function testProtectionDuWebhookLueDepuisLeNoeud(): void
    {
        $this->assertSame('none', N8nService::webhookFromWorkflow($this->workflow())['auth']);

        $protege = $this->workflow(['nodes' => [[
            'type'       => 'n8n-nodes-base.webhook',
            'parameters' => ['path' => 'x', 'authentication' => 'headerAuth'],
        ]]]);
        $this->assertSame('headerAuth', N8nService::webhookFromWorkflow($protege)['auth']);
    }

    // ---------- Étape 3 : cohérence de la protection des webhooks ----------

    /** Workflow exposé avec un webhook portant la protection demandée. */
    private function workflowAuth(string $nom, string $auth): array
    {
        return $this->workflow([
            'name'  => $nom,
            'nodes' => [[
                'type'       => 'n8n-nodes-base.webhook',
                'parameters' => ['path' => strtolower($nom), 'httpMethod' => 'POST', 'authentication' => $auth],
            ]],
        ]);
    }

    /** Cas bloquant : le workflow exige un en-tête, MIA n'en envoie aucun → 403 garanti. */
    public function testHeaderAuthSansEnteteConfigureEstUnEchec(): void
    {
        $verdict = N8nService::classifyWebhookProtection(
            [$this->workflowAuth('Ticket', 'headerAuth')],
            'mia',
            '',
            false
        );
        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('403', $verdict['error']);
    }

    /** Basic Auth / JWT : MIA ne sait pas les fournir, quel que soit l'en-tête configuré. */
    public function testBasicOuJwtEstUnEchecMemeAvecEnteteConfigure(): void
    {
        $verdict = N8nService::classifyWebhookProtection(
            [$this->workflowAuth('Facture', 'basicAuth')],
            'mia',
            'X-Mia-Token',
            true
        );
        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('Basic Auth', $verdict['error']);
        $this->assertStringContainsString('Facture', $verdict['error']);
    }

    /** Configuration cohérente : on le dit, en précisant ce qui reste invérifiable. */
    public function testHeaderAuthAvecEnteteConfigureEstConforme(): void
    {
        $verdict = N8nService::classifyWebhookProtection(
            [$this->workflowAuth('Ticket', 'headerAuth')],
            'mia',
            'X-Mia-Token',
            true
        );
        $this->assertTrue($verdict['ok']);
        $this->assertStringContainsString('X-Mia-Token', $verdict['detail']);
        // La valeur vit dans un credential n8n : le test doit l'annoncer plutôt
        // que de laisser croire à une validation complète.
        $this->assertStringContainsString('valeur', $verdict['detail']);
    }

    /** Webhook ouvert : ce n'est pas une panne, mais ça doit se voir. */
    public function testWebhookOuvertEstSignaleSansEtreUneErreur(): void
    {
        $verdict = N8nService::classifyWebhookProtection(
            [$this->workflowAuth('Stock', 'none')],
            'mia',
            '',
            false
        );
        $this->assertTrue($verdict['ok']);
        $this->assertStringContainsString('Stock', $verdict['detail']);
        $this->assertStringContainsString('ouvert', $verdict['detail']);
    }

    /** En-tête configuré mais inutile : personne ne le lit. */
    public function testEnteteConfigureMaisAucunWorkflowNeLeLit(): void
    {
        $verdict = N8nService::classifyWebhookProtection(
            [$this->workflowAuth('Stock', 'none')],
            'mia',
            'X-Mia-Token',
            true
        );
        $this->assertTrue($verdict['ok']);
        $this->assertStringContainsString('ne sert à rien', $verdict['detail']);
    }

    /** Valeur sans nom d'en-tête : rien ne serait envoyé, autant le dire. */
    public function testValeurSansNomDEnteteEstRefusee(): void
    {
        $verdict = N8nService::classifyWebhookProtection([], 'mia', 'nom invalide', true);
        $this->assertFalse($verdict['ok']);
    }

    /** Workflow sans webhook : sa protection n'a pas d'objet, il ne compte pas. */
    public function testWorkflowSansWebhookIgnoreParLaVerification(): void
    {
        $sansHook = $this->workflow(['nodes' => [['type' => 'n8n-nodes-base.scheduleTrigger', 'parameters' => []]]]);
        $verdict  = N8nService::classifyWebhookProtection([$sansHook], 'mia', '', false);
        $this->assertTrue($verdict['ok']);
        $this->assertStringContainsString('aucun workflow déclenchable', $verdict['detail']);
    }

    public function testUrlDeWebhookAssembleeSansDoubleSlash(): void
    {
        $this->assertSame(
            'https://n8n.exemple.fr/webhook/ticket-support',
            N8nService::webhookUrl('https://n8n.exemple.fr/', '/ticket-support')
        );
    }

    // ---------- Arguments du modèle ----------

    public function testDonneesAccepteesEnObjetOuEnChaineJson(): void
    {
        $this->assertSame(['sujet' => 'Panne'], N8nService::normalizePayload(['sujet' => 'Panne']));
        $this->assertSame(['sujet' => 'Panne'], N8nService::normalizePayload('{"sujet":"Panne"}'));
        $this->assertSame([], N8nService::normalizePayload(null));
        $this->assertSame([], N8nService::normalizePayload(''));
    }

    public function testDonneesInexploitablesRefuseesAvecUnMessageAuModele(): void
    {
        $this->expectException(RuntimeException::class);
        N8nService::normalizePayload('sujet: Panne');
    }

    public function testListeNueRefusee(): void
    {
        $this->expectException(RuntimeException::class);
        N8nService::normalizePayload([1, 2, 3]);
    }

    // ---------- Résumés ----------

    public function testResumeDeListeTronqueLaDescription(): void
    {
        $long = str_repeat('détail ', 200);
        $wf   = $this->workflow(['nodes' => [[
            'type'       => 'n8n-nodes-base.webhook',
            'notes'      => $long,
            'parameters' => ['path' => 'x', 'httpMethod' => 'POST'],
        ]]]);
        $court = N8nService::summarizeWorkflow($wf, 'mia');
        $plein = N8nService::summarizeWorkflow($wf, 'mia', true);

        $this->assertLessThanOrEqual(N8nService::SHORT_NOTES + 1, mb_strlen($court['description']));
        $this->assertGreaterThan(mb_strlen($court['description']), mb_strlen($plein['description']));
        $this->assertArrayHasKey('methode', $plein);
        $this->assertArrayNotHasKey('methode', $court);
    }

    public function testSortieJsonRenvoyeeDecodee(): void
    {
        $out = N8nService::summarizeOutput('{"ticket":"T-42"}');
        $this->assertSame(['ticket' => 'T-42'], $out['sortie']);
        $this->assertFalse($out['tronque']);
    }

    public function testSortieVolumineuseTronquee(): void
    {
        $out = N8nService::summarizeOutput(str_repeat('a', 9000), 1000);
        $this->assertTrue($out['tronque']);
        $this->assertLessThanOrEqual(1001, mb_strlen((string)$out['sortie']));
    }

    public function testSortieVideNeCasseRien(): void
    {
        $this->assertNull(N8nService::summarizeOutput('')['sortie']);
    }

    // ---------- Carte d'approbation ----------

    public function testApercuDApprobationNommeLeWorkflowEtSesDonnees(): void
    {
        $preview = N8nService::actionPreview(
            'executer_workflow_n8n',
            ['workflow' => 'AbCd1234', 'donnees' => ['sujet' => 'Panne imprimante']],
            ['id' => 'AbCd1234', 'nom' => 'Créer un ticket support', 'lecture_seule' => false]
        );
        $this->assertSame('Créer un ticket support', $preview['fichier']);
        $this->assertStringContainsString('Panne imprimante', (string)$preview['apercu']);
        $this->assertNotEmpty($preview['impacts']);
        $this->assertSame('/assets/img/n8n.svg', $preview['icone']);
    }

    /** Une charge illisible ne doit pas casser la carte : l'aperçu tombe à vide. */
    public function testApercuResisteADesDonneesIllisibles(): void
    {
        $preview = N8nService::actionPreview('executer_workflow_n8n', ['donnees' => 'pas du json'], null);
        $this->assertNull($preview['apercu']);
        $this->assertNotSame('', $preview['fichier']);
    }

    // ---------- Câblage : n8n est un OUTIL, pas un connecteur ----------

    /**
     * Le type d'outil doit exister de bout en bout : carte de choix côté
     * administration, config lue et écrite par le contrôleur, résolution par
     * l'assistant côté chat. Un maillon manquant laisse un outil qu'on peut
     * créer mais que personne n'expose — sans la moindre erreur.
     */
    public function testLeTypeDOutilEstBrancheDeBoutEnBout(): void
    {
        $tools = (string)file_get_contents(ROOT . '/templates/admin/tools.php');
        $this->assertStringContainsString('data-t="n8n"', $tools, 'Aucune carte de type n8n.');
        $this->assertStringContainsString('config_n8n_base_url', $tools);
        $this->assertStringContainsString('config_n8n_tag', $tools);

        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString('N8nService::configFromPost', $admin);
        $this->assertStringContainsString("'n8n' => \$this->runN8nToolTest(\$tool)", $admin,
            'Le bouton Tester de la liste ne saurait pas quoi faire d\'un outil n8n.');

        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString('private function resolveProfileN8nTool', $chat);
        $this->assertStringContainsString('N8nService::fromTool', $chat);
    }

    /**
     * L'option du menu « + » a disparu : le serveur ne lit plus `n8n_access`,
     * et l'exposition dépend uniquement de l'assistant. La laisser traîner
     * rouvrirait un chemin d'accès que plus personne ne garde.
     */
    public function testLOptionDuMenuPlusADisparu(): void
    {
        foreach ([
            '/src/Controllers/ChatController.php',
            '/templates/chat/index.php',
            '/public/assets/js/chat.js',
            '/public/assets/js/tool-suggest.js',
        ] as $chemin) {
            $source = (string)file_get_contents(ROOT . $chemin);
            $this->assertStringNotContainsString('n8n_access', $source, "n8n_access subsiste dans $chemin.");
            $this->assertStringNotContainsString('n8nAccessToggle', $source, "Le toggle subsiste dans $chemin.");
        }
    }

    /**
     * Les trois outils exposés au modèle doivent être définis ET nommés dans
     * la consigne : une définition sans consigne laisse le modèle deviner,
     * une consigne sans définition lui fait appeler un outil inexistant.
     */
    public function testLesTroisOutilsSontDefinisEtDocumentes(): void
    {
        // Fournisseur d'outils n8n (src/Agent/Tools) : définitions, consigne
        // (dernière méthode de la classe) et handlers au même endroit.
        $src         = AgentSource::tools('N8nTools');
        $definitions = self::corpsDeMethode($src, 'n8nToolDefinitions', 'n8nToolGuidance');
        $consigne    = substr($src, (int)strpos($src, 'private function n8nToolGuidance'));

        foreach (['lister_workflows_n8n', 'decrire_workflow_n8n', 'executer_workflow_n8n'] as $outil) {
            $this->assertStringContainsString("'name'        => '$outil'", $definitions, "« $outil » non défini.");
            $this->assertStringContainsString($outil, $consigne, "« $outil » absent de la consigne.");
            // Et un handler doit exister, sinon l'appel renvoie « Outil inconnu ».
            $this->assertStringContainsString("\$toolHandlers['" . $outil . "']", $src);
        }
    }

    /**
     * L'exécution approuvée doit être dispatchée dans la route d'approbation,
     * et re-résoudre l'OUTIL (pas une instance globale) : c'est ce qui
     * re-vérifie les droits au moment du clic.
     */
    public function testLExecutionApprouveeReResoutLOutil(): void
    {
        $chat = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $debut = strpos($chat, 'public function driveAction');
        $fin   = strpos($chat, 'private function executeDriveWrite');
        $bloc  = substr($chat, (int)$debut, (int)$fin - (int)$debut);
        $this->assertStringContainsString('executer_workflow_n8n', $bloc);
        $this->assertStringContainsString('ChatTool::findActiveByIdForUser', $bloc);
        $this->assertStringContainsString('N8nService::fromTool', $bloc);
    }

    /** Corps d'une méthode, bornée par la déclaration de la suivante. */
    private static function corpsDeMethode(string $source, string $methode, string $suivante): string
    {
        $debut = strpos($source, 'private function ' . $methode);
        $fin   = strpos($source, 'private function ' . $suivante);
        if ($debut === false || $fin === false || $fin <= $debut) {
            return '';
        }
        return substr($source, $debut, $fin - $debut);
    }

}
