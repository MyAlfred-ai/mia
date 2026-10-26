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
}
