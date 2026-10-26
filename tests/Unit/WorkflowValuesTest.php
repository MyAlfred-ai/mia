<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Valeurs des Fiches et règles des conditions (WorkflowValues) — cf. CLAUDE.md
 * « Workflows ». Une règle se calcule sans IA : la même réponse à chaque fois,
 * quelle que soit la façon dont le modèle a écrit le nombre ou la date.
 */
final class WorkflowValuesTest extends TestCase
{
    public function testLesNombresSeLisentEnFrancaisEtEnAnglais(): void
    {
        $this->assertSame(180000, WorkflowValues::number('180 000 € HT'));
        $this->assertSame(1234.5, WorkflowValues::number('1.234,50'));
        $this->assertSame(1234.5, WorkflowValues::number('1,234.50'));
        $this->assertSame(1.5, WorkflowValues::number('1,5'));
        $this->assertSame(1234567, WorkflowValues::number('1,234,567'));
        $this->assertSame(5000, WorkflowValues::number("5\u{202F}000"));
        $this->assertSame(-12, WorkflowValues::number('-12'));
        $this->assertNull(WorkflowValues::number('aucun'));
        $this->assertNull(WorkflowValues::number(['x']));
    }

    public function testLesDatesSeLisentSousPlusieursFormes(): void
    {
        $this->assertSame('2026-10-30', WorkflowValues::date('2026-10-30'));
        $this->assertSame('2026-10-30', WorkflowValues::date('30/10/2026'));
        $this->assertSame('2026-10-30', WorkflowValues::date('30.10.26'));
        $this->assertSame('2026-10-30', WorkflowValues::date('30 octobre 2026'));
        $this->assertSame('2026-10-01', WorkflowValues::date('jeudi 1er octobre 2026'));
        $this->assertSame('2026-08-15', WorkflowValues::date('15 août 2026'));
        $this->assertSame('2026-10-30', WorkflowValues::date('30 October 2026'));
        $this->assertNull(WorkflowValues::date('31/02/2026'), 'Une date impossible ne passe pas.');
        $this->assertNull(WorkflowValues::date('bientôt'));
    }

    public function testOuiNonListeEmail(): void
    {
        $this->assertTrue(WorkflowValues::yesno('Oui.'));
        $this->assertFalse(WorkflowValues::yesno('non'));
        $this->assertTrue(WorkflowValues::yesno('yes'));
        $this->assertNull(WorkflowValues::yesno('peut-être'));
        $this->assertSame(['A', 'B', 'C'], WorkflowValues::listOf("- A\n* B\n1. C"));
        $this->assertSame(['x', 'y'], WorkflowValues::listOf('x; y'));
        $this->assertSame('marie@exemple.fr', WorkflowValues::email('Marie <marie@exemple.fr>'));
        $this->assertSame('marie@exemple.fr', WorkflowValues::email('mailto:marie@exemple.fr'));
        $this->assertNull(WorkflowValues::email('marie'));
    }

    public function testUneRegleSeCalculeSurLesChampsDUneFiche(): void
    {
        $pieces = ['infos' => ['form' => 'fiche', 'fields' => [
            ['key' => 'montant', 'label' => 'Montant TTC', 'type' => 'number'],
            ['key' => 'echeance', 'label' => 'Échéance', 'type' => 'date'],
            ['key' => 'urgent', 'label' => 'Urgent', 'type' => 'yesno'],
        ]]];
        $contents = ['infos' => ['fields' => ['montant' => '6 200 €', 'echeance' => '2026-11-15', 'urgent' => false]]];

        $plus = WorkflowValues::evaluate(['join' => 'all', 'items' => [['piece' => 'infos', 'field' => 'montant', 'op' => 'gt', 'value' => '5 000']]], $pieces, $contents);
        $this->assertTrue($plus['result']);
        $this->assertCount(1, $plus['lines']);
        $this->assertStringContainsString('Montant TTC', $plus['lines'][0]);

        $deux = ['join' => 'all', 'items' => [
            ['piece' => 'infos', 'field' => 'montant', 'op' => 'gt', 'value' => '5000'],
            ['piece' => 'infos', 'field' => 'urgent', 'op' => 'yes', 'value' => ''],
        ]];
        $this->assertFalse(WorkflowValues::evaluate($deux, $pieces, $contents)['result'], 'Toutes : une seule fausse suffit.');
        $deux['join'] = 'any';
        $this->assertTrue(WorkflowValues::evaluate($deux, $pieces, $contents)['result'], 'Au moins une.');

        $date = ['items' => [['piece' => 'infos', 'field' => 'echeance', 'op' => 'lt', 'value' => '30/11/2026']]];
        $this->assertTrue(WorkflowValues::evaluate($date, $pieces, $contents)['result']);

        // Un champ absent vaut vide, jamais une erreur.
        $vide = ['items' => [['piece' => 'infos', 'field' => 'inconnu', 'op' => 'empty', 'value' => '']]];
        $this->assertTrue(WorkflowValues::evaluate($vide, $pieces, $contents)['result']);
        $this->assertFalse(WorkflowValues::evaluate(['items' => []], $pieces, $contents)['result'], 'Sans condition : non.');
    }

    public function testComparaisonDeTexteSansCasseNiAccents(): void
    {
        $this->assertTrue(WorkflowValues::compare('Société Générale', 'eq', 'societe generale', 'text'));
        $this->assertTrue(WorkflowValues::compare('Appel d’offres public', 'contains', 'OFFRES', 'text'));
        $this->assertFalse(WorkflowValues::compare('abc', 'gt', 'abd', 'text'), 'Pas d’ordre sur un texte.');
        $this->assertFalse(WorkflowValues::compare(null, 'eq', 'x', 'text'));
        $this->assertTrue(WorkflowValues::compare(['Rouge', 'Bleu'], 'contains', 'bleu', 'list'));
    }

    public function testAffichageEtOperateursProposes(): void
    {
        $this->assertSame('—', WorkflowValues::display(null, 'text'));
        $this->assertSame('30/10/2026', WorkflowValues::display('2026-10-30', 'date'));
        $this->assertSame("180\u{202F}000", WorkflowValues::display(180000, 'number'));
        $this->assertSame(['yes', 'no', 'empty'], WorkflowValues::opsFor('yesno'));
        $this->assertContains('gt', WorkflowValues::opsFor('number'));
        $this->assertNotContains('gt', WorkflowValues::opsFor('text'));
        foreach (WorkflowSpec::RULE_OPS as $op) {
            $this->assertNotSame($op, WorkflowValues::opLabel($op), "Libellé manquant : $op");
        }
    }
}
