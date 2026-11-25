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

    public function testLaRechercheEtLesFiltresSontCablesDansLeGabarit(): void
    {
        // Piste D : une grande recherche, la portée (Tout / Bases / Tables) et
        // la visibilité (Privées / Communes / Publiques), sur les lignes RENDUES.
        $tpl = $this->fichier('templates/admin/knowledge-bases.php');
        foreach (['id="kbListSearch"', 'data-scope="all"', 'data-scope="base"', 'data-scope="table"', 'data-vis-filter=', 'id="kbListNone"', 'data-group="base"', 'data-group="table"'] as $marqueur) {
            $this->assertStringContainsString($marqueur, $tpl, 'Liste : ' . $marqueur . ' manquant.');
        }
        foreach (['data-search=', 'data-vis=', 'data-kb-row='] as $attr) {
            $this->assertStringContainsString($attr, $tpl, 'Attribut ' . $attr . ' manquant sur la ligne.');
        }
        $this->assertStringContainsString('<script src="/assets/js/kb-list.js', $tpl);
    }

    public function testLaRechercheIgnoreAccentsEtCasseEtVeutTousLesMots(): void
    {
        $js = $this->fichier('public/assets/js/kb-list.js');
        $this->assertStringContainsString(".normalize('NFD').replace(/[\\u0300-\\u036f]/g, '').toLowerCase()", $js);
        $this->assertStringContainsString('words.every((w) => row.dataset.fold.includes(w))', $js);
        // « / » met le curseur dans la recherche, jamais quand on écrit déjà.
        $this->assertStringContainsString("if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;", $js);
        $this->assertDoesNotMatchRegularExpression('/[éèêàùçÉÈÀ]/u', preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $js), 'Aucun libellé dans le script.');
    }

    public function testLaVisibiliteDUneLigneVientDUneSeuleRegle(): void
    {
        $tpl = $this->fichier('templates/admin/knowledge-bases.php');
        // Le compteur d'un filtre et l'attribut de la ligne classent une base à
        // l'identique : une seule lecture de KnowledgeBase::visibility().
        $this->assertSame(1, substr_count($tpl, 'KnowledgeBase::visibility($kb)'));
        $this->assertStringContainsString('$visCnt[$vKey]++;', $tpl);
        $this->assertStringContainsString("'vis'    => \$vKey,", $tpl);
        // Icône, nom et phrase : KbPages, partagé avec la consultation et la fiche.
        $this->assertStringContainsString('KbPages::visibilityIcon($r[\'vis\'])', $tpl);
        $this->assertStringContainsString('KbPages::visibility($vKey', $tpl);
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

    public function testLaRechercheCouvreLesNomsDesDocuments(): void
    {
        // « Où est le contrat Durand ? » : les noms des documents de chaque base
        // sont lus en UNE requête et rejoignent le texte cherché de sa ligne.
        $ctrl = $this->fichier('src/Controllers/AdminController.php');
        $this->assertStringContainsString('KnowledgeBaseDocument::namesByKb(', $ctrl);
        $tpl = $this->fichier('templates/admin/knowledge-bases.php');
        $this->assertStringContainsString("(string)(\$docNamesByKb[\$kbId] ?? ''),", $tpl);
        $model = $this->fichier('src/Models/KnowledgeBaseDocument.php');
        $this->assertStringContainsString('public static function namesByKb(array $kbIds): array', $model);
    }
}
