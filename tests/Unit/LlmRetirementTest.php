<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Modèles LLM arrêtés par leur éditeur (bloc `retirement` du catalogue).
 *
 * Le problème résolu : un modèle annoncé arrêté restait proposé comme si de
 * rien n'était — rien ne prévenait l'administrateur qui l'avait activé, et le
 * jour J chaque message échouait (« modèle introuvable »), y compris pour les
 * assistants qui l'imposaient. Le catalogue porte désormais la date et le
 * remplaçant désigné ; LlmCatalog::retirement() est la source unique lue par
 * l'administration (badge, points à régler) et par le chat (le modèle quitte
 * LLMFactory::available à la date, le défaut prend la relève).
 *
 * Premier cas : courriel d'OpenAI du 14/10/2026 — GPT-4o, GPT-4o mini,
 * GPT-4.1 et GPT-4.1 mini coupés le 14/01/2027, remplaçant gpt-6-luna.
 */
final class LlmRetirementTest extends TestCase
{
    private const SERIE_GPT4 = ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini'];

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        LlmCatalog::setToday(null);
        AdminAttention::forget();
    }

    private static function lire(string $relatif): string
    {
        $abs = ROOT . '/' . ltrim($relatif, '/');
        self::assertFileExists($abs);
        return (string)file_get_contents($abs);
    }

    /** @return array<string, array<string,mixed>> */
    private static function parId(array $signals): array
    {
        $out = [];
        foreach ($signals as $s) {
            $out[$s['id']] = $s;
        }
        return $out;
    }

    // ================================================================ Catalogue

    public function testLaSerieGpt4EstArreteeLe14Janvier2027AuProfitDeGpt6Luna(): void
    {
        LlmCatalog::setToday('2026-10-14');
        foreach (self::SERIE_GPT4 as $modele) {
            $arret = LlmCatalog::retirement('openai', $modele);
            $this->assertNotNull($arret, $modele);
            $this->assertSame('2027-01-14', $arret['date'], $modele);
            $this->assertSame('gpt-6-luna', $arret['replacement'], $modele);
            $this->assertSame('GPT-6 Luna', $arret['replacement_label'], $modele);
            $this->assertSame('https://developers.openai.com/api/docs/deprecations', $arret['source_url'], $modele);
            $this->assertFalse($arret['retired'], "$modele est encore servi en octobre 2026");
            $this->assertStringContainsString('14/01/2027', (string)LlmCatalog::model('openai', $modele)['note'], $modele);
        }
        // Le remplaçant, lui, n'est pas arrêté : sinon on enverrait les
        // administrateurs d'un modèle mort vers un autre.
        $this->assertNull(LlmCatalog::retirement('openai', 'gpt-6-luna'));
    }

    public function testLesArretsDejaAnnoncesParOpenAiSontDeclares(): void
    {
        LlmCatalog::setToday('2026-10-14');
        $attendus = [
            'o3'            => ['2026-12-11', 'gpt-5.6-sol'],
            'gpt-5-mini'    => ['2026-12-11', 'gpt-5.6-terra'],
            'gpt-5.3-codex' => ['2027-04-01', 'gpt-6-sol'],
        ];
        foreach ($attendus as $modele => [$date, $remplacant]) {
            $arret = LlmCatalog::retirement('openai', $modele);
            $this->assertNotNull($arret, $modele);
            $this->assertSame($date, $arret['date'], $modele);
            $this->assertSame($remplacant, $arret['replacement'], $modele);
        }
        // GPT-6 Sol n'est pas au catalogue : son identifiant tient lieu de libellé.
        $this->assertSame('gpt-6-sol', LlmCatalog::retirement('openai', 'gpt-5.3-codex')['replacement_label']);
    }

    public function testChaqueBlocDuCatalogueEstBienForme(): void
    {
        $vus = 0;
        foreach (LlmCatalog::all() as $provider => $config) {
            foreach (($config['models'] ?? []) as $id => $info) {
                if (!isset($info['retirement'])) {
                    continue;
                }
                $vus++;
                $bloc = $info['retirement'];
                $this->assertIsArray($bloc, "$provider/$id");
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string)($bloc['date'] ?? ''), "$provider/$id : date AAAA-MM-JJ");
                $this->assertNotSame('', trim((string)($bloc['replacement'] ?? '')), "$provider/$id : remplaçant désigné");
                $this->assertNotSame($id, $bloc['replacement'], "$provider/$id : remplaçant distinct");
                $this->assertStringStartsWith('https://', (string)($bloc['source_url'] ?? ''), "$provider/$id : source");
                // Un remplaçant présent au catalogue ne s'arrête pas avant le modèle qu'il remplace.
                $relais = LlmCatalog::model($provider, (string)$bloc['replacement'])['retirement']['date'] ?? null;
                if ($relais !== null) {
                    $this->assertGreaterThan($bloc['date'], $relais, "$provider/$id : remplaçant arrêté avant lui");
                }
            }
        }
        $this->assertGreaterThanOrEqual(7, $vus);
    }

    public function testLArretTombeLeJourMemeEtPasLaVeille(): void
    {
        LlmCatalog::setToday('2027-01-13');
        $this->assertFalse(LlmCatalog::isRetired('openai', 'gpt-4o'));
        $this->assertFalse(LLMFactory::isRetired('openai::gpt-4.1-mini'));

        LlmCatalog::setToday('2027-01-14');
        foreach (self::SERIE_GPT4 as $modele) {
            $this->assertTrue(LlmCatalog::isRetired('openai', $modele), $modele);
            $this->assertTrue(LLMFactory::isRetired('openai::' . $modele), $modele);
        }
        // Rien d'autre n'est emporté : un slug sans modèle, le LLM local, un
        // modèle sans bloc, un modèle inconnu.
        foreach (['openai', 'local', 'openai::gpt-6-luna', 'openai::gpt-5.6', 'anthropic::claude-opus-5', 'openai::inconnu'] as $slug) {
            $this->assertFalse(LLMFactory::isRetired($slug), $slug);
        }
    }

    public function testLaFormePureExigeUneDateValide(): void
    {
        $this->assertNull(LlmCatalog::retirementFrom([], '2027-01-14'));
        $this->assertNull(LlmCatalog::retirementFrom(['date' => '14/01/2027'], '2027-01-14'), 'une date mal écrite n\'arrête rien');
        $this->assertSame(
            ['date' => '2027-01-14', 'replacement' => 'gpt-6-luna', 'source_url' => '', 'retired' => true],
            LlmCatalog::retirementFrom(['date' => '2027-01-14', 'replacement' => ' gpt-6-luna '], '2027-01-14')
        );
        $this->assertFalse(LlmCatalog::retirementFrom(['date' => '2027-01-14'], '2027-01-13')['retired']);
    }

    // ================================================================ Points à régler

    public function testUnModeleActiveAnnonceArreteEstSignaleAvecSaDateEtSonRemplacant(): void
    {
        LlmCatalog::setToday('2026-10-14');
        $actifs = [
            ['provider' => 'openai', 'model' => 'gpt-4o', 'display_name' => 'GPT-4o'],
            ['provider' => 'openai', 'model' => 'gpt-6-luna', 'display_name' => 'GPT-6 Luna'],
        ];
        $s = self::parId(AdminAttention::llmRetirementSignals($actifs, []));
        $this->assertArrayHasKey('llm_arret_annonce', $s);
        $this->assertArrayNotHasKey('llm_modeles_arretes', $s);
        $this->assertSame('llms-models', $s['llm_arret_annonce']['entry']);
        $this->assertSame(AdminAttention::WARN, $s['llm_arret_annonce']['level']);
        $this->assertStringContainsString('GPT-4o (le 14/01/2027, remplaçant : GPT-6 Luna)', $s['llm_arret_annonce']['text']);
        $this->assertStringNotContainsString('GPT-6 Luna (le', $s['llm_arret_annonce']['text'], 'le remplaçant n\'est pas signalé');
    }

    public function testApresLaDateLeModeleEncoreActiveEstDitRetireDuChat(): void
    {
        LlmCatalog::setToday('2027-01-14');
        $s = self::parId(AdminAttention::llmRetirementSignals(
            [['provider' => 'openai', 'model' => 'gpt-4.1', 'display_name' => '']],
            [['name' => 'Juriste', 'default_llm' => 'openai::gpt-4.1']]
        ));
        $this->assertArrayHasKey('llm_modeles_arretes', $s);
        $this->assertArrayNotHasKey('llm_arret_annonce', $s);
        // Libellé du catalogue quand l'instance n'en porte pas.
        $this->assertStringContainsString('GPT-4.1 (depuis le 14/01/2027', $s['llm_modeles_arretes']['text']);
        $this->assertStringContainsString('modèle par défaut', $s['llm_modeles_arretes']['text']);
        // L'assistant : « assistant sur un modèle retiré » (assistantSignals) prend le relais.
        $this->assertArrayNotHasKey('assistant_modele_arret_annonce', $s);
    }

    public function testUnAssistantQuiImposeUnModeleBientotArreteEstSignale(): void
    {
        LlmCatalog::setToday('2026-12-01');
        $s = self::parId(AdminAttention::llmRetirementSignals([], [
            ['name' => 'Juriste', 'default_llm' => 'openai::gpt-4o-mini'],
            ['name' => 'Rédacteur', 'default_llm' => 'openai::gpt-6-luna'],
            ['name' => 'Généraliste', 'default_llm' => null],
        ]));
        $this->assertSame(['assistant_modele_arret_annonce'], array_keys($s));
        $this->assertSame('profiles', $s['assistant_modele_arret_annonce']['entry']);
        $this->assertStringContainsString('« Juriste »', $s['assistant_modele_arret_annonce']['text']);
        $this->assertStringNotContainsString('Rédacteur', $s['assistant_modele_arret_annonce']['text']);
    }

    public function testLeDefautEtLeSecoursArretesLeDisent(): void
    {
        LlmCatalog::setToday('2027-01-14');
        $s = self::parId(AdminAttention::llmSignals(
            ['openai'],
            [['provider' => 'openai', 'model' => 'gpt-6-luna', 'display_name' => 'GPT-6 Luna']],
            ['openai::gpt-6-luna' => 'GPT-6 Luna'],
            'openai::gpt-4o',
            'openai::gpt-4.1-mini',
            'openai::gpt-6-luna'
        ));
        $this->assertStringStartsWith('Le modèle par défaut enregistré a été arrêté par son éditeur : MIA utilise « GPT-6 Luna »', $s['llm_defaut_a_confirmer']['text']);
        $this->assertStringContainsString('« GPT-4.1 Mini » a été arrêté par son éditeur', $s['llm_secours']['text']);

        // Un réglage simplement absent garde son message d'origine.
        $s = self::parId(AdminAttention::llmSignals(['openai'], [], ['openai::gpt-6-luna' => 'GPT-6 Luna'], 'openai', '', 'openai::gpt-6-luna'));
        $this->assertStringStartsWith('Aucun modèle par défaut n\'est enregistré', $s['llm_defaut_a_confirmer']['text']);
    }

    public function testRienAnnoncéRienDit(): void
    {
        LlmCatalog::setToday('2026-10-14');
        $this->assertSame([], AdminAttention::llmRetirementSignals(
            [['provider' => 'anthropic', 'model' => 'claude-opus-5', 'display_name' => 'Claude Opus 5']],
            [['name' => 'A', 'default_llm' => 'anthropic::claude-opus-5']]
        ));
    }

    // ================================================================ Câblage

    public function testLeChatEcarteLeModeleArreteEtLeDefautPrendLaRelève(): void
    {
        $factory = self::lire('src/LLM/LLMFactory.php');
        $this->assertMatchesRegularExpression(
            '/foreach \(LlmModel::active\(\) as \$m\) \{\s*(?:\/\/[^\n]*\n\s*)*if \(LlmCatalog::isRetired\(/',
            $factory,
            'LLMFactory::available() écarte un modèle arrêté'
        );

        // Une conversation qui portait le modèle continue sur le défaut, au
        // lieu d'un « pas autorisé pour votre groupe » — envoi ET régénération.
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count(
            $chat,
            "if (!isset(\$allowedLlms[\$llm]) && LLMFactory::isRetired(\$llm)) {\n            \$llm = \$this->resolveAppDefaultLlm(\$availableLlms);"
        ));

        // Le modèle enregistré avec la clé (ancien format) n'est plus servi.
        $this->assertStringContainsString('LlmCatalog::isRetired($provider, (string)$row[\'model\']) ? null', self::lire('src/Models/ApiKey.php'));
    }

    public function testLAdministrationNeLeProposePlusEtNeLAppellePlusEnDur(): void
    {
        $admin = self::lire('src/Controllers/AdminController.php');
        // Activation refusée côté serveur (page restée ouverte).
        $this->assertMatchesRegularExpression('/function saveLlmModel\(\).*?LlmCatalog::retirement\(.*?LlmModel::save\(/s', $admin);
        // Test de clé : un modèle arrêté ne fait pas passer la clé pour invalide.
        $this->assertMatchesRegularExpression('/function modelsToTest\(.*?LlmCatalog::isRetired\(\$provider, \$m\)/s', $admin);
        // Plus aucun replis codé sur la série GPT-4.
        $this->assertDoesNotMatchRegularExpression("/'gpt-4o'|'gpt-4o-mini'|'gpt-4\\.1'|'gpt-4\\.1-mini'/", $admin);

        $page = self::lire('templates/admin/llms/models.php');
        $this->assertStringContainsString('LlmCatalog::retirement($p, $m)', $page);
        $this->assertStringContainsString("Arrêté par l'éditeur", $page);
        $this->assertStringContainsString('Remplaçant désigné', $page);
        $this->assertStringContainsString('!empty($error)', self::lire('templates/admin/llms/_head.php'));

        $this->assertStringContainsString('self::llmRetirementSignals($actifs, $profils)', self::lire('src/Helpers/AdminAttention.php'));
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLesGuidesEtLesNouveautesLeDisent(): void
    {
        $this->assertStringContainsString('arrêt', mb_strtolower(self::lire('docs/user/admin/modeles/activer-les-modeles.md')));
        $this->assertStringContainsString('Arrêt le', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('<!-- id: modeles-arretes-editeur |', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('LlmCatalog::retirement', self::lire('CLAUDE.md'));
    }
}
