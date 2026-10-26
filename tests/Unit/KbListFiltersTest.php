<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Garde de la barre d'outils de /admin/knowledge-bases (filtre par source,
 * tri) et du compteur d'utilisation qui l'alimente (migration 068).
 *
 * Le tri « Utilisation » est le seul de la barre qui ne se lise pas dans la
 * carte : il repose sur deux colonnes incrémentées ailleurs, dans le chemin
 * de réponse du chat. Les trois maillons (colonne, `kb_id` remonté avec les
 * chunks, appel au compteur) cassent en silence s'ils sont dissociés — d'où
 * un test de câblage plutôt qu'un test de valeur.
 */
final class KbListFiltersTest extends TestCase
{
    private function fichier(string $rel): string
    {
        $chemin = ROOT . '/' . $rel;
        $this->assertFileExists($chemin, $rel . ' est introuvable');
        return (string)file_get_contents($chemin);
    }

    // ============ Compteur d'utilisation (migration 068) ============

    public function testLaMigrationPoseLesDeuxColonnesDUtilisation(): void
    {
        $sql = $this->fichier('database/migrations/068_kb_usage_counters.sql');
        $this->assertMatchesRegularExpression('/ALTER TABLE\s+knowledge_bases/i', $sql);
        $this->assertStringContainsString('query_count', $sql);
        $this->assertStringContainsString('last_used_at', $sql);
    }

    public function testLeCompteurEstEcritParUneSeuleMethode(): void
    {
        $this->assertTrue(
            method_exists(KnowledgeBase::class, 'markUsedFromChunks'),
            'KnowledgeBase::markUsedFromChunks est le seul écrivain des compteurs.'
        );

        // Personne d'autre ne touche aux colonnes : un second point d'écriture
        // divergerait (comptage des recherches au lieu des contributions).
        $ecrivains = [];
        $arbre = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT . '/src'));
        foreach ($arbre as $fichier) {
            if ($fichier->getExtension() !== 'php') { continue; }
            $code = (string)file_get_contents((string)$fichier);
            if (preg_match('/query_count\s*=\s*query_count\s*\+/i', $code) === 1) {
                $ecrivains[] = $fichier->getFilename();
            }
        }
        $this->assertSame(['KnowledgeBase.php'], $ecrivains);
    }

    public function testLesDeuxCheminsDeRecuperationMarquentLUtilisation(): void
    {
        // Chemin vectoriel : le contrôleur marque les bases qui ont contribué.
        $chat = $this->fichier('src/Controllers/ChatController.php');
        $this->assertStringContainsString('KnowledgeBase::markUsedFromChunks($rows)', $chat);

        // Chemin dégradé (fallback lexical FULLTEXT) : compté aussi, sinon une
        // panne Qdrant ferait passer toutes les bases pour inutilisées.
        $doc = $this->fichier('src/Models/KnowledgeBaseDocument.php');
        $this->assertStringContainsString('KnowledgeBase::markUsedFromChunks($rows)', $doc);
    }

    public function testLesChunksRemontentLeurBase(): void
    {
        // Sans `c.kb_id` dans le SELECT, markUsedFromChunks ne marque RIEN et
        // le compteur reste à zéro sans la moindre erreur.
        $doc = $this->fichier('src/Models/KnowledgeBaseDocument.php');
        $this->assertSame(
            3,
            substr_count($doc, 'c.kb_id,'),
            'Les trois requêtes de chunks (2 lexicales + chunksByIds) doivent remonter kb_id.'
        );
    }

    // ============ Barre d'outils de la liste ============

    public function testLaBarreDOutilsEstCableeDansLeGabarit(): void
    {
        $tpl = $this->fichier('templates/admin/knowledge-bases.php');

        foreach (['kb-listbar', 'kb-srcfilter', 'id="kbSort"', 'id="kbFilter"', 'id="kbNoMatch"'] as $marqueur) {
            $this->assertStringContainsString($marqueur, $tpl, 'Barre d\'outils : ' . $marqueur . ' manquant.');
        }

        // Les cartes portent les clés de tri : le tri se fait sur les nœuds
        // rendus, jamais sur un second rendu qui dériverait des droits.
        foreach (['data-src=', 'data-size=', 'data-docs=', 'data-usage=', 'data-lastused=', 'data-assistants=', 'data-created='] as $attr) {
            $this->assertStringContainsString($attr, $tpl, 'Attribut de tri ' . $attr . ' manquant sur la carte.');
        }
    }

    public function testChaqueTriProposeAUnComparateur(): void
    {
        $tpl = $this->fichier('templates/admin/knowledge-bases.php');

        preg_match('/\$kbSorts = \[(.*?)\];/s', $tpl, $m);
        $this->assertNotEmpty($m, 'Liste des tris ($kbSorts) introuvable.');
        preg_match_all("/'([a-z_]+)'\s*=>/", $m[1], $cles);
        $this->assertNotEmpty($cles[1]);

        preg_match('/const KB_COMPARATORS = \{(.*?)\n    \};/s', $tpl, $c);
        $this->assertNotEmpty($c, 'Table des comparateurs JS introuvable.');

        foreach ($cles[1] as $cle) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($cle, '/') . '\s*:/',
                $c[1],
                'Le tri « ' . $cle . " » est proposé sans comparateur : il ne trierait rien."
            );
        }
    }

    public function testLaSourceDUneCarteVientDUneSeuleFonction(): void
    {
        $tpl = $this->fichier('templates/admin/knowledge-bases.php');

        // Compteurs des puces et attribut de la carte doivent classer une base
        // à l'identique, sinon une puce annoncerait 3 bases et en montrerait 2.
        $this->assertSame(1, substr_count($tpl, '$kbSourceKey = static function'));
        $this->assertSame(2, substr_count($tpl, '$kbSourceKey('));

        // Une seule table de fournisseurs (puces, pastille de la carte, fiche
        // et schéma) : KbDiagram::SOURCES, jamais recopiée dans la liste.
        $this->assertSame(1, substr_count($tpl, '$syncProviderMeta = KbDiagram::SOURCES;'));
        $this->assertStringNotContainsString('$syncProviderMeta = [', $tpl);
    }

    public function testUneCarteMasqueeEstReellementMasquee(): void
    {
        // `.kb-card` est en display:flex — sans cette règle, l'attribut
        // `hidden` posé par le filtre n'aurait aucun effet visible.
        $css = $this->fichier('public/assets/css/main.css');
        $this->assertStringContainsString('.kb-card[hidden] { display: none !important; }', $css);

        foreach (['.kb-listbar', '.kb-srcbtn', '.kb-sortbox', '.kb-nomatch'] as $classe) {
            $this->assertStringContainsString($classe . ' ', $css, 'CSS manquant : ' . $classe);
        }
    }

    public function testLesPreferencesDeListeSontCloisonneesParUtilisateur(): void
    {
        $tpl = $this->fichier('templates/admin/knowledge-bases.php');
        $this->assertMatchesRegularExpression(
            "/KB_PREFS_KEY = 'kbListPrefs:<\?= \(int\)\(\\\$currentUser\['id'\] \?\? 0\) \?>'/",
            $tpl,
            'Navigateur partagé : la préférence de filtre doit être scopée à l\'utilisateur.'
        );
    }
}
