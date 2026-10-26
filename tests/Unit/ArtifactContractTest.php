<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contrat des pièces (ArtifactContract) — cf. CLAUDE.md « Workflows ». Une
 * étape remet sa pièce DANS sa réponse ; ce contrat dit au modèle comment
 * l'écrire et la relit. Une lecture qui échoue rend sa raison : elle est
 * rendue au modèle pour une seconde tentative.
 */
final class ArtifactContractTest extends TestCase
{
    private static function piece(string $form, array $extra = []): array
    {
        return $extra + ['key' => 'p', 'name' => 'Synthèse', 'form' => $form, 'fields' => [], 'format' => 'docx'];
    }

    public function testUnTexteEstLaReponseSansEnveloppe(): void
    {
        $r = ArtifactContract::extract(self::piece('text'), "```\nLe texte.\n```");
        $this->assertTrue($r['ok']);
        $this->assertSame('Le texte.', $r['content']['text']);
        $this->assertFalse(ArtifactContract::extract(self::piece('text'), '')['ok']);
        $this->assertFalse(ArtifactContract::extract(self::piece('text'), "⚠️ Erreur\n\nLe modèle…")['ok'], 'Une réponse en erreur n’est pas une pièce.');
    }

    public function testUneListeSeLitPuceParPuce(): void
    {
        $r = ArtifactContract::extract(self::piece('list'), "Voici :\n- **Un**\n* Deux\n3. Trois\nFin.");
        $this->assertTrue($r['ok']);
        $this->assertSame(['Un', 'Deux', 'Trois'], $r['content']['items']);
        $sans = ArtifactContract::extract(self::piece('list'), 'Aucune puce ici.');
        $this->assertFalse($sans['ok']);
        $this->assertNotSame('', $sans['error'], 'La raison est rendue au modèle.');
    }

    public function testUnTableauEstLePremierTableauMarkdown(): void
    {
        $reply = "Intro\n\n| Qui | Quoi |\n|---|---|\n| Paul | Devis |\n| Marie | Planning |\n\nFin";
        $r = ArtifactContract::extract(self::piece('table'), $reply);
        $this->assertTrue($r['ok']);
        $this->assertSame([['Qui', 'Quoi'], ['Paul', 'Devis'], ['Marie', 'Planning']], $r['content']['rows']);
        $this->assertStringContainsString('| Paul | Devis |', ArtifactContract::tableMarkdown($r['content']['rows']));
        $this->assertSame('2 lignes', ArtifactContract::summary(self::piece('table'), $r['content']));
        $this->assertFalse(ArtifactContract::extract(self::piece('table'), 'Pas de tableau')['ok']);
    }

    public function testUneFicheEstLeDernierBlocJsonAuxValeursTypees(): void
    {
        $piece = self::piece('fiche', ['fields' => [
            ['key' => 'client', 'label' => 'Client', 'type' => 'text'],
            ['key' => 'budget', 'label' => 'Budget estimé', 'type' => 'number'],
            ['key' => 'limite', 'label' => 'Date limite', 'type' => 'date'],
        ]]);
        $reply = "Synthèse du marché.\n\n```json\n{\"client\": \"Ville de Lyon\", \"Budget estimé\": \"180 000 €\", \"limite\": \"30/10/2026\"}\n```";
        $r = ArtifactContract::extract($piece, $reply);
        $this->assertTrue($r['ok']);
        $this->assertSame('Ville de Lyon', $r['content']['fields']['client']);
        $this->assertSame(180000, $r['content']['fields']['budget'], 'La clé par libellé est retrouvée, et la valeur typée.');
        $this->assertSame('2026-10-30', $r['content']['fields']['limite']);
        $this->assertSame('Synthèse du marché.', $r['content']['summary']);
        $this->assertStringContainsString('- Budget estimé : 180', ArtifactContract::ficheText($piece, $r['content']));

        $this->assertFalse(ArtifactContract::extract($piece, 'Pas de JSON.')['ok']);
        $this->assertFalse(ArtifactContract::extract($piece, "```json\n{\"autre\": 1}\n```")['ok'], 'Aucune clé attendue : refus.');
    }

    public function testUnDocumentEstLeBlocNommeJusquAuDernierDelimiteur(): void
    {
        $piece = self::piece('document', ['format' => 'docx']);
        $reply = "Voici le mémoire.\n\n```markdown fichier=\"memoire.docx\"\n# Mémoire\n\nUn exemple :\n\n```\ncode\n```\n\nFin.\n```\n";
        $r = ArtifactContract::extract($piece, $reply);
        $this->assertTrue($r['ok']);
        $this->assertSame('docx', $r['content']['format']);
        $this->assertStringStartsWith('# Mémoire', $r['content']['source']);
        $this->assertStringContainsString("```\ncode\n```", $r['content']['source'], 'Un bloc de code intérieur ne ferme pas le document.');
        $this->assertStringEndsWith('Fin.', $r['content']['source']);
        $this->assertSame('Synthèse.docx', ArtifactContract::filename($piece));

        // Sans bloc : une réponse substantielle EST le document ; trop courte, refus.
        $this->assertTrue(ArtifactContract::extract($piece, str_repeat('Paragraphe rédigé. ', 20))['ok']);
        $this->assertFalse(ArtifactContract::extract($piece, 'Court.')['ok']);
    }

    public function testLeRenduEstBorneEtLeResumeParle(): void
    {
        $long = str_repeat('mot ', 30000);
        $rendu = ArtifactContract::render(self::piece('text'), ['text' => $long], 1000);
        $this->assertLessThan(1200, mb_strlen($rendu));
        $this->assertStringContainsString('pièce tronquée', $rendu);
        $this->assertSame('3 mots', ArtifactContract::summary(self::piece('text'), ['text' => 'un deux trois']));
        $this->assertSame('2 éléments', ArtifactContract::summary(self::piece('list'), ['items' => ['a', 'b']]));
    }

    public function testChaqueFormeAUneConsigneEtUnLibelle(): void
    {
        foreach (WorkflowSpec::FORMS as $form) {
            $piece = self::piece($form, ['fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text']]]);
            $this->assertNotSame('', ArtifactContract::instruction($piece), "Consigne de la forme $form");
            $this->assertNotSame($form, ArtifactContract::formLabel($form), "Libellé de la forme $form");
        }
        foreach (WorkflowSpec::DOC_FORMATS as $format) {
            $this->assertNotSame('', ArtifactContract::formatLabel($format));
        }
    }

    public function testLeDernierObjetJsonEstRetrouveMemeSansBloc(): void
    {
        $this->assertSame(['a' => 1], ArtifactContract::lastJsonObject('Texte {"a": 1} fin'));
        $this->assertSame(['b' => 2], ArtifactContract::lastJsonObject("```json\n{\"a\": 1}\n```\n```json\n{\"b\": 2}\n```"));
        $this->assertNull(ArtifactContract::lastJsonObject('rien'));
    }
}
