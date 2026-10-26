<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feuilles de style de l'application : accolades équilibrées.
 *
 * Une accolade fermante perdue n'est signalée par RIEN — ni le navigateur, ni
 * une erreur : tout ce qui suit glisse dans le bloc resté ouvert. Payé au
 * rebase des tâches programmées : le `@media (max-width: 768px)` de l'écran
 * Slack a perdu sa fermeture, et toute la section suivante (horloge de
 * l'assistant, bouton « Ajuster », page des tâches) ne s'appliquait plus que
 * sur mobile. Main.css s'allonge en fin de fichier à chaque évolution, et
 * chaque conflit s'y règle à la main : le test dit où le bloc s'est ouvert.
 */
final class StylesheetSyntaxTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function feuilles(): iterable
    {
        foreach (glob(ROOT . '/public/assets/css/*.css') ?: [] as $fichier) {
            yield basename($fichier) => [$fichier];
        }
    }

    #[DataProvider('feuilles')]
    public function testLesAccoladesSontEquilibrees(string $fichier): void
    {
        $css = self::neutraliser((string)file_get_contents($fichier));
        $ouvertes = [];
        $ligne = 1;
        $n = strlen($css);
        for ($i = 0; $i < $n; $i++) {
            $c = $css[$i];
            if ($c === "\n") {
                $ligne++;
            } elseif ($c === '{') {
                $ouvertes[] = $ligne;
            } elseif ($c === '}') {
                $this->assertNotEmpty($ouvertes, basename($fichier) . " : accolade fermante sans ouvrante, ligne $ligne.");
                array_pop($ouvertes);
            }
        }
        $this->assertSame(
            [],
            $ouvertes,
            basename($fichier) . ' : bloc jamais refermé, ouvert ligne ' . implode(', ', $ouvertes)
                . ' — tout ce qui le suit en hérite (un @media avale le reste du fichier).'
        );
    }

    /**
     * Commentaires et chaînes remplacés par des espaces, retours à la ligne
     * gardés : une accolade y est du texte, et les numéros de ligne restent
     * ceux du fichier.
     */
    private static function neutraliser(string $css): string
    {
        $blanc = static fn(array $m): string => preg_replace('/[^\n]/', ' ', $m[0]);
        $css = preg_replace_callback('#/\*.*?\*/#s', $blanc, $css);
        return preg_replace_callback('/"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'/', $blanc, (string)$css);
    }

    public function testLaSectionDesTachesProgrammeesEstAuPremierNiveau(): void
    {
        // Le symptôme exact du bogue : la règle de l'horloge existait, mais
        // dans un @media. Au premier niveau, elle vaut sur tous les écrans.
        $css = self::neutraliser((string)file_get_contents(ROOT . '/public/assets/css/main.css'));
        $pos = strpos($css, '.assistant-identity-schedule {');
        $this->assertIsInt($pos);
        $avant = substr($css, 0, $pos);
        $this->assertSame(substr_count($avant, '{'), substr_count($avant, '}'));
    }
}
