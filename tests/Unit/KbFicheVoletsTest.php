<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fiche d'une base en volets (templates/admin/knowledge-bases/edit.php,
 * public/assets/js/kb-form.js) : chaque question se replie sur une ligne qui
 * la résume, l'ouverture retenue par base et par appareil, un problème
 * nouveau qui rouvre les documents, et une liste de documents compacte.
 *
 * Le problème résolu : une base de cent documents, avec partage et
 * assistants, demandait plusieurs écrans de défilement pour retrouver une
 * information.
 */
final class KbFicheVoletsTest extends TestCase
{
    private const FORM   = 'templates/admin/knowledge-bases/edit.php';
    private const FORMJS = 'public/assets/js/kb-form.js';

    private static function src(string $chemin): string
    {
        return (string)file_get_contents(ROOT . '/' . $chemin);
    }

    public function testLesSectionsSontDesCartesToujoursOuvertes(): void
    {
        // Piste D : plus de volets — un menu des sections à gauche mène à
        // chaque carte. La logique des volets de kb-form.js ne trouve plus
        // aucun `details.kbf-fold` et ne fait rien ; seuls les réglages du
        // dossier synchronisé (kbf-subfold) se replient encore.
        $fiche = self::src(self::FORM);
        $this->assertStringNotContainsString('kbf-fold"', $fiche);
        $this->assertStringNotContainsString('data-fold-summary', $fiche);
        $this->assertStringContainsString('<nav class="kbx-nav"', $fiche);
        $this->assertSame(1, substr_count($fiche, 'class="kbf-subfold"'));
        // La signature d'attention suit toujours la section « Documents ».
        $this->assertStringContainsString("\$foldAttention = \$isCreate || \$kbStatus === null ? '' : KbStatus::attention(\$kbStatus);", $fiche);
        $this->assertStringContainsString('<div id="kbSectionSources" data-attention="<?= htmlspecialchars($foldAttention) ?>">', $fiche);
        $this->assertStringContainsString("'userId'      => (int)(\$currentUser['id'] ?? 0),", $fiche);
        $this->assertStringContainsString("Array.from(document.querySelectorAll('details.kbf-fold'))", self::src(self::FORMJS));
    }

    public function testLeRafraichissementPorteLaSignatureDAttention(): void
    {
        $ctrl = self::src('src/Controllers/AdminController.php');
        $this->assertMatchesRegularExpression("#'attention'\\s+=> KbStatus::attention\\(\\\$st\\),#", $ctrl);
        $js = self::src(self::FORMJS);
        $this->assertStringContainsString("Folds.attention(data.attention, data.busy);", $js);
        // Pendant un passage, rien n'est retenu : le défaut d'avant, revenu à
        // la fin du passage, passerait pour un nouveau.
        $this->assertStringContainsString('if (!foldMem || busy) return;', $js);
        $this->assertStringContainsString('delete foldMem.open.sources;', $js);
    }

    public function testLOuvertureEstRetenueParBaseEtParCompte(): void
    {
        $js = self::src(self::FORMJS);
        $this->assertStringContainsString("const FOLD_KEY   = 'kbFold:' + (Number(C.userId) || 0) + ':' + (Number(C.id) || 0);", $js);
        // Seul un geste de la personne est retenu (le clic précède la bascule).
        $this->assertStringContainsString('foldMem.open[d.dataset.fold] = !d.open;', $js);
        $this->assertStringContainsString("const foldMem = C.create || !C.id ? null :", $js);
        // Rien n'échoue sans stockage (navigation privée).
        $this->assertGreaterThanOrEqual(2, substr_count($js, 'localStorage'));
        $this->assertStringContainsString('try { localStorage.setItem(FOLD_KEY', $js);
    }

    public function testRienNeResteCacheDansUnVoletReplie(): void
    {
        $js = self::src(self::FORMJS);
        // Un champ invalide, un lien profond, un document de la fiche d'un
        // document (volet), les conseils : leurs volets s'ouvrent.
        $this->assertStringContainsString("form.addEventListener('invalid', (e) => openFoldOf(e.target), true);", $js);
        $this->assertStringContainsString("window.addEventListener('hashchange', openForHash);", $js);
        $this->assertStringContainsString('reveal: (id) => revealDoc(id),', $js);
        $this->assertMatchesRegularExpression('#function openAdvice\(\) \{\s+if \(!adviceBox\) return;\s+openFoldOf\(adviceBox\);#', $js);
        // Les réglages du dossier se rouvrent dès qu'on change de source.
        $this->assertStringContainsString('if (syncFold && p !== saved.provider) syncFold.open = true;', $js);
        $fiche = self::src(self::FORM);
        $this->assertStringContainsString('<details class="kbf-subfold" id="kbSyncFold"', $fiche);
        $this->assertStringContainsString('id="kbSyncFoldSum"', $fiche);
    }

    public function testLaListeDesDocumentsEstCompacte(): void
    {
        $js = self::src(self::FORMJS);
        $this->assertStringContainsString('const DOCS_VISIBLE    = 5;', $js);
        $this->assertStringContainsString('const DOCS_SEARCH_MIN = 8;', $js);
        // Ce qui demande l'attention d'abord.
        $this->assertStringContainsString('const rank = { error: 0, warn: 1, busy: 2, idle: 3, ok: 4 };', $js);
        $this->assertStringContainsString("tn(hidden, 'Voir l’autre document', 'Voir les {n} autres documents')", $js);
        // Filtres et « Voir les autres » sont des LIENS : un fieldset désactivé
        // (lecture seule) désactiverait des boutons.
        $this->assertStringContainsString("docsFilters.querySelector('[data-filter=\"' + f.key + '\"]')", $js);
        $this->assertMatchesRegularExpression("#const a = document\\.createElement\\('a'\\);\\s+a\\.href = '\\#kbDocumentsList';#", $js);
        $this->assertStringContainsString("docsMoreLink = document.createElement('a');", $js);
        // Recherche sans casse ni accents ; absente en lecture seule (un champ).
        $this->assertStringContainsString(".normalize('NFD').replace(/\\p{M}/gu, '')", $js);
        $this->assertStringContainsString('const searchOn = !C.readOnly', $js);
        // La fiche d'un document passe d'un document à l'autre dans l'ordre de
        // la liste filtrée, lignes repliées comprises.
        $this->assertStringContainsString('ids: () => docsView.map((d) => Number(d.id)),', $js);
        // L'ancienne barre « N à vérifier · Voir seulement ceux-là » a disparu.
        $this->assertStringNotContainsString('docsOnlyProblems', $js);

        $fiche = self::src(self::FORM);
        $this->assertStringContainsString('id="kbDocsFilters"', $fiche);
        $this->assertMatchesRegularExpression('#id="kbDocsSearch" data-autosave-skip#', $fiche, 'La recherche ne part pas dans l\'enregistrement.');
        $this->assertStringContainsString('id="kbDocsMore"', $fiche);
    }

    public function testLesVoletsSontDessines(): void
    {
        $css = self::src('public/assets/css/main.css');
        // Un <details> n'est pas flex partout : le volet reste un bloc, le
        // rembourrage mobile commun ne l'atteint pas.
        $this->assertStringContainsString('body.admin-page .kbf-fold.kbf-card { display: block; padding: 0; }', $css);
        foreach (['.kbf-fold-head', '.kbf-fold-sum', '.kbf-fold-body', '.kbf-sum-item.is-strong', '.kbf-fold-all', '.kbf-subfold-head', '.kb-docs-chip', '.kb-docs-more-link'] as $classe) {
            $this->assertStringContainsString($classe . ' {', $css, $classe);
        }
        // Le résumé ne se montre que replié.
        $this->assertStringContainsString('.kbf-fold[open] > .kbf-fold-head .kbf-fold-sum { visibility: hidden; }', $css);
        $this->assertStringContainsString('.kbf-fold-head::-webkit-details-marker { display: none; }', $css);
    }

    public function testLesTextesSontTraduits(): void
    {
        $client = require ROOT . '/lang/en.client.php';
        foreach (['Tout replier', 'Tout déplier', 'À vérifier', 'Indexés', 'Voir les {n} autres documents', 'Réduire la liste',
                  'Aucun document ne correspond.', 'Partagée avec {liste}', 'Aucun assistant lié', 'synchronisation manuelle'] as $cle) {
            $this->assertArrayHasKey($cle, $client, $cle);
        }
        $serveur = require ROOT . '/lang/en.php';
        foreach (['Tout déplier', 'Réglages du dossier', 'Filtrer les documents', 'Rechercher un document…'] as $cle) {
            $this->assertArrayHasKey($cle, $serveur, $cle);
        }
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationLeDit(): void
    {
        $page = self::src('docs/user/fonctionnalites/bases-de-connaissances/creer-une-base-de-connaissances.md');
        // Piste D : sections toujours ouvertes, la fiche se referme par « Terminé ».
        $this->assertStringContainsString('**Terminé**', $page);
        $this->assertStringContainsString('Vue d\'ensemble', $page);
        $this->assertStringContainsString('KbStatus::attention', self::src('CLAUDE.md'));
    }
}
