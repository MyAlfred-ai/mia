<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cohérence des connecteurs avec LA CONVERSATION EN COURS, et signalement des
 * actions impossibles.
 *
 * LE PROBLÈME VERROUILLÉ ICI. `ChatController::buildLLMMessages` reconstruit
 * l'historique depuis les seuls `content` persistés : les `tool_call` et les
 * `tool_result` d'un message ANTÉRIEUR ne reviennent jamais. Au tour suivant,
 * le modèle a donc perdu l'identifiant du fichier qu'il vient de lire — d'où
 * les oublis constatés très précisément sur les MODIFICATIONS de fichier
 * (« ajoute une conclusion à ce document » repartait d'une recherche à
 * l'aveugle, ou d'un id inventé).
 *
 * Trois mécaniques répondent à ça, chacune gardée ci-dessous :
 *
 *  1. [[ConnectorContext]] retient les objets touchés, par conversation, et les
 *     rappelle FILTRÉS aux connecteurs engagés du message ;
 *  2. `UserDriveService` accepte un NOM de fichier là où il n'acceptait qu'un id
 *     ou une URL — symétrie enfin acquise avec `folderRef()` ;
 *  3. `ConnectorRegistry::dormantConnectorsNote` dit à l'assistant qu'un
 *     connecteur est connecté mais ÉTEINT, avec le geste exact pour l'allumer,
 *     et `ConnectorContext::regenerationNote` annonce la dégradation d'une
 *     régénération au lieu de la subir en silence.
 *
 * Aucun réseau, aucune base : statiques pures et lecture du code source.
 */
final class ConnectorContextTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    /** Contrôleur + registre d'outils (src/Agent/) : les handlers y vivent. */
    private function chatSource(): string
    {
        return AgentSource::chat();
    }

    // ---------------------------------------------------------------------
    // 1. Forme des entrées (pur)
    // ---------------------------------------------------------------------

    public function testUneEntreeEstRattacheeAuConnecteurQuiSaitLaRelire(): void
    {
        $e = ConnectorContext::normalizeEntry('drive_file', '1a2B3c', 'Budget 2026', 'Google Doc');
        $this->assertNotNull($e);
        $this->assertSame('drive', $e['connector']);
        $this->assertSame('1a2B3c', $e['ref']);
        $this->assertSame('Budget 2026', $e['name']);
    }

    public function testUnTypeInconnuOuUneEntreeIncompleteNEstJamaisMemorisee(): void
    {
        $this->assertNull(ConnectorContext::normalizeEntry('inconnu', 'x1234567890', 'Truc'));
        $this->assertNull(ConnectorContext::normalizeEntry('drive_file', '', 'Budget'), 'Sans identifiant, rien à réutiliser.');
        $this->assertNull(ConnectorContext::normalizeEntry('drive_file', '1a2B3c', '   '), 'Sans nom, le rappel serait illisible.');
    }

    public function testLesNomsVenusDUneApiTierceSontRamenesAUneLigneEtBornes(): void
    {
        $e = ConnectorContext::normalizeEntry('gmail_msg', 'm1', "Objet\nsur deux\tlignes");
        $this->assertSame('Objet sur deux lignes', $e['name']);

        $long = ConnectorContext::normalizeEntry('drive_file', str_repeat('i', 900), str_repeat('n', 900));
        $this->assertSame(ConnectorContext::MAX_REF, mb_strlen($long['ref']));
        $this->assertSame(ConnectorContext::MAX_NAME, mb_strlen($long['name']));
    }

    // ---------------------------------------------------------------------
    // 2. Lecture d'un appel d'outil
    // ---------------------------------------------------------------------

    public function testLIdentifiantEtLeNomViennentDuResultatQuandIlLesPorte(): void
    {
        // Le modèle a désigné le fichier par un NOM approximatif ; le service a
        // résolu le vrai. C'est la résolution qui doit être mémorisée, sinon le
        // tour suivant repartirait du nom approximatif.
        $e = ConnectorContext::entryForTool(
            'lire_google_drive',
            ['fichier' => 'budget'],
            ['id' => '1a2B3c', 'nom' => 'Budget 2026 — consolidé', 'type' => 'Google Doc']
        );
        $this->assertSame('1a2B3c', $e['ref']);
        $this->assertSame('Budget 2026 — consolidé', $e['name']);
    }

    public function testAdefautDIdentifiantDansLeResultatOnRetientCeluiDeLAppel(): void
    {
        // readMessage (Gmail/Outlook) et readEvent (Google) ne renvoient pas
        // d'id : l'argument EST l'identifiant qui vient de fonctionner.
        $gmail = ConnectorContext::entryForTool(
            'lire_gmail',
            ['message' => '18f2c'],
            ['objet' => 'Devis chantier', 'de' => 'alice@ex.fr']
        );
        $this->assertSame('18f2c', $gmail['ref']);
        $this->assertSame('Devis chantier', $gmail['name']);
        $this->assertSame('alice@ex.fr', $gmail['extra']);

        $agenda = ConnectorContext::entryForTool(
            'lire_evenement_agenda',
            ['evenement' => 'evt42'],
            ['titre' => 'Revue de projet', 'debut' => '2026-08-20 09:00']
        );
        $this->assertSame('evt42', $agenda['ref']);
        $this->assertSame('gcal_event', $agenda['kind']);
    }

    /**
     * Les clés lues doivent exister dans le retour RÉEL de chaque service :
     * `de` chez les deux messageries et non `expediteur`. Une clé fantaisiste
     * ne casse rien de visible — elle prive juste le rappel de son détail, en
     * silence.
     */
    public function testLesClesDeResultatLuesExistentDansLesServices(): void
    {
        foreach ([
            [ROOT . '/src/Gmail/UserGmailService.php', 'readMessage', ['objet', 'de']],
            [ROOT . '/src/Outlook/UserOutlookService.php', 'readMessage', ['objet', 'de']],
            [ROOT . '/src/Tasks/UserTasksService.php', 'summarizeTask', ['id', 'titre', 'echeance']],
            [ROOT . '/src/Calendar/UserCalendarService.php', 'readEvent', ['titre', 'debut']],
        ] as [$file, $method, $keys]) {
            $src = (string)file_get_contents($file);
            $pos = strpos($src, 'function ' . $method . '(');
            $this->assertNotFalse($pos, $method . ' introuvable dans ' . basename($file));
            $chunk = substr($src, $pos, 4500);
            foreach ($keys as $k) {
                $this->assertStringContainsString(
                    "'" . $k . "'",
                    $chunk,
                    'La mémoire de conversation lit la clé ' . $k . ' dans ' . $method . '() : elle doit y exister.'
                );
            }
        }
    }

    public function testUnAppelEnEchecNeMemoriseRien(): void
    {
        $this->assertNull(ConnectorContext::entryForTool(
            'lire_google_drive',
            ['fichier' => 'budget'],
            ['error' => 'Fichier introuvable ou non accessible avec ce compte Google.']
        ), 'Mémoriser un échec ferait croire au modèle qu\'il détient un objet jamais obtenu.');
    }

    public function testUneRechercheNeMemoriseRien(): void
    {
        // Une recherche renvoie une LISTE : retenir dix résultats noierait le
        // rappel sans désigner quoi que ce soit.
        foreach (['rechercher_google_drive', 'rechercher_gmail', 'rechercher_agenda', 'lister_taches'] as $tool) {
            $this->assertNull(ConnectorContext::entryForTool($tool, ['requete' => 'x'], ['resultats' => [['id' => 'a']]]), $tool);
        }
    }

    // ---------------------------------------------------------------------
    // 3. Fragment injecté
    // ---------------------------------------------------------------------

    private function rows(): array
    {
        return [
            ['connector' => 'drive', 'kind' => 'drive_file', 'ref_id' => '1a2B3c', 'name' => 'Budget 2026', 'extra' => 'Google Doc'],
            ['connector' => 'gmail', 'kind' => 'gmail_msg', 'ref_id' => '18f2c', 'name' => 'Devis chantier', 'extra' => 'alice@ex.fr'],
        ];
    }

    public function testLeFragmentEstFiltreAuxConnecteursEngagesDuMessage(): void
    {
        $drive = ConnectorContext::fragmentFromRows($this->rows(), ['drive']);
        $this->assertStringContainsString('1a2B3c', $drive);
        $this->assertStringNotContainsString(
            '18f2c',
            $drive,
            'Rappeler un email alors que Gmail est éteint ferait tenter un appel impossible.'
        );

        $this->assertStringContainsString('18f2c', ConnectorContext::fragmentFromRows($this->rows(), ['drive', 'gmail']));
    }

    public function testAucunConnecteurEngageOuAucunObjetDonneUnFragmentVide(): void
    {
        $this->assertSame('', ConnectorContext::fragmentFromRows($this->rows(), []), 'Zéro token quand rien n\'est engagé.');
        $this->assertSame('', ConnectorContext::fragmentFromRows([], ['drive']));
        $this->assertSame('', ConnectorContext::promptFragment(0, []), 'Aucune requête ne doit partir sans connecteur engagé.');
    }

    public function testLeFragmentPorteLIdentifiantEtInterditDeChoisirEnCasDeDoute(): void
    {
        $f = ConnectorContext::fragmentFromRows($this->rows(), ['drive', 'gmail']);
        $this->assertStringContainsString('identifiant', $f, 'Sans identifiant, le rappel ne servirait à rien.');
        $this->assertStringContainsString('plus récent', $f);
        $this->assertStringContainsString('demande lequel', $f, 'Deviner entre deux objets est le pire des deux mondes.');
    }

    public function testLeFragmentEstBorneEnNombreDObjets(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['kind' => 'drive_file', 'ref_id' => 'id' . $i, 'name' => 'Doc ' . $i, 'extra' => null];
        }
        $lignes = substr_count(ConnectorContext::fragmentFromRows($rows, ['drive']), "\n- ");
        $this->assertSame(ConnectorContext::MAX_IN_PROMPT, $lignes);
    }

    // ---------------------------------------------------------------------
    // 4. Câblage : capture au point UNIQUE, injection, purge
    // ---------------------------------------------------------------------

    public function testLaCaptureViTAuDispatchUniqueDesOutils(): void
    {
        $src = $this->chatSource();
        // Un seul endroit voit passer TOUS les appels d'outils, quel que soit
        // l'adapter : c'est là que vit déjà la trace d'actions.
        $this->assertStringContainsString('ConnectorContext::recordToolCall($convId, $name, $args', $src);
        // Le rappel du registre (src/Agent/ToolRegistry) est posé par runSend,
        // qui lui passe la conversation : c'est ce rappel qui trace ET mémorise.
        $this->assertStringContainsString(
            '$registry = new ToolRegistry(function (string $name, array $args, array $result) use ($convId, &$registry): void {',
            $src,
            'Le dispatch a besoin de la conversation pour mémoriser.'
        );
        $registre = AgentSource::registry();
        $this->assertStringContainsString('($this->onDone)($name, $args, $result);', $registre,
            'Chaque appel exécuté doit passer par le rappel, quel que soit le fournisseur.');
    }

    public function testLesObjetsEcritsApresApprobationSontMemorises(): void
    {
        $src = $this->chatSource();
        // Un fichier tout juste créé est CE dont l'utilisateur va parler ensuite.
        $this->assertStringContainsString("ConnectorContext::record(\$convId, 'drive_file'", $src);
        $this->assertStringContainsString("ConnectorContext::record(\$convId, 'gcal_event'", $src);
        $this->assertStringContainsString("ConnectorContext::record(\$convId, 'mscal_event'", $src);
        $this->assertStringContainsString("ConnectorContext::record(\$convId, 'task'", $src);
    }

    public function testUneTacheSupprimeeEstOubliee(): void
    {
        // Proposer ensuite l'identifiant d'une tâche effacée enverrait le modèle
        // sur un 404 opaque. Google Tasks est le SEUL connecteur qui supprime.
        $this->assertStringContainsString("ConnectorContext::forget(\$convId, 'task'", $this->chatSource());
    }

    public function testLInjectionEstConditionneeAuxConnecteursEngages(): void
    {
        $src = $this->chatSource();
        // Connecteurs engagés, et ceux que MIA peut activer elle-même
        // (« Laisser MIA choisir ») : leurs objets restent désignables.
        $this->assertStringContainsString('ConnectorContext::promptFragment($convId, array_merge($engagedConnectors, array_keys($autoConnectors)))', $src);
        $this->assertStringNotContainsString(
            '$connectorToolsEngaged',
            $src,
            'Le booléen a été remplacé par la LISTE des connecteurs engagés : deux états parallèles divergeraient.'
        );
    }

    public function testLesSeptConnecteursDeclarentLeurCleQuandIlsExposentDesOutils(): void
    {
        // Un fournisseur d'outils se déclare connecteur par connector() : le
        // registre en déduit la liste des connecteurs engagés du message.
        $classes = [
            'drive'      => 'DriveTools',
            'gmail'      => 'GmailTools',
            'outlook'    => 'OutlookTools',
            'calendar'   => 'GoogleCalendarTools',
            'mscalendar' => 'OutlookCalendarTools',
            'tasks'      => 'TasksTools',
            'mstodo'     => 'MicrosoftTodoTools',
            'teams'      => 'TeamsTools',
            'n8n'        => 'N8nTools',
        ];
        foreach ($classes as $key => $class) {
            $this->assertMatchesRegularExpression(
                "/public function connector\\(\\): \\?string\\s*\\{\\s*return '" . $key . "';/",
                AgentSource::tools($class),
                'Le connecteur ' . $key . ' doit se déclarer, sinon ses objets ne sont jamais rappelés.'
            );
        }
        $this->assertStringContainsString('$engagedConnectors = $registry->engagedConnectors();', $this->chatSource());
    }

    public function testLaTableEstCreeeParUneMigrationEtSuitLaConversation(): void
    {
        $sql = (string)file_get_contents(ROOT . '/database/migrations/066_conversation_connector_refs.sql');
        $this->assertStringContainsString('conversation_connector_refs', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql, 'Une conversation supprimée emporte sa mémoire.');
        $this->assertStringContainsString('UNIQUE KEY', $sql, 'Revoir le même objet le rafraîchit au lieu de le dupliquer.');
    }

    public function testLaClasseEstChargeeParLesDeuxListesDeRequire(): void
    {
        // Pas d'autoloader : une classe ajoutée côté web et oubliée côté CLI ne
        // plante que dans le worker, en production.
        foreach (['/config/classes.php', '/tests/bootstrap.php'] as $f) {
            $this->assertStringContainsString(
                'Connectors/ConnectorContext.php',
                (string)file_get_contents(ROOT . $f),
                $f
            );
        }
    }

    // ---------------------------------------------------------------------
    // 5. Désignation d'un fichier par son NOM (symétrie avec folderRef)
    // ---------------------------------------------------------------------

    public function testUnIdNuSeDistingueDUneUrlEtDUnNom(): void
    {
        $this->assertTrue(UserDriveService::isBareId('1a2B3c4D5e6F7g'));
        $this->assertFalse(UserDriveService::isBareId('Budget 2026'), 'Deux mots : jamais un id.');
        $this->assertFalse(UserDriveService::isBareId('https://docs.google.com/document/d/1a2B3c4D5e6F7g/edit'));
        // C'est LE cas qui partait en 404 opaque : un nom d'un seul mot, sans
        // accent, d'au moins 10 caractères, passe le motif d'id générique.
        $this->assertTrue(UserDriveService::isBareId('Budget2026Consolide'));
    }

    public function testLaResolutionParNomEstPartageeParLaLectureEtLesEcritures(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        // Une seule porte d'entrée : trois copies dériveraient (et c'est déjà
        // par une copie manquante que la lecture refusait les noms).
        $this->assertSame(
            1,
            substr_count($src, 'private function resolveFileMeta('),
            'La résolution « id | URL | nom » doit être déclarée une seule fois.'
        );
        foreach (['readFile', 'resolveGoogleFile', 'anyFileRef'] as $method) {
            $pos   = strpos($src, 'function ' . $method . '(');
            $chunk = substr($src, (int)$pos, 900);
            $this->assertStringContainsString(
                '$this->resolveFileMeta(',
                $chunk,
                $method . '() doit passer par la résolution commune, sinon un nom y reste refusé.'
            );
        }
        // Et le message d'erreur historique ne doit plus être la seule issue.
        $this->assertStringNotContainsString(
            "throw new \\RuntimeException('Identifiant ou URL Google Drive non reconnu.');",
            $src,
            'Ce message était un cul-de-sac : il ne disait même pas de chercher d\'abord.'
        );
    }

    public function testLeMessageDEchecParNomOrienteVersLaRecherche(): void
    {
        $src   = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        $pos   = strpos($src, 'function findFileByName(');
        // Fenêtre large : la méthode a grossi avec les trois passes de
        // recherche (cf. DriveSearchTest) et le renvoi vers le parcours de
        // dossier — un cadrage trop court ferait échouer sur rien.
        $chunk = substr($src, (int)$pos, 5000);
        $this->assertStringContainsString('rechercher_google_drive', $chunk, 'Une erreur doit être actionnable.');
        $this->assertStringContainsString('Plusieurs fichiers correspondent', $chunk);
        $this->assertStringContainsString(
            'ne choisis pas à sa place',
            $chunk,
            'Écrire dans le mauvais fichier ne se rejoue pas.'
        );
    }

    public function testLesOutilsAnnoncentQuUnNomEstAccepte(): void
    {
        $src = $this->chatSource();
        $this->assertStringContainsString('ou NOM du', $src, 'Un paramètre que le modèle croit fermé aux ids reste inutilisé.');
        $this->assertStringContainsString('un nom ambigu renvoie les candidats', $src);
    }

    public function testLesEcrituresRenvoientLIdentifiantDuFichierTouche(): void
    {
        // Sans `id` dans le retour, l'objet écrit ne peut pas devenir la cible
        // du message suivant : la mémoire resterait vide au moment le plus utile.
        $src = (string)file_get_contents(ROOT . '/src/Drive/UserDriveService.php');
        foreach (['updateSheet', 'formatDocText', 'formatSheetRange'] as $method) {
            $pos   = strpos($src, 'function ' . $method . '(');
            $chunk = substr($src, (int)$pos, 6000);
            $this->assertMatchesRegularExpression(
                "/return \[\s*\n\s*'id'/",
                $chunk,
                $method . '() doit renvoyer l\'identifiant du fichier touché.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // 6. Signalement des actions impossibles
    // ---------------------------------------------------------------------

    public function testUnConnecteurConnecteMaisEteintEstSignaleAvecLeGesteExact(): void
    {
        $note = ConnectorRegistry::dormantConnectorsNote(['Fichiers Google Drive']);
        $this->assertStringContainsString('Fichiers Google Drive', $note);
        $this->assertStringContainsString('menu « + »', $note, 'Le geste doit être nommé, pas suggéré.');
        $this->assertStringContainsString('SEULEMENT si', $note, 'Sinon l\'assistant proposerait Gmail à chaque question.');
        $this->assertStringContainsString('rien en inventer', $note);
    }

    public function testLeLibelleCiteEstCeluiDeLaLigneDuMenuPlus(): void
    {
        // « active Google Drive » ne désigne rien à l'écran : la ligne s'appelle
        // « Fichiers Google Drive ».
        $this->assertSame('Fichiers Google Drive', ConnectorRegistry::chatLabel(ConnectorRegistry::DRIVE));
        $this->assertSame('Emails Gmail', ConnectorRegistry::chatLabel(ConnectorRegistry::GMAIL));
        $this->assertSame('', ConnectorRegistry::chatLabel('inconnu'));
    }

    public function testAucunConnecteurDormantDonneUneNoteVide(): void
    {
        $this->assertSame('', ConnectorRegistry::dormantConnectorsNote([]));
        $this->assertSame('', ConnectorRegistry::dormantConnectorsNote(['', '   ']));
    }

    public function testLaNoteDesConnecteursDormantsEstReserveeAuxModelesAOutils(): void
    {
        // Sur un modèle sans function-calling, cocher la case ne changerait
        // rien : c'est la branche de dégradation qui parle, et elle dit de
        // changer de modèle.
        $src = $this->chatSource();
        $pos = strpos($src, 'ConnectorRegistry::dormantConnectorsNote(');
        $this->assertNotFalse($pos);
        // Modèle à outils ET rôle qui ouvre les connecteurs (Personnes → Rôles).
        $this->assertStringContainsString('if ($supportsTools && $connectorsAllowed) {', substr($src, $pos - 400, 400));
    }

    public function testUneRegenerationAnnonceSaDegradation(): void
    {
        $note = ConnectorContext::regenerationNote($this->rows());
        $this->assertStringContainsString('Budget 2026', $note);
        $this->assertStringContainsString('RÉGÉNÉRATION', $note);
        $this->assertStringContainsString('renvoyer sa demande', $note, 'Il faut donner l\'issue, pas juste le constat.');
        // Les identifiants n'ont rien à faire là : sans outil, ils invitent à
        // faire semblant.
        $this->assertStringNotContainsString('1a2B3c', $note);
        $this->assertSame('', ConnectorContext::regenerationNote([]));
    }

    public function testLaNoteDeRegenerationEstBranchee(): void
    {
        $this->assertStringContainsString(
            'ConnectorContext::regenerationNote(ConnectorContext::forConversation($convId))',
            $this->chatSource()
        );
    }
}
