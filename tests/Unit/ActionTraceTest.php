<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Trace des actions effectuées par MIA avant de répondre (migration 064).
 *
 * On verrouille :
 *   - Message::normalizeActions : bornes et forme des entrées persistées
 *   - ChatController::actionEntryForTool : mapping outil → type + détail
 *     (détail depuis les arguments OU le résultat — nom de fichier, objet
 *     d'email résolus par le service)
 *   - la cohérence type → libellé (ACTION_LABELS, source unique rendue telle
 *     quelle par le front et message.php)
 *   - la cohérence type → icône (ACTION_ICONS : chaque type a une famille
 *     visuelle, chaque famille a son glyphe dans main.css, et AUCUN des deux
 *     rendus ne porte de SVG en propre)
 *   - le câblage de bout en bout : collecte au dispatch unique des outils,
 *     persistance dans send() ET regenerate(), exposition loadJson/done,
 *     rendu partial + chat.js HORS .msg-content (exclu de Copier/exports).
 */
final class ActionTraceTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    // ---------------------------------------------------------------------
    // Message::normalizeActions (pure)
    // ---------------------------------------------------------------------

    public function testNormalizeEcarteLesEntreesInvalides(): void
    {
        $clean = Message::normalizeActions([
            ['t' => 'web_search', 'l' => 'Recherche sur Internet'],
            ['t' => '', 'l' => 'Sans type'],
            ['l' => 'Sans type non plus'],
            ['t' => 'drive_search'],           // sans libellé
            'pas-un-tableau',
        ]);
        $this->assertCount(1, $clean);
        $this->assertSame('web_search', $clean[0]['t']);
    }

    public function testNormalizeBorneLesTextesEtPlafonneLeNombre(): void
    {
        $entrees = [];
        for ($i = 0; $i < Message::ACTIONS_MAX + 10; $i++) {
            $entrees[] = [
                't' => 'drive_search',
                'l' => str_repeat('L', Message::ACTION_LABEL_MAX + 30),
                'd' => str_repeat('D', Message::ACTION_DETAIL_MAX + 30),
            ];
        }
        $clean = Message::normalizeActions($entrees);
        $this->assertCount(Message::ACTIONS_MAX, $clean);
        $this->assertSame(Message::ACTION_LABEL_MAX, mb_strlen($clean[0]['l']));
        $this->assertSame(Message::ACTION_DETAIL_MAX, mb_strlen($clean[0]['d']));
    }

    public function testNormalizeOkVautVraiParDefautEtLeDetailVideDisparait(): void
    {
        $clean = Message::normalizeActions([
            ['t' => 'gmail_read', 'l' => 'Lecture', 'd' => '   '],
            ['t' => 'gmail_read', 'l' => 'Lecture', 'ok' => false],
        ]);
        $this->assertTrue($clean[0]['ok']);
        $this->assertArrayNotHasKey('d', $clean[0]);
        $this->assertFalse($clean[1]['ok']);
    }

    // ---------------------------------------------------------------------
    // ChatController::actionEntryForTool (pure)
    // ---------------------------------------------------------------------

    public function testLeDetailDUneRechercheVientDeLaRequete(): void
    {
        $spec = ChatController::actionEntryForTool('rechercher_google_drive', ['requete' => 'budget 2026'], []);
        $this->assertSame('drive_search', $spec['t']);
        $this->assertSame('budget 2026', $spec['d']);
    }

    public function testLeDetailDUneLecturePrefereLeResultatResolu(): void
    {
        // Le service a résolu le vrai nom : il prime sur la référence brute
        // passée par le modèle (souvent un id opaque).
        $spec = ChatController::actionEntryForTool(
            'lire_google_drive',
            ['fichier' => '1AbC…xyz'],
            ['nom' => 'Rapport annuel.docx', 'contenu' => '…']
        );
        $this->assertSame('Rapport annuel.docx', $spec['d']);

        $spec = ChatController::actionEntryForTool('lire_gmail', ['message' => 'id123'], ['objet' => 'Facture mars']);
        $this->assertSame('gmail_read', $spec['t']);
        $this->assertSame('Facture mars', $spec['d']);
    }

    public function testLesEcrituresPortentLeurNomDOutilEnType(): void
    {
        // Type = nom d'outil → libellé « Préparation… » : la pile dit que
        // l'action attend l'approbation, pas qu'elle est faite.
        $spec = ChatController::actionEntryForTool('creer_brouillon_gmail', ['objet' => 'Relance devis'], []);
        $this->assertSame('creer_brouillon_gmail', $spec['t']);
        $this->assertSame('Relance devis', $spec['d']);
        $this->assertStringContainsString('Préparation', ChatController::ACTION_LABELS[$spec['t']]);
    }

    public function testUnOutilInconnuNeTraceRien(): void
    {
        $this->assertNull(ChatController::actionEntryForTool('outil_fantome', [], []));
    }

    public function testChaqueOutilTraceUnTypeConnuDesLibelles(): void
    {
        $outils = [
            'generer_image', 'rechercher_google_drive', 'lire_google_drive',
            'creer_google_doc', 'modifier_google_doc', 'creer_google_sheet', 'modifier_google_sheet',
            'rechercher_gmail', 'lire_gmail', 'creer_brouillon_gmail',
            'rechercher_outlook', 'lire_outlook', 'creer_brouillon_outlook',
            'lister_agendas', 'rechercher_agenda', 'lire_evenement_agenda', 'creer_evenement_agenda',
            'lister_agendas_outlook', 'rechercher_agenda_outlook',
            'lire_evenement_agenda_outlook', 'creer_evenement_agenda_outlook',
            'lister_listes_taches', 'lister_taches', 'lire_tache',
            'creer_tache', 'modifier_tache', 'supprimer_tache',
            'lister_workflows_n8n', 'decrire_workflow_n8n', 'executer_workflow_n8n',
            'generer_presentation_gamma',
        ];
        foreach ($outils as $outil) {
            $spec = ChatController::actionEntryForTool($outil, [], []);
            $this->assertNotNull($spec, "Aucune trace pour l'outil $outil");
            $this->assertArrayHasKey(
                $spec['t'], ChatController::ACTION_LABELS,
                "Type « {$spec['t']} » ($outil) sans libellé — la pile afficherait la clé brute."
            );
        }
    }

    public function testLesTracesAmontOntAussiLeurLibelle(): void
    {
        // Tracées directement (hors dispatch d'outils) dans send()/regenerate().
        foreach (['searching_knowledge_base', 'searching_knowledge_bases', 'web_search', 'memorizing'] as $type) {
            $this->assertArrayHasKey($type, ChatController::ACTION_LABELS, $type);
        }
    }

    // ---------------------------------------------------------------------
    // ChatController::ACTION_ICONS (famille visuelle d'une action)
    // ---------------------------------------------------------------------

    public function testChaqueTypeTraceAUneFamilleVisuelle(): void
    {
        // Sans entrée, la ligne retomberait sur la coche générique — soit
        // exactement ce que les icônes explicites remplacent.
        foreach (array_keys(ChatController::ACTION_LABELS) as $type) {
            $this->assertArrayHasKey($type, ChatController::ACTION_ICONS, $type);
            $this->assertNotSame('check', ChatController::ACTION_ICONS[$type], $type);
        }
        // Et pas d'icône orpheline : un type retiré des libellés doit l'être ici aussi.
        foreach (array_keys(ChatController::ACTION_ICONS) as $type) {
            $this->assertArrayHasKey($type, ChatController::ACTION_LABELS, $type);
        }
    }

    public function testLesFamillesAttenduesDesConnecteurs(): void
    {
        // Le point de la demande : mail, tâches, agenda et fichiers ne se
        // distinguaient pas. Gmail et Outlook partagent l'enveloppe (le
        // libellé nomme déjà le fournisseur), les deux agendas le calendrier.
        $attendu = [
            'gmail_search' => 'mail', 'gmail_read' => 'mail',
            'outlook_search' => 'mail', 'outlook_read' => 'mail',
            'creer_brouillon_gmail' => 'mail', 'creer_brouillon_outlook' => 'mail',
            'calendar_search' => 'calendar', 'mscalendar_search' => 'calendar',
            'calendar_read' => 'calendar', 'mscalendar_read' => 'calendar',
            'tasks_read' => 'tasks', 'creer_tache' => 'tasks', 'supprimer_tache' => 'tasks',
            'drive_search' => 'drive', 'drive_list' => 'folder', 'drive_read' => 'doc',
            'modifier_google_sheet' => 'sheet',
            'web_search' => 'web', 'searching_knowledge_bases' => 'kb',
        ];
        foreach ($attendu as $type => $famille) {
            $this->assertSame($famille, ChatController::actionIcon($type), $type);
        }
        $this->assertSame('check', ChatController::actionIcon('type_inconnu'));
    }

    public function testLIconeSeRededuitAuRenduEtNEstJamaisPersistee(): void
    {
        // Relecture d'un message enregistré AVANT les icônes : la famille est
        // repeuplée depuis le type, sans migration ni réécriture en base.
        $relu = ChatController::withActionDisplay([
            ['t' => 'gmail_search', 'l' => 'Recherche dans Gmail', 'ok' => true],
            'pas-un-tableau',
        ]);
        $this->assertSame('mail', $relu[0]['i']);
        $this->assertSame('pas-un-tableau', $relu[1]);

        // …et la persistance ne la fige pas : normalizeActions ne garde que
        // t/l/ok/d, donc un jeu d'icônes qui évolue vaut aussi pour l'existant.
        $clean = Message::normalizeActions([['t' => 'gmail_search', 'l' => 'Recherche', 'i' => 'mail']]);
        $this->assertArrayNotHasKey('i', $clean[0]);
    }

    /**
     * Le libellé suit la langue COURANTE, pas celle du jour où le message a été
     * écrit. Même mécanique que l'icône, et pour la même raison : le faire
     * dépendre du type au rendu fait profiter l'historique de toute évolution
     * sans migration. Un type disparu du catalogue garde son libellé persisté —
     * une trace ancienne vaut mieux qu'un slug technique affiché.
     */
    public function testLeLibelleSeRelitDansLaLangueCourante(): void
    {
        $ancien = [['t' => 'gmail_search', 'l' => 'Un libellé périmé', 'ok' => true]];

        I18n::use('fr');
        $this->assertSame(
            ChatController::ACTION_LABELS['gmail_search'],
            ChatController::withActionDisplay($ancien)[0]['l'],
            'Le libellé persisté doit être remplacé par celui du catalogue.'
        );

        // Type inconnu : repli sur ce qui avait été enregistré.
        $this->assertSame(
            'Un libellé périmé',
            ChatController::withActionDisplay([['t' => 'type_disparu', 'l' => 'Un libellé périmé']])[0]['l']
        );
        $this->assertSame('type_disparu', ChatController::actionLabel('type_disparu'));
    }

    public function testChaqueFamilleAUnGlypheEtAucunRenduNePorteDeSvg(): void
    {
        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');
        $familles = array_unique(array_values(ChatController::ACTION_ICONS));
        // 'warn' = ligne en échec, posé par les deux rendus sans passer par le mapping.
        foreach (array_merge($familles, ['warn']) as $famille) {
            $this->assertStringContainsString(
                '.msg-trace-icon[data-icon="' . $famille . '"]', $css,
                "Famille « $famille » sans glyphe : masque invalide, donc carré plein."
            );
        }
        // Le repli 'check' est porté par la règle de base (un --trace-glyph
        // absent invaliderait le masque).
        $this->assertMatchesRegularExpression('/\.msg-trace-icon \{[^}]*--trace-glyph:/s', $css);

        // Le glyphe vit dans le CSS, une seule fois : les deux rendus n'émettent
        // qu'un data-icon. Deux copies de SVG divergeraient au premier ajout.
        $php = (string)file_get_contents(ROOT . '/templates/partials/message.php');
        $js  = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString('msg-trace-icon" data-icon', $php);
        $this->assertStringContainsString('msg-trace-icon" data-icon', $js);
        $this->assertStringNotContainsString('msg-trace-icon"><svg', $php);
        $this->assertStringNotContainsString('msg-trace-icon">${', $js);
    }

    // ---------------------------------------------------------------------
    // Câblage
    // ---------------------------------------------------------------------

    public function testLaMigrationAjouteLaColonne(): void
    {
        $sql = (string)file_get_contents(ROOT . '/database/migrations/064_message_actions.sql');
        $this->assertStringContainsString('ALTER TABLE messages', $sql);
        $this->assertStringContainsString('actions_json', $sql);
    }

    public function testLeControleurCollectePersisteEtExpose(): void
    {
        $src = (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');

        // Collecte au point de dispatch UNIQUE des outils (aucun handler modifié).
        $this->assertStringContainsString('self::actionEntryForTool($name, $args', $src);

        // Remise à zéro ET persistance dans les DEUX tours (send + regenerate) :
        // une seule occurrence = un des deux chemins raconte la trace du tour
        // précédent, ou ne la sauve jamais.
        $this->assertGreaterThanOrEqual(2, substr_count($src, '$this->actionTrace = [];'));
        $this->assertGreaterThanOrEqual(2, substr_count($src, "Message::setActions((int)\$msg['id'], \$this->actionTrace)"));

        // Payload done (rattachement tardif) + historique JSON (rechargement SPA).
        $this->assertGreaterThanOrEqual(2, substr_count($src, "'actions' => \$this->actionTrace"));
        $this->assertStringContainsString("\$m['actions_json']", $src);

        // Icône ET libellé posés sur le flux live, puis TOUS DEUX repeuplés sur
        // l'historique relu : le libellé est persisté en français dans
        // actions_json, il doit se relire dans la langue courante.
        $this->assertStringContainsString("'i'  => self::actionIcon(\$type)", $src);
        $this->assertStringContainsString("'l'  => self::actionLabel(\$type)", $src);
        $this->assertStringContainsString('self::withActionDisplay($decodedActions)', $src);
    }

    public function testLePartialRendLaTraceHorsDuContenuCopiable(): void
    {
        $html = (string)file_get_contents(ROOT . '/templates/partials/message.php');
        // Attributs class réels (les commentaires citent aussi ces noms).
        $posTrace   = strpos($html, 'class="msg-trace"');
        $posContent = strpos($html, 'class="msg-content"');
        $this->assertNotFalse($posTrace, 'Trace absente du partial.');
        // AVANT .msg-content (et hors de lui) : jamais dans data-raw, donc
        // jamais dans Copier ni les exports PDF/Word.
        $this->assertLessThan($posContent, $posTrace);
        $this->assertStringContainsString('msg-trace-more', $html);
    }

    public function testLeFrontRendLaPileLiveEtHistorique(): void
    {
        $js = (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringContainsString('function renderActionTrace', $js);
        $this->assertStringContainsString('json.action', $js);
        $this->assertStringContainsString('ACTION_TRACE_VISIBLE', $js);
        $this->assertStringContainsString('options.actions', $js);
        $this->assertStringContainsString('.msg-trace-more', $js);

        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');
        $this->assertStringContainsString('.msg-trace-row', $css);
    }
}
