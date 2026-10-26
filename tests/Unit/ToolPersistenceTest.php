<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Persistance de l'outil actif (cf. CLAUDE.md « Outil actif — persistance par
 * conversation »). Un outil engagé — clic du menu « + » OU suggestion
 * acceptée — le reste pour toute la conversation, jusqu'à extinction
 * explicite. Tout vit côté client : ces tests gardent le CÂBLAGE des deux
 * fichiers concernés (chat.js, tool-suggest.js), pas leur exécution.
 */
final class ToolPersistenceTest extends TestCase
{
    private static function chatJs(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
    }

    private static function suggestJs(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/js/tool-suggest.js');
    }

    // ---- Source unique de l'état « outil actif » ----

    public function testUneSeuleFonctionPoseLEtatDeLOutilActif(): void
    {
        $js = self::chatJs();
        $this->assertStringContainsString('function applySelectedTool(tool, persist = true)', $js);

        // `selectedTool` n'est affecté QUE par applySelectedTool : une seconde
        // écriture ailleurs désynchroniserait pastille, surlignage et mémoire.
        preg_match_all('/^\s*selectedTool\s*=/m', $js, $m);
        $this->assertCount(1, $m[0], 'selectedTool doit être posé par applySelectedTool seul');
    }

    public function testLaPastilleEtLeMenuSuiventLEtat(): void
    {
        $js = self::chatJs();
        $this->assertStringContainsString('setToolChip(tool);', $js);
        $this->assertStringContainsString("item?.setAttribute('aria-checked', 'true');", $js);
    }

    // ---- Mémoire : par utilisateur, par conversation, bornée ----

    public function testLaMemoireEstCleeParUtilisateurEtParConversation(): void
    {
        $js = self::chatJs();
        // Clé par utilisateur : navigateur partagé = pas de fuite entre comptes.
        $this->assertStringContainsString("const TOOL_PREFS_KEY = 'toolPrefs:' + (window.CURRENT_USER_ID ?? 'anon');", $js);
        // Une entrée par conversation.
        $this->assertStringContainsString('const convKey = () =>', $js);
        $this->assertStringContainsString('prefs[key] = { id: tool.id, type: tool.type, ts: Date.now() };', $js);
        // Bornée : sans purge, le stockage grossirait à chaque conversation.
        $this->assertStringContainsString('const MAX_TOOL_PREFS =', $js);
        $this->assertStringContainsString('while (keys.length > MAX_TOOL_PREFS) delete prefs[keys.shift()];', $js);
    }

    public function testLEcritureLocalStorageEstBestEffort(): void
    {
        // Navigation privée / quota plein : pas de persistance, jamais d'erreur
        // qui casserait le composer.
        $js = self::chatJs();
        $this->assertMatchesRegularExpression(
            '/localStorage\.setItem\(TOOL_PREFS_KEY[^\n]*\n\s*catch/',
            $js,
            "L'écriture des préférences d'outil doit être protégée par un catch"
        );
        $this->assertMatchesRegularExpression(
            '/localStorage\.getItem\(TOOL_PREFS_KEY[^\n]*\n\s*catch/',
            $js,
            'La lecture des préférences doit retomber sur un objet vide'
        );
    }

    public function testLeChoixDuBrouillonSuitLaConversationCreee(): void
    {
        $js = self::chatJs();
        $this->assertStringContainsString('window.carryToolChoiceToConversation = function ()', $js);
        // Appelé par createConversation, sinon le choix fait avant le premier
        // message resterait attaché au brouillon.
        $this->assertStringContainsString('window.carryToolChoiceToConversation?.();', $js);
    }

    // ---- Restauration : rejoue une intention, n'en exprime aucune ----

    public function testLaRestaurationNEcritPasEnMemoire(): void
    {
        $js = self::chatJs();
        $this->assertStringContainsString('window.restoreToolForConversation = function ()', $js);
        $this->assertStringContainsString('applySelectedTool(toolFromItem(item), false);', $js);
        $this->assertStringContainsString('if (selectedTool) applySelectedTool(null, false);', $js);
    }

    public function testLaRestaurationExigeLOutilExactEtDisponible(): void
    {
        // Outil retiré par un admin ou dernier test en échec : on n'arme rien
        // plutôt que d'envoyer le message suivant vers un outil mort — et
        // jamais un remplaçant du même type (bascule silencieuse).
        $js = self::chatJs();
        $this->assertMatchesRegularExpression(
            '/data-id="\$\{pref\.id\}"\]:not\(\[data-disabled="1"\]\)/',
            $js
        );
        $this->assertStringContainsString('if (!item || item.dataset.type !== pref.type) return false;', $js);
    }

    public function testLaConversationAfficheeEstResynchronisee(): void
    {
        $js = self::chatJs();
        // Chargement de la page (fin de l'IIFE du menu « + ») + changement de
        // conversation : sans ces deux appels la pastille mentirait.
        $this->assertSame(
            2,
            preg_match_all('/^\s*syncToolForConversation\(\);$/m', $js),
            'syncToolForConversation doit être appelée au chargement ET au changement de conversation'
        );
    }

    // ---- Une proposition automatique n'écrase jamais un choix ----

    public function testArmToolFromPickerCedeAUnOutilDejaEngage(): void
    {
        $js = self::chatJs();
        $this->assertMatchesRegularExpression(
            '/window\.armToolFromPicker = function \(types, preferId = null\) \{\s*\n\s*if \(selectedTool\) return false;/',
            $js
        );
    }

    public function testLaFinDUnJobHtmlNeReArmeRien(): void
    {
        // L'outil est déjà engagé (mémoire de conversation) : le ré-armer
        // reviendrait à annuler une extinction faite pendant la génération.
        $js = self::chatJs();
        $this->assertStringNotContainsString("window.armToolFromPicker?.(['html', 'html_illustrated'], state.data.toolId", $js);
    }

    // ---- La suggestion arme le picker, elle ne détient aucun état ----

    public function testApprouverUneSuggestionEngageLOutilDuPicker(): void
    {
        $suggest = self::suggestJs();
        $this->assertStringContainsString(
            'window.armToolFromPicker?.([current.tool.type], current.tool.id);',
            $suggest
        );
    }

    public function testPlusAucunArmementPourUnSeulMessage(): void
    {
        $suggest = self::suggestJs();
        // L'état « armed » (outil retenu pour CE message) et l'API armedTool()
        // ont disparu : deux sources de vérité en parallèle du picker
        // divergeaient au premier envoi.
        $this->assertStringNotContainsString('armedTool', $suggest);
        $this->assertStringNotContainsString("'armed'", $suggest);
        $this->assertStringNotContainsString('armedTool', self::chatJs());
    }

    public function testLEnvoiNeLitQueLOutilEngage(): void
    {
        $js = self::chatJs();
        $this->assertStringContainsString('const activeTool = selectedTool;', $js);
    }

    // ---- Documentation (règle du dépôt : doc à jour dans le même commit) ----

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLeComportementEstDocumente(): void
    {
        $claude = (string)file_get_contents(ROOT . '/CLAUDE.md');
        $this->assertStringContainsString('## Outil actif — persistance par conversation', $claude);
        $this->assertStringContainsString('toolPrefs:{userId}', $claude);

        $guide = (string)file_get_contents(ROOT . '/docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString("### L'outil reste actif tant que vous ne l'éteignez pas", $guide);
    }
}
