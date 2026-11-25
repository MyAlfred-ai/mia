<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Réglages → Mémoire (« Ce que MIA sait de vous ») — câblage :
 *   - une page, un contrôleur mince, des routes littérales avant `{id}` ;
 *   - « Votre contexte » en formulaire auto-enregistré, les souvenirs en
 *     gestes JSON rendus depuis la source de vérité ;
 *   - une seule entrée dans le menu du compte, un nom partout (« Mémoire ») ;
 *   - l'interrupteur d'administration et ses effets (chat, idées de saisie) ;
 *   - les souvenirs hors de la sauvegarde de l'instance ;
 *   - la pastille du chat (ajouté, corrigé, retiré — avec « Annuler »).
 */
final class MemoryPageTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        $abs = ROOT . '/' . $chemin;
        self::assertFileExists($abs, "Fichier attendu absent : $chemin");
        return (string)file_get_contents($abs);
    }

    public function testLesRoutesMenentAuControleurDansLeBonOrdre(): void
    {
        $routes = self::lire('public/index.php');
        foreach ([
            "get('/settings/memory',"                => 'index',
            "post('/settings/memory',"               => 'add',
            "post('/settings/memory/prefs',"         => 'savePrefs',
            "post('/settings/memory/clear',"         => 'clear',
            "post('/settings/memory/{id}',"          => 'update',
            "post('/settings/memory/{id}/delete',"   => 'delete',
            "post('/settings/memory/{id}/confirm',"  => 'confirm',
            "post('/settings/memory/{id}/reject',"   => 'reject',
        ] as $route => $methode) {
            $this->assertMatchesRegularExpression(
                '#' . preg_quote($route, '#') . '\s*\[MemoryController::class, \'' . $methode . '\'\]#',
                $routes,
                $route
            );
        }
        // Les anciennes routes de Préférences IA ont disparu.
        $this->assertStringNotContainsString("[UserSettingsController::class, 'addMemory']", $routes);
        $this->assertStringNotContainsString("[UserSettingsController::class, 'deleteMemory']", $routes);

        $classes = self::lire('config/classes.php');
        $this->assertLessThan(
            strpos($classes, "src/Controllers/MemoryController.php"),
            (int)strpos($classes, "src/Controllers/UserSettingsController.php")
        );
        $this->assertStringContainsString("src/Memory/MemoryCapture.php", $classes);
        $this->assertStringContainsString("'/src/Memory/MemoryCapture.php'", self::lire('tests/bootstrap.php'));
    }

    public function testLeControleurResteMinceEtRendLaListeAJour(): void
    {
        $ctrl = self::lire('src/Controllers/MemoryController.php');
        // Chaque geste rend la liste depuis la source de vérité.
        $this->assertStringContainsString("AjaxResponse::send(\n            ['ok' => \$error === null, 'error' => \$error] + self::payload(\$userId)", $ctrl);
        // Une saisie de la page est un souvenir écrit par la personne.
        $this->assertStringContainsString("'source' => UserMemory::SOURCE_MANUAL", $ctrl);
        // « Tout effacer » exige une confirmation explicite.
        $this->assertStringContainsString("(\$_POST['confirm'] ?? '') !== '1'", $ctrl);
        // « Votre contexte » n'est écrit que s'il est posté.
        $this->assertStringContainsString("array_key_exists('ai_user_context', \$_POST)", $ctrl);
        $this->assertStringContainsString('UserPreferences::save($userId, null, $context);', $ctrl);
        // La visite est notée : ce qui arrivera ensuite portera « Nouveau ».
        $this->assertStringContainsString('UserMemory::markSeen($userId);', $ctrl);
        // Aucune requête SQL : tout passe par le modèle.
        $this->assertStringNotContainsString('Database::getInstance', $ctrl);
    }

    public function testLaPageSuitLeSocleEtExpliqueSonUtilite(): void
    {
        $tpl = self::lire('templates/settings/memory.php');
        $this->assertStringContainsString("\$page = 'memory'", $tpl);
        $this->assertStringContainsString('admin-topbar.php', $tpl);
        $this->assertStringContainsString('page-head', $tpl);
        $this->assertStringContainsString("te('Ce que MIA sait de vous')", $tpl);
        $this->assertStringContainsString("te('Comment fonctionne la mémoire')", $tpl);

        // Un formulaire, vide, auto-enregistré ; « Votre contexte » s'y rattache.
        preg_match_all('#<form\s[^>]*>#', $tpl, $formulaires);
        $this->assertCount(1, $formulaires[0]);
        $this->assertStringContainsString('id="memoryPrefsForm"', $formulaires[0][0]);
        $this->assertStringContainsString('data-autosave', $formulaires[0][0]);
        $sansPhp = (string)preg_replace('/<\?(?:php|=).*?\?>/s', '', $tpl);
        preg_match_all('#<(?:input|textarea|select)\b[^>]*\bname="([^"]+)"[^>]*>#s', $sansPhp, $champs, PREG_SET_ORDER);
        $this->assertSame(['ai_user_context', 'memory_learn', 'memory_questions'], array_values(array_unique(array_column($champs, 1))));
        foreach ($champs as $champ) {
            $this->assertStringContainsString('form="memoryPrefsForm"', $champ[0], $champ[1]);
        }

        // Un bloc par thème, dans l'ordre du modèle ; données passées en JSON sûr.
        $this->assertStringContainsString('UserMemory::themes()', $tpl);
        $this->assertStringContainsString('JSON_HEX_TAG', $tpl);
        $this->assertStringContainsString('id="memoryBootstrap"', $tpl);
        $this->assertStringContainsString('/assets/js/memory.js?v=', $tpl);
        // Mémoire coupée : la page le dit, et reste consultable.
        $this->assertStringContainsString('mem-alert-off', $tpl);
    }

    public function testLeScriptNeContientAucunTexteNiAucunGesteSansServeur(): void
    {
        $js = self::lire('public/assets/js/memory.js');
        // Chaque geste passe par le serveur, qui renvoie la liste à jour.
        foreach (["'/settings/memory'", "'/settings/memory/clear'", "'/delete'", "'/confirm'", "'/reject'"] as $adresse) {
            $this->assertStringContainsString($adresse, $js);
        }
        $this->assertStringContainsString("'X-CSRF-Token'", $js);
        $this->assertStringContainsString('data.memories', $js);
        // Libellés par t() / tn() (dictionnaire client) ; seul un pictogramme
        // CONSTANT passe par innerHTML, jamais le texte d'un souvenir.
        preg_match_all('/innerHTML\s*=\s*([^;]+);/', $js, $html);
        foreach ($html[1] as $valeur) {
            $this->assertMatchesRegularExpression('/^[A-Z_]+$/', trim($valeur), 'innerHTML ne reçoit qu\'une constante.');
        }
        $this->assertGreaterThan(10, substr_count($js, "t('"));
        // Tout effacer demande confirmation.
        $this->assertStringContainsString('confirm(', $js);
    }

    public function testUneSeuleEntreeDansLeMenuEtUnNomPartout(): void
    {
        $this->assertSame('/settings/memory', NavRegistry::ACCOUNT['memory']['href']);
        $this->assertSame('Mémoire', NavRegistry::ACCOUNT['memory']['label']);
        $this->assertSame('memory', NavRegistry::ACCOUNT['memory']['page']);
        $keys = array_keys(NavRegistry::ACCOUNT);
        $this->assertSame(array_search('ai', $keys, true) + 1, array_search('memory', $keys, true), 'Juste après Préférences IA.');
        $nav = self::lire('src/Helpers/NavRegistry.php');
        $this->assertMatchesRegularExpression("/'memory'\s*=>\s*'[^']*que sais-tu de moi/", $nav);
        $this->assertMatchesRegularExpression("/'memory'\s*=>\s*'[^']*mia pour vous/", $nav, 'L\'ancien nom reste trouvable dans la palette.');
        $this->assertNotSame('', AdminMenu::icon('memory'));

        // « MIA pour vous » a disparu de l'interface.
        foreach (['templates/settings/ai.php', 'templates/settings/profile.php', 'templates/settings/memory.php',
                  'public/assets/js/chat.js', 'public/assets/js/memory.js', 'templates/admin/tools.php'] as $fichier) {
            $this->assertStringNotContainsString('MIA pour vous', self::lire($fichier), $fichier);
        }
    }

    public function testLAdministrateurPeutCouperLaMemoire(): void
    {
        $this->assertSame('memory_enabled', UserMemory::SETTING_ENABLED);
        require_once ROOT . '/src/Controllers/AdminController.php';
        $this->assertContains(UserMemory::SETTING_ENABLED, AdminController::BUILTIN_TOOL_KEYS);

        $tools = self::lire('templates/admin/tools.php');
        $this->assertStringContainsString('id="builtinMemoryCard"', $tools);
        $this->assertStringContainsString('data-builtin-key="<?= htmlspecialchars(UserMemory::SETTING_ENABLED) ?>"', $tools);

        // Coupée : ni capture, ni outil, ni injection — et la note le dit au modèle.
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count($chat, '$memoryOn       = UserMemory::isEnabled();') + substr_count($chat, '$memoryOn      = UserMemory::isEnabled();'));
        $this->assertStringContainsString('UserMemory::isEnabled(), $fileReread)', $chat);
        // Les idées de saisie ne proposent plus de mémoriser.
        $this->assertMatchesRegularExpression("/'memory'\s*=>\s*UserMemory::isEnabled\(\),/", self::lire('templates/chat/index.php'));
        $this->assertStringContainsString("unset(\$phrases['memoire'])", self::lire('src/Helpers/ComposerHints.php'));
    }

    public function testLesSouvenirsNePartentPasDansLaSauvegarde(): void
    {
        $this->assertContains('user_memories', BackupService::EXCLUDED_TABLES);
        $this->assertStringContainsString("te('Mémoire de chaque personne (strictement personnelle)')", self::lire('templates/admin/backup.php'));
    }

    public function testLaPastilleDuChatDitCeQuiAChangeEtPermetDAnnuler(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $debut = strpos($js, 'function renderMemoryChip(');
        $this->assertIsInt($debut);
        $corps = substr($js, $debut, 6000);
        foreach (["t('Ajouté à votre mémoire')", "t('Corrigé dans votre mémoire')", "t('À confirmer dans votre mémoire')",
                  "t('Retiré de votre mémoire')", "'/settings/memory'"] as $attendu) {
            $this->assertStringContainsString($attendu, $corps, $attendu);
        }
        // « Annuler » recrée le souvenir retiré par le chemin de la page.
        $this->assertStringContainsString('memory-chip-undo', $corps);
        $this->assertStringContainsString("fetch('/settings/memory'", $corps);
        $this->assertStringContainsString('.memory-chip-undo', self::lire('public/assets/css/main.css'));
    }

    public function testLeStyleDeLaPageExiste(): void
    {
        $css = self::lire('public/assets/css/main.css');
        foreach (['.mem-page [hidden]', '.mem-theme-head', '.mem-item', '.mem-item--proposed', '.mem-gauge-bar', '.mem-clear-btn', '.mem-how'] as $classe) {
            $this->assertStringContainsString($classe, $css, $classe);
        }
    }
}
