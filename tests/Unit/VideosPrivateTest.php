<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Les vidéos de MIA (dossier videos/) restent dans le dépôt privé : jamais dans
 * l'export vers le dépôt public MyAlfred-ai/mia, jamais dans les images Docker.
 * Trois verrous, testés ici : la liste blanche de la publication les exclut, le
 * garde-fou du public refuse un export qui en contiendrait, .dockerignore les écarte.
 */
final class VideosPrivateTest extends TestCase
{
    private const MEDIAS = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'm4a', 'mp3', 'wav', 'aac', 'ogg', 'flac'];

    private array $aSupprimer = [];

    protected function tearDown(): void
    {
        foreach ($this->aSupprimer as $dossier) {
            exec('rm -rf ' . escapeshellarg($dossier));
        }
    }

    private function dossierTemporaire(): string
    {
        $d = sys_get_temp_dir() . '/mia-videos-' . bin2hex(random_bytes(6));
        mkdir($d, 0700, true);
        $this->aSupprimer[] = $d;
        return $d;
    }

    private static function lignes(string $chemin): array
    {
        return array_map('trim', file(ROOT . '/' . $chemin, FILE_IGNORE_NEW_LINES) ?: []);
    }

    private static function outil(string $commande): void
    {
        exec('command -v ' . escapeshellarg($commande), $o, $code);
        if ($code !== 0) {
            self::markTestSkipped("$commande absent");
        }
    }

    public function testLaListeBlancheExclutLeDossierEtTousLesMedias(): void
    {
        $lignes = self::lignes('publication/liste-blanche.txt');
        $this->assertContains('!videos/**', $lignes);
        foreach (self::MEDIAS as $ext) {
            $this->assertContains('!*.' . $ext, $lignes, "le format .$ext doit être exclu de la publication");
        }
        foreach ($lignes as $l) {
            $this->assertFalse(str_starts_with($l, 'videos'), "videos/ ne doit jamais être inclus : $l");
        }
    }

    public function testLExportReelNeContientAucuneVideo(): void
    {
        self::outil('git');
        self::outil('bash');
        // exporter.sh lit l'archive avec le tar GNU (option -i) : celui de la CI Linux.
        exec('tar --version 2>&1', $version);
        if (!str_contains(implode(' ', $version), 'GNU tar')) {
            self::markTestSkipped('tar GNU requis par exporter.sh (présent en CI)');
        }
        $depot = $this->dossierTemporaire();
        $fichiers = [
            'src/Garde.php' => '<?php',
            'docs/user/index.md' => '# Doc',
            'public/assets/img/logo.png' => 'png',
            'public/assets/media/intro.mp4' => 'video',
            'docs/user/images/demo.webm' => 'video',
            'videos/presentation/mia-demo.mp4' => 'video',
            'videos/presentation/mia-demo.srt' => 'sous-titres',
            'videos/README.md' => '# Vidéos',
        ];
        foreach ($fichiers as $chemin => $contenu) {
            @mkdir(dirname("$depot/$chemin"), 0700, true);
            file_put_contents("$depot/$chemin", $contenu);
        }
        $q = escapeshellarg($depot);
        exec("git -C $q init -q && git -C $q -c user.name=t -c user.email=t@example.test add -A && git -C $q -c user.name=t -c user.email=t@example.test commit -qm init 2>&1", $o, $code);
        $this->assertSame(0, $code, implode("\n", $o));

        $sortie = $this->dossierTemporaire();
        exec('bash ' . escapeshellarg(ROOT . '/.github/publication/exporter.sh') . " $q "
            . escapeshellarg(ROOT . '/publication/liste-blanche.txt') . ' ' . escapeshellarg($sortie) . ' 2>&1', $o, $code);
        $this->assertSame(0, $code, implode("\n", $o));

        $this->assertFileExists("$sortie/src/Garde.php");
        $this->assertFileExists("$sortie/docs/user/index.md");
        $this->assertFileExists("$sortie/public/assets/img/logo.png");
        $this->assertDirectoryDoesNotExist("$sortie/videos");
        $this->assertFileDoesNotExist("$sortie/public/assets/media/intro.mp4");
        $this->assertFileDoesNotExist("$sortie/docs/user/images/demo.webm");
    }

    public function testLeGardeFouDuPublicRefuseVideosEtSons(): void
    {
        self::outil('bash');
        $script = ROOT . '/publication/.github/scripts/garde-fou.sh';

        $propre = $this->dossierTemporaire();
        file_put_contents("$propre/README.md", '# MIA');
        exec('bash ' . escapeshellarg($script) . ' ' . escapeshellarg($propre) . ' 2>&1', $o, $code);
        $this->assertSame(0, $code, implode("\n", $o));

        foreach (['videos/notes.txt', 'public/intro.MP4', 'docs/son.m4a', 'docs/voix.wav'] as $intrus) {
            $d = $this->dossierTemporaire();
            @mkdir(dirname("$d/$intrus"), 0700, true);
            file_put_contents("$d/$intrus", 'x');
            $o = [];
            exec('bash ' . escapeshellarg($script) . ' ' . escapeshellarg($d) . ' 2>&1', $o, $code);
            $this->assertSame(1, $code, "le garde-fou doit refuser $intrus");
            $this->assertStringContainsString($intrus, implode("\n", $o));
        }
    }

    public function testLesVideosNEntrentPasDansLesImagesDocker(): void
    {
        $this->assertContains('videos/', self::lignes('.dockerignore'));
    }

    public function testAucunMediaVersionneHorsDuDossierVideos(): void
    {
        self::outil('git');
        exec('git -C ' . escapeshellarg(ROOT) . ' ls-files 2>/dev/null', $fichiers, $code);
        if ($code !== 0 || $fichiers === []) {
            self::markTestSkipped('pas de dépôt git');
        }
        $motif = '/\.(' . implode('|', self::MEDIAS) . ')$/i';
        $this->addToAssertionCount(1);
        foreach ($fichiers as $f) {
            if (preg_match($motif, $f)) {
                $this->assertStringStartsWith('videos/', $f, "média versionné hors de videos/ : $f");
            }
        }
    }
}
