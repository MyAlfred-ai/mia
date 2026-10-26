<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Nouveautés — forme pure (aucune base, aucun git) et câblage.
 *
 * Ce qui casse en silence ici : deux entrées portant le même identifiant (la
 * seconde hériterait de la date de la première), une métadonnée d'outillage
 * qui fuite dans le document lu par l'assistant d'aide, un identifiant retouché
 * qui re-date une vieille entrée, et surtout un générateur qui cesserait d'être
 * déterministe — le hook amende le commit avec sa sortie, deux sorties
 * différentes sur le même historique feraient amender sans fin.
 */
final class NouveautesTest extends TestCase
{
    private const EXEMPLE = <<<'MD'
# Nouveautés de MIA

<!--
  Instructions de rédaction, jamais publiées.
-->

## Un titre lisible
<!-- id: mon-entree | genre: nouveaute | pour: tous -->

Première phrase. Seconde phrase.

## Un correctif
<!-- id: autre-entree | genre: correctif | pour: administrateurs | date: 2026-01-15 | version: 1.0.7 -->

Le bouton passait pour désactivé.
MD;

    // ---------- Analyse ----------

    public function testUneEntreePorteSonTitreSesMetadonneesEtSonCorps(): void
    {
        $e = Nouveautes::parse(self::EXEMPLE);

        $this->assertCount(2, $e);
        $this->assertSame('mon-entree', $e[0]['id']);
        $this->assertSame('Un titre lisible', $e[0]['titre']);
        $this->assertSame('nouveaute', $e[0]['genre']);
        $this->assertSame('tous', $e[0]['pour']);
        $this->assertSame('Première phrase. Seconde phrase.', $e[0]['corps']);

        // Date et version écrites à la main : reprises telles quelles.
        $this->assertSame('2026-01-15', $e[1]['date']);
        $this->assertSame('1.0.7', $e[1]['version']);
        $this->assertSame('correctif', $e[1]['genre']);
    }

    public function testLePreambuleNEstJamaisUneEntree(): void
    {
        // Le bloc de commentaires en tête porte les règles de rédaction : il
        // précède le premier `##` et ne doit rien produire.
        $this->assertCount(2, Nouveautes::parse(self::EXEMPLE));
    }

    public function testDateEtVersionSontFacultatives(): void
    {
        // Sans elles, le générateur les pose depuis l'historique git ; leur
        // absence ne doit donc jamais faire échouer l'analyse.
        $e = Nouveautes::parse(self::EXEMPLE);
        $this->assertSame('', $e[0]['date']);
        $this->assertSame('', $e[0]['version']);
        $this->assertSame([], Nouveautes::valider($e));
    }

    public function testUnSousTitreNOuvrePasUneEntree(): void
    {
        $md = "## Titre\n<!-- id: a | genre: nouveaute | pour: tous -->\n\nDébut.\n\n### Détail\n\nSuite.\n";
        $e  = Nouveautes::parse($md);
        $this->assertCount(1, $e);
        $this->assertStringContainsString('Détail', $e[0]['corps']);
    }

    public function testLesCommentairesNArriventJamaisDansLeCorps(): void
    {
        $md = "## Titre\n<!-- id: a | genre: nouveaute | pour: tous -->\n\nTexte visible.\n<!-- note interne -->\nSuite.\n";
        $corps = Nouveautes::parse($md)[0]['corps'];
        $this->assertStringNotContainsString('note interne', $corps);
        $this->assertStringContainsString('Texte visible', $corps);
        $this->assertStringContainsString('Suite.', $corps);
    }

    // ---------- Validation ----------

    public function testUnIdentifiantDupliqueEstRefuse(): void
    {
        $md = "## A\n<!-- id: meme | genre: nouveaute | pour: tous -->\n\nUn.\n\n"
            . "## B\n<!-- id: meme | genre: nouveaute | pour: tous -->\n\nDeux.\n";
        $erreurs = Nouveautes::valider(Nouveautes::parse($md));
        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('déjà utilisé', implode(' ', $erreurs));
    }

    public function testLesFormesFautivesSontSignaleesUneParUne(): void
    {
        $cas = [
            'identifiant manquant' => "## A\n\nTexte.\n",
            'invalide'             => "## A\n<!-- id: Mon_Id | genre: nouveaute | pour: tous -->\n\nTexte.\n",
            'genre'                => "## A\n<!-- id: a | genre: inventé | pour: tous -->\n\nTexte.\n",
            'public'               => "## A\n<!-- id: a | genre: nouveaute | pour: martiens -->\n\nTexte.\n",
            'aucun texte'          => "## A\n<!-- id: a | genre: nouveaute | pour: tous -->\n",
            'AAAA-MM-JJ'           => "## A\n<!-- id: a | genre: nouveaute | pour: tous | date: 15/01/2026 -->\n\nTexte.\n",
        ];
        foreach ($cas as $attendu => $md) {
            $erreurs = Nouveautes::valider(Nouveautes::parse($md));
            $this->assertNotEmpty($erreurs, "aucune erreur pour « $attendu »");
            $this->assertStringContainsString($attendu, implode(' ', $erreurs));
        }
    }

    public function testUnTexteInterminableEstRefuse(): void
    {
        $md = "## A\n<!-- id: a | genre: nouveaute | pour: tous -->\n\n"
            . str_repeat('mot ', Nouveautes::MAX_CORPS) . "\n";
        $this->assertNotEmpty(Nouveautes::valider(Nouveautes::parse($md)));
    }

    // ---------- Publication ----------

    public function testLaPublicationEstBorneeEtGardeLOrdreDuFichier(): void
    {
        $md = '';
        for ($i = 1; $i <= 25; $i++) {
            $md .= "## Titre $i\n<!-- id: e$i | genre: nouveaute | pour: tous -->\n\nTexte $i.\n\n";
        }
        $publiees = Nouveautes::publiables(Nouveautes::parse($md));

        $this->assertCount(Nouveautes::MAX_PUBLIEES, $publiees);
        // L'ordre du fichier fait foi : trier sur la date ferait dériver la
        // liste de ce que le rédacteur a sous les yeux, et une entrée dont la
        // date n'est pas encore posée passerait en queue sans raison visible.
        $this->assertSame('e1', $publiees[0]['id']);
        $this->assertSame('e20', $publiees[19]['id']);
    }

    public function testVingtEntreesSontDemandees(): void
    {
        $this->assertSame(20, Nouveautes::MAX_PUBLIEES);
    }

    // ---------- Rendu pour l'assistant d'aide ----------

    public function testLeDocumentIngereNePorteAucuneMetadonneeTechnique(): void
    {
        $rendu = Nouveautes::renderForKb(Nouveautes::parse(self::EXEMPLE));

        // L'assistant a interdiction d'employer un terme technique : lui
        // servir des identifiants d'outillage serait l'inviter à les citer.
        $this->assertStringNotContainsString('<!--', $rendu);
        $this->assertStringNotContainsString('mon-entree', $rendu);
        $this->assertStringNotContainsString('id:', $rendu);

        // Ce qui sert à répondre, en revanche, reste : date lisible, genre,
        // public concerné.
        $this->assertStringContainsString('Un titre lisible', $rendu);
        $this->assertStringContainsString('15 janvier 2026', $rendu);
        $this->assertStringContainsString('Correctif', $rendu);
        $this->assertStringContainsString('Administration', $rendu);
    }

    public function testLaDateEstEcriteEnFrancais(): void
    {
        $this->assertSame('21 août 2026', Nouveautes::dateLisible('2026-08-21'));
        $this->assertSame('1 mai 2026', Nouveautes::dateLisible('2026-05-01'));
        // Entrée non datée ou format inattendu : rendu tel quel, jamais une
        // date inventée.
        $this->assertSame('', Nouveautes::dateLisible(''));
        $this->assertSame('demain', Nouveautes::dateLisible('demain'));
    }

    // ---------- Le fichier réel ----------

    public function testLeFichierLivreEstValideEtFournitVingtEntrees(): void
    {
        $entrees = Nouveautes::depuisFichier(ROOT);

        $this->assertSame([], Nouveautes::valider($entrees));
        $this->assertGreaterThanOrEqual(
            Nouveautes::MAX_PUBLIEES,
            count($entrees),
            'La modale annonce 20 nouveautés : le fichier doit pouvoir les fournir.'
        );
    }

    public function testAucuneNouveauteNeParleTechnique(): void
    {
        // La règle d'écriture, rendue exécutable : ces marques trahissent un
        // texte écrit pour un développeur, jamais pour la personne qui lit la
        // modale.
        $interdits = ['.php', '.js', 'src/', 'SELECT ', '->', 'localStorage', 'JSON', 'API ', 'OAuth'];

        foreach (Nouveautes::depuisFichier(ROOT) as $e) {
            $texte = $e['titre'] . ' ' . $e['corps'];
            foreach ($interdits as $marque) {
                $this->assertStringNotContainsString(
                    $marque,
                    $texte,
                    "« {$e['titre']} » emploie « $marque » — à traduire en langage visible à l'écran."
                );
            }
        }
    }

    // ---------- Câblage ----------

    public function testLaClasseEstChargeeAvantSonUtilisateur(): void
    {
        // Pas d'autoloader : l'ordre EST le chargement. DefaultAssistant
        // référence Nouveautes::SOURCE dans une constante de classe.
        $classes = file_get_contents(ROOT . '/config/classes.php');
        $iNouv   = strpos($classes, 'src/Changelog/Nouveautes.php');
        $iAssist = strpos($classes, 'src/Models/DefaultAssistant.php');

        $this->assertNotFalse($iNouv, 'Nouveautes absente de config/classes.php');
        $this->assertNotFalse($iAssist);
        $this->assertLessThan($iAssist, $iNouv);
    }

    public function testLaModaleEstUnPartialPartageParLesDeuxEcrans(): void
    {
        $partial = ROOT . '/templates/partials/whats-new-modal.php';
        $this->assertFileExists($partial);
        $this->assertFileExists(ROOT . '/public/assets/js/whats-new.js');

        foreach (['templates/chat/index.php', 'templates/about/index.php'] as $gabarit) {
            $this->assertStringContainsString(
                'whats-new-modal.php',
                file_get_contents(ROOT . '/' . $gabarit),
                "$gabarit ne charge pas la modale commune"
            );
        }

        // La modale vivait en double : un exemplaire recopié dans chat.js
        // divergerait au premier ajustement.
        $chatJs = file_get_contents(ROOT . '/public/assets/js/chat.js');
        $this->assertStringNotContainsString('changelog.json', $chatJs);
        $this->assertStringNotContainsString('versionModal', $chatJs);
    }

    public function testLesTroisIdentifiantsDeLaModaleSontServisParLePartial(): void
    {
        // Les boutons de version (pied du chat, page À propos) visent
        // #versionBtn ; le partial doit porter les cibles attendues par le JS.
        $partial = file_get_contents(ROOT . '/templates/partials/whats-new-modal.php');
        foreach (['versionModal', 'versionList', 'versionClose'] as $id) {
            $this->assertStringContainsString('"' . $id . '"', $partial);
        }
        foreach (['templates/chat/index.php', 'templates/about/index.php'] as $gabarit) {
            $this->assertStringContainsString('versionBtn', file_get_contents(ROOT . '/' . $gabarit));
        }
    }

    public function testLAssistantDAideIngereLesNouveautes(): void
    {
        $src = file_get_contents(ROOT . '/src/Models/DefaultAssistant.php');
        $this->assertStringContainsString('Nouveautes::SOURCE', $src);
        $this->assertStringContainsString('Nouveautes::renderForKb', $src);

        // Hash PAR DOCUMENT : sans lui, les 200 Ko de guides repasseraient par
        // le chunking et les embeddings à chaque évolution fonctionnelle,
        // puisque le fichier des nouveautés bouge à chaque fois.
        $this->assertStringContainsString('mia_help_docs_sigs', $src);
        $this->assertStringNotContainsString(
            'dropCollection',
            $src,
            'Détruire la collection effacerait les vecteurs des documents inchangés.'
        );
    }

    public function testLeGenerateurEstDeterministe(): void
    {
        // Le hook amende le commit avec la sortie du générateur : une horloge
        // dans le fichier publié ferait diverger deux exécutions sur le même
        // historique, donc amender à l'infini.
        $script = file_get_contents(ROOT . '/scripts/build_changelog.php');
        foreach (['date(', 'time()', 'DateTime', 'mt_rand', 'uniqid'] as $marque) {
            $this->assertStringNotContainsString($marque, $script);
        }
    }

    public function testLeHookDelegueAuGenerateur(): void
    {
        $hook = file_get_contents(ROOT . '/scripts/hooks/post-commit');
        $this->assertStringContainsString('scripts/build_changelog.php', $hook);
        // Le repli sans PHP maintient VERSION à jour : un poste sans CLI ne
        // doit pas faire dériver le numéro de version.
        $this->assertStringContainsString('VERSION_BASE', $hook);
        $this->assertStringContainsString('MIA_VERSION_HOOK_RUNNING', $hook);
    }

    public function testLeFichierPublieEstLuParLaModale(): void
    {
        $charge = Nouveautes::chargePubliee(ROOT);
        $this->assertNotSame('', $charge['version']);
        $this->assertCount(Nouveautes::MAX_PUBLIEES, $charge['nouveautes']);
        $this->assertSame(Nouveautes::dernierId(ROOT), $charge['nouveautes'][0]['id']);

        // Sujets de commit : conservés pour le détail technique, jamais
        // affichés par défaut.
        $this->assertLessThanOrEqual(Nouveautes::MAX_COMMITS, count($charge['commits']));
        $js = file_get_contents(ROOT . '/public/assets/js/whats-new.js');
        $this->assertStringContainsString('IS_STAFF', $js);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaRegleDeMiseAJourEstDocumentee(): void
    {
        $claude = file_get_contents(ROOT . '/CLAUDE.md');
        $this->assertStringContainsString('docs/NOUVEAUTES.md', $claude);
    }
}
