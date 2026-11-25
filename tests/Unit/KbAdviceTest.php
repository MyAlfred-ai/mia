<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Conseils d'une base de connaissances (src/Sync/KbAdvice.php) : règles
 * pures — qu'est-ce qui rend une base peu exploitable, et quoi changer —,
 * puis leur câblage dans la fiche, la liste et le rafraîchissement.
 */
final class KbAdviceTest extends TestCase
{
    private const KB = ['id' => 7, 'backend' => 'local', 'is_system' => 0];

    protected function setUp(): void
    {
        I18n::use('fr');
    }

    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function src(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    /** Un document lu et vectorisé, avec ses tokens et ses extraits. */
    private static function doc(string $name, int $tokens, int $chunks, string $mime = 'application/pdf'): array
    {
        return [
            'file_name'         => $name,
            'mime_type'         => $mime,
            'token_count'       => $tokens,
            'chunk_count'       => $chunks,
            'extraction_status' => 'done',
            'vector_status'     => 'done',
            'has_text'          => $tokens > 0 ? 1 : 0,
        ];
    }

    /** @return list<string> */
    private static function keys(array $docs, array $kb = self::KB): array
    {
        return array_column(KbAdvice::forBase($kb, $docs), 'key');
    }

    // =================== Règles ===================

    public function testUneBaseSaineNeRecoitAucunConseil(): void
    {
        $docs = [self::doc('Guide RH.pdf', 9000, 24), self::doc('Procédure congés.docx', 2100, 6)];
        $this->assertSame([], KbAdvice::forBase(self::KB, $docs));
        $this->assertSame([], KbAdvice::forBase(self::KB, []), 'Une base vide : le panneau d\'état le dit déjà.');
    }

    public function testRienPourUnCerveauNiPourLaBaseSysteme(): void
    {
        $docs = [self::doc('Scan.pdf', 0, 0)];
        $this->assertSame([], KbAdvice::forBase(['id' => 1, 'backend' => 'mybrain', 'is_system' => 0], $docs));
        $this->assertSame([], KbAdvice::forBase(['id' => 1, 'backend' => 'local', 'is_system' => 1], $docs));
    }

    public function testUnScanSansTexteDitQuoiDeposerALaPlace(): void
    {
        $advice = KbAdvice::forBase(self::KB, [self::doc('Scan contrat.pdf', 0, 0), self::doc('Guide.pdf', 9000, 24)]);
        $this->assertSame('no_text', $advice[0]['key']);
        $this->assertSame('Un document sans texte', $advice[0]['title']);
        $this->assertStringContainsString('version texte', $advice[0]['text']);
        $this->assertSame(['Scan contrat.pdf'], $advice[0]['docs']);
    }

    public function testTresPeuDeTexteSeulementUneFoisToutLu(): void
    {
        $this->assertContains('thin', self::keys([self::doc('Note.txt', 120, 1)]));
        $enCours = self::doc('Gros manuel.pdf', 0, 0);
        $enCours['extraction_status'] = 'processing';
        $enCours['vector_status'] = 'pending';
        $this->assertNotContains('thin', self::keys([self::doc('Note.txt', 120, 1), $enCours]), 'Pendant la lecture, le total monte encore.');
        $this->assertNotContains('thin', self::keys([self::doc('Manuel.pdf', KbAdvice::THIN_TOKENS, 2)]));
        $advice = KbAdvice::forBase(self::KB, [self::doc('Note.txt', 120, 1)]);
        $this->assertStringContainsString('environ 90 mots', $advice[0]['text']);
    }

    public function testDeuxVersionsDUnMemeDocumentSeReconnaissent(): void
    {
        $this->assertSame('reglement interieur', KbAdvice::baseTitle('Règlement intérieur v2 (1).pdf'));
        $this->assertSame('reglement interieur', KbAdvice::baseTitle('reglement-interieur_final.docx'));
        $this->assertSame('tarifs', KbAdvice::baseTitle('Copie de Tarifs.xlsx'));
        // Ni les années ni les dates : des documents distincts, pas des versions.
        $this->assertNotSame(KbAdvice::baseTitle('Rapport 2024.pdf'), KbAdvice::baseTitle('Rapport 2025.pdf'));
        $this->assertNotSame(KbAdvice::baseTitle('CR réunion 2026-10-01.docx'), KbAdvice::baseTitle('CR réunion 2026-10-08.docx'));

        $docs = [
            self::doc('Règlement intérieur.pdf', 9000, 20),
            self::doc('Règlement intérieur v2.pdf', 9100, 21),
            self::doc('Rapport 2024.pdf', 3000, 8),
            self::doc('Rapport 2025.pdf', 3000, 8),
        ];
        $advice = KbAdvice::forBase(self::KB, $docs);
        $this->assertSame(['versions'], array_column($advice, 'key'));
        $this->assertSame(['Règlement intérieur.pdf', 'Règlement intérieur v2.pdf'], $advice[0]['docs']);
    }

    public function testUneBaseDeTableursRenvoieVersLaConversation(): void
    {
        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $docs = [self::doc('Ventes.xlsx', 8000, 20, $xlsx), self::doc('Note.pdf', 2000, 5)];
        $advice = KbAdvice::forBase(self::KB, $docs);
        $this->assertSame(['tables'], array_column($advice, 'key'));
        $this->assertStringContainsString('joignez plutôt le fichier dans la conversation', $advice[0]['text']);
        // Quelques lignes de tableur ne gênent personne ; un CSV se reconnaît à son nom.
        $this->assertNotContains('tables', self::keys([self::doc('Liste.csv', 400, 1, 'text/plain'), self::doc('Guide.pdf', 300, 1)]));
        $this->assertTrue(KbAdvice::isTable(['file_name' => 'Liste.csv', 'mime_type' => 'text/plain']));
        $this->assertFalse(KbAdvice::isTable(['file_name' => 'Guide.pdf', 'mime_type' => 'application/pdf']));
    }

    public function testUnFourreToutTropGrosSuggereDeDecouper(): void
    {
        $this->assertContains('large', self::keys([self::doc('Archives.pdf', 2_000_000, KbAdvice::LARGE_CHUNKS + 1)]));
        $this->assertNotContains('large', self::keys([self::doc('Archives.pdf', 1_000_000, KbAdvice::LARGE_CHUNKS)]));
    }

    public function testLesConseilsVontDuPlusBloquantAuPlusDiscret(): void
    {
        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $docs = [
            self::doc('Scan.pdf', 0, 0),
            self::doc('Tarifs.xlsx', 5000, 12, $xlsx),
            self::doc('Copie de Tarifs.xlsx', 5000, 12, $xlsx),
            self::doc('Guide.pdf', 800, KbAdvice::LARGE_CHUNKS + 10),
        ];
        $this->assertSame(['no_text', 'versions', 'tables', 'large'], self::keys($docs));
    }

    public function testLesConseilsParlentAnglaisDansUnEcranAnglais(): void
    {
        I18n::use('en');
        $advice = KbAdvice::forBase(self::KB, [self::doc('Scan.pdf', 0, 0), self::doc('Note.txt', 0, 0)]);
        $this->assertSame('2 documents without text', $advice[0]['title']);
        $this->assertStringNotContainsString('version texte', $advice[0]['text']);
    }

    // =================== Câblage ===================

    public function testLaFicheMontreLesConseilsEtLaPastille(): void
    {
        $fiche = self::src('templates/admin/knowledge-bases/edit.php');
        $this->assertStringContainsString('<details class="kbf-advice" id="conseils"', $fiche);
        $this->assertStringContainsString('id="kbAdvicePill"', $fiche);
        $this->assertStringContainsString("require ROOT . '/templates/partials/kb-advice.php';", $fiche);
        // Les conseils généraux : un sujet par base, du vrai texte, une version…
        foreach (['Un sujet par base.', 'Du vrai texte.', 'Une seule version de chaque document.', 'Des titres et des paragraphes.'] as $conseil) {
            $this->assertStringContainsString($conseil, $fiche);
        }
        $this->assertStringContainsString('KbAdvice::chunkWords()', $fiche, 'La taille d\'un extrait vient du découpeur, pas d\'un chiffre recopié.');

        $ctrl = self::src('src/Controllers/AdminController.php');
        $this->assertGreaterThanOrEqual(3, substr_count($ctrl, 'KbAdvice::forBase('), 'Fiche, liste et rafraîchissement.');
        $this->assertStringContainsString("'advice'       => \$this->kbAdvicePayload(\$kb, \$docs),", $ctrl);

        $js = self::src('public/assets/js/kb-form.js');
        $this->assertStringContainsString('if (data.advice) applyAdvice(data.advice);', $js, 'Les conseils suivent la lecture des documents.');
        $this->assertStringContainsString("location.hash === '#conseils'", $js);
    }

    public function testLaPageDUneBaseRenvoieAuxConseils(): void
    {
        // Piste D : la liste reste légère ; la page de consultation dit combien
        // de points restent à améliorer et mène à « Pour une base efficace ».
        $show = self::src('templates/admin/knowledge-bases/show.php');
        $this->assertStringContainsString("I18n::plural(\$adviceCount, '{n} point à améliorer', '{n} points à améliorer')", $show);
        $this->assertStringContainsString("#conseils", $show);
        $this->assertStringContainsString('KbAdvice::forBase($kb, $docs)', self::src('src/Controllers/AdminController.php'));
        $this->assertStringContainsString('.kb-pill-advice', self::src('public/assets/css/main.css'));
    }

    public function testLaClasseEstChargeeApresKbStatus(): void
    {
        foreach (['config/classes.php', 'tests/bootstrap.php'] as $liste) {
            $src = self::src($liste);
            $this->assertNotFalse(strpos($src, '/src/Sync/KbAdvice.php'), $liste);
            $this->assertLessThan(strpos($src, '/src/Sync/KbAdvice.php'), strpos($src, '/src/Sync/KbStatus.php'), $liste);
        }
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationDonneLesConseils(): void
    {
        $page = self::src('docs/user/fonctionnalites/bases-de-connaissances/creer-une-base-de-connaissances.md');
        $this->assertStringContainsString('## Pour une base efficace', $page);
        $this->assertStringContainsString('points à améliorer', $page);
        $this->assertStringContainsString('id: kb-conseils', self::src('docs/NOUVEAUTES.md'));
        $this->assertStringContainsString('KbAdvice', self::src('CLAUDE.md'));
    }
}
