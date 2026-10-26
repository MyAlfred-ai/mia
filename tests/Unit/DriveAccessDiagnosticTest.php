<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pourquoi le connecteur Google Drive répondait « je n'ai pas accès » alors que
 * le compte était le bon et le dossier bien partagé.
 *
 * Quatre causes distinctes, toutes silencieuses, qui se cumulaient — d'où le
 * caractère intermittent constaté (« des fois ça marche ») :
 *
 *  1. la colonne `scope` tronquait la liste des autorisations AU MILIEU d'un
 *     scope : l'autorisation incrémentale de Google fait porter au jeton
 *     l'union de tous les connecteurs reliés, qui dépasse 500 caractères dès le
 *     quatrième. La comparaison étant exacte (elle doit l'être), le scope
 *     amputé et TOUS ceux placés après la coupure passaient pour non accordés ;
 *     l'ordre des scopes n'étant pas garanti par Google, la capacité perdue
 *     changeait d'une connexion à l'autre ;
 *  2. un 403 « scope insuffisant » était présenté comme un manque de droits
 *     Google SUR LE FICHIER — les deux portent `PERMISSION_DENIED` — et le
 *     message affirmait qu'une reconnexion n'y changerait rien, c'est-à-dire
 *     exactement le contraire du remède ;
 *  3. l'autorisation de LECTURE n'était jamais vérifiée : sans elle il reste
 *     `drive.file`, et Drive répond 200 avec une liste vide plutôt qu'une
 *     erreur — un Drive qui a l'air vide, sans un mot d'explication ;
 *  4. un rangement refusé par les droits du fichier n'était découvert
 *     qu'APRÈS l'approbation de l'utilisateur, alors que Google annonce à
 *     l'avance ce qu'il autorise (`capabilities`).
 *
 * Tout est vérifié sans réseau : fonctions pures, constantes, source.
 */
final class DriveAccessDiagnosticTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // =====================================================================
    // 1. La liste des autorisations ne doit jamais être coupée en deux
    // =====================================================================

    public function testLUnionDesScopesGoogleNeTientPasDansLAncienneColonne(): void
    {
        // C'est LA mesure qui explique l'intermittence : un utilisateur qui
        // relie ses quatre connecteurs Google dépasse les 500 caractères de la
        // colonne d'origine — avant même les `openid` / `userinfo.*` que Google
        // ajoute de lui-même.
        $union = array_unique(array_merge(
            GoogleDriveOAuth::SCOPES,
            GoogleGmailOAuth::SCOPES,
            GoogleCalendarOAuth::SCOPES,
            GoogleTasksOAuth::SCOPES
        ));
        $len = mb_strlen(implode(' ', $union));

        $this->assertGreaterThan(500, $len, 'Sans dépassement, la troncature ne serait pas le sujet.');
        $this->assertLessThanOrEqual(
            UserDriveToken::SCOPE_MAX_LEN,
            $len,
            'La colonne doit contenir l\'union de TOUS les connecteurs Google, sinon la troncature revient.'
        );
    }

    public function testLaMigrationElargitLaColonneScope(): void
    {
        $sql = (string)file_get_contents(ROOT . '/database/migrations/070_user_drive_tokens_scope.sql');
        $this->assertStringContainsString('user_drive_tokens', $sql);
        $this->assertStringContainsString('scope VARCHAR(' . UserDriveToken::SCOPE_MAX_LEN . ')', $sql);
    }

    public function testUneListeTropLongueEstCoupeeEntreDeuxScopesJamaisAuMilieu(): void
    {
        $a = 'https://www.googleapis.com/auth/drive.readonly';
        $b = 'https://www.googleapis.com/auth/drive.metadata';
        $c = 'https://www.googleapis.com/auth/documents';

        // Borne volontairement placée EN PLEIN MILIEU du deuxième scope.
        $coupe = UserDriveToken::fitScope("$a $b $c", mb_strlen($a) + 20);

        $this->assertSame($a, $coupe, 'Seuls des scopes entiers survivent.');
        $this->assertTrue(UserDriveService::grantsRead($coupe));
        // Le piège d'origine : un scope amputé ne correspond plus à rien, et
        // la comparaison exacte le rejette — donc la capacité disparaît.
        $this->assertFalse(
            UserDriveService::grantsOrganize($coupe),
            'Un scope tronqué ne doit jamais être présenté comme accordé.'
        );
        $this->assertStringNotContainsString(
            'metadat',
            $coupe,
            'Un fragment de scope est pire qu\'un scope absent : il ne veut plus rien dire.'
        );

        // Cas courant : rien à couper.
        $this->assertSame("$a $b", UserDriveToken::fitScope(" $a $b ", 200));
        $this->assertSame('', UserDriveToken::fitScope('', 200));
    }

    public function testLaTroncatureBruteADisparuDuModele(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Models/UserDriveToken.php');
        $this->assertStringNotContainsString(
            'mb_substr($scope, 0, 500)',
            $src,
            'Une troncature brute réintroduirait des scopes fantômes.'
        );
        $this->assertSame(2, substr_count($src, 'self::fitScope($scope)'), 'Les deux écritures doivent passer par la même borne.');
    }

    // =====================================================================
    // 2. Autorisation manquante ≠ droits manquants
    // =====================================================================

    public function testUnScopeInsuffisantNEstPasPrisPourUnManqueDeDroitsSurLeFichier(): void
    {
        // Corps réel d'un 403 « scope insuffisant » : il porte le MÊME
        // `PERMISSION_DENIED` qu'un refus de droits sur le fichier.
        $scopeInsuffisant = json_encode([
            'error' => [
                'code'    => 403,
                'message' => 'Request had insufficient authentication scopes.',
                'errors'  => [['message' => 'Insufficient Permission', 'domain' => 'global', 'reason' => 'insufficientPermissions']],
                'status'  => 'PERMISSION_DENIED',
                'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']],
            ],
        ]);
        $droitsFichier = json_encode([
            'error' => [
                'code'   => 403,
                'errors' => [['reason' => 'insufficientFilePermissions', 'message' => 'The user does not have sufficient permissions for file abc.']],
                'status' => 'PERMISSION_DENIED',
            ],
        ]);

        $this->assertTrue(GoogleUserApiService::isScopeError((string)$scopeInsuffisant));
        // LE piège : `insufficientFilePermissions` contient « insufficient ».
        // Un str_contains l'aurait pris pour un scope manquant — la confusion
        // inverse, tout aussi trompeuse.
        $this->assertFalse(
            GoogleUserApiService::isScopeError((string)$droitsFichier),
            'Un refus de droits sur le fichier n\'est pas une autorisation manquante.'
        );

        // Et le service tranche bien dans le bon sens : chaque corps produit
        // son propre message.
        $this->assertStringContainsString(
            'reconnecter son Google Drive',
            $this->presenter(403, (string)$scopeInsuffisant),
            'Un scope manquant se répare en se reconnectant.'
        );
        $this->assertStringContainsString(
            'Éditeur',
            $this->presenter(403, (string)$droitsFichier),
            'Un manque de droits se répare auprès du propriétaire du fichier.'
        );
    }

    public function testLeCasDuScopeManquantEstTesteAvantCeluiDesDroits(): void
    {
        $corps = $this->corpsDeMethode(
            (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php'),
            'protected function specificHttpError('
        );
        $this->assertLessThan(
            strpos($corps, 'insufficientFilePermissions'),
            strpos($corps, 'isScopeError'),
            'Inversés, les deux 403 se confondent : le message réclame un droit que l\'utilisateur possède déjà.'
        );
    }

    public function testUneApiDesactiveeResteDetecteeAvantToutLeReste(): void
    {
        // Garde historique : `SERVICE_DISABLED` arrive aussi en 403, et le
        // message qui nomme l'API à activer ne doit être volé par personne.
        $corps = (string)json_encode([
            'error' => [
                'code'    => 403,
                'status'  => 'PERMISSION_DENIED',
                'details' => [['reason' => 'SERVICE_DISABLED', 'metadata' => ['service' => 'docs.googleapis.com']]],
            ],
        ]);
        $this->assertStringContainsString('Google Docs', $this->presenter(403, $corps));
        $this->assertStringContainsString('console Google Cloud', $this->presenter(403, $corps));
    }

    // =====================================================================
    // 3. La lecture est une autorisation comme les autres
    // =====================================================================

    public function testLaLectureEstVerifieeEtAttrapeLePiegeDePrefixe(): void
    {
        $this->assertTrue(UserDriveService::grantsRead(UserDriveService::SCOPE_READONLY . ' openid'));
        // `drive` complet implique la lecture : le déclarer aveugle
        // afficherait un avertissement mensonger.
        $this->assertTrue(UserDriveService::grantsRead('https://www.googleapis.com/auth/drive'));
        // Le régime dégradé : accès PAR FICHIER seul.
        $this->assertFalse(UserDriveService::grantsRead(UserDriveService::SCOPE_FILE));
        $this->assertFalse(UserDriveService::grantsRead('https://www.googleapis.com/auth/drive.metadata.readonly'));
        $this->assertFalse(UserDriveService::grantsRead(''));

        $this->assertContains(UserDriveService::SCOPE_READONLY, GoogleDriveOAuth::SCOPES);
    }

    public function testUnResultatVideSansAutorisationDeLectureEstExplique(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');

        // Le contrôle est joué sur les chemins d'ÉCHEC seulement : une
        // connexion partielle voit quand même les fichiers créés par MIA, et
        // tant qu'elle trouve, il n'y a rien à signaler.
        $recherche = $this->corpsDeMethode($src, 'public function search(');
        $this->assertStringContainsString('readScopeNote()', $recherche);
        $this->assertStringContainsString('$rows === [] && ($note', $recherche);

        // Les deux autres culs-de-sac : un fichier désigné par une URL ou un
        // id (404 opaque) et un dossier introuvable.
        $this->assertSame(
            4,
            substr_count($src, '($note = $this->readScopeNote()) !== null'),
            'Recherche, résolution par id/URL, recherche par nom et dossier : les quatre impasses.'
        );
        $this->assertStringContainsString('MIA n\'a pas l\'autorisation de voir les fichiers', UserDriveService::MSG_READ_NOT_GRANTED);
        $this->assertStringContainsString('Reconnecter et autoriser', UserDriveService::MSG_READ_NOT_GRANTED);
    }

    public function testLaPageConnexionsEtLeModeleAnnoncentLaLectureManquante(): void
    {
        $ctrl = (string)file_get_contents(ROOT . '/src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString('UserDriveService::grantsRead($scope)', $ctrl);

        // Bornée à la carte Drive : les autres connecteurs ont leurs propres
        // badges, et leur régime de scopes n'est pas celui-ci.
        $tpl   = (string)file_get_contents(ROOT . '/templates/settings/connections.php');
        $carte = substr($tpl, 0, (int)strpos($tpl, '$driveCanWriteDocs ?'));
        $this->assertStringContainsString('$driveCanRead ? \'✓\' : \'—\'', $carte);
        $this->assertStringNotContainsString(
            '<span class="badge badge-gray">Lecture</span>',
            $carte,
            'Un badge « Lecture » en dur masque précisément la panne qu\'on cherche à rendre visible.'
        );

        // Côté chat, le modèle doit savoir qu\'un « rien trouvé » ne prouve rien.
        $chat = AgentSource::chat();
        $this->assertStringContainsString('!$drive->canRead()', $chat);
    }

    // =====================================================================
    // 4. Ce que Google autorise, lu AVANT d'agir
    // =====================================================================

    public function testUneCapaciteAbsenteNEstJamaisTenuePourRefusee(): void
    {
        // Google ne renvoie que les sous-champs demandés dans `fields` :
        // traiter le silence comme un refus ferait bloquer des opérations
        // parfaitement légales à la première évolution de la liste.
        $this->assertNull(UserDriveService::organizeBlocker([], 'renommer', 'Budget.xlsx'));
        $this->assertNull(UserDriveService::organizeBlocker(['canEdit' => true], 'deplacer'));
        $this->assertNull(UserDriveService::organizeBlocker(['canRename' => true], 'renommer'));
        // Action inconnue : rien ne bloque (le service refuserait ailleurs).
        $this->assertNull(UserDriveService::organizeBlocker(['canRename' => false], 'supprimer'));
    }

    public function testUnRefusExpliciteEstNommeEtOrienteVersLeProprietaire(): void
    {
        $msg = UserDriveService::organizeBlocker(['canMoveItemWithinDrive' => false], 'deplacer', 'CR réunion.docx');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('CR réunion.docx', (string)$msg);
        $this->assertStringContainsString('Éditeur', (string)$msg);
        $this->assertStringContainsString(
            'reconnecter MIA n\'y changerait rien',
            (string)$msg,
            'Ici la reconnexion est bel et bien inutile : ce sont les droits Google du compte qui bloquent.'
        );

        $this->assertNotNull(UserDriveService::organizeBlocker(['canRename' => false], 'renommer'));
        $this->assertNotNull(UserDriveService::organizeBlocker(['canCopy' => false], 'dupliquer'));
    }

    public function testLeControleEstJoueAvantLaCarteEtALExecution(): void
    {
        $chat = AgentSource::chat();
        $drive = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');

        // Avant la carte : faire approuver un rangement voué à l'échec fait
        // porter la déception au clic, quand plus rien n'est rattrapable.
        $this->assertSame(
            3,
            substr_count($chat, 'UserDriveService::organizeBlocker('),
            'Renommer, déplacer et dupliquer doivent tous les trois être contrôlés en amont.'
        );
        // Et à l'exécution : les droits ont pu changer entre l'approbation et
        // le clic, comme pour toutes les gardes de la file d'approbation.
        $this->assertSame(3, substr_count($drive, 'self::organizeBlocker('));

        $this->assertStringContainsString('capabilities(canRename', $drive, 'Sans le champ, la capacité est toujours absente.');
    }

    // =====================================================================
    // Déplacement : effet réel, et la racine qui n'est pas un identifiant
    // =====================================================================

    public function testLeDeplacementVerifieQueLeFichierABougeReellement(): void
    {
        $move = $this->corpsDeMethode(
            (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php'),
            'public function moveFile('
        );

        // Règle maison : une écriture sans effet LÈVE. Drive répond 200 à un
        // PATCH qui n'a rien déplacé — la conversation affichait alors
        // « ✅ déplacé » sur un fichier resté en place.
        $this->assertStringContainsString("'fields'            => 'id,name,parents'", $move);
        $this->assertStringContainsString('in_array($destId, $nouveaux, true)', $move);

        // La racine est un ALIAS : Drive l'accepte en écriture mais ne le
        // renvoie jamais en lecture. Comparé tel quel à `parents`, il produit
        // un déplacement fantôme vers un dossier où le fichier est déjà.
        $this->assertStringContainsString('resolveRootAlias($folder[\'id\'])', $move);
        // Un même parent des deux côtés est un ordre contradictoire.
        $this->assertStringContainsString('array_diff($ref[\'parents\'], [$destId])', $move);
    }

    public function testLeRenommageVerifieLeNomEnregistre(): void
    {
        $rename = $this->corpsDeMethode(
            (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php'),
            'public function renameFile('
        );
        $this->assertStringContainsString('$obtenu !== $name', $rename);
    }

    // =====================================================================
    // Les autorisations connues suivent celles que Google redit
    // =====================================================================

    public function testLeScopeEstReenregistreAChaqueRafraichissement(): void
    {
        $oauth = (string)file_get_contents(ROOT . '/src/Auth/GoogleUserOAuth.php');
        $refresh = $this->corpsDeMethode($oauth, 'public static function refreshAccessToken(');
        $this->assertStringContainsString("'scope'        => (string)(\$resp['scope'] ?? '')", $refresh);

        $socle = (string)file_get_contents(ROOT . '/src/Connectors/ConnectorApiService.php');
        $this->assertStringContainsString('$this->syncGrantedScope((string)($fresh[\'scope\'] ?? \'\'))', $socle);
        $this->assertStringContainsString('UserDriveToken::updateScope(', $socle);

        // Une valeur vide n'est pas un retrait : tous les fournisseurs ne
        // renvoient pas `scope` à chaque fois, et prendre le silence pour un
        // retrait ferait perdre ses outils à une connexion valide.
        $sync = $this->corpsDeMethode($socle, 'private function syncGrantedScope(');
        $this->assertStringContainsString("\$scope === ''", $sync);
        // Best-effort : un alignement de confort ne casse jamais l'appel.
        $this->assertStringContainsString('catch (\\Throwable', $sync);

        // Le champ ne peut plus être `readonly`, sinon rien ne serait alignable.
        $this->assertStringNotContainsString(
            'protected readonly string $grantedScope',
            $socle,
            'Un scope figé au jour de la connexion est exactement le bug corrigé.'
        );
    }

    // =====================================================================
    // La guidance ne doit plus affirmer une limitation qui n'existe pas
    // =====================================================================

    public function testLaGuidanceNAffirmePlusQueLeRangementEstReserveAuxFichiersDeLApp(): void
    {
        $chat = AgentSource::chat();
        $guidance = $this->corpsDeMethode($chat, 'private function driveToolGuidance(');

        // Cette phrase était injectée au system prompt à chaque message : elle
        // donnait au modèle une explication toute prête pour abandonner
        // (« Google ne me le permet pas, faites-le vous-même ») quelle que soit
        // la vraie cause de l'échec.
        $this->assertStringNotContainsString(
            'Google limite le renommage et le déplacement aux fichiers créés par cette',
            $guidance,
            '`drive.metadata` est un scope de portée globale : cette limitation n\'existe pas.'
        );
        $this->assertStringContainsString('TOUS les fichiers de l\'utilisateur', $guidance);
        $this->assertStringContainsString('transmets le message d\'erreur tel quel', $guidance);
    }

    // ---------------------------------------------------------------------
    // Outillage
    // ---------------------------------------------------------------------

    /** Message FR rendu par la chaîne d'erreurs, sans réseau ni jeton. */
    private function presenter(int $status, string $body): string
    {
        $service = (new ReflectionClass(UserDriveService::class))->newInstanceWithoutConstructor();
        $methode = new ReflectionMethod(UserDriveService::class, 'presentHttpError');
        return $methode->invoke($service, $status, $body)->getMessage();
    }

    /** Corps source d'une méthode, jusqu'au bloc de documentation suivant. */
    private function corpsDeMethode(string $src, string $signature): string
    {
        $debut = strpos($src, $signature);
        $this->assertNotFalse($debut, 'Signature introuvable : ' . $signature);
        $fin = strpos($src, "\n    /**", $debut + strlen($signature));
        return substr($src, $debut, ($fin === false ? strlen($src) : $fin) - $debut);
    }
}
