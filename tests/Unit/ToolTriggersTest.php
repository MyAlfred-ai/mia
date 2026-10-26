<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Expressions déclencheuses des OUTILS — partie pure (aucune base) et
 * câblage. Ce qui casse en silence : une expression par défaut qui ne
 * survit pas à sa propre normalisation (badge « Personnalisées » mensonger),
 * un mot générique seul (« image » ferait déclencher « analyse cette
 * image »), et un maillon manquant entre le PHP, le gabarit du chat et
 * tool-suggest.js — la suggestion disparaîtrait sans la moindre erreur.
 */
final class ToolTriggersTest extends TestCase
{
    // ---------- Règles et types ----------

    public function testChaqueTypeSuggestibleEstCouvertParUneRegle(): void
    {
        $this->assertSame('image', ToolTriggers::ruleForType('image'));
        $this->assertSame('video', ToolTriggers::ruleForType('video'));
        $this->assertSame('map', ToolTriggers::ruleForType('map'));
        // Plus de suggestion « présentation » : elle se demande dans la
        // conversation (carte PowerPoint), l'outil a quitté le menu « + ».
        $this->assertNull(ToolTriggers::ruleForType('presentation'));
        // La règle html couvre les DEUX types de pages.
        $this->assertSame('html', ToolTriggers::ruleForType('html'));
        $this->assertSame('html', ToolTriggers::ruleForType('html_illustrated'));
    }

    public function testN8nEtGammaNeSontJamaisSuggeres(): void
    {
        // Capacités d'assistant : le déclenchement vient du LLM, avec
        // approbation — pas d'une bannière pendant la frappe.
        $this->assertNull(ToolTriggers::ruleForType('n8n'));
        $this->assertNull(ToolTriggers::ruleForType('gamma'));
        $this->assertSame([], ToolTriggers::defaults('n8n'));
        $this->assertSame([], ToolTriggers::defaults('gamma'));
    }

    public function testLOrdreDesReglesEstLaPriorite(): void
    {
        // vidéo avant image (« génère une vidéo d'un chat »), carte avant image
        // (« dessine-moi un itinéraire »).
        $this->assertSame(['video', 'html', 'map', 'image'], ToolTriggers::ruleIds());
    }

    // ---------- Défauts ----------

    public function testChaqueRegleALesDefautsNonVides(): void
    {
        foreach (ToolTriggers::ruleIds() as $rule) {
            $this->assertNotEmpty(ToolTriggers::defaults($rule), "Aucune expression par défaut pour « $rule ».");
        }
    }

    /**
     * `setTriggers` compare la saisie aux défauts pour décider « retour aux
     * défauts = fin de la personnalisation » : les défauts doivent être leur
     * propre forme normalisée (même règle que les connecteurs).
     */
    public function testLesDefautsSontDejaSousLeurFormeNormalisee(): void
    {
        foreach (ToolTriggers::ruleIds() as $rule) {
            $defauts = ToolTriggers::defaults($rule);
            $this->assertSame(
                $defauts,
                ConnectorRegistry::sanitizeTriggerList(implode("\n", $defauts)),
                "Les expressions par défaut de « $rule » ne survivent pas à leur propre normalisation."
            );
        }
    }

    /**
     * Curation : un nom générique seul sur-déclencherait (« analyse cette
     * image », « la carte des vins », « le site de la mairie »). Il faut un
     * verbe de création, un déterminant marqué ou un objet spécifique.
     */
    public function testAucuneExpressionParDefautNestUnNomGeneriqueSeul(): void
    {
        $interdits = ['image', 'images', 'photo', 'video', 'vidéo', 'presentation', 'présentation',
                      'slides', 'page', 'site', 'carte', 'trajet', 'parcours', 'itinéraire', 'dessin'];
        foreach (ToolTriggers::ruleIds() as $rule) {
            foreach (ToolTriggers::defaults($rule) as $phrase) {
                $this->assertNotContains(
                    $phrase,
                    $interdits,
                    "« $phrase » (règle $rule) est un nom générique seul : il sur-déclencherait."
                );
            }
        }
    }

    // ---------- Câblage : la détection vient du serveur ----------

    /**
     * Plus aucun motif d'outil en dur dans le JavaScript : les expressions
     * sont compilées depuis window.TOOL_TRIGGERS, sinon les cartes de
     * /admin/tools mentiraient.
     */
    public function testLaDetectionOutilVientDuServeur(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/tool-suggest.js');
        $this->assertStringContainsString('window.TOOL_TRIGGERS', $js);
        $this->assertStringContainsString('function toolTriggerToRegex(', $js);
        $this->assertStringContainsString('function toolRule(', $js);
        // Fragment littéral des anciennes règles en dur : sa réapparition
        // signalerait un motif invisible depuis l'administration.
        $this->assertStringNotContainsString('genere|generes|generer', $js);

        $chat = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringContainsString(
            'window.TOOL_TRIGGERS = <?= json_encode(ToolTriggers::all()',
            $chat,
            'Sans injection, aucun outil ne serait jamais suggéré.'
        );
    }

    public function testLesCartesDAdministrationPortentLEditeur(): void
    {
        $tools = (string)file_get_contents(ROOT . '/templates/admin/tools.php');
        $this->assertStringContainsString('tool-triggers-input', $tools);
        $this->assertStringContainsString('data-trigger-rule', $tools);
        $this->assertStringContainsString("fetch('/admin/tools/triggers'", $tools);

        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString('public function saveToolTriggers', $admin);

        // La route DOIT précéder /admin/tools/{id} : « triggers » serait
        // sinon avalé par {id} et arriverait dans updateTool.
        $routes   = (string)file_get_contents(ROOT . '/public/index.php');
        $triggers = strpos($routes, "post('/admin/tools/triggers'");
        $byId     = strpos($routes, "post('/admin/tools/{id}'");
        $this->assertNotFalse($triggers, 'Route /admin/tools/triggers absente.');
        $this->assertNotFalse($byId);
        $this->assertLessThan($byId, $triggers, 'La route triggers doit être déclarée AVANT /admin/tools/{id}.');
    }
}
