<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mise à jour des prix (Administration → Coûts → Tarifs & crédits) en tâche de fond.
 *
 * Ce qui casse en silence ici : une recherche qui tourne bien côté serveur mais
 * dont le RÉSULTAT n'est appliqué que dans l'état JavaScript de la page —
 * quitter l'écran perd alors le travail sans le moindre message. Et un job sans
 * battement de cœur, que le reaper rejoue en plein vol au bout de deux minutes.
 */
final class CreditsPriceRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        // Le contrôleur n'est pas dans le socle des tests (il tire vendor/ à
        // l'exécution) ; son chargement seul suffit pour les méthodes pures.
        require_once ROOT . '/src/Controllers/AdminController.php';
    }

    // ---------- Partie pure : estampille du commentaire interne ----------

    public function testEstampilleRemplaceLaPrecedenteAuLieuDeSAccumuler(): void
    {
        $avant = "Tarif négocié\nMaj Internet 2025-01-02 (USD) — vieille source — https://exemple.test";
        $apres = AdminController::stampPriceSourceComment(
            $avant,
            'Tarif catalogue',
            'https://openai.com/pricing',
            '2026-08-09'
        );

        $this->assertStringContainsString('Tarif négocié', $apres, 'Le commentaire de l\'admin doit survivre.');
        $this->assertSame(
            1,
            substr_count($apres, 'Maj Internet '),
            'Une ligne de synchronisation par recherche s\'accumulerait à l\'infini.'
        );
        $this->assertStringContainsString('Maj Internet 2026-08-09 (USD) — Tarif catalogue — https://openai.com/pricing', $apres);
    }

    public function testEstampilleSansSourceNeToucheARien(): void
    {
        $this->assertSame(
            'Commentaire libre',
            AdminController::stampPriceSourceComment('Commentaire libre', '', '   ', '2026-08-09')
        );
    }

    public function testEstampilleSurCommentaireVide(): void
    {
        $this->assertSame(
            'Maj Internet 2026-08-09 (USD) — Tarif catalogue',
            AdminController::stampPriceSourceComment('', 'Tarif catalogue', '', '2026-08-09')
        );
    }

    // ---------- Le job écrit la configuration ----------

    /**
     * Sans écriture côté serveur, la recherche « asynchrone » resterait
     * suspendue à la présence de la page : c'est le cœur de la garantie
     * « changer d'écran n'annule rien ».
     */
    public function testLaRechercheEnregistreElleMemeLaConfiguration(): void
    {
        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');

        $debut = strpos($admin, 'public function runCreditsPriceRefresh');
        $fin   = strpos($admin, 'public static function stampPriceSourceComment');
        $this->assertIsInt($debut);
        $this->assertIsInt($fin);
        $bloc = substr($admin, (int)$debut, (int)$fin - (int)$debut);

        $this->assertStringContainsString('applyPriceUpdatesToStoredConfig', $bloc);
        $this->assertStringContainsString("Settings::set('credits_config_json'", $bloc,
            'Les prix trouvés doivent atterrir en base, pas seulement dans la réponse.');
        $this->assertStringContainsString("\$this->decodeJsonSetting('credits_config_json')", $bloc,
            'La configuration doit être RELUE au moment de l\'écriture : une modification '
            . 'enregistrée pendant la recherche serait sinon écrasée.');
    }

    public function testLaRouteEnfileUnJobDedupliqueEtRendLaMain(): void
    {
        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $debut = strpos($admin, 'public function refreshCreditsPrices');
        $fin   = strpos($admin, 'public function runCreditsPriceRefresh');
        $bloc  = substr($admin, (int)$debut, (int)$fin - (int)$debut);

        $this->assertStringContainsString("JobQueue::enqueue(\n            'credits_refresh'", $bloc);
        $this->assertStringContainsString("'credits_refresh'\n        );", $bloc,
            'Sans clé de déduplication, deux clics lancent deux recherches facturées.');
        $this->assertStringContainsString('JobQueue::respondAndDispatch', $bloc,
            'La requête doit rendre la main tout de suite, sinon rien n\'est asynchrone.');
    }

    // ---------- Battement de cœur et annulation ----------

    /**
     * La recherche est UN appel HTTP bloquant (jusqu'à 180 s) alors qu'un job
     * sans battement depuis 120 s est réputé mort. Sans sonde, le reaper le
     * remettrait en file pendant qu'il tourne encore : double appel LLM.
     */
    public function testLeTransfertHttpBatLeCoeurDuJob(): void
    {
        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString('CURLOPT_XFERINFOFUNCTION', $admin,
            'Aucune sonde pendant le transfert : le job passerait pour mort.');
        $this->assertStringContainsString(
            'private function postJsonPricingRequest(',
            $admin
        );

        $job = (string)file_get_contents(ROOT . '/src/Jobs/CreditsRefreshJob.php');
        $this->assertStringContainsString('$ctx->shouldStop()', $job,
            'shouldStop() est ce qui écrit le heartbeat ET relit la demande d\'arrêt.');
        // La sonde est transmise, et l'admin à l'origine du job est imputé
        // (source price_refresh, hors quota).
        $this->assertStringContainsString('runCreditsPriceRefresh($services, $keepAlive, ', $job);
        $this->assertStringContainsString('$ctx->userId()', $job);
        $this->assertStringContainsString("'cancelled' => true", $job,
            'Un transfert coupé par la sonde est une annulation, pas une panne.');
    }

    public function testLeTypeEstRejouableEtHorsQuota(): void
    {
        $queue = (string)file_get_contents(ROOT . '/src/Jobs/JobQueue.php');

        $this->assertStringContainsString("'credits_refresh' => new CreditsRefreshJob()", $queue);
        $this->assertMatchesRegularExpression("/QUOTA_EXEMPT = \\[[^\\]]*'credits_refresh'[^\\]]*\\]/", $queue,
            'Tâche d\'administration : le quota personnel de l\'admin n\'a pas à la bloquer.');
        $this->assertStringNotContainsString("'presentation', 'chat', 'credits_refresh'", $queue,
            'La recherche de prix est idempotente : le reaper doit pouvoir la rejouer.');
    }

    // ---------- L'écran n'est plus qu'un spectateur ----------

    public function testLEcranNeBloquePlusEtSeRattacheAuJob(): void
    {
        $page = (string)file_get_contents(ROOT . '/templates/admin/credits.php');

        $this->assertStringNotContainsString('refresh-overlay-card', $page,
            'Un voile modal fait croire que quitter la page annule la recherche.');
        $this->assertStringNotContainsString('showRefreshOverlay', $page);
        $this->assertStringContainsString('id="priceJobBanner"', $page);

        $this->assertStringContainsString("fetch('/jobs/active'", $page,
            'Sans interrogation de la file, une recherche lancée depuis un autre écran '
            . 'ou un autre onglet resterait invisible.');
        $this->assertStringContainsString("new EventSource('/jobs/' + jobId + '/stream')", $page);
        $this->assertStringContainsString('restorePriceJob();', $page);
    }

    /**
     * Le job vient d'écrire la configuration ; un envoi d'auto-save resté en
     * attente reposterait l'état d'AVANT et écraserait les prix.
     */
    public function testLAutoSaveEstDesarmeeAvantLeRechargement(): void
    {
        $page  = (string)file_get_contents(ROOT . '/templates/admin/credits.php');
        $debut = strpos($page, 'function finishPriceJob');
        $this->assertIsInt($debut);
        $bloc  = substr($page, (int)$debut, 1600);

        $this->assertStringContainsString('window.MiaAutosave.detach(els.form)', $bloc);
        $this->assertStringContainsString('window.location.reload()', $bloc);
        $this->assertLessThan(
            strpos($bloc, 'window.location.reload()'),
            strpos($bloc, 'window.MiaAutosave.detach(els.form)'),
            'Désarmer APRÈS le rechargement ne servirait à rien.'
        );
    }

    // ---------- Ce qui part en ligne, et comment ----------

    /**
     * La recherche en ligne durait des minutes parce que TOUT ce que le
     * catalogue ne tarifait pas y partait : pages web, cartes, présentations
     * (facturées aux jetons de leur modèle), Gamma, n8n, modèle local, modèle
     * arrêté. Aucun n'a de prix à chercher — un prix « trouvé » était inventé.
     */
    public function testLesServicesSansPrixAChercherNePartentPasEnLigne(): void
    {
        ToolCatalog::setToday('2026-11-13');
        try {
            foreach (['html', 'html_illustrated', 'map', 'presentation', 'gamma', 'n8n'] as $type) {
                $outil = ['id' => 7, 'type' => $type, 'provider' => 'openai', 'model' => 'gpt-5.6'];
                $this->assertNotNull(
                    AdminController::priceRefreshSkipReason(['catalog_key' => 'tool:7'], $outil),
                    "Un outil « $type » n'a aucun prix unitaire à chercher en ligne."
                );
            }
            $this->assertNotNull(AdminController::priceRefreshSkipReason(['catalog_key' => 'llm:local::llama3:input'], null),
                'Un modèle local est gratuit.');
            $this->assertNotNull(AdminController::priceRefreshSkipReason(['catalog_key' => 'tool:9'], null),
                'Un outil disparu n\'a rien à tarifer.');
            $this->assertNotNull(AdminController::priceRefreshSkipReason(
                ['catalog_key' => 'tool:3'],
                ['type' => 'image', 'provider' => 'openai', 'model' => 'dall-e-3']
            ), 'DALL-E 3 est arrêté : plus aucune image à tarifer.');

            // Ce qui garde un prix unitaire à trouver part toujours en ligne.
            $this->assertNull(AdminController::priceRefreshSkipReason(
                ['catalog_key' => 'tool:4'],
                ['type' => 'image', 'provider' => 'openai', 'model' => 'gpt-5.4']
            ));
            $this->assertNull(AdminController::priceRefreshSkipReason(['catalog_key' => 'websearch:mistral'], null));
            $this->assertNull(AdminController::priceRefreshSkipReason(['catalog_key' => 'llm:openai::modele-maison:input'], null));
        } finally {
            ToolCatalog::setToday(null);
        }
    }

    /**
     * Un prix fixe posé sur la ligne d'un modèle d'image tarifé au jeton
     * PRIME sur le calcul image par image (CreditService::computeToolCredits) :
     * c'est ce qu'écrivait la recherche en ligne. La mise à jour remet la
     * ligne à zéro, et l'écran dit sur quoi l'image est comptée.
     */
    public function testUnModeleDImageAuJetonNEstPlusTarifeAPrixFixe(): void
    {
        $base = AdminController::toolCostBasis(['type' => 'image', 'model' => 'gpt-image-2.6-flare']);
        $this->assertNotNull($base);
        $this->assertStringContainsString('jeton', (string)$base);
        $this->assertNull(AdminController::toolCostBasis(['type' => 'image', 'model' => 'gemini-nano-banana-2.1']),
            'Un modèle tarifé à l\'image a un prix unitaire.');

        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertMatchesRegularExpression(
            "/if \\(is_array\\(\\\$pricing\\['tokens_usd_per_1m'\\] \\?\\? null\\)\\) \\{\\s*return \\['real_cost_usd' => 0\\.0/",
            $admin,
            'Le tarif au jeton doit remettre la ligne à zéro, pas chercher un prix fixe.'
        );

        $page = (string)file_get_contents(ROOT . '/templates/admin/credits.php');
        $this->assertStringContainsString("source: 'basis'", $page);
        $this->assertStringContainsString("'basis' => \$basis", $admin, 'Le serveur pose la raison sous la ligne.');
    }

    public function testLesLotsRegroupentParFournisseur(): void
    {
        $services = [];
        for ($i = 0; $i < 8; $i++) {
            $services[] = ['id' => "m$i", 'provider' => 'Mistral'];
        }
        $services[] = ['id' => 'o1', 'provider' => 'openai'];
        $services[] = ['id' => 'x1', 'provider' => ''];

        $lots = AdminController::priceLookupBatches($services, 6);
        $this->assertSame([6, 2, 1, 1], array_map('count', $lots));
        $this->assertSame('m6', $lots[1][0]['id'], 'Les services d\'un fournisseur restent ensemble, dans l\'ordre.');
        $this->assertSame([], AdminController::priceLookupBatches([]));
    }

    /**
     * Le modèle de la clé pouvait être un modèle frontière à raisonnement long
     * (GPT-6 Astra, GPT-5.5 Pro) : des minutes pour lire une page de tarifs.
     * Le catalogue désigne le rapide, à effort faible ; la clé n'est qu'un repli.
     */
    public function testLaRechercheEssaieDAbordLeModeleRapideAEffortFaible(): void
    {
        $prefere = (string)(LlmCatalog::provider('openai')['price_lookup_model'] ?? '');
        $this->assertNotSame('', $prefere, 'Le catalogue doit désigner le modèle de la recherche des prix.');

        $essais = AdminController::priceLookupVariants('gpt-6-astra');
        $this->assertSame(['model' => $prefere, 'reasoning' => true], $essais[0]);
        $this->assertSame(['model' => $prefere, 'reasoning' => false], $essais[1],
            'Un modèle qui refuse l\'effort de raisonnement est rejoué sans lui.');
        $this->assertSame('gpt-6-astra', $essais[2]['model'], 'Le modèle de la clé sert de repli.');

        $cles = array_map(
            static fn(array $e): string => $e['model'] . ($e['reasoning'] ? '+effort' : ''),
            AdminController::priceLookupVariants($prefere)
        );
        $this->assertSame($cles, array_values(array_unique($cles)), 'Aucun essai en double.');

        $this->assertNotContains('o3', array_column(AdminController::priceLookupVariants('o3'), 'model'),
            'Un modèle qui ne cherche pas sur le web refuserait l\'outil.');
        $this->assertContains('modele-maison', array_column(AdminController::priceLookupVariants('modele-maison'), 'model'),
            'Un modèle inconnu du catalogue, choisi pour la clé, reste essayé.');

        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString("\$payload['reasoning'] = ['effort' => 'low'];", $admin);
    }

    public function testUnRefusSeCorrigeParLEssaiSuivant(): void
    {
        $raisonnement = '{"error":{"message":"Unsupported parameter: \'reasoning.effort\' is not supported with this model.","type":"invalid_request_error","param":"reasoning.effort","code":"unsupported_parameter"}}';
        $inconnu = '{"error":{"message":"The model `gpt-6-luna` does not exist or you do not have access to it.","type":"invalid_request_error","code":"model_not_found"}}';
        $web = '{"error":{"message":"Tool \'web_search\' is not supported with gpt-4.1-nano.","type":"invalid_request_error"}}';

        $this->assertSame('reasoning', AdminController::pricingRetryKind(400, $raisonnement));
        $this->assertSame('model', AdminController::pricingRetryKind(404, $inconnu));
        $this->assertSame('model', AdminController::pricingRetryKind(400, $web));
        $this->assertNull(AdminController::pricingRetryKind(401, $inconnu), 'Une clé refusée ne se corrige pas en changeant de modèle.');
        $this->assertNull(AdminController::pricingRetryKind(429, $inconnu));
        $this->assertNull(AdminController::pricingRetryKind(500, $raisonnement));
        $this->assertNull(AdminController::pricingRetryKind(200, '{}'));
        $this->assertNull(AdminController::pricingRetryKind(400, '{"error":{"message":"Invalid JSON body."}}'));

        $essais = [
            ['model' => 'a', 'reasoning' => true],
            ['model' => 'a', 'reasoning' => false],
            ['model' => 'b', 'reasoning' => false],
        ];
        $this->assertSame(1, AdminController::nextPricingVariant($essais, 0, 'reasoning'));
        $this->assertSame(2, AdminController::nextPricingVariant($essais, 0, 'model'), 'Un modèle refusé : on passe au suivant.');
        $this->assertNull(AdminController::nextPricingVariant($essais, 2, 'model'));
    }

    /**
     * Le VRAI chemin des lots, sans réseau : chaque lot reçoit sa réponse, un
     * lot refusé pour l'effort de raisonnement est rejoué sans, un lot en
     * panne n'emporte pas les autres — ses services sont dits sans prix.
     */
    public function testLesLotsSontLusUnAUnEtUnePanneNEmportePasLesAutres(): void
    {
        $appels = [];
        AdminController::overridePricingTransport(static function (string $url, string $payload, string $bearer) use (&$appels): array {
            $req = json_decode($payload, true);
            $appels[] = ['model' => $req['model'], 'reasoning' => isset($req['reasoning'])];
            preg_match_all('/"id":"([^"]+)"/', (string)$req['input'], $m);
            $ids = $m[1];
            if (in_array('panne', $ids, true)) {
                return [500, '{"error":{"message":"Internal server error"}}', null];
            }
            if (isset($req['reasoning']) && in_array('ws-a', $ids, true)) {
                return [400, '{"error":{"message":"Unsupported parameter: \'reasoning.effort\' is not supported with this model."}}', null];
            }
            $updates = array_map(static fn(string $id): array => ['id' => $id, 'real_cost_usd' => 0.01, 'source_url' => 'https://exemple.test/prix', 'source_note' => 'Page officielle'], $ids);
            $texte = json_encode(['updates' => $updates, 'not_found' => []]);
            return [200, (string)json_encode(['status' => 'completed', 'output' => [
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $texte]]],
            ]]), null];
        });

        try {
            $services = [
                ['id' => 'ws-a', 'provider' => 'mistral', 'type' => 'web_search', 'unit' => 'request'],
                ['id' => 'ws-b', 'provider' => 'mistral', 'type' => 'web_search', 'unit' => 'request'],
                ['id' => 'panne', 'provider' => 'autre', 'type' => 'web_search', 'unit' => 'request'],
                ['id' => 'img', 'provider' => 'openai', 'type' => 'image', 'unit' => 'image_generated'],
            ];
            $ctrl = new AdminController();
            $run  = new ReflectionMethod($ctrl, 'runPricingBatches');
            $read = new ReflectionMethod($ctrl, 'readOpenAiPricingResponse');
            $result = $run->invoke(
                $ctrl,
                'OpenAI',
                $services,
                [['model' => 'rapide', 'reasoning' => true], ['model' => 'rapide', 'reasoning' => false]],
                static fn(array $lot, array $essai): array => ['url' => 'https://exemple.test/v1/responses', 'bearer' => 'sk-test',
                    'payload' => (string)json_encode(['model' => $essai['model'], 'input' => json_encode($lot)] + ($essai['reasoning'] ? ['reasoning' => ['effort' => 'low']] : []))],
                static fn(int $statut, string $corps, array $lot, array $essai): array => $read->invoke($ctrl, $statut, $corps, $lot, (string)$essai['model']),
                null
            );
        } finally {
            AdminController::overridePricingTransport(null);
        }

        $this->assertEqualsCanonicalizing(['ws-a', 'ws-b', 'img'], array_column($result['updated'], 'id'), json_encode($result));
        $this->assertSame(['panne'], array_column($result['not_found'], 'id'));
        $this->assertStringContainsString('incomplète', (string)($result['warning'] ?? ''));
        $this->assertSame('openai:rapide', $result['engine']);
        $this->assertContains(['model' => 'rapide', 'reasoning' => false], $appels, 'Le lot refusé est rejoué sans effort de raisonnement.');
        $this->assertCount(4, $appels, 'Trois lots, dont un rejoué une fois.');
    }

    /**
     * Les prix du catalogue sont sûrs : une recherche en ligne en échec ne
     * les emporte plus. Seul ce que le catalogue ne couvre pas part en ligne.
     */
    public function testUnePanneEnLigneGardeLesPrixDuCatalogue(): void
    {
        $ctrl = new class extends AdminController {
            public array $envoyes = [];
            public ?\Throwable $panne = null;
            protected function fetchUsdPricingViaLlm(array $services, ?callable $keepAlive = null): array
            {
                $this->envoyes = array_column($services, 'catalog_key');
                if ($this->panne !== null) {
                    throw $this->panne;
                }
                return ['engine' => 'openai:rapide', 'updated' => [], 'not_found' => [], 'checked_at' => gmdate('c')];
            }
        };
        $orchestre = new ReflectionMethod(AdminController::class, 'fetchLatestUsdPricingFromInternet');
        $services = [
            ['id' => 'luna-in', 'catalog_key' => 'llm:openai::gpt-6-luna:input'],
            ['id' => 'local',   'catalog_key' => 'llm:local::llama3:input'],
            ['id' => 'ws',      'catalog_key' => 'websearch:mistral', 'provider' => 'mistral'],
        ];

        $ok = $orchestre->invoke($ctrl, $services, null);
        $this->assertSame(['websearch:mistral'], $ctrl->envoyes, 'Seul ce que le catalogue ne couvre pas part en ligne.');
        $this->assertSame(['local'], array_column($ok['skipped'], 'id'));
        $this->assertSame(['luna-in'], array_column($ok['updated'], 'id'));

        $ctrl->panne = new \RuntimeException('Appel OpenAI impossible (délai dépassé).');
        $degrade = $orchestre->invoke($ctrl, $services, null);
        $this->assertSame(['luna-in'], array_column($degrade['updated'], 'id'), 'Les prix du catalogue restent appliqués.');
        $this->assertSame(['ws'], array_column($degrade['not_found'], 'id'));
        $this->assertStringContainsString('délai dépassé', (string)$degrade['warning']);

        // Un arrêt demandé annule tout, prix du catalogue compris.
        $ctrl2 = new class extends AdminController {
            protected function fetchUsdPricingViaLlm(array $services, ?callable $keepAlive = null): array
            {
                $keepAlive();
                throw new \RuntimeException('Recherche interrompue.');
            }
        };
        $this->expectException(\RuntimeException::class);
        $orchestre->invoke($ctrl2, $services, static fn(): bool => false);
    }

    /** Rien à chercher en ligne : aucune clé, aucun appel, la mise à jour est immédiate. */
    public function testToutAuCatalogueNAppelleRien(): void
    {
        $ctrl = new class extends AdminController {
            public int $appels = 0;
            protected function fetchUsdPricingViaLlm(array $services, ?callable $keepAlive = null): array
            {
                $this->appels++;
                return [];
            }
        };
        $orchestre = new ReflectionMethod(AdminController::class, 'fetchLatestUsdPricingFromInternet');
        $r = $orchestre->invoke($ctrl, [
            ['id' => 'a', 'catalog_key' => 'llm:anthropic::claude-opus-5:output'],
            ['id' => 'b', 'catalog_key' => 'llm:local::qwen:output'],
        ], null);
        $this->assertSame(0, $ctrl->appels);
        $this->assertSame('catalogue', $r['engine']);
    }

    /**
     * Plusieurs lots partent ENSEMBLE : trois réponses d'une seconde prennent
     * une seconde, pas trois. Un serveur local par requête (`php -S` sert une
     * requête à la fois).
     */
    public function testTroisLotsDUneSecondePrennentUneSeconde(): void
    {
        if (!function_exists('curl_multi_init')) {
            $this->markTestSkipped('curl absent.');
        }
        $routeur = sys_get_temp_dir() . '/mia_prix_routeur_' . getmypid() . '.php';
        file_put_contents($routeur, '<?php usleep(1000000); header("Content-Type: application/json"); echo json_encode(["corps" => file_get_contents("php://input"), "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? ""]);');
        $serveurs = [];
        $nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        try {
            $adresses = [];
            for ($n = 0; $n < 3; $n++) {
                $adresse = null;
                for ($essai = 0; $essai < 5 && $adresse === null; $essai++) {
                    $port = random_int(20000, 40000);
                    $p = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $routeur], [1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $pipes);
                    if (!is_resource($p)) {
                        continue;
                    }
                    $serveurs[] = $p;
                    for ($i = 0; $i < 50; $i++) {
                        if (!(proc_get_status($p)['running'] ?? false)) {
                            break;
                        }
                        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                        if ($s !== false) {
                            fclose($s);
                            $adresse = 'http://127.0.0.1:' . $port;
                            break;
                        }
                        usleep(100000);
                    }
                }
                if ($adresse === null) {
                    $this->markTestSkipped('Serveur HTTP local indisponible.');
                }
                $adresses[] = $adresse;
            }

            $ctrl = new AdminController();
            $envoi = new ReflectionMethod($ctrl, 'postJsonPricingRequests');
            $battements = 0;
            $t0 = microtime(true);
            $r = $envoi->invoke($ctrl, [
                0 => ['url' => $adresses[0] . '/a', 'bearer' => 'sk-test', 'payload' => '{"lot":0}'],
                1 => ['url' => $adresses[1] . '/b', 'bearer' => 'sk-test', 'payload' => '{"lot":1}'],
                2 => ['url' => $adresses[2] . '/c', 'bearer' => 'sk-test', 'payload' => '{"lot":2}'],
            ], static function () use (&$battements): bool {
                $battements++;
                return true;
            });
            $duree = microtime(true) - $t0;

            $this->assertLessThan(2.2, $duree, 'Les lots partent ensemble (sinon : trois secondes).');
            $this->assertSame([0, 1, 2], array_keys($r));
            $this->assertSame(200, $r[1][0]);
            $this->assertSame('{"lot":1}', json_decode($r[1][1], true)['corps']);
            $this->assertSame('Bearer sk-test', json_decode($r[2][1], true)['auth']);
            $this->assertGreaterThan(0, $battements, 'La sonde bat le cœur du job pendant l\'attente.');

            // La sonde dit « arrêt » : rien n'attend plus.
            $t0 = microtime(true);
            try {
                $envoi->invoke($ctrl, [
                    0 => ['url' => $adresses[0] . '/a', 'bearer' => 'sk-test', 'payload' => '{}'],
                    1 => ['url' => $adresses[1] . '/b', 'bearer' => 'sk-test', 'payload' => '{}'],
                ], static fn(): bool => false);
                $this->fail('Un arrêt demandé doit interrompre la recherche.');
            } catch (\RuntimeException $e) {
                $this->assertLessThan(0.9, microtime(true) - $t0);
            }
        } finally {
            foreach ($serveurs as $p) {
                proc_terminate($p);
                proc_close($p);
            }
            @unlink($routeur);
        }
    }

    public function testUnEchecDitSaCauseAuLieuDuMessageGenerique(): void
    {
        require_once ROOT . '/src/Jobs/CreditsRefreshJob.php';

        $reseau = CreditsRefreshJob::errorPayload(new \RuntimeException('Appel OpenAI impossible (Operation timed out).'));
        $this->assertSame('Mise à jour des prix impossible', $reseau['title']);
        $this->assertSame('La recherche de prix a échoué : Appel OpenAI impossible (Operation timed out).', $reseau['message']);

        // Erreur de base : rien de technique à l'écran, renvoi aux journaux.
        $base = CreditsRefreshJob::errorPayload(new \PDOException("SQLSTATE[22001]: 1406 Data too long for column 'value'"));
        $this->assertStringNotContainsString('SQLSTATE', $base['message']);
        $this->assertStringContainsString('Journaux', $base['message']);

        $code = CreditsRefreshJob::errorPayload(new \TypeError('Argument #1 must be of type array'));
        $this->assertStringNotContainsString('Argument', $code['message']);

        // Le job ne laisse plus passer une erreur sans charge utile : c'est elle
        // que JobQueue::execute remplaçait par « La génération n'a pas abouti ».
        $src = (string)file_get_contents(ROOT . '/src/Jobs/CreditsRefreshJob.php');
        $this->assertStringContainsString('throw new JobErrorException(self::errorPayload($e), $e->getMessage());', $src);
    }

    public function testLesReglagesPeuventDepasser64Ko(): void
    {
        $migration = (string)file_get_contents(ROOT . '/database/migrations/108_settings_mediumtext.sql');
        $this->assertMatchesRegularExpression('/ALTER TABLE settings MODIFY `value` MEDIUMTEXT/', $migration);
    }
}
