<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Recherche Google Drive — la partie pure, et le câblage du parcours de
 * dossier.
 *
 * Ce que ce test protège, et qui est passé en production :
 *  - `name contains` de l'API Drive ne matche qu'en PRÉFIXE (documenté) et
 *    n'aplatit pas les accents ; envoyer la phrase de l'utilisateur telle
 *    quelle en un seul littéral ne trouvait quasiment jamais rien ;
 *  - un DOSSIER ressortait de la recherche avec `lisible: false` et aucun
 *    moyen de l'ouvrir : le modèle en concluait qu'il était inaccessible.
 */
final class DriveSearchTest extends TestCase
{
    // ---------- Normalisation ----------

    public function testLaNormalisationIgnoreCasseAccentsEtSeparateurs(): void
    {
        $this->assertSame(
            'reunion equipe 2026 docx',
            UserDriveService::normalizeForSearch('Réunion_Équipe-2026.docx')
        );
        $this->assertSame('cout du projet', UserDriveService::normalizeForSearch('Coût du projet'));
        $this->assertSame('ca 2025', UserDriveService::normalizeForSearch('  ÇA   2025  '));
        $this->assertSame('', UserDriveService::normalizeForSearch('—  ***  '));
    }

    public function testLesDeuxCotesSontNormalisesDeLaMemeFacon(): void
    {
        // C'est tout l'intérêt : la requête de l'utilisateur et le nom renvoyé
        // par Google passent par la MÊME moulinette, sinon le filtrage local
        // comparerait des choses non comparables.
        $this->assertSame(
            UserDriveService::normalizeForSearch('BUDGET Prévisionnel'),
            UserDriveService::normalizeForSearch('budget previsionnel')
        );
    }

    // ---------- Jetons ----------

    public function testLesMotsVidesSontEcartes(): void
    {
        // Joints par `and`, ils EXCLUENT : exiger que le nom contienne « le »,
        // « de » et « la » ne laissait passer presque aucun fichier.
        $tokens = array_column(UserDriveService::searchTokens("le compte rendu de la réunion d'équipe"), 'norme');
        $this->assertSame(['compte', 'rendu', 'reunion', 'equipe'], $tokens);
    }

    public function testLaFormeAccentueeEstConservee(): void
    {
        // Le nom stocké chez Google est accentué : perdre la forme d'origine
        // ferait rater « Réunion » sur toutes les recherches serveur.
        $tokens = UserDriveService::searchTokens('Réunion');
        $this->assertSame('Réunion', $tokens[0]['brut']);
        $this->assertSame('reunion', $tokens[0]['norme']);
    }

    public function testLesJetonsSontDedupliquesEtBornes(): void
    {
        $tokens = UserDriveService::searchTokens('budget Budget BUDGET');
        $this->assertCount(1, $tokens);

        $longue = UserDriveService::searchTokens('alpha bravo charlie delta echo foxtrot golf hotel india juliett');
        $this->assertCount(8, $longue, 'Au-delà, la requête Drive enfle sans rien gagner.');
    }

    public function testLesJetonsDUnSeulCaractereSontIgnores(): void
    {
        $tokens = array_column(UserDriveService::searchTokens("O'Brien x"), 'norme');
        $this->assertSame(['brien'], $tokens);
    }

    // ---------- Requête Drive ----------

    public function testChaqueJetonEstCherchePourLesDeuxFormes(): void
    {
        $q = UserDriveService::buildTokenQuery(UserDriveService::searchTokens('réunion'), true);
        $this->assertStringContainsString("name contains 'réunion'", $q);
        $this->assertStringContainsString("name contains 'reunion'", $q);
        $this->assertStringContainsString("fullText contains 'réunion'", $q);
        $this->assertStringContainsString('trashed=false', $q);
    }

    public function testTousLesMotsOuAuMoinsUn(): void
    {
        $tokens = UserDriveService::searchTokens('budget projet');
        $this->assertStringContainsString(') and (', UserDriveService::buildTokenQuery($tokens, true));
        $this->assertStringContainsString(') or (', UserDriveService::buildTokenQuery($tokens, false));
    }

    public function testLeModeNomSeulNElargitPasAuContenu(): void
    {
        // Résoudre « le compte rendu » désigne UN fichier : élargir au contenu
        // ramènerait tous ceux qui en PARLENT, et une désignation claire
        // deviendrait une ambiguïté.
        $q = UserDriveService::buildTokenQuery(UserDriveService::searchTokens('budget'), true, '', true);
        $this->assertStringContainsString("name contains 'budget'", $q);
        $this->assertStringNotContainsString('fullText', $q);
    }

    public function testLesApostrophesEtAntislashsSontEchappes(): void
    {
        // Un nom de fichier non échappé casse la requête Drive (HTTP 400).
        $q = UserDriveService::buildTokenQuery([['brut' => "d'été", 'norme' => "d'été"]], true);
        $this->assertStringContainsString("name contains 'd\\'été'", $q);

        $q2 = UserDriveService::buildTokenQuery([['brut' => 'a\\b', 'norme' => 'a\\b']], true);
        $this->assertStringContainsString("name contains 'a\\\\b'", $q2);
    }

    public function testLaClauseSupplementaireEstAjoutee(): void
    {
        $q = UserDriveService::buildTokenQuery(
            UserDriveService::searchTokens('factures'),
            true,
            "mimeType='" . UserDriveService::MIME_FOLDER . "'",
            true
        );
        $this->assertStringEndsWith("and mimeType='" . UserDriveService::MIME_FOLDER . "'", $q);
    }

    public function testUneListeVideNeProduitPasDeClauseOrpheline(): void
    {
        $this->assertSame('trashed=false', UserDriveService::buildTokenQuery([], true));
    }

    // ---------- Câblage ----------

    public function testLaRechercheNEnvoiePlusLaPhraseEnUnSeulLitteral(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        // L'ancienne requête, cause directe des « fichiers non retrouvés ».
        $this->assertStringNotContainsString("(name contains '{\$safe}' or fullText contains '{\$safe}')", $src);
        $this->assertStringNotContainsString("name contains '{\$safe}' and trashed=false", $src);
        // Le balayage local est le seul à savoir chercher au milieu d'un mot.
        $this->assertStringContainsString('private function scanFiles', $src);
        $this->assertStringContainsString('nextPageToken', $src);
    }

    public function testUnNomDeDossierPrisPourUnIdRetombeSurLaRecherche(): void
    {
        // « Comptabilite » (un mot, ≥ 10 caractères, sans accent) passe le motif
        // d'id générique : sans ce repli, folderRef partait en 404 opaque.
        $this->assertTrue(UserDriveService::isBareId('Comptabilite'));
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $folderRef = substr($src, strpos($src, 'public function folderRef'));
        $folderRef = substr($folderRef, 0, strpos($folderRef, 'private function folderName'));
        $this->assertStringContainsString('self::isBareId($input)', $folderRef);
        $this->assertStringContainsString('self::MSG_NOT_FOUND', $folderRef);
    }

    public function testUnDossierSeParcourtAuLieuDeSeLire(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $this->assertStringContainsString('public function listFolder(', $src);
        // `dossier` distingue un dossier d'un fichier illisible : sans ce champ,
        // `lisible: false` seul faisait conclure à un accès refusé.
        $this->assertStringContainsString("'dossier' => \$isFolder,", $src);
        $this->assertStringContainsString("!\$isFolder && (isset(self::EXPORT_MAP[\$mime])", $src);
    }

    public function testUnNomDeDossierNeFinitPasEnFichierIntrouvable(): void
    {
        // Les dossiers sont exclus d'office de la résolution d'un FICHIER :
        // « aucun fichier de ce nom » se lisait alors comme « ce dossier
        // n'existe pas », pile le symptôme rapporté.
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $this->assertStringContainsString('private function folderExists(', $src);
        $this->assertStringContainsString('est un DOSSIER, pas un fichier', $src);
        // Et lire un dossier par son id le dit aussi, au lieu du générique
        // « type de fichier non pris en charge », qui se lit comme un refus.
        $this->assertStringContainsString('il ne se lit pas, il se parcourt', $src);
    }

    public function testLOutilDeListeEstExposeEtTrace(): void
    {
        $chat = AgentSource::chat();
        $this->assertStringContainsString("'name'        => 'lister_dossier_google_drive'", $chat);
        $this->assertStringContainsString("\$toolHandlers['lister_dossier_google_drive']", $chat);
        $this->assertStringContainsString("'phase' => 'drive_list'", $chat);
        $this->assertStringContainsString("'drive_list'                => 'Contenu d\\'un dossier Google Drive'", $chat);
        $this->assertStringContainsString("'lister_dossier_google_drive' => ['t' => 'drive_list'", $chat);
        // Le modèle doit savoir qu'un dossier `lisible: false` n'est pas un échec.
        $this->assertStringContainsString('il se PARCOURT, il ne se lit pas', $chat);

        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString('drive_list:', $js, 'Phase sans libellé côté client = bandeau muet.');
    }

    // ---------- Navigation prioritaire sur la recherche ----------

    public function testUneRechercheSePorteBornerAUnDossier(): void
    {
        // Le bug rapporté : « INITECH » cherché sur TOUT le Drive alors que le
        // dossier parent (01-CLIENTS) était connu — le bruit passe devant.
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $this->assertStringContainsString(
            'public function search(string $query, int $limit = 10, string $dansDossier = \'\', string $type = \'\')',
            $src
        );
        $this->assertStringContainsString('private function searchInFolder(', $src);

        $chat = AgentSource::chat();
        // Le dossier imposé par les filtres d'une brique de workflow passe avant
        // celui que le modèle demande (SearchScope).
        $this->assertStringContainsString("\$dossier = \$scope->driveFolder() !== '' ? \$scope->driveFolder() : trim((string)(\$args['dossier'] ?? ''));", $chat);
    }

    public function testPlusieursParentsTiennentDansUnSeulAppel(): void
    {
        // Un dossier par appel aurait coûté 200 requêtes sous « 01-CLIENTS » :
        // Drive accepte plusieurs `in parents` dans le même `q`, donc un
        // NIVEAU = un appel.
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $this->assertStringContainsString('private static function parentsClause(', $src);
        $walk = substr($src, strpos($src, 'private function searchInFolder('));
        $walk = substr($walk, 0, strpos($walk, 'private static function parentsClause('));
        $this->assertStringContainsString('self::SUBTREE_MAX_FOLDERS', $walk, 'Une descente non bornée peut ratisser tout le Drive.');
        $this->assertStringContainsString('self::SUBTREE_MAX_DEPTH', $walk);
        // La descente ne doit pas ramener la corbeille.
        $this->assertStringContainsString("and trashed=false and mimeType='", $walk);
    }

    public function testUnCheminDescendEnUnSeulAppelDOutil(): void
    {
        // « 01-CLIENTS/INITECH/03-CR et echanges » : trois appels d'outil, donc
        // trois rounds du budget TOOL_MAX_ROUNDS, pour une arborescence que
        // l'utilisateur avait déjà donnée.
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $this->assertStringContainsString('private function resolveFolderPath(', $src);
        $this->assertStringContainsString('private function childFolder(', $src);
        $this->assertStringContainsString('self::PATH_MAX_SEGMENTS', $src);
        // Une URL contient des « / » : la découper produirait des segments absurdes.
        $path = substr($src, strpos($src, 'private function resolveFolderPath('));
        $path = substr($path, 0, strpos($path, 'private function childFolder('));
        $this->assertStringContainsString('self::parseFileInput($input) !== null', $path);
        // Drive autorise le « / » dans un nom : rattrapage prévu.
        $this->assertStringContainsString('$this->folderRef($input)', $path);
    }

    public function testUnSegmentDeCheminExigeUneCorrespondanceExacte(): void
    {
        // Descendre dans la mauvaise branche fait échouer la suite du chemin
        // sans dire où : on demande plutôt.
        $src   = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $chunk = substr($src, strpos($src, 'private function childFolder('), 2600);
        $this->assertStringContainsString('normalizeForSearch((string)($f[\'name\'] ?? \'\')) === $cible', $chunk);
        $this->assertStringContainsString('Plusieurs sous-dossiers', $chunk);
        // L'erreur liste ce qui existe : sans ça le modèle redevine à l'aveugle.
        $this->assertStringContainsString('Sous-dossiers disponibles', $chunk);
    }

    public function testUnDossierTronqueResteAtteignable(): void
    {
        // 200 clients listés par ordre alphabétique et coupés à 50 : « INITECH »
        // était invisible, et `tronque: true` n'offrait AUCUNE suite.
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $this->assertStringContainsString(
            'public function listFolder(string $input, int $limit = 50, string $filtre = \'\')',
            $src
        );
        $this->assertStringContainsString("'total'    => \$total,", $src);
        $this->assertStringContainsString('private static function filterByName(', $src);
        // Le listing est paginé : une page manquante se lit comme un dossier
        // plus petit qu'il n'est.
        $this->assertStringContainsString('self::CHILDREN_MAX_PAGES', $src);

        $chat = AgentSource::chat();
        $this->assertStringContainsString("(string)(\$args['filtre'] ?? '')", $chat);
    }

    public function testLaGuidanceImposeLaNavigationAvantLaRechercheGlobale(): void
    {
        $chat = AgentSource::chat();
        $this->assertStringContainsString('NAVIGUER PLUTÔT QUE CHERCHER', $chat);
        $this->assertStringContainsString('jamais une recherche globale d\'abord', $chat);
        $this->assertStringContainsString('n\'annonce JAMAIS qu\'un élément est', $chat);
        $this->assertStringContainsString('relance avec `filtre` sur le nom cherché', $chat);
    }

    public function testLaPasseNomEstToujoursJoueeEtFusionneeEnPremier(): void
    {
        // LE bug : `(name or fullText)` en une seule requête, tronquée côté
        // Google (qui IGNORE orderBy dès que q contient fullText). Un dossier
        // nommé exactement « INITECH » disparaissait derrière trente documents
        // qui se contentent de mentionner INITECH — et aucun tri local ne peut
        // rattraper ce que la page n'a pas ramené.
        $src   = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $chunk = substr($src, strpos($src, 'public function search(string $query'), 2800);

        $posNom     = strpos($chunk, 'buildTokenQuery($tokens, true, $extra, true)');
        $posContenu = strpos($chunk, 'buildTokenQuery($tokens, true, $extra)');
        $this->assertNotFalse($posNom, 'Passe nom-seul absente.');
        $this->assertNotFalse($posContenu, 'Passe contenu absente.');
        $this->assertLessThan($posContenu, $posNom, 'Le nom doit être interrogé AVANT le contenu.');

        // Fusion, pas remplacement : une passe non vide ne doit pas masquer
        // les suivantes (c'est exactement ce qui évinçait les dossiers).
        $this->assertStringContainsString('self::mergeById(', $chunk);
        $this->assertStringContainsString('private static function mergeById(', $src);
        $this->assertStringContainsString('self::CONTENT_PAGE', $chunk);
    }

    public function testUnDossierNePeutRemonterQueParSonNom(): void
    {
        // Un dossier n'a aucun contenu indexé : il est le premier évincé d'une
        // page dominée par les correspondances fullText. D'où le filtre de type.
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $this->assertStringContainsString('private static function typeClause(', $src);
        $this->assertStringContainsString("'dossier' => \"mimeType='\" . self::MIME_FOLDER . \"'\"", $src);
        $this->assertStringContainsString("'fichier' => \"mimeType!='\" . self::MIME_FOLDER . \"'\"", $src);

        $chat = AgentSource::chat();
        $this->assertStringContainsString("'enum'        => ['dossier', 'fichier']", $chat);
        $this->assertStringContainsString("(string)(\$args['type'] ?? '')", $chat);
        $this->assertStringContainsString('passe `type: dossier`', $chat);
    }

    public function testLaFusionNeDupliquePasEtPreserveLOrdre(): void
    {
        $src   = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $chunk = substr($src, strpos($src, 'private static function mergeById('), 700);
        // Sans dédoublonnage, un fichier trouvé par son nom ET son contenu
        // occuperait deux places sur les dix rendues au modèle.
        $this->assertStringContainsString('isset($vus[$id])', $chunk);
        // Réordonner ici casserait la priorité nom-avant-contenu, que
        // rankByRelevance utilise pour départager les égalités.
        $this->assertStringNotContainsString('usort', $chunk);
    }

    public function testLeDossierParcouruEstMemorise(): void
    {
        // Sans ça, « et le compte rendu qui est dedans ? » au tour suivant
        // n'a plus rien pour retrouver le dossier (les tool_result ne sont
        // jamais réinjectés dans l'historique).
        $entry = ConnectorContext::entryForTool(
            'lister_dossier_google_drive',
            ['dossier' => 'Factures'],
            ['dossier' => ['id' => '1AbC', 'nom' => 'Factures'], 'elements' => []]
        );
        $this->assertNotNull($entry);
        $this->assertSame('drive_folder', $entry['kind']);
        $this->assertSame('drive', $entry['connector']);
        $this->assertSame('1AbC', $entry['ref']);
        $this->assertSame('Factures', $entry['name']);

        // Chemin connu : il situe le dossier, « INITECH » seul oblige à redeviner.
        $profond = ConnectorContext::entryForTool(
            'lister_dossier_google_drive',
            ['dossier' => '01-CLIENTS/INITECH'],
            ['dossier' => ['id' => '2X', 'nom' => 'INITECH'], 'chemin' => '01-CLIENTS/INITECH', 'elements' => []]
        );
        $this->assertSame('01-CLIENTS/INITECH', $profond['name']);
    }

    public function testUneListeEnErreurNeMemoriseRien(): void
    {
        $this->assertNull(ConnectorContext::entryForTool(
            'lister_dossier_google_drive',
            ['dossier' => 'Factures'],
            ['error' => 'Aucun dossier « Factures » trouvé dans ce Drive.']
        ));
    }
}
