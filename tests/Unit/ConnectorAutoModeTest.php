<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * « Laisser MIA choisir » et provenance des cartes :
 *
 *  - les connecteurs RELIÉS mais éteints deviennent activables par le modèle
 *    (outil activer_connecteur) ; seuls leurs outils ACTIVÉS partent, relus à
 *    chaque aller (option `tools_source`) ;
 *  - une carte d'approbation nomme les contenus écrits par des tiers que MIA
 *    avait lus avant de la proposer (Provenance).
 */
final class ConnectorAutoModeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------- activer_connecteur ----------

    public function testActiverUnConnecteurLeChargeUneFois(): void
    {
        $registry = new ToolRegistry();
        $charges = [];
        $turn = new AgentTurn(1, 2, [], 1000, static function (array $e): void {}, static fn(string $t, array $a, ?array $r): array => []);
        $loader = new ConnectorLoaderTools($turn, ['gmail' => 'Gmail', 'drive' => 'Google Drive'],
            function (string $key) use (&$charges, $registry): ?ToolProvider {
                $charges[] = $key;
                if ($key === 'drive') {
                    return null; // connexion expirée entre-temps
                }
                $p = new class implements ToolProvider {
                    public function key(): string { return 'gmail'; }
                    public function connector(): ?string { return 'gmail'; }
                    public function definitions(): array { return [['name' => 'lire_gmail', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]]; }
                    public function guidance(): string { return "\n\nConsigne Gmail."; }
                    public function handlers(): array { return ['lire_gmail' => fn(array $a): array => ['corps' => 'x']]; }
                    public function externalContentTools(): array { return ['lire_gmail']; }
                };
                $registry->add($p);
                return $p;
            });
        $registry->add($loader);
        $this->assertSame(['activer_connecteur'], array_column($registry->definitions(), 'name'),
            'Au départ, un seul outil léger : aucune définition de connecteur ne part.');

        $res = $registry->dispatch('activer_connecteur', ['connecteur' => 'gmail']);
        $this->assertTrue($res['actif']);
        $this->assertSame(['lire_gmail'], $res['outils']);
        $this->assertStringContainsString('Consigne Gmail.', $res['consignes'], 'La consigne arrive dans le résultat : le prompt système ne bouge pas.');
        $this->assertStringContainsString('Sécurité — contenus lus', $res['consignes'], 'Connecteur qui lit des contenus tiers : la règle suit.');
        $this->assertSame(['activer_connecteur', 'lire_gmail'], array_column($registry->definitions(), 'name'));
        $this->assertArrayHasKey(ExternalContent::KEY, $registry->dispatch('lire_gmail', []), 'Ses résultats sont enveloppés comme les autres.');

        $this->assertTrue($registry->dispatch('activer_connecteur', ['connecteur' => 'gmail', 'x' => 1])['deja_actif'] ?? false);
        $this->assertArrayHasKey('error', $registry->dispatch('activer_connecteur', ['connecteur' => 'drive']), 'Indisponible : dit, jamais inventé.');
        $this->assertArrayHasKey('error', $registry->dispatch('activer_connecteur', ['connecteur' => 'slack']));
        $this->assertSame(['gmail', 'drive'], $charges);

        $def = $loader->definitions()[0];
        $this->assertSame(['gmail', 'drive'], $def['parameters']['properties']['connecteur']['enum']);
        $this->assertStringContainsString('jamais « au cas où »', $def['description']);
    }

    public function testLeModeEstCableDeBoutEnBout(): void
    {
        $src = AgentSource::chat();
        $this->assertStringContainsString("\$connectorsAuto = (\$_POST['connectors_auto'] ?? '0') === '1' && ConnectorRegistry::isAutoModeEnabled();", $src,
            'Le serveur reste juge : réglage coupé, rien d’activable.');
        $this->assertStringContainsString('ConnectorRegistry::autoLoadableConnectors((int)$user[\'id\'], $connectorAsked)', $src);
        $this->assertStringContainsString('$connectorAsked + array_fill_keys(array_keys($autoConnectors), true)', $src,
            'Un connecteur activable n’est pas « dormant » : inutile d’inviter à le cocher.');
        $this->assertStringContainsString("'tools_source' => function () use (\$registry, &\$loopRounds): array {", $src,
            'Définitions relues à chaque aller (le compteur d’allers-retours de la télémétrie y vit aussi).');
        $this->assertStringContainsString('                return $registry->definitions();', $src);
        $this->assertStringContainsString("\$this->traceAction('connecteur_active', ConnectorRegistry::label(\$key));", $src);
        $this->assertSame(2, substr_count($src, 'self::connectorProvider('), 'Une seule fabrique : connecteurs cochés ET activés à la demande.');
        $this->assertSame('automation', ChatController::ACTION_ICONS['connecteur_active']);

        foreach (['src/LLM/AnthropicAdapter.php', 'src/LLM/OpenAIAdapter.php'] as $f) {
            $this->assertStringContainsString("is_callable(\$options['tools_source'] ?? null)", (string)file_get_contents(ROOT . '/' . $f), $f);
        }
        $admin = (string)file_get_contents(ROOT . '/src/Controllers/AdminController.php');
        $this->assertStringContainsString('ConnectorRegistry::setAutoModeEnabled(in_array(ConnectorRegistry::AUTO, $checked, true));', $admin,
            'Formulaire complet : case absente = décochée, comme les connecteurs.');

        $tpl = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringContainsString('id="connectorsAutoToggle"', $tpl);
        $this->assertStringContainsString('ConnectorRegistry::isAutoModeEnabled()', $tpl);
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString("fd.append('connectors_auto',", $js);
        $this->assertStringContainsString("{ name: 'auto',     row: document.getElementById('plusMenuAutoRow'),      toggle: autoToggle }", $js,
            'Même traitement qu’un connecteur : persistance, garde de modèle compatible.');
    }

    // ---------- Provenance ----------

    public function testLaProvenanceEstBorneeEtSansDoublon(): void
    {
        $this->assertSame('Lecture d’un email Gmail — « Devis Durand »', Provenance::entry('Lecture d’un email Gmail', "Devis\nDurand"));
        $this->assertSame('Recherche dans Gmail', Provenance::entry('Recherche dans Gmail', null));
        $long = Provenance::entry('Lecture', str_repeat('a', 200));
        $this->assertLessThan(90, mb_strlen($long));

        $s = [];
        foreach (['A', 'B', 'A', 'C', 'D', 'E'] as $e) {
            $s = Provenance::add($s, $e);
        }
        $this->assertSame(['B', 'A', 'C', 'D', 'E'] === $s ? $s : array_slice(['B', 'A', 'C', 'D', 'E'], -Provenance::MAX), $s);
        $this->assertCount(Provenance::MAX, $s);
        $this->assertSame('E', end($s), 'La plus récente en dernier.');

        $this->assertSame(['action' => 'x'], Provenance::attach(['action' => 'x'], []), 'Rien lu : rien à dire.');
        $avec = Provenance::attach(['action' => 'x'], ['A']);
        $this->assertSame(['A'], $avec['provenance']);
        $this->assertSame(['A'], Provenance::carry(['action' => 'y'], $avec)['provenance'], 'Une révision garde la provenance.');
        $this->assertArrayNotHasKey('provenance', Provenance::carry(['action' => 'y'], ['action' => 'x']));
    }

    public function testLaProvenanceVientDesSeulesLecturesDeContenusTiers(): void
    {
        $src = AgentSource::chat();
        $this->assertMatchesRegularExpression(
            '/!isset\(\$result\[\'error\'\]\) && \$registry instanceof ToolRegistry && \$registry->isExternalContent\(\$name\)/', $src,
            'Une écriture, une erreur ou un outil qui ne lit rien ne sont pas une provenance.');
        $this->assertMatchesRegularExpression(
            '/\$preview = Provenance::attach\(\$preview, \$this->turnSources\);\s*\$id = DrivePendingAction::create\(/', $src,
            'Jointe AVANT le dépôt : persistée avec la carte, rejouée au rechargement.');
        $this->assertStringContainsString('$preview = Provenance::carry($preview, $action[\'preview\']);', $src);
        $this->assertStringContainsString('$this->turnSources = [];', $src);

        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertSame(2, substr_count($js, '${provenanceHtml(data)}') + substr_count($js, '${provenanceHtml(p)}'),
            'Carte d’approbation ET carte de relecture d’un email.');
        $this->assertStringContainsString('.drive-confirm-provenance', (string)file_get_contents(ROOT . '/public/assets/css/main.css'));
        $client = require ROOT . '/lang/en.client.php';
        $this->assertArrayHasKey('Préparé après la lecture de :', $client);
    }
}
