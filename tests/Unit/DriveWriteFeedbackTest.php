<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Une écriture Google sans effet ne doit JAMAIS passer pour un succès.
 *
 * LE BUG. `replaceAllText` (Docs) et `values.update` (Sheets) répondent **200
 * même quand ils n'ont rien touché** : texte introuvable, plage vide. Le
 * résultat remontait alors intact jusqu'à la conversation, qui affichait
 * « ✅ Google Doc modifié : « X » — 0 occurrence(s) remplacée(s) ». Aucune
 * erreur, aucune modification, et un utilisateur convaincu du contraire — le
 * pire des trois mondes, parce qu'il ne peut même pas savoir qu'il doit
 * réessayer.
 *
 * La cause profonde est banale et durable : le texte rendu par la LECTURE
 * (export docx puis extraction) ne coïncide pas au caractère près avec le
 * document réel — apostrophes typographiques, espaces insécables, tirets
 * longs. Le modèle recopie donc de bonne foi un passage qui n'existe pas tel
 * quel. On ne peut pas l'empêcher ; on peut refuser de mentir dessus.
 *
 * Vérifié sur le CODE seul : ces chemins exigent un appel réseau authentifié.
 */
final class DriveWriteFeedbackTest extends TestCase
{
    private function drive(): string
    {
        return (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
    }

    /**
     * Corps d'une méthode, borné au membre SUIVANT — quelle que soit sa
     * visibilité et qu'il soit statique ou non. Chercher « public function »
     * seul débordait sur des centaines de lignes et faisait passer les
     * assertions par accident.
     */
    private function methode(string $src, string $nom): string
    {
        $pos = strpos($src, 'function ' . $nom . '(');
        $this->assertNotFalse($pos, $nom . ' introuvable.');
        $reste = substr($src, $pos + 10);
        $fin   = preg_match('/\n    (?:public|private|protected)(?: static)? function /', $reste, $m, PREG_OFFSET_CAPTURE) === 1
            ? (int)$m[0][1]
            : strlen($reste);
        return substr($src, $pos, $fin + 10);
    }

    // ---------------------------------------------------------------------
    // Docs
    // ---------------------------------------------------------------------

    public function testUnRemplacementSansOccurrenceLeveAuLieuDeSeDireFait(): void
    {
        $corps = $this->methode($this->drive(), 'updateDoc');

        $this->assertStringContainsString("if (\$result['occurrences'] === 0) {", $corps);
        $this->assertStringContainsString('throw new \RuntimeException(', $corps);
        $this->assertStringContainsString(
            'a PAS été',
            $corps,
            'Le message doit dire que rien n\'a changé — c\'est toute l\'information utile.'
        );
    }

    public function testLeMessageDEchecExpliqueCeQuiFaitRaterLaCorrespondance(): void
    {
        $corps = $this->methode($this->drive(), 'updateDoc');
        // Sans ces précisions, l'utilisateur relit dix fois un texte qui lui
        // paraît identique : la différence est invisible à l'œil.
        foreach (['casse est ignorée', 'accents', 'apostrophes typographiques', 'remplacer_tout'] as $indice) {
            $this->assertStringContainsString($indice, $corps, 'Indice manquant : ' . $indice);
        }
    }

    public function testAucunModeDeModificationNePeutNeRienEnvoyerEnSilence(): void
    {
        $corps = $this->methode($this->drive(), 'updateDoc');

        // Les deux gardes historiques enveloppaient l'appel dans un `if` : quand
        // la condition était fausse, rien ne partait chez Google et la méthode
        // renvoyait quand même un résultat de succès.
        $this->assertStringNotContainsString(
            "if (\$conv['texte'] !== '') {",
            $corps,
            'Un contenu vide doit lever, pas court-circuiter l\'appel.'
        );
        $this->assertStringNotContainsString(
            "if (\$requests !== []) {",
            $corps,
            'Une liste de requêtes vide doit lever, pas court-circuiter l\'appel.'
        );
        $this->assertStringContainsString("if (\$conv['texte'] === '') {", $corps);
        $this->assertStringContainsString('if ($requests === []) {', $corps);
    }

    // ---------------------------------------------------------------------
    // Sheets — même piège, même règle
    // ---------------------------------------------------------------------

    public function testUneEcritureDeZeroCelluleLeve(): void
    {
        $corps = $this->methode($this->drive(), 'updateSheet');

        $this->assertStringContainsString('if ($cells === 0) {', $corps);
        $this->assertStringContainsString('a PAS été modifiée', $corps);
        $this->assertStringContainsString('Vérifiez la plage', $corps, 'Le message doit être actionnable.');
    }

    // ---------------------------------------------------------------------
    // Diagnostic et restitution
    // ---------------------------------------------------------------------

    public function testChaqueEcritureExecuteeLaisseUneTraceServeur(): void
    {
        // Sans journal, un « ça n'a pas marché » rapporté par un utilisateur
        // n'est rattachable à rien : ni fichier, ni mode, ni effet.
        $src = $this->drive();
        $this->assertStringContainsString("error_log('[Drive user] doc modifié id='", $src);
        $this->assertStringContainsString("error_log('[Drive user] feuille modifiée id='", $src);
    }

    public function testLeMessageDeConversationAnnonceLEffetReel(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
        $this->assertStringContainsString("isset(\$result['caracteres'])", $src, 'Un ajout doit dire ce qu\'il a écrit.');
        // Pluriel réel depuis le lot 3 : le français pluralise à > 1, l'anglais
        // à != 1 — d'où I18n::plural et non un « (s) » figé.
        $this->assertStringContainsString("'{n} caractère écrit', '{n} caractères écrits'", $src);
    }

    public function testLOutilPrevientLeModeleDeLExigenceDuTexteExact(): void
    {
        $src = AgentSource::chat();
        $this->assertStringContainsString('exige le texte EXACT', $src);
        $this->assertStringContainsString('ÉCHOUE si rien ne correspond', $src);
    }

    // ---------------------------------------------------------------------
    // Drive partagé d'entreprise : nommer le vrai obstacle
    // ---------------------------------------------------------------------

    public function testUn403DeDroitsSurLeFichierNeRenvoiePasVersLaReconnexion(): void
    {
        $corps = $this->methode($this->drive(), 'specificHttpError');

        $this->assertStringContainsString('insufficientFilePermissions', $corps);
        $this->assertStringContainsString('Éditeur', $corps, 'Le message doit nommer le rôle Google à demander.');
        $this->assertStringContainsString(
            'y changera rien',
            $corps,
            'Conseiller une reconnexion serait faux : ce sont les droits du fichier qui bloquent.'
        );
    }

    public function testUnRefusDAccesParFichierNeSeConfondPasAvecUnManqueDeDroits(): void
    {
        $src   = $this->drive();
        $corps = $this->methode($src, 'specificHttpError');

        // Observé en recette : PATCH /drive/v3/files/{id} → 403 « The user has
        // not granted the app … write access to the file ». L'utilisateur a
        // pourtant tous les droits sur SON fichier — c'est l'application qui
        // n'y a pas accès. Les deux 403 appellent des remèdes opposés.
        $this->assertStringContainsString('appNotAuthorizedToFile', $corps);
        $this->assertStringContainsString('has not granted the app', $corps);
        $this->assertStringContainsString('MSG_PER_FILE_REFUSED', $corps);

        $this->assertStringContainsString('PAR FICHIER', UserDriveService::MSG_PER_FILE_REFUSED);
        // Le message a longtemps conclu que « se reconnecter n'y changerait
        // rien », en s'appuyant sur l'idée que `drive.metadata` était, comme
        // `drive.file`, un accès par fichier. C'est faux : Google le documente
        // comme un scope RESTREINT de portée globale (« View and manage
        // metadata of files in your Drive »). Cette erreur ne survient donc que
        // s'il ne reste QUE `drive.file`, faute d'avoir accordé le reste — et
        // la reconnexion est bel et bien le remède. Affirmer le contraire
        // envoyait chacun ranger ses fichiers à la main.
        $this->assertStringContainsString(
            'Reconnecter et autoriser',
            UserDriveService::MSG_PER_FILE_REFUSED,
            'La reconnexion EST le remède : ce scope se décoche, il n\'est pas structurellement absent.'
        );
        $this->assertStringNotContainsString(
            'y changerait rien',
            UserDriveService::MSG_PER_FILE_REFUSED,
            'Ce conseil était faux et décourageait la seule action utile.'
        );

        // L'ordre compte : le cas « accès par fichier » doit être testé AVANT
        // celui des droits insuffisants, sinon un fichier personnel se verrait
        // conseiller de réclamer le rôle Éditeur à son propre propriétaire.
        $this->assertLessThan(
            strpos($corps, 'insufficientFilePermissions'),
            strpos($corps, 'appNotAuthorizedToFile'),
            'Le refus par fichier doit précéder le refus de droits.'
        );
    }

    public function testLaDuplicationGardeSonMessageSpecifique(): void
    {
        // Même famille de refus, mais un conseil plus précis existe pour la
        // copie : il ne doit pas être avalé par le message générique.
        $corps = $this->methode($this->drive(), 'copyFile');
        $this->assertStringContainsString('MSG_PER_FILE_REFUSED', $corps);
        $this->assertStringContainsString('MSG_COPY_REFUSED', $corps);
    }

    public function testLaDetectionDApiNonActiveeRestePrioritaireSurLes403(): void
    {
        // « API non activée » arrive AUSSI en 403. L'intercepter avant la
        // rendrait muette — le piège que ce fichier documente depuis toujours.
        $corps = $this->methode($this->drive(), 'specificHttpError');
        $this->assertStringContainsString('self::disabledApiFromBody($body) === null', $corps);
    }

    // ---------------------------------------------------------------------
    // Menu du compte : les deux surfaces doivent lister les mêmes pages
    // ---------------------------------------------------------------------

    public function testLeMenuDuCompteEtLaNavDesParametresListentLesMemesPages(): void
    {
        // « Connexions » manquait au menu du compte alors que la nav des
        // paramètres la portait : la page n'était atteignable qu'en passant
        // d'abord par un AUTRE onglet des paramètres. Désormais le menu latéral
        // des pages /settings et la palette ⌘K lisent la MÊME liste
        // (NavRegistry::ACCOUNT) : ils ne peuvent plus diverger.
        $nav = (string)file_get_contents(ROOT . '/templates/partials/admin-nav.php');
        $this->assertStringContainsString('NavRegistry::accountFor($navUser)', $nav);
        $this->assertSame(
            ['/settings', '/settings/ai', '/settings/display', '/settings/connections', '/settings/usage'],
            array_values(array_column(NavRegistry::ACCOUNT, 'href'))
        );
        $this->assertSame('Comptes connectés', NavRegistry::ACCOUNT['connections']['label']);
        $this->assertArrayHasKey(
            'Comptes connectés',
            (array)(require ROOT . '/lang/en.php'),
            "L'entrée « Comptes connectés » n'a pas de traduction."
        );
    }
}
