<?php

declare(strict_types=1);

/**
 * Nouveautés majeures sur la vraie base : la fenêtre montre ce que la
 * personne peut utiliser, une seule fois ; ouvrir la page éteint la pastille ;
 * un compte neuf ne voit rien ; la pastille s'éteint seule après 30 jours ;
 * une nouveauté soumise à un réglage n'apparaît qu'une fois celui-ci posé.
 *
 * Catalogue de substitution et jour fixé : le vrai catalogue vieillit, ce
 * test ne doit pas casser dans trois mois.
 */
final class MajorFeaturesPipelineTest extends DatabaseTestCase
{
    private const CATALOG = [
        'nouv-creations' => [
            'date'  => '2026-06-10',
            'titre' => 'Mes créations',
            'texte' => 'Texte.',
            'image' => '/assets/img/nouveautes/workflows.svg',
            'cible' => ['espace', 'creations'],
        ],
        'nouv-comptes' => [
            'date'  => '2026-06-05',
            'titre' => 'Comptes connectés',
            'texte' => 'Texte.',
            'image' => '/assets/img/nouveautes/claude-office.svg',
            'cible' => ['compte', 'connections'],
            'ancre' => 'office-addin',
        ],
        'nouv-workflows' => [
            'date'  => '2026-06-01',
            'titre' => 'Workflows',
            'texte' => 'Texte.',
            'image' => '/assets/img/nouveautes/workflows.svg',
            'cible' => ['espace', 'workflows'],
        ],
        'nouv-office' => [
            'date'      => '2026-05-30',
            'titre'     => 'Office',
            'texte'     => 'Texte.',
            'image'     => '/assets/img/nouveautes/claude-office.svg',
            'cible'     => ['compte', 'connections'],
            'condition' => 'office',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetSettings();
        MajorFeatures::overrideCatalog(self::CATALOG);
        MajorFeatures::setToday(date('Y-m-d', strtotime('2026-06-15')));
    }

    protected function tearDown(): void
    {
        MajorFeatures::overrideCatalog(null);
        MajorFeatures::setToday(null);
        if (self::$pdo !== null) {
            $this->forgetSettings();
        }
        parent::tearDown();
    }

    private function forgetSettings(): void
    {
        $this->db()->exec("DELETE FROM settings WHERE `key` IN ('office_addin_enabled', 'workflows_enabled')");
        Settings::refresh();
    }

    private function user(int $id, string $role = 'user', string $created = '2026-01-01 09:00:00'): array
    {
        $this->insertUser($id, $role);
        $this->db()->prepare('UPDATE users SET created_at = ? WHERE id = ?')->execute([$created, $id]);
        $row = $this->db()->query('SELECT * FROM users WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        MajorFeatures::forget();
        return $row;
    }

    private function itemIds(array $items): array
    {
        return array_column($items, 'id');
    }

    public function testLaFenetreMontreCeQueLaPersonnePeutUtiliserUneSeuleFois(): void
    {
        $u = $this->user(1);
        $state = MajorFeatures::forViewer($u);
        // Un utilisateur simple n'ouvre pas les workflows ; Office est éteint.
        $this->assertSame(['nouv-creations', 'nouv-comptes'], $this->itemIds($state['popup']));
        $this->assertSame(['nouv-creations', 'nouv-comptes'], $state['dots']);
        $this->assertSame('/settings/connections#office-addin', $state['popup'][1]['lien']);

        MajorFeatures::markShown(1, $state['pending']);
        MajorFeatures::forget();
        $apres = MajorFeatures::forViewer($u);
        $this->assertSame([], $apres['popup'], 'Vue une fois, la fenêtre ne revient plus.');
        $this->assertSame(['nouv-creations', 'nouv-comptes'], $apres['dots'], 'La pastille reste jusqu’à la visite.');

        // Un second appel ne remet pas l'heure de la fenêtre à zéro.
        $this->db()->exec("UPDATE user_feature_news SET shown_at = '2026-01-02 00:00:00' WHERE user_id = 1");
        MajorFeatures::markShown(1, ['nouv-creations']);
        $this->assertSame('2026-01-02 00:00:00', $this->db()->query("SELECT shown_at FROM user_feature_news WHERE user_id = 1 AND feature_id = 'nouv-creations'")->fetchColumn());
    }

    public function testOuvrirLaPageEteintLaPastilleEtLaFenetre(): void
    {
        $u = $this->user(2);
        $state = MajorFeatures::forViewer($u, 'connections');
        $this->assertSame(['nouv-creations'], $this->itemIds($state['popup']), 'Trouvée seule : plus besoin de la présenter.');
        $this->assertSame(['nouv-creations'], $state['dots']);
        $row = $this->db()->query("SELECT shown_at IS NOT NULL AS s, visited_at IS NOT NULL AS v FROM user_feature_news WHERE user_id = 2 AND feature_id = 'nouv-comptes'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['s' => 1, 'v' => 1], array_map('intval', $row));
    }

    public function testALArriveeLaCarteDeLaNouveautePorteSaPastille(): void
    {
        $u = $this->user(2);
        $this->assertSame('', MajorFeatures::anchorDot('office-addin'), 'Avant la visite : rien sur la page');
        MajorFeatures::forViewer($u, 'connections');
        // La pastille du menu s'éteint ; celle de la carte dit où regarder, le temps de la visite.
        $dot = MajorFeatures::anchorDot('office-addin');
        $this->assertStringContainsString('class="news-dot is-anchor"', $dot);
        $this->assertStringContainsString('data-tip="Nouveau : Comptes connectés"', $dot);
        $this->assertSame('', MajorFeatures::anchorDot('autre-carte'));

        // Visite suivante : déjà vue, plus de pastille.
        MajorFeatures::forget();
        MajorFeatures::forViewer($u, 'connections');
        $this->assertSame('', MajorFeatures::anchorDot('office-addin'));
    }

    public function testUnCompteNeufNeVoitRien(): void
    {
        $u = $this->user(3, 'user', '2026-06-12 08:00:00');
        $state = MajorFeatures::forViewer($u);
        $this->assertSame([], $state['popup']);
        $this->assertSame([], $state['dots']);
    }

    public function testLeCreateurVoitLesWorkflowsSaufSiLAdministrateurLesCoupe(): void
    {
        $u = $this->user(4, 'creator');
        $this->assertContains('nouv-workflows', $this->itemIds(MajorFeatures::forViewer($u)['popup']));
        Settings::set('workflows_enabled', '0');
        Settings::refresh();
        MajorFeatures::forget();
        $this->assertNotContains('nouv-workflows', $this->itemIds(MajorFeatures::forViewer($u)['popup']));
    }

    public function testOfficeNApparaitQuUneFoisActive(): void
    {
        $u = $this->user(5);
        $this->assertNotContains('nouv-office', MajorFeatures::forViewer($u)['pending']);
        Settings::set('office_addin_enabled', '1');
        Settings::refresh();
        MajorFeatures::forget();
        $this->assertContains('nouv-office', MajorFeatures::forViewer($u)['pending']);
    }

    public function testAuPlusTroisDansLaFenetreToutesCompteesVues(): void
    {
        Settings::set('office_addin_enabled', '1');
        Settings::refresh();
        $u = $this->user(6, 'creator');
        $state = MajorFeatures::forViewer($u);
        $this->assertCount(MajorFeatures::MAX_POPUP, $state['popup']);
        $this->assertCount(4, $state['pending']);
        MajorFeatures::markShown(6, $state['pending']);
        MajorFeatures::forget();
        $this->assertSame([], MajorFeatures::forViewer($u)['popup'], 'La quatrième ne revient pas seule à la page suivante.');
    }

    public function testLaPastilleSEteintSeuleApresTrenteJours(): void
    {
        $u = $this->user(7);
        $this->db()->exec("INSERT INTO user_feature_news (user_id, feature_id, shown_at) VALUES
            (7, 'nouv-creations', NOW() - INTERVAL 31 DAY), (7, 'nouv-comptes', NOW() - INTERVAL 3 DAY)");
        MajorFeatures::forget();
        $state = MajorFeatures::forViewer($u);
        $this->assertSame([], $state['popup']);
        $this->assertSame(['nouv-comptes'], $state['dots']);
    }

    public function testUnIdentifiantInconnuNEstPasEcrit(): void
    {
        $this->user(8);
        MajorFeatures::markShown(8, ['inconnu', 'nouv-comptes', 'nouv-comptes']);
        $this->assertSame(['nouv-comptes'], $this->db()->query('SELECT feature_id FROM user_feature_news WHERE user_id = 8')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testLaSuppressionDuCompteEmporteSesLignes(): void
    {
        $this->user(9);
        MajorFeatures::markShown(9, ['nouv-comptes']);
        $this->db()->exec('DELETE FROM users WHERE id = 9');
        $this->assertSame(0, (int)$this->db()->query('SELECT COUNT(*) FROM user_feature_news WHERE user_id = 9')->fetchColumn());
    }
}
