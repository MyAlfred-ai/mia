<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Identité de l'assistant dans le composer (cf. CLAUDE.md « Identité de
 * l'assistant dans le composer »). Le bandeau de tête et le dégradé de fond ont
 * disparu : l'assistant se présente sur la première rangée de la pilule de
 * saisie, sa couleur ne vit que sur cette pilule, et les caractéristiques
 * secondaires sont repliées par défaut. Ces tests gardent le CÂBLAGE des
 * quatre fichiers concernés (gabarit, CSS, chat.js, contrôleur) et des guides.
 */
final class AssistantIdentityTest extends TestCase
{
    private static function tpl(): string
    {
        return (string)file_get_contents(ROOT . '/templates/chat/index.php');
    }

    private static function css(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/css/main.css');
    }

    private static function js(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
    }

    private static function controller(): string
    {
        return (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
    }

    // ---- Gabarit ----

    public function testLIdentiteVitDansLaPiluleDeSaisieAvantLeChampDeTexte(): void
    {
        $tpl = self::tpl();
        $inputBox = strpos($tpl, 'id="inputBox"');
        $identity = strpos($tpl, 'id="assistantIdentity"');
        $chips    = strpos($tpl, 'id="fileChips"');
        $textarea = strpos($tpl, 'id="msgInput"');
        $this->assertNotFalse($inputBox);
        $this->assertNotFalse($identity);
        $this->assertTrue($inputBox < $identity && $identity < $chips && $chips < $textarea,
            'L\'identité est la PREMIÈRE rangée de la pilule : avant les pièces jointes et le champ de texte.');

        // Rangée repliée : avatar, mot « Assistant », nom, chevron ; à droite, Modifier.
        foreach (['assistantIdentityToggle', 'assistantIdentityAvatar', 'assistantIdentityName',
                  'assistantIdentityDetails', 'assistantIdentityDesc', 'assistantIdentityLlmIcon',
                  'assistantIdentityLlmProvider', 'assistantIdentityLlmVersion', 'assistantIdentityTools',
                  'assistantIdentityKbs', 'profileEditBtn'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $tpl, "#$id manque au gabarit");
        }
        // Le mot est traduit : on asserte la clé, l'ancrage restant la classe.
        $this->assertStringContainsString('class="assistant-identity-kicker"><?= te(\'Assistant\') ?></span>', $tpl);
        // Détails repliés par défaut, chevron accessible.
        $this->assertStringContainsString('id="assistantIdentityDetails" hidden', $tpl);
        $this->assertStringContainsString('aria-expanded="false" aria-controls="assistantIdentityDetails"', $tpl);
    }

    public function testLeBandeauDeTeteALaisseLaPlaceAuModeAssistantSurMain(): void
    {
        $tpl = self::tpl();
        $this->assertStringNotContainsString('profile-lock-header', $tpl);
        $this->assertStringNotContainsString('Verrouillé</span>', $tpl);
        // Classe de mode + couleur posées côté serveur sur <main> : pas de flash.
        $this->assertStringContainsString("<?= \$profileLocked ? ' has-assistant' : '' ?>", $tpl);
        $this->assertStringContainsString("style=\"--profile-accent:' . htmlspecialchars(\$profileAccent) . '\"", $tpl);
        // Couleur validée : une valeur libre en base ne casse pas l'attribut style.
        $this->assertStringContainsString("preg_match('/^#[0-9a-fA-F]{6}$/', \$profileAccent)", $tpl);
        // Le bloc qui résout l'assistant actif précède <main> (il en a besoin).
        $this->assertTrue(strpos($tpl, '$profileLocked = (bool)$activeProfile;') < strpos($tpl, '<main class="chat-main'));
    }

    public function testLeBoutonModifierResteSoumisAuDroitTrancheCoteServeur(): void
    {
        $tpl = self::tpl();
        $this->assertStringContainsString("Profile::manageableBy((int)\$activeProfile['id'], Session::currentUser())", $tpl);
        $this->assertStringContainsString("<?= \$canEditActiveProfile ? '' : 'hidden' ?>", $tpl);
    }

    public function testUneConversationDAssistantVideSePresenteAuCentre(): void
    {
        $tpl = self::tpl();
        $this->assertStringContainsString('<?php elseif ($profileLocked && empty($messages)): ?>', $tpl);
        $this->assertStringContainsString('class="chat-welcome assistant-welcome" id="assistantWelcome"', $tpl);
        // Même DOM côté JS (changement de conversation vers un fil vide).
        $this->assertStringContainsString('function buildAssistantWelcomeHtml(profile)', self::js());
        $this->assertStringContainsString('class="chat-welcome assistant-welcome" id="assistantWelcome"', self::js());
    }

    public function testLesIconesDOutilsSontDefiniesUneFoisEtExposeesAuJs(): void
    {
        $tpl = self::tpl();
        $this->assertSame(1, substr_count($tpl, '$toolIcons = ['), 'une seule carte d\'icônes d\'outils');
        $this->assertTrue(strpos($tpl, '$toolIcons = [') < strpos($tpl, 'id="assistantIdentity"'),
            'la carte doit précéder l\'identité, qui s\'en sert');
        $this->assertStringContainsString("window.TOOL_TYPE_ICONS = <?= json_encode(\$toolIcons, JSON_UNESCAPED_UNICODE) ?>;", $tpl);
        // Le modèle courant est décrit au JS (fournisseur, version, logo).
        $this->assertStringContainsString("'provider'   => LLMFactory::providerLabel(\$provider),", $tpl);
        $this->assertStringContainsString("'version'    => \$modelId,", $tpl);
        $this->assertStringContainsString("'logo'       => LLMFactory::logos()[\$provider] ?? '',", $tpl);
        // Plus de table de libellés de fournisseurs recopiée dans le gabarit.
        $this->assertStringNotContainsString('$providerNames', $tpl);
    }

    // ---- CSS ----

    public function testLaCouleurVitSurLaPiluleEtLaToolbarEstMasqueeEnModeAssistant(): void
    {
        $css = self::css();
        $this->assertStringContainsString('.chat-main.has-assistant { --composer-accent: var(--profile-accent, var(--accent)); }', $css);
        $this->assertStringContainsString('.chat-main.has-assistant .chat-toolbar { display: none; }', $css);
        $this->assertStringContainsString('.chat-main.has-assistant .input-box {', $css);
        $this->assertStringContainsString('.chat-main.has-assistant .input-box:focus-within {', $css);
        // Plus de cadre d'écran, plus de teinte rgb, plus de règles du bandeau.
        $this->assertStringNotContainsString('.chat-main::after', $css);
        $this->assertStringNotContainsString('--profile-accent-rgb', $css);
        $this->assertStringNotContainsString('.profile-lock-', $css);
    }

    public function testHiddenLEmporteSurDisplayFlex(): void
    {
        // Même piège que .kb-picker-panel, .kb-card et .pf-ai-panel.
        $this->assertStringContainsString(
            ".assistant-identity[hidden],\n.assistant-identity-details[hidden],\n.assistant-identity-edit[hidden],\n.assistant-identity-chip[hidden] { display: none !important; }",
            self::css()
        );
    }

    public function testLeMobileGardeLeNomEtPerdLeSuperflu(): void
    {
        $css = self::css();
        $this->assertStringContainsString('.assistant-identity-kicker { display: none; }', $css);
        $this->assertStringContainsString('.assistant-identity-edit span { display: none; }', $css);
    }

    // ---- chat.js ----

    public function testUnSeulRenduRemplitLIdentiteAuChangementDeConversation(): void
    {
        $js = self::js();
        $this->assertStringContainsString('function renderAssistantIdentity(profile, ctx = {})', $js);
        $this->assertSame(3, substr_count($js, 'renderAssistantIdentity('),
            'définition + un appel par branche de switchConversation (assistant / sans) : aucun autre chemin ne remplit l\'identité');
        $this->assertStringContainsString("main?.classList.toggle('has-assistant', !!profile);", $js);
        // Repli à l'arrivée sur une conversation (défaut demandé).
        $this->assertStringContainsString('if (details) details.hidden = true;', $js);
        // Modèle affiché = default_llm COURANT de l'assistant.
        $this->assertStringContainsString('data.profile.default_llm', $js);
        // Plus aucune trace du bandeau.
        $this->assertStringNotContainsString('profileLockHeader', $js);
        $this->assertStringNotContainsString('profile-accent-active', $js);
    }

    public function testLaCouleurNeTeintePlusLEcran(): void
    {
        $js = self::js();
        $start = strpos($js, 'function applyProfileColor(color)');
        $end   = strpos($js, 'function renderAssistantIdentity');
        $this->assertNotFalse($start);
        $body = substr($js, $start, $end - $start);
        $this->assertStringNotContainsString('linear-gradient', $body);
        $this->assertStringNotContainsString('borderTop', $body);
        $this->assertStringContainsString("main.style.setProperty('--profile-accent', hex);", $body);
        $this->assertStringContainsString("/^#[0-9a-fA-F]{6}$/.test(hex)", $body);
    }

    public function testLeChevronDeplieEtReplieLesCaracteristiques(): void
    {
        $js = self::js();
        $this->assertStringContainsString("document.getElementById('assistantIdentityToggle')", $js);
        $this->assertStringContainsString("toggle.setAttribute('aria-expanded', open ? 'true' : 'false');", $js);
    }

    // ---- Contrôleur ----

    public function testLesOutilsDeLAssistantViennentDUnHelperPartage(): void
    {
        $ctl = self::controller();
        $this->assertStringContainsString('private function activeProfileToolBadges(int $profileId, string $role, array $groupIds): array', $ctl);
        // Définition + show() + loadJson() : le rendu initial et le changement de
        // conversation montrent la même liste.
        $this->assertSame(3, substr_count($ctl, 'activeProfileToolBadges('));
        $this->assertStringContainsString("'default_llm' => (string)(\$p['default_llm'] ?? ''),", $ctl);
        $this->assertStringContainsString("'tools'       => \$this->activeProfileToolBadges(", $ctl);
    }

    // ---- Documentation ----

    public function testLesGuidesEtLesNouveautesDecriventLaPilule(): void
    {
        $user = (string)file_get_contents(ROOT . '/docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString('sur la première ligne de la pilule de saisie', $user);
        $this->assertStringContainsString('un bouton **Modifier**, à droite de cette ligne', $user);
        $this->assertStringNotContainsString('badge **Verrouillé**', $user);

        $creator = (string)file_get_contents(ROOT . '/docs/GUIDE-CREATEUR.md');
        $this->assertStringContainsString('en haut de la pilule de saisie, ouvre directement sa fiche', $creator);

        $news = (string)file_get_contents(ROOT . '/docs/NOUVEAUTES.md');
        $this->assertStringContainsString('<!-- id: assistant-identite-composer | genre: amelioration | pour: tous -->', $news);
    }
}
