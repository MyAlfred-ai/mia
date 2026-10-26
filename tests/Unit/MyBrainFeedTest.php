<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Nourrir le cerveau MyBrain (lot 3) — les FORMES pures : validation des
 * arguments de l'outil, définition, carte d'approbation, messages persistés.
 * Les appels (qui peut nourrir, dépôt, suivi, job) sont couverts par
 * tests/Functional/MyBrainKbPipelineTest.php.
 */
final class MyBrainFeedTest extends TestCase
{
    private const TEXTE = 'Réunion du 12 septembre avec Exemple : le projet Alpha démarre en octobre.';

    // =================== Arguments ===================

    public function testUnTexteTitreEstAccepteEtBorne(): void
    {
        $args = MyBrainFeed::normalizeArgs(['titre' => '  Réunion Exemple  ', 'texte' => self::TEXTE, 'lien' => 'https://ex.fr/cr']);
        $this->assertSame('Réunion Exemple', $args['titre']);
        $this->assertSame(self::TEXTE, $args['texte']);
        $this->assertSame('https://ex.fr/cr', $args['lien']);
        $this->assertSame('', $args['url']);

        $long = MyBrainFeed::normalizeArgs(['titre' => str_repeat('t', 500), 'texte' => self::TEXTE]);
        $this->assertSame(MyBrainFeed::MAX_TITLE, mb_strlen($long['titre']));
    }

    public function testUnePageWebSeTransmetParSonAdresse(): void
    {
        $args = MyBrainFeed::normalizeArgs(['url' => 'https://exemple.fr/article']);
        $this->assertSame('https://exemple.fr/article', $args['url']);
        $this->assertSame('', $args['texte']);
    }

    /** Chaque refus porte un message que le MODÈLE peut suivre — avant que la carte n'existe. */
    public function testLesArgumentsInvalidesSontRefusesAvecUnMessageUtile(): void
    {
        $cas = [
            'texte et url à la fois' => [['titre' => 'T', 'texte' => self::TEXTE, 'url' => 'https://ex.fr'], 'OU'],
            'rien à transmettre'     => [['titre' => 'T'], 'Rien à transmettre'],
            'texte sans titre'       => [['texte' => self::TEXTE], 'titre'],
            'texte trop court'       => [['titre' => 'T', 'texte' => 'trop court'], 'trop court'],
            'texte trop long'        => [['titre' => 'T', 'texte' => str_repeat('a', MyBrainFeed::MAX_TEXT + 1)], 'trop long'],
            'lien qui n\'est pas http' => [['titre' => 'T', 'texte' => self::TEXTE, 'lien' => 'javascript:alert(1)'], 'http'],
            'url qui n\'est pas http'  => [['url' => 'file:///etc/passwd'], 'http'],
        ];
        foreach ($cas as $nom => [$args, $attendu]) {
            try {
                MyBrainFeed::normalizeArgs($args);
                $this->fail("accepté à tort : $nom");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($attendu, $e->getMessage(), $nom);
            }
        }
    }

    // =================== Outil ===================

    public function testLOutilNExigeQueLeTitreEtNExposeLaBaseQuAvecPlusieursCerveaux(): void
    {
        $un = MyBrainFeed::toolDefinition([['id' => 1, 'name' => 'Cerveau']]);
        $this->assertSame('nourrir_mybrain', $un['name']);
        $this->assertSame(['titre'], $un['parameters']['required']);
        $this->assertSame(['titre', 'texte', 'lien', 'url'], array_keys($un['parameters']['properties']));
        $this->assertStringContainsString('QUE sur demande explicite', $un['description']);

        $deux = MyBrainFeed::toolDefinition([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]);
        $this->assertSame(['A', 'B'], $deux['parameters']['properties']['base']['enum']);
    }

    public function testLaConsigneInterditLInitiativeEtLAnnonceDUnAjoutFait(): void
    {
        $g = MyBrainFeed::toolGuidance();
        $this->assertStringContainsString('Jamais de ta propre initiative', $g);
        $this->assertStringContainsString('ne dis JAMAIS que le texte est entré dans le cerveau', $g);
        $this->assertStringContainsString('sans rien inventer', $g);
    }

    // =================== Carte d'approbation ===================

    public function testLaCarteDitOuVaLeTexteEtAuNomDeQui(): void
    {
        $p = MyBrainFeed::actionPreview(
            MyBrainFeed::normalizeArgs(['titre' => 'Réunion Exemple', 'texte' => str_repeat('Un fait. ', 60)]),
            'Cerveau MyAlfred',
            'alex@exemple.fr'
        );
        $this->assertSame('Réunion Exemple', $p['fichier']);
        $this->assertSame('/assets/img/mybrain.png', $p['icone']);
        $this->assertStringContainsString('revue du cerveau « Cerveau MyAlfred »', $p['impacts'][0]);
        $this->assertStringContainsString('alex@exemple.fr', $p['impacts'][1]);
        $this->assertCount(3, $p['impacts']);
        $this->assertLessThanOrEqual(281, mb_strlen($p['apercu']), 'extrait borné, suivi de …');

        $page = MyBrainFeed::actionPreview(MyBrainFeed::normalizeArgs(['url' => 'https://ex.fr/a']), 'C', 'a@b.fr');
        $this->assertSame('https://ex.fr/a', $page['fichier']);
        $this->assertCount(4, $page['impacts'], 'une page lue par MyBrain le dit');
    }

    // =================== Messages ===================

    public function testLeMessageDAttenteRelaieLesAvertissementsDeMyBrain(): void
    {
        $m = MyBrainFeed::pendingMessage('Réunion', 'Cerveau', ['alex@exemple.fr n\'a pas de compte myBrain']);
        $this->assertStringStartsWith('⏳ ', $m);
        $this->assertStringContainsString('« Réunion »', $m);
        $this->assertStringContainsString("\n- alex@exemple.fr n'a pas de compte myBrain", $m);
    }

    private function suivi(array $extra): array
    {
        return $extra + ['statut' => 'fait', 'position' => null, 'erreur' => null, 'zone_restreinte' => false,
            'proposition_url' => '', 'source_url' => '', 'pages' => []];
    }

    public function testUnDepotAnalyseRenvoieALaRevue(): void
    {
        $m = MyBrainFeed::resultMessage($this->suivi([
            'proposition_url' => 'https://cerveau.fr/revue?id=p1',
            'pages'           => ['wiki/clients/exemple.md', 'wiki/projets/alpha.md'],
        ]), 'Réunion', 'Cerveau');
        $this->assertSame('✅ « Réunion » est en revue dans le cerveau « Cerveau » : [trancher la proposition](https://cerveau.fr/revue?id=p1) — 2 fiches concernées.', $m);
    }

    /** Un dépôt RH : MyBrain ne dit à une application QUE cela — MIA ne peut rien répéter de plus. */
    public function testUnDepotRangeEnZoneRhNeRevelePasSaPage(): void
    {
        $m = (string)MyBrainFeed::resultMessage($this->suivi([
            'zone_restreinte' => true,
            'proposition_url' => 'https://cerveau.fr/revue?id=secret',
        ]), 'Entretien', 'Cerveau');
        $this->assertStringContainsString('zone RH', $m);
        $this->assertStringNotContainsString('revue?id=secret', $m);
    }

    public function testRienDeNeufNEstPasUnEchec(): void
    {
        $m = (string)MyBrainFeed::resultMessage($this->suivi(['source_url' => 'https://cerveau.fr/cerveau?f=s']), 'Note', 'Cerveau');
        $this->assertStringStartsWith('✅ ', $m);
        $this->assertStringContainsString('rien de neuf à proposer', $m);
        $this->assertStringContainsString('(https://cerveau.fr/cerveau?f=s)', $m);
    }

    public function testTantQueLAnalyseNEstPasFinieRienNEstConclu(): void
    {
        foreach (['en-attente', 'en-cours', 'erreur'] as $statut) {
            $this->assertNull(MyBrainFeed::resultMessage($this->suivi(['statut' => $statut]), 'T', 'C'), $statut);
        }
        $m = MyBrainFeed::stillRunningMessage('Réunion', 'Cerveau', 'https://cerveau.fr/revue');
        $this->assertStringContainsString('[revue](https://cerveau.fr/revue)', $m);
    }

    public function testLaConnexionDoitPorterLeDroitNourrirEtEtreDisponible(): void
    {
        $ok = ['rights' => 'lire,nourrir', 'status' => 'ok', 'paused' => 0];
        $this->assertTrue(MyBrainFeed::connectionAllowsFeeding($ok));
        $this->assertFalse(MyBrainFeed::connectionAllowsFeeding(['rights' => 'lire'] + $ok), 'clé sans le droit « nourrir »');
        $this->assertFalse(MyBrainFeed::connectionAllowsFeeding(['status' => 'refused'] + $ok));
        $this->assertFalse(MyBrainFeed::connectionAllowsFeeding(['paused' => 1] + $ok));
    }
}
