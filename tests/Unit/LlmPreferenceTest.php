<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Modèle préféré de chaque personne (migration 107, LlmPreference) : celui qui
 * ouvre ses nouvelles conversations sans assistant.
 *
 * Ce que ces tests protègent :
 *  - les formes pures : un choix n'est retenu que s'il est encore proposé, ''
 *    revient à celui de l'organisation, un choix renvoyé tel quel n'est jamais
 *    refusé (le formulaire de Préférences IA est posté en entier) ;
 *  - le PÉRIMÈTRE : seules les conversations SANS assistant suivent le choix —
 *    un assistant garde son modèle ou le défaut de l'organisation ;
 *  - le câblage des deux surfaces (étoile du sélecteur, Préférences IA) sur une
 *    seule colonne et une seule route d'écriture.
 */
final class LlmPreferenceTest extends TestCase
{
    private const PROPOSES = [
        'anthropic::claude-opus-5' => 'Claude Opus 5',
        'openai::gpt-5.6'          => 'GPT-5.6',
        'mistral::mistral-large-latest' => 'Mistral Large 3',
    ];

    private static function lire(string $chemin): string
    {
        $abs = ROOT . '/' . ltrim($chemin, '/');
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    // ------------------------------------------------------------------
    // 1. Formes pures
    // ------------------------------------------------------------------

    public function testLeChoixEstRetenuSeulementSIlEstEncorePropose(): void
    {
        $this->assertSame('openai::gpt-5.6', LlmPreference::resolve('openai::gpt-5.6', self::PROPOSES));
        $this->assertNull(LlmPreference::resolve('', self::PROPOSES), 'aucun choix');
        $this->assertNull(LlmPreference::resolve('google::gemini-3-pro', self::PROPOSES), 'retiré des groupes ou arrêté');
    }

    public function testLeChoixEnregistreEstLuProprement(): void
    {
        $this->assertSame('openai::gpt-5.6', LlmPreference::of(['preferred_llm' => '  openai::gpt-5.6 ']));
        $this->assertSame('', LlmPreference::of(['preferred_llm' => null]));
        $this->assertSame('', LlmPreference::of(null), 'compte absent');
        $this->assertSame('', LlmPreference::of([]), 'colonne absente (avant la migration)');
        $this->assertSame('', LlmPreference::clean("openai::gpt\n5"), 'retour à la ligne');
        $this->assertSame('', LlmPreference::clean('a b'), 'espace');
        $this->assertSame('', LlmPreference::clean(str_repeat('x', LlmPreference::MAX_LEN + 1)));
        $this->assertSame('', LlmPreference::clean(['openai::gpt-5.6']), 'tableau');
    }

    public function testUnChoixRecuEstValideContreLaListeProposee(): void
    {
        $this->assertSame('openai::gpt-5.6', LlmPreference::normalize('openai::gpt-5.6', self::PROPOSES));
        $this->assertSame('', LlmPreference::normalize('', self::PROPOSES), "'' = celui de l'organisation");
        $this->assertSame('', LlmPreference::normalize('  ', self::PROPOSES));
        $this->assertNull(LlmPreference::normalize('google::gemini-3-pro', self::PROPOSES), 'hors périmètre');
        $this->assertNull(LlmPreference::normalize(['openai::gpt-5.6'], self::PROPOSES), 'tableau forgé');
        $this->assertNull(LlmPreference::normalize(null, self::PROPOSES), 'champ absent de la route');
    }

    public function testLeChoixDejaEnregistreRenvoyeTelQuelEstAccepteMemeRetire(): void
    {
        // Le formulaire de Préférences IA est posté en entier : changer de
        // style renvoie le choix, même s'il n'est plus proposé. Le refuser
        // ferait échouer chaque geste de la page ; l'effacer perdrait le choix.
        $this->assertSame('google::gemini-3-pro', LlmPreference::normalize('google::gemini-3-pro', self::PROPOSES, 'google::gemini-3-pro'));
        $this->assertNull(LlmPreference::normalize('google::gemini-3-pro', self::PROPOSES, 'openai::gpt-5.6'), 'un AUTRE modèle retiré reste refusé');
    }

    public function testLEtatDitCeQuiOuvreLesNouvellesConversations(): void
    {
        $org = 'anthropic::claude-opus-5';

        $aucun = LlmPreference::state(['preferred_llm' => ''], self::PROPOSES, $org);
        $this->assertSame('', $aucun['mine']);
        $this->assertSame($org, $aucun['effective']);
        $this->assertFalse($aucun['unavailable']);
        $this->assertTrue($aucun['choosable']);

        $choisi = LlmPreference::state(['preferred_llm' => 'mistral::mistral-large-latest'], self::PROPOSES, $org);
        $this->assertSame('mistral::mistral-large-latest', $choisi['mine']);
        $this->assertSame('mistral::mistral-large-latest', $choisi['effective']);
        $this->assertSame($org, $choisi['organization']);

        $retire = LlmPreference::state(['preferred_llm' => 'google::gemini-3-pro'], self::PROPOSES, $org);
        $this->assertSame('google::gemini-3-pro', $retire['preferred'], 'le choix est gardé…');
        $this->assertSame('', $retire['mine']);
        $this->assertSame($org, $retire['effective'], "… mais l'organisation reprend en attendant");
        $this->assertTrue($retire['unavailable']);

        $seul = LlmPreference::state(null, ['openai::gpt-5.6' => 'GPT-5.6'], 'openai::gpt-5.6');
        $this->assertFalse($seul['choosable'], 'un seul modèle proposé : rien à choisir');
    }

    public function testLaChargeDuNavigateurPorteSesTextesTraduits(): void
    {
        $state  = LlmPreference::state(['preferred_llm' => 'openai::gpt-5.6'], self::PROPOSES, 'anthropic::claude-opus-5');
        $client = LlmPreference::clientConfig($state);
        $this->assertSame('openai::gpt-5.6', $client['mine']);
        $this->assertSame('openai::gpt-5.6', $client['effective']);
        foreach (['set', 'mine', 'org', 'saved', 'reset', 'error'] as $cle) {
            $this->assertNotSame('', $client['texts'][$cle] ?? '', "texte « $cle »");
        }
        foreach (['set', 'mine', 'org', 'saved', 'reset'] as $cle) {
            $this->assertStringContainsString('{model}', $client['texts'][$cle], "« $cle » nomme le modèle");
        }

        I18n::use('en');
        try {
            $en = LlmPreference::clientConfig($state);
            $this->assertSame('Make {model} your default model', $en['texts']['set']);
            $this->assertStringContainsString('organization', $en['texts']['mine']);
        } finally {
            I18n::use('fr');
        }
    }

    // ------------------------------------------------------------------
    // 2. Périmètre : seules les conversations sans assistant
    // ------------------------------------------------------------------

    public function testLesConversationsSansAssistantSuiventLeChoixDeLaPersonne(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');

        $this->assertMatchesRegularExpression(
            '/private function resolveUserDefaultLlm\(\?array \$user, array \$availableLlms\): string\s*\{\s*return LlmPreference::defaultFor\(\$user, \$availableLlms\);/',
            $chat
        );
        // Création : sans modèle demandé, le sien.
        $this->assertMatchesRegularExpression(
            '/function create\(\).*?\$llm\s*= \$requestedLlm !== \'\' \? \$requestedLlm : \$this->resolveUserDefaultLlm\(\$user, \$availableLlms\);/s',
            $chat
        );
        // Envoi ET régénération : conversation sans modèle et sans assistant.
        $this->assertSame(2, substr_count(
            $chat,
            "\$llm = \$requestedLlm !== '' ? \$requestedLlm : (\$convLlm !== '' ? \$convLlm : \$this->resolveUserDefaultLlm(\$user, \$availableLlms));"
        ));
        // Le périmètre du sélecteur ET de la préférence : une seule règle.
        $this->assertMatchesRegularExpression(
            '/function filterLlmsForUserScope\(array \$llms, array \$groupScope\): array\s*\{\s*return LlmPreference::withinGroups\(/',
            $chat
        );
    }

    public function testUnAssistantNeSuitJamaisLeChoixDeLaPersonne(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        if (!preg_match('/private function resolveProfileDefaultLlm\(.*?\n    \}/s', $chat, $m)) {
            $this->fail('resolveProfileDefaultLlm introuvable');
        }
        $this->assertStringNotContainsString('resolveUserDefaultLlm', $m[0]);
        $this->assertStringNotContainsString('LlmPreference', $m[0]);

        // Branche « assistant » de l'envoi et de la régénération : le défaut
        // de l'organisation, jamais celui de la personne.
        $this->assertSame(2, substr_count(
            $chat,
            ": (\$convLlm !== '' ? \$convLlm : \$this->resolveAppDefaultLlm(\$availableLlms));\n            \$allowedLlms = \$availableLlms + LLMFactory::available();"
        ));
        // Les tâches programmées ont leur propre choix.
        $this->assertMatchesRegularExpression('/function scheduledTurnLlm\(.*?LLMFactory::appDefault\(\$available\)/s', $chat);
    }

    // ------------------------------------------------------------------
    // 3. Câblage des deux surfaces
    // ------------------------------------------------------------------

    public function testLEtoileDuSelecteur(): void
    {
        $tpl = self::lire('templates/chat/index.php');
        $this->assertStringContainsString("\$defaultChatLlm = \$llmPreference['effective'];", $tpl, "l'accueil s'ouvre sur le modèle de la personne");
        $this->assertStringContainsString('data-llm-fav', $tpl);
        $this->assertMatchesRegularExpression('/\$llmStarsOn = !empty\(\$llmPreference\[\'choosable\'\]\);/', $tpl, 'pas d\'étoile avec un seul modèle');
        $this->assertStringContainsString('aria-pressed="<?= $isMineLlm', $tpl);
        $this->assertStringContainsString('window.LLM_PREFERENCE  = <?= json_encode(LlmPreference::clientConfig($llmPreference)', $tpl);
        $this->assertStringContainsString('class="llm-picker-foot"', $tpl);

        $js = self::lire('public/assets/js/chat.js');
        $this->assertMatchesRegularExpression(
            '/const onStar = async \(e\) => \{\s*(?:\/\/[^\n]*\n\s*)*e\.preventDefault\(\);\s*e\.stopPropagation\(\);/',
            $js,
            "l'étoile ne choisit pas le modèle de la ligne et ne ferme pas la liste"
        );
        $this->assertStringContainsString("fetchWithSessionGuard('/settings/llm-default'", $js);
        $this->assertStringContainsString("const wanted = value === pref.mine ? '' : value;", $js, 'retoucher son étoile revient à celle de l\'organisation');
        $this->assertStringContainsString("list.querySelectorAll('[data-llm-fav]').forEach(star => star.addEventListener('click', onStar));", $js);
        // Aucun libellé dans le script : les textes viennent du serveur.
        $this->assertStringContainsString("pref?.texts?.[key]", $js);

        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.llm-picker-item.is-mine .llm-fav-btn', $css);
        $this->assertMatchesRegularExpression('/@media \(hover: none\) \{\s*(?:\/\*.*?\*\/\s*)?\.llm-fav-btn \{ opacity: \.6; width: 40px; height: 40px;/s', $css, 'au doigt : toujours visible, cible de 40 px');
    }

    public function testUneSeuleRouteEtUneSeuleColonne(): void
    {
        $routes = self::lire('public/index.php');
        $this->assertStringContainsString("\$router->post('/settings/llm-default',    [UserSettingsController::class, 'updatePreferredLlm']);", $routes);

        $ctrl = self::lire('src/Controllers/UserSettingsController.php');
        $this->assertMatchesRegularExpression(
            '/public function updatePreferredLlm\(\): void.*?LlmPreference::normalize\(\$_POST\[\'llm\'\] \?\? null, \$available\).*?LlmPreference::save\(/s',
            $ctrl
        );
        // Préférences IA : écrit SEULEMENT si posté, choix gardé accepté.
        $this->assertMatchesRegularExpression(
            '/if \(array_key_exists\(\'preferred_llm\', \$_POST\)\) \{\s*(?:\/\/[^\n]*\n\s*)*\$llm = LlmPreference::normalize\(\$_POST\[\'preferred_llm\'\], LlmPreference::choicesFor\(\$user\), LlmPreference::of\(\$user\)\);/',
            $ctrl
        );

        $helper = self::lire('src/Helpers/LlmPreference.php');
        $this->assertSame(1, substr_count($helper, 'UPDATE users SET preferred_llm'), 'un seul écrivain');

        $migration = self::lire('database/migrations/107_user_preferred_llm.sql');
        $this->assertStringContainsString("ADD COLUMN preferred_llm VARCHAR(100) NOT NULL DEFAULT ''", $migration);

        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $this->assertStringContainsString('/src/Helpers/LlmPreference.php', self::lire($liste), $liste);
        }
    }

    public function testLaPagePreferencesIa(): void
    {
        $tpl = self::lire('templates/settings/ai.php');
        $this->assertStringContainsString('id="modele"', $tpl);
        $this->assertStringContainsString('name="preferred_llm" form="aiPrefsForm"', $tpl, 'rattaché au formulaire auto-enregistré');
        // Un choix plus proposé reste SÉLECTIONNÉ (sinon l'enregistrement
        // automatique le remplacerait au premier geste sur la page).
        $this->assertMatchesRegularExpression('/if \(!empty\(\$llmPreference\[\'unavailable\'\]\)\):.*?<option value="<\?= htmlspecialchars\(\$prefSelected\) \?>"[^\n]*selected>/s', $tpl);
        $this->assertStringContainsString('href="#modele"', $tpl, 'sommaire de la page');
    }

    public function testLaDocumentationEnParle(): void
    {
        $this->assertStringContainsString('modèle par défaut', mb_strtolower(self::lire('docs/GUIDE-UTILISATEUR.md')));
        $this->assertStringContainsString('choisir-son-modele-par-defaut', self::lire('docs/user/fonctionnalites/modeles/index.md'));
        $this->assertFileExists(ROOT . '/docs/user/fonctionnalites/modeles/choisir-son-modele-par-defaut.md');
        $this->assertStringContainsString('<!-- id: modele-par-defaut-personnel |', self::lire('docs/NOUVEAUTES.md'));
    }
}
