<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Action par ligne d'une table de travail (WorkTableActions) : le gabarit et
 * sa déclinaison, les colonnes de résultat, la carte du groupe et le bilan.
 *
 * Ce que ces tests protègent : une colonne citée qui n'existe pas est nommée
 * avant toute action ; une ligne ne donne jamais une action à moitié remplie ;
 * une reprise ne refait pas ce qui est fait ; la carte dit TOUT ce qui sera
 * créé, et qu'aucun email ne part.
 */
final class WorkTableActionsTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::use('fr');
    }

    private static function columns(): array
    {
        return WorkTableOps::columns(['Prénom', 'Email', 'Numéro', 'Montant dû']);
    }

    public function testSeulsLesOutilsQuiCreentSansEcrireAPersonneSontPermis(): void
    {
        $this->assertSame(
            ['creer_brouillon_gmail', 'creer_brouillon_outlook', 'creer_tache', 'creer_tache_todo'],
            array_keys(WorkTableActions::TOOLS)
        );
        foreach (['envoyer_message_slack', 'envoyer_message_teams', 'creer_evenement_agenda_outlook', 'supprimer_tache', 'modifier_google_doc'] as $tool) {
            $this->assertFalse(WorkTableActions::isAllowed($tool), $tool . ' : à cette échelle, une erreur ne se rattrape pas.');
        }
        // Chaque outil permis a son connecteur au registre.
        foreach (WorkTableActions::TOOLS as $def) {
            $this->assertNotSame('', ConnectorRegistry::label($def['connector']));
        }
    }

    public function testLeGabaritSeLitEtSesColonnesSontVerifiees(): void
    {
        $tpl = WorkTableActions::template('{"destinataire": "{Email}", "objet": "Relance {numero}", "corps": "Bonjour {prénom},\n\nVous devez {Montant dû} €."}');
        $this->assertSame(['Email', 'numero', 'prénom', 'Montant dû'], WorkTableActions::placeholders($tpl));
        $map = WorkTableActions::bind(self::columns(), $tpl);
        $this->assertSame(['Email' => 'email', 'numero' => 'numero', 'prénom' => 'prenom', 'Montant dû' => 'montant_du'], $map);

        $args = WorkTableActions::fill($tpl, $map, ['prenom' => 'Léa', 'email' => 'lea@ex.fr', 'numero' => 'F-12', 'montant_du' => '1 250']);
        $this->assertSame('lea@ex.fr', $args['destinataire']);
        $this->assertSame('Relance F-12', $args['objet']);
        $this->assertSame("Bonjour Léa,\n\nVous devez 1 250 €.", $args['corps']);
        // Gabarit imbriqué : les listes aussi.
        $this->assertSame(['a' => ['x Léa', 3]], WorkTableActions::fill(['a' => ['x {prénom}', 3]], $map, ['prenom' => 'Léa']));

        try {
            WorkTableActions::bind(self::columns(), ['corps' => 'Bonjour {nom}']);
            $this->fail('Colonne inconnue acceptée.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('« nom » inconnue', $e->getMessage());
            $this->assertStringContainsString('prenom, email, numero, montant_du', $e->getMessage());
        }
        try {
            WorkTableActions::bind(self::columns(), ['corps' => 'Bonjour à tous']);
            $this->fail('Gabarit sans colonne accepté : toutes les lignes donneraient la même action.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('aucune colonne', $e->getMessage());
        }
        foreach (['pas du json', '[]', '["a"]', '', null, 12] as $bad) {
            try {
                WorkTableActions::template($bad);
                $this->fail('Gabarit accepté à tort.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('{colonne}', $e->getMessage());
            }
        }
    }

    public function testUneLigneIncompleteNeDonnePasDAction(): void
    {
        $map = ['Email' => 'email', 'prénom' => 'prenom'];
        $this->assertSame('', WorkTableActions::emptyColumn($map, ['email' => 'a@ex.fr', 'prenom' => 'Léa']));
        $this->assertSame('prenom', WorkTableActions::emptyColumn($map, ['email' => 'a@ex.fr', 'prenom' => '  ']));
        $this->assertSame('email', WorkTableActions::emptyColumn($map, ['prenom' => 'Léa']));
    }

    public function testLesColonnesDeResultatEtLaReprise(): void
    {
        $this->assertSame(['status' => 'brouillon_statut', 'link' => 'brouillon_lien'], WorkTableActions::resultKeys('creer_brouillon_outlook'));
        $this->assertSame(['status' => 'tache_statut', 'link' => 'tache_lien'], WorkTableActions::resultKeys('creer_tache'));

        $cols = WorkTableActions::withResultColumns(self::columns(), 'creer_brouillon_gmail');
        $this->assertSame(['prenom', 'email', 'numero', 'montant_du', 'brouillon_statut', 'brouillon_lien'], WorkTableOps::keys($cols));
        $this->assertSame($cols, WorkTableActions::withResultColumns($cols, 'creer_brouillon_gmail'), 'Ajoutées une seule fois.');

        $rows = [
            ['email' => 'a@ex.fr', 'brouillon_statut' => 'fait'],
            ['email' => 'b@ex.fr', 'brouillon_statut' => 'échec : adresse refusée'],
            ['email' => 'c@ex.fr'],
        ];
        $this->assertSame([1, 2], WorkTableActions::pending($rows, 'creer_brouillon_gmail', false), 'Une ligne faite n’est pas refaite ; un échec se retente.');
        $this->assertSame([0, 1, 2], WorkTableActions::pending($rows, 'creer_brouillon_gmail', true));
        $this->assertSame([0, 1, 2], WorkTableActions::pending($rows, 'creer_tache', false), 'Une autre action a ses propres colonnes.');
    }

    public function testLaCarteDitToutCeQuiSeraCree(): void
    {
        $table = ['name' => 'relances', 'title' => 'Clients à relancer'];
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $items[] = ['pos' => $i, 'id' => 100 + $i, 'label' => "Relance F-$i  →  c$i@ex.fr"];
        }
        $skipped = [['pos' => 7, 'reason' => 'colonne « email » vide'], ['pos' => 9, 'reason' => 'colonne « email » vide'], ['pos' => 11, 'reason' => 'Adresse invalide']];
        $card = WorkTableActions::preview('creer_brouillon_gmail', $table, $items, $skipped, 'Bonjour Léa, votre facture…', '/assets/img/gmail.svg');
        $this->assertSame('Créer 3 brouillons Gmail', $card['action']);
        $this->assertSame('Table « Clients à relancer »', $card['fichier']);
        $this->assertSame('/assets/img/gmail.svg', $card['icone']);
        $text = implode(' | ', $card['impacts']);
        $this->assertStringContainsString('3 brouillons Gmail — une par ligne', $text);
        $this->assertStringContainsString('3 lignes écartées : colonne « email » vide ; Adresse invalide', $text, 'Chaque motif une fois.');
        $this->assertStringContainsString('Rien n’est envoyé', $text);
        $this->assertStringContainsString('brouillon_statut', $text);
        $this->assertStringContainsString('Exemple (première ligne) :', $card['apercu']);
        $this->assertStringContainsString("1. Relance F-1 → c1@ex.fr\n2. Relance F-2 → c2@ex.fr", $card['apercu'], 'Toutes les lignes sont listées, une par ligne.');

        $task = WorkTableActions::preview('creer_tache', $table, [$items[0]], []);
        $this->assertSame('Créer 1 tâche Google', $task['action']);
        $this->assertStringNotContainsString('envoyé', implode(' ', $task['impacts']));
        $this->assertArrayNotHasKey('icone', $task);

        I18n::use('en');
        $this->assertSame('Create 3 Gmail drafts', WorkTableActions::preview('creer_brouillon_gmail', $table, $items, [])['action']);
    }

    public function testLeBilanDitCeQuiEstFaitCeQuiAEchoueEtCeQuiReste(): void
    {
        $ok = WorkTableActions::resultMessage('creer_brouillon_gmail', 118, 2, 0, 'relances', false);
        $this->assertStringStartsWith('✅ 118 brouillons Gmail créés — table « relances ».', $ok);
        $this->assertStringContainsString('2 lignes en échec', $ok);
        $this->assertStringContainsString('brouillon_statut', $ok);
        $this->assertStringNotContainsString('arrêtée', $ok);

        $stopped = WorkTableActions::resultMessage('creer_tache_todo', 40, 0, 60, 'suivi', true);
        $this->assertStringContainsString('40 tâches Microsoft To Do créées', $stopped);
        $this->assertStringContainsString('60 lignes n’ont pas été traitées', $stopped);

        $none = WorkTableActions::resultMessage('creer_tache', 0, 5, 0, 'suivi', false);
        $this->assertStringStartsWith('⚠️', $none);

        $this->assertStringStartsWith('⏳', WorkTableActions::startedMessage('creer_brouillon_outlook', 12, 'Clients'));
        $this->assertStringContainsString('12 brouillons Outlook', WorkTableActions::startedMessage('creer_brouillon_outlook', 12, 'Clients'));
    }
}
