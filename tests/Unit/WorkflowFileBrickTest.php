<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Brique « Fichier » du groupe Créer — cf. CLAUDE.md « Workflows ». Une
 * étape qui remet un fichier de TOUT format : un document rédigé (Word, PDF,
 * PowerPoint, Excel, Markdown) comme avant, ou un fichier brut (script
 * Python, JSON, CSV, HTML, toute extension de fichier texte) dont l'étape
 * écrit le contenu exact, recopié tel quel au téléchargement.
 */
final class WorkflowFileBrickTest extends TestCase
{
    private static function piece(string $format, string $name = 'Nettoyage'): array
    {
        return ['key' => 'p', 'name' => $name, 'form' => 'document', 'fields' => [], 'format' => $format];
    }

    private static function src(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testLeFormatAccepteToutFichierTexteMaisJamaisUnBinaire(): void
    {
        $this->assertSame('py', WorkflowSpec::docFormat('py'));
        $this->assertSame('py', WorkflowSpec::docFormat('.PY'));
        $this->assertSame('conf', WorkflowSpec::docFormat('conf'), 'Une extension texte hors catalogue reste acceptée.');
        $this->assertSame('md', WorkflowSpec::docFormat('md'));
        foreach (['', 'zip', 'exe', 'png', 'doc', '../x', '2026', ['py']] as $refuse) {
            $this->assertSame('docx', WorkflowSpec::docFormat($refuse), 'Refusé : ' . json_encode($refuse));
        }
    }

    public function testLaPieceGardeSonFormatEtNePorteLeModeleQueSurUnDocumentOffice(): void
    {
        $def = WorkflowSpec::normalize(['nodes' => [
            ['key' => 'code', 'type' => 'step', 'title' => 'Créer un fichier', 'instruction' => 'Écris le script.',
             'output' => ['key' => 'script', 'name' => 'Script', 'form' => 'document', 'format' => 'py', 'template' => true]],
            ['key' => 'cr', 'type' => 'step', 'title' => 'Compte rendu', 'instruction' => 'Rédige.',
             'output' => ['key' => 'cr', 'name' => 'Compte rendu', 'form' => 'document', 'format' => 'docx']],
        ]]);
        $this->assertSame('py', $def['nodes'][0]['output']['format']);
        $this->assertFalse($def['nodes'][0]['output']['template'], 'Le modèle de l’entreprise n’habille pas un script.');
        $this->assertTrue($def['nodes'][1]['output']['template']);
        $pieces = WorkflowSpec::pieces($def);
        $this->assertSame('document', $pieces['script']['form']);
        $this->assertSame('py', $pieces['script']['format']);
    }

    public function testFichierBrutOuDocumentRedige(): void
    {
        foreach (['py', 'json', 'csv', 'html', 'txt', 'conf'] as $f) {
            $this->assertTrue(ArtifactContract::isRawFile($f), $f);
        }
        foreach (['docx', 'pdf', 'pptx', 'xlsx', 'md', 'rtf', ''] as $f) {
            $this->assertFalse(ArtifactContract::isRawFile($f), $f);
        }
        $this->assertSame('python', ArtifactContract::languageFor('py'));
        $this->assertSame('json', ArtifactContract::languageFor('json'));
        $this->assertSame('text', ArtifactContract::languageFor('conf'));
        $this->assertSame('Python', ArtifactContract::formatLabel('py'));
        $this->assertSame('Word', ArtifactContract::formatLabel('docx'));
    }

    public function testLaConsigneDemandeLeContenuExactDansUnBlocNomme(): void
    {
        $i = ArtifactContract::instruction(self::piece('py'));
        $this->assertStringContainsString('FICHIER Python (.py)', $i);
        $this->assertStringContainsString("````python fichier=\"Nettoyage.py\"", $i);
        $this->assertStringContainsString('EXACT', $i);
        $this->assertStringNotContainsString('Markdown structuré', $i, 'Un script ne s’écrit pas en Markdown.');
        // Un document rédigé garde sa consigne.
        $this->assertStringContainsString('Markdown structuré', ArtifactContract::instruction(self::piece('md')));
    }

    public function testLaReponseSeLitBlocNommeBlocSeulOuReponseEntiere(): void
    {
        $code = "import csv\n\n# Nettoie le fichier\ndef run():\n    return 1";
        $nomme = ArtifactContract::extract(self::piece('py'), "Voici le script.\n\n````python fichier=\"Nettoyage.py\"\n{$code}\n````");
        $this->assertTrue($nomme['ok']);
        $this->assertSame($code, $nomme['content']['source']);
        $this->assertSame('python', $nomme['content']['language']);
        $this->assertSame('Nettoyage.py', $nomme['content']['filename']);

        $seul = ArtifactContract::extract(self::piece('py'), "Le script :\n```python\n{$code}\n```\nBonne journée.");
        $this->assertTrue($seul['ok']);
        $this->assertSame($code, $seul['content']['source'], 'La phrase autour ne fait pas partie du fichier.');

        $court = ArtifactContract::extract(self::piece('json'), '{"ok": true}');
        $this->assertTrue($court['ok'], 'Un fichier court est un fichier : pas de longueur minimale.');
        $this->assertSame('{"ok": true}', $court['content']['source']);
        $this->assertSame('json', $court['content']['language']);

        $this->assertFalse(ArtifactContract::extract(self::piece('py'), '')['ok']);
        $this->assertFalse(ArtifactContract::extract(self::piece('py'), "[Clé API OpenAI manquante. Configurez-la dans Admin → Clés API avant d'utiliser cet assistant.]")['ok'],
            'Une erreur du fournisseur n’est jamais le contenu d’un fichier.');
        $this->assertFalse(ArtifactContract::extract(self::piece('json'), '[Erreur OpenAI (503) : service indisponible]')['ok']);
        $this->assertTrue(ArtifactContract::extract(self::piece('json'), '["a", "b"]')['ok'], 'Un tableau JSON reste un fichier.');
        $this->assertSame('Python · 5 lignes', ArtifactContract::summary(self::piece('py'), $nomme['content']));
    }

    public function testLeFichierSeFabriqueTelQuel(): void
    {
        $source = "print('é')\n# Titre qui n'en est pas un";
        $file = FileFactory::build('Nettoyage.py', $source, 'python', 'Nettoyage.py', ['locale' => 'fr', 'author' => 'MIA']);
        $this->assertSame('Nettoyage.py', $file->name);
        $this->assertSame($source . "\n", $file->bytes);
    }

    public function testLeContenuSAfficheEnBlocJamaisEnMarkdown(): void
    {
        $html = WorkflowContentView::piece(self::piece('py'), ['format' => 'py', 'language' => 'python', 'source' => "# commentaire\nx = 1 < 2"]);
        $this->assertStringContainsString('wf-view-pre', $html);
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringContainsString('1 &lt; 2', $html);
    }

    public function testLEditeurProposeLaBriqueEtLeTypeDeFichier(): void
    {
        $js = self::src('public/assets/js/workflow-editor.js');
        $this->assertStringContainsString("sfile: true", $js, 'Une entrée « Un fichier » dans le groupe Créer.');
        $this->assertStringContainsString("preset && preset.file", $js);
        $this->assertStringContainsString('data-ofmt-select', $js);
        $this->assertStringContainsString('data-ofmt', $js);
        $this->assertStringContainsString("'__other'", $js, 'Toute autre extension s’écrit à la main.');
        $labels = WorkflowCatalog::labels();
        $this->assertSame('Python (.py)', $labels['fileFormats']['py']);
        $this->assertSame('Un fichier', $labels['createFile']);
        $this->assertSame(array_keys(WorkflowSpec::CREATE_KINDS), array_keys($labels['createKinds']), 'Un fichier n’est pas une création par outil.');
        $this->assertArrayHasKey('codefile', WorkflowCatalog::icons());
        // Mêmes formats rédigés partout (serveur, éditeur, rail, exécution).
        foreach (['public/assets/js/workflow-editor.js', 'public/assets/js/workflow-rail.js', 'public/assets/js/workflow-run.js'] as $f) {
            $this->assertStringContainsString("['docx', 'pdf', 'rtf', 'pptx', 'md', 'xlsx']", self::src($f), $f);
        }
    }

    public function testLesLibellesSontTraduits(): void
    {
        $client = require dirname(__DIR__, 2) . '/lang/en.client.php';
        foreach (['Créer un fichier', 'Type de fichier', 'Autre extension…', 'Extension du fichier', 'Fichier'] as $k) {
            $this->assertArrayHasKey($k, $client, $k);
        }
        $server = require dirname(__DIR__, 2) . '/lang/en.php';
        $this->assertArrayHasKey('Un fichier', $server);
    }
}
