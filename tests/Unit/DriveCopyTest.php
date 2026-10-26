<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Duplication d'un fichier Google Drive (`files.copy`), sous approbation.
 *
 * Pourquoi une copie CÔTÉ GOOGLE et non un « lire puis recréer » : la lecture
 * passe par un export docx aplati par FileExtractor — le contournement
 * perdrait mise en forme, images et tableaux. D'où aussi la consigne explicite
 * au modèle de ne jamais le simuler.
 *
 * Points verrouillés :
 *  - le scope est `drive.file` (accès PAR FICHIER) et non `drive` complet ;
 *  - la comparaison de scopes reste EXACTE : `.../auth/drive` est un préfixe
 *    de `drive.file` — un str_contains sur le scope complet ferait passer
 *    n'importe quelle connexion pour autorisée ;
 *  - `parents` est toujours posé explicitement, sinon la carte d'approbation
 *    ne peut pas annoncer où atterrit la copie ;
 *  - le 403 de `drive.file` sur un fichier que l'app n'a pas créé est traduit
 *    en message explicite, PAS en « reconnectez-vous » (qui n'y changerait rien).
 */
final class DriveCopyTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------------
    // Scope
    // ---------------------------------------------------------------------

    public function testLeScopeEstDriveFileEtNonDriveComplet(): void
    {
        $this->assertContains(UserDriveService::SCOPE_FILE, GoogleDriveOAuth::SCOPES);
        $this->assertSame('https://www.googleapis.com/auth/drive.file', UserDriveService::SCOPE_FILE);
        $this->assertNotContains(
            'https://www.googleapis.com/auth/drive',
            GoogleDriveOAuth::SCOPES,
            'drive complet donnerait l\'écriture sur TOUS les fichiers ; drive.file suffit à déposer une copie.'
        );
    }

    public function testLaComparaisonDeScopeResteExacte(): void
    {
        $this->assertTrue(UserDriveService::grantsCopy(
            'https://www.googleapis.com/auth/drive.readonly ' . UserDriveService::SCOPE_FILE
        ));
        // Le piège : `.../auth/drive` est un préfixe de `drive.file`.
        $this->assertFalse(
            UserDriveService::grantsCopy('https://www.googleapis.com/auth/drive.readonly'),
            'Une connexion en lecture seule ne doit jamais passer pour autorisée à copier.'
        );
        $this->assertFalse(UserDriveService::grantsCopy('https://www.googleapis.com/auth/drive.metadata'));
        $this->assertFalse(UserDriveService::grantsCopy(''));
    }

    // ---------------------------------------------------------------------
    // Fonctions pures
    // ---------------------------------------------------------------------

    public function testNomParDefautDeLaCopie(): void
    {
        $this->assertSame('Copie de Rapport Q3', UserDriveService::defaultCopyName('Rapport Q3'));
        $this->assertSame('Copie de Devis.pdf', UserDriveService::defaultCopyName('  Devis.pdf '));
        // Borne Drive : 255 caractères, tronquer vaut mieux qu'un 400 opaque.
        $this->assertSame(255, mb_strlen(UserDriveService::defaultCopyName(str_repeat('é', 400))));
    }

    public function testLApercuNommeLaCopieSonEmplacementEtRassureSurLOriginal(): void
    {
        $preview = UserDriveService::actionPreview(
            'dupliquer_google_drive',
            ['fichier' => 'x'],
            ['nom' => 'Modèle de CR', 'url' => 'https://docs.google.com/document/d/x/edit',
             'copie' => 'Copie de Modèle de CR', 'destination' => 'Comptes rendus']
        );
        $this->assertSame('Dupliquer un fichier Drive', $preview['action']);
        $this->assertSame('Modèle de CR', $preview['fichier']);
        $impacts = implode(' ', $preview['impacts']);
        $this->assertStringContainsString('Copie de Modèle de CR', $impacts);
        $this->assertStringContainsString('Comptes rendus', $impacts);
        $this->assertStringContainsString('origine n\'est pas modifié', $impacts,
            'Seule écriture de la famille qui ne touche pas l\'original : c\'est ce qui rassure avant d\'approuver.');
    }

    // ---------------------------------------------------------------------
    // Comportement de copyFile (sur la source : pas de réseau en test)
    // ---------------------------------------------------------------------

    public function testCopyFilePoseTonjoursLesParentsEtTraduitLe403(): void
    {
        $src  = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $copy = $this->extraire($src, 'public function copyFile(', "\n    /** Minuscules sans accents");

        $this->assertStringContainsString("'/copy?'", $copy, 'La copie passe par files.copy, pas par une relecture.');
        $this->assertStringContainsString("'parents' => \$parents", $copy,
            'parents explicite : sans lui, l\'emplacement dépend de Google et la carte ne peut pas l\'annoncer.');
        $this->assertStringContainsString("'root'", $copy,
            'Source sans parent visible (« Partagé avec moi ») : la racine est un emplacement prévisible.');
        // Le 403 « drive.file ne couvre pas ce fichier » ne doit pas conseiller
        // une reconnexion inutile.
        $this->assertStringContainsString('self::MSG_COPY_REFUSED', $copy);
        $this->assertStringContainsString('MSG_RECONNECT_WRITE', $copy);
        $this->assertStringNotContainsString('reconnect', mb_strtolower(UserDriveService::MSG_COPY_REFUSED));
    }

    public function testAucuneSuppressionAjoutee(): void
    {
        // La famille Drive ne supprime toujours rien — la duplication non plus.
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $code = '';
        foreach (token_get_all($src) as $token) {
            $code .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }
        $this->assertStringNotContainsString("'DELETE'", $code);
        $this->assertStringNotContainsString("'trashed' =>", $code);
    }

    // ---------------------------------------------------------------------
    // Câblage
    // ---------------------------------------------------------------------

    public function testLOutilEstExposeGardeEtExecutable(): void
    {
        $src = AgentSource::chat();

        $this->assertStringContainsString("'name'        => 'dupliquer_google_drive'", $src);
        $this->assertStringContainsString("case 'dupliquer_google_drive':", $src, 'Pas exécutable après approbation.');
        $this->assertStringContainsString("\$queueConnectorWrite('dupliquer_google_drive'", $src,
            'La duplication passe par la file d\'approbation, jamais exécutée pendant le stream.');
        $this->assertArrayHasKey('dupliquer_google_drive', ChatController::ACTION_LABELS);
        $this->assertStringContainsString("'phase' => 'drive_copy'", $src);

        // Définitions, handlers et guidance : les trois gardés par le scope
        // (la guidance doit ANNONCER la capacité, sinon le modèle ne l'utilise
        // pas et bricole une relecture).
        $this->assertSame(2, substr_count($src, '$drive->canCopy()'));
        $this->assertSame(1, substr_count($src, '$driveService->canCopy()'));

        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString('drive_copy:', $js, 'Une phase sans libellé front n\'affiche rien.');
    }

    public function testLaGuidanceInterditDeSimulerUneCopie(): void
    {
        $src = AgentSource::chat();
        // Sans cette consigne, le modèle « copie » en lisant puis recréant —
        // et le document perd toute sa mise en forme, sans que rien ne le dise.
        $this->assertStringContainsString('Ne simule JAMAIS une', $src);
        $this->assertStringContainsString('modifie la COPIE', $src);
    }

    public function testLeMessageFinalPointeLaCopieEtNommeLaSource(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        // Libellés traduits (lot 3) : on asserte la CLÉ, jamais le rendu.
        $this->assertStringContainsString("=> t('dupliqué')", $src);
        $this->assertStringContainsString("copie de « {source} » dans « {dossier} »", $src,
            'Deux fichiers de même nom seraient indistinguables sans nommer la source.');
    }

    public function testLaPageConnexionsAfficheLaCapacite(): void
    {
        $ctrl = (string)file_get_contents(ROOT . '/src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString('UserDriveService::grantsCopy($scope)', $ctrl);

        $tpl = (string)file_get_contents(ROOT . '/templates/settings/connections.php');
        $this->assertStringContainsString('$driveCanCopy', $tpl);
        $this->assertStringContainsString('Dupliquer', $tpl);
    }

    /** Extrait la portion de source entre deux marqueurs (bornes exclues). */
    private function extraire(string $src, string $de, string $a): string
    {
        $debut = strpos($src, $de);
        $this->assertNotFalse($debut, 'Marqueur introuvable : ' . $de);
        $fin = strpos($src, $a, $debut + strlen($de));
        $this->assertNotFalse($fin, 'Marqueur introuvable : ' . $a);
        return substr($src, $debut, $fin - $debut);
    }
}
