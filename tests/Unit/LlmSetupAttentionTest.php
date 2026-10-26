<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Signal de configuration initiale : aucune clé API LLM active.
 *
 * À l'installation, aucun LLM n'est renseigné et MIA ne peut répondre à
 * personne — sans signal, la seule trace en serait des conversations en
 * erreur. La navigation d'administration fait donc clignoter
 * « Modèles IA / Clés API » (pastille ambre pulsante), garde le sous-menu
 * déplié, et le tableau de bord comme la page Clés API affichent un bandeau
 * de démarrage. Tout s'éteint à la première clé enregistrée.
 *
 * Source unique du constat : ApiKey::hasActiveLlm(). Ce test verrouille le
 * câblage de bout en bout (modèle → nav → CSS → JS → gabarits → doc).
 */
final class LlmSetupAttentionTest extends TestCase
{
    private static function lire(string $relatif): string
    {
        return file_get_contents(ROOT . '/' . $relatif) ?: '';
    }

    public function testLaSourceUniqueExisteSurLeModele(): void
    {
        $this->assertTrue(
            method_exists(ApiKey::class, 'hasActiveLlm'),
            'ApiKey::hasActiveLlm() est la source unique du signal de configuration initiale'
        );

        // Une ligne ACTIVE, cloud ou locale : un endpoint local sans clé
        // compte (c'est base_url qui le rend utilisable), une clé désactivée
        // ne compte pas.
        $modele = self::lire('src/Models/ApiKey.php');
        $this->assertStringContainsString('is_active = 1', $modele);
    }

    public function testLaNavPoseLaPastilleEtForceLeDepliage(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');

        $this->assertStringContainsString('ApiKey::hasActiveLlm()', $nav,
            'La nav lit la source unique, jamais une requête recopiée');
        $this->assertStringContainsString('nav-attention-dot', $nav);
        $this->assertStringContainsString("\$llmSetupNeeded ? ' nav-attention'", $nav,
            'Le groupe porte la classe nav-attention (dépliage prioritaire côté nav-drawer.js)');
        // Rubrique dépliée tant que la configuration manque : « Clés API » doit
        // être visible ($force du rendu de rubrique).
        $this->assertStringContainsString("[\$llmSetupAttr . \$llmGAttr, \$llmGDot, \$llmSetupHint . \$llmGHint], \$llmSetupDot, \$llmSetupNeeded, \$llmSetupNeeded ? ' nav-attention' : ''", $nav);
        $this->assertStringContainsString('$open   = $active || $force;', $nav);

        // Deux pastilles : la rubrique « Modèles IA » ET le sous-lien « Clés API »
        // (la rubrique peut être repliée à la main, la cible du signal reste
        // marquée) — une seule pastille déclarée, posée aux deux endroits.
        $this->assertSame(1, substr_count($nav, 'nav-attention-dot'), 'Une seule pastille déclarée…');
        $this->assertStringContainsString("\$navSub('llms-keys', [\$llmKeyAttr, \$llmSetupDot, \$llmKeyHint])", $nav, '… sur « Clés API »…');
        $this->assertStringContainsString('$llmSetupDot, $llmSetupNeeded,', $nav, '… et sur la rubrique.');
    }

    public function testLeCssPulseEtRespecteLeMouvementReduit(): void
    {
        $css = self::lire('public/assets/css/main.css');

        $this->assertStringContainsString('.nav-attention-dot', $css);
        $this->assertStringContainsString('@keyframes nav-attention-pulse', $css);
        $this->assertStringContainsString('.llm-setup-banner', $css);

        // Le clignotement est un signal, pas une exigence : en mouvement
        // réduit la pastille reste visible mais immobile.
        $reduit = strpos($css, '.nav-attention-dot { animation: none; }');
        $this->assertIsInt($reduit, 'prefers-reduced-motion doit couper l\'animation');
        $avant = substr($css, 0, (int)$reduit);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', substr($avant, (int)strrpos($avant, '@media')));
    }

    public function testLAlertePrimeSurLePliMemorise(): void
    {
        // nav-drawer.js restaure le pli localStorage : sans cette garde, un
        // sous-menu replié avant l'installation resterait fermé et cacherait
        // « Clés API », la cible même du signal.
        $js = self::lire('public/assets/js/nav-drawer.js');
        $this->assertStringContainsString("group.classList.contains('nav-attention')", $js);

        $posGarde   = strpos($js, "group.classList.contains('nav-attention')");
        $posRestore = strpos($js, 'localStorage.getItem(GROUP_PREFIX');
        $this->assertLessThan($posRestore, $posGarde,
            'La garde nav-attention doit précéder la restauration du pli mémorisé');
    }

    public function testLesDeuxGabaritsGuidentLaConfiguration(): void
    {
        // Tableau de bord : bandeau de bienvenue + CTA vers Clés API et vers
        // la liste de démarrage (/setup/suivant, déjà existante).
        $dashboard = self::lire('templates/admin/index.php');
        $this->assertStringContainsString('llm-setup-banner', $dashboard);
        $this->assertStringContainsString('!empty($llmSetupNeeded)', $dashboard,
            'La variable vient d\'admin-nav.php : !empty() obligatoire (jamais posée hors variant admin)');
        $this->assertStringContainsString('href="/admin/llms"', $dashboard);
        $this->assertStringContainsString('href="/setup/suivant"', $dashboard);

        // Page Clés API : bandeau sur place + extinction SANS rechargement.
        $keys = self::lire('templates/admin/llms/keys.php');
        $this->assertStringContainsString('llmSetupCallout', $keys);
        $this->assertStringContainsString('clearLlmSetupAttention', $keys);
        $this->assertStringContainsString("getElementById('localLlmForm')?.addEventListener('mia:autosaved', clearLlmSetupAttention)", $keys,
            'Le formulaire local ne recharge jamais la page : sans ce relais, la pastille survivrait à la première configuration locale');
    }

    public function testLeGuideAdminDocumenteLeSignal(): void
    {
        $guide = self::lire('docs/GUIDE-ADMIN.md');
        $this->assertStringContainsString('Signal de configuration initiale', $guide);
        $this->assertStringContainsString('pastille ambre', $guide);
    }

    /**
     * Ordre du menu : « Modèles IA » est la première rubrique après le tableau
     * de bord — c'est la première chose à régler sur une instance neuve, là
     * où la pastille clignote. Une rubrique n'est rendue que si l'une de ses
     * pages s'ouvre : un créateur ne voit pas apparaître les clés API.
     */
    public function testModelesIaOuvreLesRubriques(): void
    {
        $nav = self::lire('templates/partials/admin-nav.php');
        $models = strpos($nav, "\$navGroup('models',");
        $this->assertNotFalse($models);
        foreach (['capabilities', 'costs', 'organisation', 'maintenance'] as $suivante) {
            $this->assertLessThan(strpos($nav, "\$navGroup('$suivante',"), $models, "« Modèles IA » précède « $suivante ».");
        }
        $this->assertSame('models', array_keys(AdminMenu::ENTRIES)[2]);
        $this->assertStringContainsString("if (\$sub === '') {\n                    return '';", $nav, 'Rubrique sans page ouverte : rien.');
        $this->assertSame('llms.view', AdminAccess::PAGES['llms-keys'][0]);
        $this->assertNotContains('llms.view', Permissions::DEFAULTS['creator']);
    }
}
