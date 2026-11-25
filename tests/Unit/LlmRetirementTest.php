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

    /**
     * Courriel rectificatif d'OpenAI du 12/11/2026 (page Deprecations relue en
     * verbatim le même jour) : six modèles coupés le 11/12/2026, versions
     * datées comprises. o3 et gpt-5-mini sont au catalogue ; les autres sont
     * dans `retired_models` (jamais proposés, mais reconnus s'ils sont portés).
     */
    public function testLesSixModelesDuCourrielDOpenAiSArretentLe11Decembre2026(): void
    {
        $attendus = [
            'gpt-5'                 => 'gpt-5.6-sol',
            'gpt-5-2025-08-07'      => 'gpt-5.6-sol',
            'gpt-5-mini'            => 'gpt-5.6-terra',
            'gpt-5-mini-2025-08-07' => 'gpt-5.6-terra',
            'gpt-5-nano'            => 'gpt-5.6-luna',
            'gpt-5-nano-2025-08-07' => 'gpt-5.6-luna',
            'gpt-5-pro'             => 'gpt-5.6-sol',
            'gpt-5-pro-2025-10-06'  => 'gpt-5.6-sol',
            'o3'                    => 'gpt-5.6-sol',
            'o3-2025-04-16'         => 'gpt-5.6-sol',
            'o3-pro'                => 'gpt-5.6-sol',
            'o3-pro-2025-06-10'     => 'gpt-5.6-sol',
        ];
        LlmCatalog::setToday('2026-12-10');
        foreach ($attendus as $modele => $remplacant) {
            $arret = LlmCatalog::retirement('openai', $modele);
            $this->assertNotNull($arret, $modele);
            $this->assertSame('2026-12-11', $arret['date'], $modele);
            $this->assertSame($remplacant, $arret['replacement'], $modele);
            $this->assertNotSame($remplacant, $arret['replacement_label'], "$modele : le remplaçant est au catalogue, nommé par son libellé");
            $this->assertSame('https://developers.openai.com/api/docs/deprecations', $arret['source_url'], $modele);
            $this->assertFalse($arret['retired'], "$modele est encore servi la veille");
            $this->assertFalse(LLMFactory::isRetired('openai::' . $modele), $modele);
        }
        LlmCatalog::setToday('2026-12-11');
        foreach (array_keys($attendus) as $modele) {
            $this->assertTrue(LlmCatalog::isRetired('openai', $modele), $modele);
            $this->assertTrue(LLMFactory::isRetired('openai::' . $modele), $modele);
        }
        // Les remplaçants, eux, restent servis.
        foreach (array_unique(array_values($attendus)) as $remplacant) {
            $this->assertFalse(LlmCatalog::isRetired('openai', $remplacant), $remplacant);
        }
    }

    public function testUnModeleQueMiaNeProposePasNEstJamaisProposeMaisSonArretEstConnu(): void
    {
        $vus = 0;
        foreach (LlmCatalog::all() as $provider => $config) {
            foreach (($config['retired_models'] ?? []) as $id => $info) {
                $vus++;
                $id = (string)$id;
                // Jamais dans `models` : il ne serait plus « hors catalogue », et
                // l'administration le proposerait à l'activation.
                $this->assertNull(LlmCatalog::model($provider, $id), "$provider/$id : absent de models");
                $this->assertArrayNotHasKey($id, LlmCatalog::models($provider), "$provider/$id");
                $this->assertIsArray(LlmCatalog::retiredModel($provider, $id), "$provider/$id");
                $this->assertNotSame('', trim((string)($info['label'] ?? '')), "$provider/$id : libellé");
                $this->assertSame((string)$info['label'], LlmCatalog::label($provider, $id), "$provider/$id");

                $bloc = $info['retirement'] ?? null;
                $this->assertIsArray($bloc, "$provider/$id : bloc retirement");
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string)($bloc['date'] ?? ''), "$provider/$id : date AAAA-MM-JJ");
                $this->assertStringStartsWith('https://', (string)($bloc['source_url'] ?? ''), "$provider/$id : source");
                // Le remplaçant est un modèle que MIA PROPOSE : l'administrateur
                // doit pouvoir l'activer depuis la même page.
                $remplacant = (string)($bloc['replacement'] ?? '');
                $this->assertNotNull(LlmCatalog::model($provider, $remplacant), "$provider/$id : remplaçant « $remplacant » au catalogue");
                $this->assertNotNull(LlmCatalog::retirement($provider, $id), "$provider/$id");
                // Le remplaçant ne s'arrête pas avant le modèle qu'il remplace.
                $relais = LlmCatalog::model($provider, $remplacant)['retirement']['date'] ?? null;
                if ($relais !== null) {
                    $this->assertGreaterThan((string)$bloc['date'], $relais, "$provider/$id : remplaçant arrêté avant lui");
                }
            }
        }
        $this->assertGreaterThanOrEqual(24, $vus);

        // Un modèle du catalogue n'est jamais lu dans `retired_models`, et un
        // identifiant inconnu des deux reste sans arrêt.
        $this->assertNull(LlmCatalog::retiredModel('openai', 'o3'));
        $this->assertNull(LlmCatalog::retirement('openai', 'inconnu'));
        $this->assertSame('inconnu', LlmCatalog::label('openai', 'inconnu'));
        $this->assertSame('GPT-6 Luna', LlmCatalog::label('openai', 'gpt-6-luna'));
    }

    /**
     * Le défaut corrigé : un ancien modèle du catalogue resté activé (ligne
     * `llm_models`) était proposé dans le chat après sa coupure et échouait à
     * chaque message ; il n'avait aucun bloc, et la page ne le listait plus.
     */
    public function testLesAnciensModelesDuCatalogueDejaCoupesQuittentLeChat(): void
    {
        LlmCatalog::setToday('2026-11-12');
        foreach (['o4-mini', 'gpt-5.3-chat-latest', 'gpt-4o-search-preview', 'gpt-4.5-preview'] as $modele) {
            $this->assertTrue(LLMFactory::isRetired('openai::' . $modele), $modele);
        }
        // Le modèle de référence d'une clé saisi en texte libre aussi.
        $this->assertTrue(LlmCatalog::isRetired('openai', 'o4-mini'));

        LlmCatalog::setToday('2026-10-22');
        $this->assertFalse(LlmCatalog::isRetired('openai', 'o4-mini'), 'la veille de sa coupure, il répondait encore');
    }

    /**
     * Même défaut chez les autres éditeurs : DeepSeek V3 et R1, Sonar
     * Reasoning, Mistral Small 3.2 et Medium 3.1, Gemini 3.1 Flash-Lite preview
     * étaient proposés jusqu'au 24/04/2026, Gemini 2.0 Flash et deux
     * préversions 2.5 en mars 2026 ; tous coupés depuis. Dates relues le
     * 2026-11-12 sur les pages de chaque éditeur.
     */
    public function testLesModelesRetiresDesAutresEditeursQuittentLeChat(): void
    {
        $attendus = [
            'deepseek::deepseek-chat'                => ['2026-07-24', 'deepseek-flash'],
            'deepseek::deepseek-reasoner'            => ['2026-07-24', 'deepseek-flash'],
            'perplexity::sonar-reasoning'            => ['2025-12-15', 'sonar-reasoning-pro'],
            'mistral::mistral-small-2506'            => ['2026-07-31', 'mistral-small-2603'],
            'mistral::mistral-medium-2508'           => ['2026-08-31', 'mistral-medium-3-5'],
            'mistral::mistral-medium-2505'           => ['2026-08-31', 'mistral-medium-3-5'],
            'gemini::gemini-3.1-flash-lite-preview'  => ['2026-05-25', 'gemini-3.1-flash-lite'],
            'gemini::gemini-2.0-flash'               => ['2026-06-01', 'gemini-3.6-flash'],
            'gemini::gemini-2.5-pro-preview-03-25'   => ['2025-12-02', 'gemini-3.8-flash'],
            'gemini::gemini-2.5-flash-preview-04-17' => ['2025-07-15', 'gemini-3.6-flash'],
        ];
        LlmCatalog::setToday('2026-11-12');
        foreach ($attendus as $slug => [$date, $remplacant]) {
            [$provider, $modele] = explode('::', $slug, 2);
            $this->assertNull(LlmCatalog::model($provider, $modele), "$slug : plus proposé");
            $arret = LlmCatalog::retirement($provider, $modele);
            $this->assertNotNull($arret, $slug);
            $this->assertSame($date, $arret['date'], $slug);
            $this->assertSame($remplacant, $arret['replacement'], $slug);
            $this->assertTrue($arret['retired'], "$slug : déjà coupé");
            $this->assertTrue(LLMFactory::isRetired($slug), $slug);
        }

        // La veille de leur coupure, ils répondaient encore.
        LlmCatalog::setToday('2026-07-23');
        $this->assertFalse(LlmCatalog::isRetired('deepseek', 'deepseek-chat'));
        $this->assertFalse(LlmCatalog::isRetired('mistral', 'mistral-small-2506'));
        $this->assertTrue(LlmCatalog::isRetired('gemini', 'gemini-2.0-flash'));
    }

    /**
     * Garde : un modèle que MIA a proposé un jour peut rester activé sur une
     * instance (ligne `llm_models`). Le retirer de `models` sans le déclarer
     * dans `retired_models` le laisserait dans le chat après sa coupure, sans
     * date ni remplaçant, et invisible de l'administration. Liste relevée dans
     * l'historique du catalogue et de LLMFactory au 2026-11-12 ; tout modèle
     * ajouté depuis s'y ajoute.
     */
    public function testToutModeleProposeUnJourResteConnuDuCatalogue(): void
    {
        $proposes = [
            'openai' => ['gpt-4.1', 'gpt-4.1-mini', 'gpt-4.5-preview', 'gpt-4o', 'gpt-4o-mini', 'gpt-4o-search-preview', 'gpt-5-mini', 'gpt-5-mini-2025-08-07', 'gpt-5.2', 'gpt-5.3-chat-latest', 'gpt-5.3-codex', 'gpt-5.4', 'gpt-5.4-2026-03-05', 'gpt-5.5', 'gpt-5.5-pro', 'gpt-5.6', 'gpt-5.6-luna', 'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-6-astra', 'gpt-6-luna', 'gpt-6.1-sol', 'gpt-6.1-terra', 'gpt-6.2-astra', 'gpt-6.2-sol', 'o3', 'o4-mini'],
            'anthropic' => ['claude-fable-5', 'claude-fable-5-1', 'claude-fable-5-2', 'claude-haiku-4-5-20251001', 'claude-haiku-5-5', 'claude-opus-4-6', 'claude-opus-4-7', 'claude-opus-4-8', 'claude-opus-5', 'claude-opus-5-5', 'claude-sonnet-4-6', 'claude-sonnet-5', 'claude-sonnet-5-5'],
            'mistral' => ['codestral-2508', 'codestral-latest', 'mistral-large-2512', 'mistral-large-4-0', 'mistral-large-latest', 'mistral-medium-2508', 'mistral-medium-2604', 'mistral-medium-3-5', 'mistral-medium-latest', 'mistral-small-2506', 'mistral-small-2603', 'mistral-small-latest'],
            'perplexity' => ['sonar', 'sonar-pro', 'sonar-reasoning', 'sonar-reasoning-pro'],
            'gemini' => ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-flash-preview-04-17', 'gemini-2.5-pro', 'gemini-2.5-pro-preview-03-25', 'gemini-3-flash-preview', 'gemini-3.1-flash-lite', 'gemini-3.1-flash-lite-preview', 'gemini-3.1-pro-preview', 'gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.6-flash', 'gemini-3.7-flash', 'gemini-3.8-flash', 'gemini-4-argon', 'gemini-4-carbon', 'gemini-4-hydrogen', 'gemini-4-neon'],
            'deepseek' => ['deepseek-chat', 'deepseek-flash', 'deepseek-reasoner'],
            'moonshot' => ['kimi-k2.6', 'kimi-k3'],
            'zai' => ['glm-5.2', 'glm-5.3', 'glm-5.3-flash'],
        ];
        // `gpt-5.6-pro` (au catalogue du 27/06 au 07/10/2026) n'a jamais
        // existé chez OpenAI, qui sert le mode Pro par `gpt-5.6-sol` : aucune
        // date d'arrêt à déclarer, l'administration le liste hors catalogue.
        foreach ($proposes as $provider => $ids) {
            foreach ($ids as $id) {
                $connu = LlmCatalog::model($provider, $id) !== null
                    || LlmCatalog::retiredModel($provider, $id) !== null;
                $this->assertTrue($connu, "$provider/$id : retiré du catalogue sans entrée dans retired_models");
            }
        }
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
        $this->assertSame('llms-keys', $s['llm_arret_annonce']['entry']);
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

    public function testUnModeleActiveHorsCatalogueEstSignaleSousSonLibelle(): void
    {
        LlmCatalog::setToday('2026-11-12');
        $s = self::parId(AdminAttention::llmRetirementSignals(
            [
                ['provider' => 'openai', 'model' => 'gpt-5-nano', 'display_name' => ''],
                ['provider' => 'openai', 'model' => 'o4-mini', 'display_name' => 'o4-mini'],
            ],
            []
        ));
        $this->assertStringContainsString('GPT-5 Nano (le 11/12/2026, remplaçant : GPT-5.6 Luna)', $s['llm_arret_annonce']['text']);
        $this->assertStringContainsString('o4-mini (depuis le 23/10/2026, remplaçant : GPT-5.6 Terra)', $s['llm_modeles_arretes']['text']);
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
        // Sans assistant, le défaut de la PERSONNE (LlmPreference) ; avec
        // assistant, celui de l'organisation.
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count(
            $chat,
            "if (!isset(\$allowedLlms[\$llm]) && LLMFactory::isRetired(\$llm)) {\n            \$llm = \$profile ? \$this->resolveAppDefaultLlm(\$availableLlms) : \$this->resolveUserDefaultLlm(\$user, \$availableLlms);"
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

        $page = self::lire('templates/admin/llms/index.php');
        $this->assertStringContainsString('LlmCatalog::retirement($p, $m)', $page);
        $this->assertStringContainsString("Arrêté par l'éditeur", $page);
        $this->assertStringContainsString('Remplaçant désigné', $page);
        $this->assertStringContainsString('!empty($error)', self::lire('templates/admin/llms/_head.php'));
        // Un modèle activé hors du catalogue reste listé (sinon impossible de
        // l'éteindre), et un modèle de référence hors catalogue reste
        // sélectionné (sinon l'auto-save le remplacerait en silence).
        $this->assertMatchesRegularExpression('/foreach \(\$activeLlms\[\$p\] \?\? \[\] as \$modelId => \$activeRow\).*?isset\(\$models\[\$modelId\]\).*?t\(\'Hors du catalogue de MIA\.\'\)/s', $page);
        $this->assertMatchesRegularExpression('/\$savedModel !== \'\' && !isset\(\$models\[\$savedModel\]\).*?selected.*?te\(\' \(hors catalogue\)\'\)/s', $page);
        $this->assertStringContainsString('LlmCatalog::label(', self::lire('src/Helpers/AdminAttention.php'));

        $this->assertStringContainsString('self::llmRetirementSignals($actifs, $profils)', self::lire('src/Helpers/AdminAttention.php'));
    }

    /**
     * Un test qui active un modèle à date d'arrêt (o3 : seul modèle d'OpenAI
     * routé Chat Completions, donc le préféré des tests à faux serveur) DOIT
     * figer le jour : sinon il passe aujourd'hui et casse, sans rien changer,
     * le jour où le modèle quitte LLMFactory::available().
     */
    public function testAucunTestNActiveUnModeleArreteSansFigerLeJour(): void
    {
        $fautifs = [];
        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/tests', FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src = (string)file_get_contents($f->getPathname());
            $actives = [];
            if (preg_match_all("/LlmModel::save\\(\\s*\\[[^\\]]*'provider'\\s*=>\\s*'([a-z]+)'[^\\]]*'model'\\s*=>\\s*'([^']+)'/", $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $actives[] = [$x[1], $x[2]];
                }
            }
            if (preg_match_all("/->activer\\(\\s*'([a-z]+)'\\s*,\\s*'([^']+)'/", $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $actives[] = [$x[1], $x[2]];
                }
            }
            foreach ($actives as [$provider, $modele]) {
                if (LlmCatalog::retirement($provider, $modele) !== null && !str_contains($src, 'LlmCatalog::setToday(')) {
                    $fautifs[] = substr($f->getPathname(), strlen(ROOT) + 1) . " ($provider::$modele)";
                }
            }
        }
        $this->assertSame([], array_values(array_unique($fautifs)), 'Ces tests activent un modèle à date d\'arrêt sans figer le jour (LlmCatalog::setToday)');
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLesGuidesEtLesNouveautesLeDisent(): void
    {
        $this->assertStringContainsString('arrêt', mb_strtolower(self::lire('docs/user/admin/modeles/activer-les-modeles.md')));
        $this->assertStringContainsString('Arrêt le', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('<!-- id: modeles-arretes-editeur |', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('<!-- id: openai-arret-decembre-2026 |', self::lire('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('Hors du catalogue de MIA', self::lire('docs/user/admin/modeles/activer-les-modeles.md'));
        $this->assertStringContainsString('GPT-5, GPT-5 mini, GPT-5 nano, GPT-5 pro, o3 et o3-pro', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('LlmCatalog::retirement', self::lire('CLAUDE.md'));
        $this->assertStringContainsString('retired_models', self::lire('CLAUDE.md'));
    }
}
