<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Rangement Google Drive : renommer / déplacer un fichier (métadonnées),
 * toujours sous approbation.
 *
 * Points verrouillés — chacun correspond à un piège déjà payé ailleurs :
 *
 *  - le SCOPE est `drive.metadata`, pas `drive` complet : renommer et déplacer
 *    ne touchent que `name`/`parents`, `drive` ouvrirait l'écriture de tout
 *    contenu ;
 *  - la comparaison de scopes est EXACTE et déclarée UNE SEULE FOIS (socle
 *    Google) : `.../auth/drive.metadata` est un PRÉFIXE de
 *    `drive.metadata.readonly`, et `.../auth/drive` de `drive.readonly` — un
 *    `str_contains` exposerait les outils à une connexion en lecture seule ;
 *  - aucun outil ne supprime : Google n'offre pas de demi-droit
 *    (`trashed` est une métadonnée), ce sont des garde-fous applicatifs qui
 *    tiennent — comme pour Google Tasks ;
 *  - le déplacement retire l'ancien parent : sans `removeParents`, Drive
 *    AJOUTE un emplacement et le fichier vit aux deux endroits.
 *
 * Tout est vérifié sans réseau : constantes, fonctions pures et câblage source.
 */
final class DriveOrganizeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    public function testLeScopeDemandeEstMetadataEtNonDriveComplet(): void
    {
        $this->assertContains(UserDriveService::SCOPE_METADATA, GoogleDriveOAuth::SCOPES);
        $this->assertNotContains(
            'https://www.googleapis.com/auth/drive',
            GoogleDriveOAuth::SCOPES,
            'Le scope drive COMPLET ouvrirait l\'écriture du contenu de tous les fichiers — bien au-delà d\'un renommage.'
        );
        // La lecture reste ce qu'elle était : aucune connexion existante n'est
        // dégradée par l'ajout.
        $this->assertContains('https://www.googleapis.com/auth/drive.readonly', GoogleDriveOAuth::SCOPES);
    }

    public function testLaComparaisonDeScopesEstExacteEtAttrapeLePiegeDePrefixe(): void
    {
        $metadata = UserDriveService::SCOPE_METADATA;

        $this->assertTrue(UserDriveService::grantsOrganize(
            'https://www.googleapis.com/auth/drive.readonly ' . $metadata . ' openid'
        ));
        // LE piège : lecture seule des métadonnées, dont le scope CONTIENT
        // celui du rangement comme préfixe.
        $this->assertFalse(
            UserDriveService::grantsOrganize('https://www.googleapis.com/auth/drive.metadata.readonly'),
            'drive.metadata.readonly ne doit JAMAIS passer pour drive.metadata (préfixe).'
        );
        $this->assertFalse(UserDriveService::grantsOrganize('https://www.googleapis.com/auth/drive.readonly'));
        $this->assertFalse(UserDriveService::grantsOrganize(''));

        // Les autres capacités passent par la même comparaison.
        $this->assertTrue(UserDriveService::grantsDocs(UserDriveService::SCOPE_DOCS . ' openid'));
        $this->assertFalse(UserDriveService::grantsDocs('openid email'));
        $this->assertTrue(UserDriveService::grantsSheets('openid ' . UserDriveService::SCOPE_SHEETS));
    }

    public function testLaComparaisonDeScopesEstDeclareeUneSeuleFois(): void
    {
        foreach ([UserDriveService::class, UserTasksService::class] as $classe) {
            $this->assertSame(
                GoogleUserApiService::class,
                (new ReflectionMethod($classe, 'scopeGranted'))->getDeclaringClass()->getName(),
                $classe . ' redéclare scopeGranted() : le piège de préfixe se rejouerait à moitié.'
            );
        }
        // L'appelant historique (page Connexions) reste servi par héritage.
        $this->assertTrue(UserTasksService::scopeGranted('a b', 'b'));
    }

    // ---------------------------------------------------------------------
    // Fonctions pures
    // ---------------------------------------------------------------------

    public function testSanitizeFileNameBorneEtNettoie(): void
    {
        $this->assertSame('Rapport Q3', UserDriveService::sanitizeFileName("  Rapport\tQ3 \n"));
        // Séparateurs de chemin neutralisés : le nom est réaffiché dans la
        // carte d'approbation et dans la conversation.
        $this->assertSame('Factures-2026', UserDriveService::sanitizeFileName('Factures/2026'));
        $this->assertSame('a-b', UserDriveService::sanitizeFileName('a\\b'));
        $this->assertSame(255, mb_strlen(UserDriveService::sanitizeFileName(str_repeat('é', 400))));

        $this->expectException(RuntimeException::class);
        UserDriveService::sanitizeFileName("   \n ");
    }

    public function testUneUrlDeDossierEstReconnue(): void
    {
        // Drive n'utilise pas le motif `/d/` pour les dossiers : sans ce cas,
        // coller le lien du dossier de destination échouait.
        $this->assertSame(
            '1AbCdEfGhIjKlMnO',
            UserDriveService::parseFileInput('https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnO?usp=sharing')
        );
        // Les formes historiques restent reconnues.
        $this->assertSame(
            '1AbCdEfGhIjKlMnO',
            UserDriveService::parseFileInput('https://docs.google.com/document/d/1AbCdEfGhIjKlMnO/edit')
        );
        $this->assertNull(UserDriveService::parseFileInput('Factures'));
    }

    public function testLApercuNommeLeFichierEtLaDestination(): void
    {
        $ref = ['nom' => 'Devis Globex.pdf', 'url' => 'https://drive.google.com/file/d/x/view', 'type' => 'PDF'];

        $renommage = UserDriveService::actionPreview(
            'renommer_google_drive',
            ['fichier' => 'x', 'nouveau_nom' => 'Devis Globex 2026.pdf'],
            $ref
        );
        $this->assertSame('Renommer un fichier Drive', $renommage['action']);
        $this->assertSame('Devis Globex.pdf', $renommage['fichier']);
        $this->assertStringContainsString('Devis Globex 2026.pdf', implode(' ', $renommage['impacts']));
        $this->assertStringContainsString('contenu du fichier n\'est pas modifié', implode(' ', $renommage['impacts']));

        $deplacement = UserDriveService::actionPreview(
            'deplacer_google_drive',
            ['fichier' => 'x', 'dossier' => 'Factures'],
            $ref + ['destination' => 'Factures 2026', 'dossier' => 'Mon Drive']
        );
        $impacts = implode(' ', $deplacement['impacts']);
        $this->assertStringContainsString('Factures 2026', $impacts, 'La carte doit nommer la destination RÉSOLUE, pas la saisie du modèle.');
        $this->assertStringContainsString('Mon Drive', $impacts, 'Et dire d\'où le fichier part.');
    }

    // ---------------------------------------------------------------------
    // Sécurité : rien ne supprime
    // ---------------------------------------------------------------------

    public function testAucuneSuppressionNiMiseALaCorbeille(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        // Sur le CODE seul : les commentaires expliquent justement pourquoi la
        // corbeille reste hors de portée.
        $code = '';
        foreach (token_get_all($src) as $token) {
            $code .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }
        $this->assertStringNotContainsString("'DELETE'", $code, 'Aucun appel DELETE : le connecteur ne supprime rien.');
        $this->assertStringNotContainsString('trashed=true', $code);
        $this->assertStringNotContainsString("'trashed' =>", $code, 'La corbeille n\'est jamais écrite (elle n\'est lue que comme filtre de recherche).');
    }

    public function testLeDeplacementRetireLAncienParent(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $move = $this->extraire($src, 'public function moveFile(', "\n    /**");
        $this->assertStringContainsString('removeParents', $move,
            'Sans removeParents, Drive AJOUTE un emplacement : le fichier vivrait aux deux endroits.');
        $this->assertStringContainsString('addParents', $move);
        // Corps non vide : json_encode([]) produit `[]`, refusé par Drive.
        $this->assertStringContainsString("['name' => \$ref['nom']]", $move);
    }

    // ---------------------------------------------------------------------
    // Câblage
    // ---------------------------------------------------------------------

    public function testLesDeuxOutilsSontExposesGardesEtExecutables(): void
    {
        $src = AgentSource::chat();

        // Exposition conditionnée au scope, handlers, exécution après clic.
        $this->assertStringContainsString("'name'        => 'renommer_google_drive'", $src);
        $this->assertStringContainsString("'name'        => 'deplacer_google_drive'", $src);
        // Trois endroits, tous nécessaires : définitions d'outils, handlers, et
        // guidance (qui doit ANNONCER l'incapacité quand le scope manque, sinon
        // l'assistant promet un rangement impossible).
        $this->assertSame(2, substr_count($src, '$drive->canOrganize()'),
            'Définitions ET guidance doivent être gardées par le scope.');
        $this->assertSame(1, substr_count($src, '$driveService->canOrganize()'),
            'Les handlers ne sont enregistrés que si le scope est accordé.');
        foreach (['renommer_google_drive', 'deplacer_google_drive'] as $outil) {
            $this->assertStringContainsString("case '" . $outil . "':", $src, $outil . ' : pas exécutable après approbation.');
            $this->assertStringContainsString("'" . $outil . "'", $src);
            $this->assertArrayHasKey($outil, ChatController::ACTION_LABELS, $outil . ' : absent de la trace d\'actions.');
        }

        // Les deux passent par la file d'approbation, jamais exécutés pendant
        // le stream.
        $this->assertSame(2, substr_count($src, "\$queueConnectorWrite('renommer_google_drive'")
            + substr_count($src, "\$queueConnectorWrite('deplacer_google_drive'"));
    }

    public function testLaPhaseDeRangementEstDistincteDeLEcriture(): void
    {
        // Pendant la préparation, MIA ne fait que résoudre le fichier et le
        // dossier : afficher « Écriture dans votre Google Drive… » ferait
        // croire que quelque chose a déjà été modifié, avant même la carte.
        $src = AgentSource::chat();
        $this->assertSame(2, substr_count($src, "'phase' => 'drive_organize'"));

        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString('drive_organize:', $js, 'Une phase sans libellé côté front n\'affiche rien.');
    }

    public function testLeMessageFinalDitLeTypeReelEtLeBonVerbe(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        // Libellés traduits (lot 3) : on asserte la CLÉ, jamais le rendu.
        $this->assertStringContainsString("=> t('renommé')", $src);
        $this->assertStringContainsString("=> t('déplacé')", $src);
        // Un PDF déplacé ne doit pas être annoncé « Google Doc modifié ».
        $this->assertStringContainsString("(string)(\$result['type'] ?? t('Fichier Drive'))", $src);
    }

    public function testLaPageConnexionsUtiliseLaMemeComparaison(): void
    {
        $ctrl = (string)file_get_contents(ROOT . '/src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString('UserDriveService::grantsOrganize($scope)', $ctrl);
        $this->assertStringNotContainsString('str_contains($scope, UserDriveService::', $ctrl,
            'Un str_contains ici afficherait « Renommer ✓ » sur une connexion en lecture seule.');

        $tpl = (string)file_get_contents(ROOT . '/templates/settings/connections.php');
        $this->assertStringContainsString('Renommer / déplacer', $tpl);
        $this->assertStringContainsString('$driveCanOrganize', $tpl);
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
