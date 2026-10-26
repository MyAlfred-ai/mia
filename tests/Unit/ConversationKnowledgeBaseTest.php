<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sélection de bases de connaissances depuis le chat.
 *
 * Trois familles d'assertions, comme ConnectorContextTest :
 *   1. les FORMES pures (aucune base, aucun réseau) ;
 *   2. le CRITÈRE de vectorisation, avec son piège nommé ;
 *   3. le CÂBLAGE — assertions textuelles sur le source, seul moyen de garantir
 *      qu'un des trois sites RAG, la route ou le gabarit n'a pas régressé.
 */
final class ConversationKnowledgeBaseTest extends TestCase
{
    protected function setUp(): void
    {
        // Pas dans le bootstrap (dépendances lourdes) : le test ne fait que
        // lire leur source, mais ChatController doit être chargeable.
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
    }

    private function chatSource(): string
    {
        return (string)file_get_contents(ROOT . '/src/Controllers/ChatController.php');
    }

    private function chatJs(): string
    {
        return (string)file_get_contents(ROOT . '/public/assets/js/chat.js');
    }

    // =================== 1. normalizeSelection ===================

    public function testLaSelectionEstDedoublonneeEtGardeLOrdreDArrivee(): void
    {
        $res = ConversationKnowledgeBase::normalizeSelection([7, 3, 7], [3, 7]);
        $this->assertSame([7, 3], $res['ids']);
        $this->assertSame([], $res['rejected']);
        $this->assertFalse($res['capped']);
    }

    public function testLesIdentifiantsInvalidesSontIgnores(): void
    {
        $res = ConversationKnowledgeBase::normalizeSelection([0, -4, 'abc', '5', [1]], [5]);
        $this->assertSame([5], $res['ids']);
    }

    public function testUnIdentifiantHorsPerimetreEstRejeteEtSignale(): void
    {
        $res = ConversationKnowledgeBase::normalizeSelection([5, 99], [5]);
        $this->assertSame([5], $res['ids']);
        $this->assertSame([99], $res['rejected']);
    }

    /**
     * Le plafond s'applique EN DERNIER : un identifiant refusé ne doit jamais
     * consommer un des crans disponibles.
     */
    public function testLePlafondSAppliqueApresLeFiltrage(): void
    {
        $max = ConversationKnowledgeBase::MAX_SELECTED;
        $res = ConversationKnowledgeBase::normalizeSelection([99, 1, 2, 3], [1, 2, 3]);
        $this->assertSame([1, 2, 3], array_slice($res['ids'], 0, 3));
        $this->assertCount(min(3, $max), $res['ids']);
        $this->assertSame([99], $res['rejected']);
    }

    public function testAuDelaDuPlafondLaSelectionEstCoupeeEtSignalee(): void
    {
        $max     = ConversationKnowledgeBase::MAX_SELECTED;
        $allowed = range(1, $max + 2);
        $res     = ConversationKnowledgeBase::normalizeSelection($allowed, $allowed);
        $this->assertCount($max, $res['ids']);
        $this->assertTrue($res['capped']);
    }

    // =================== 2. mergeForTurn ===================

    private function kb(int $id, string $name): array
    {
        return [
            'id'                 => $id,
            'name'               => $name,
            'embedding_provider' => 'openai',
            'embedding_model'    => 'text-embedding-3-large',
        ];
    }

    /**
     * Une base portée à la fois par l'assistant et par l'utilisateur ne doit
     * apparaître QU'UNE fois : buildKnowledgeBaseContext cherche par base puis
     * fusionne par score — la doublonner ferait peser ses extraits double.
     */
    public function testLaFusionDedoublonneEtLAssistantLEmporte(): void
    {
        $merged = ConversationKnowledgeBase::mergeForTurn(
            [$this->kb(1, 'Assistant')],
            [$this->kb(1, 'Utilisateur'), $this->kb(2, 'Perso')]
        );
        $this->assertCount(2, $merged);
        $this->assertSame('Assistant', $merged[0]['name']);
        $this->assertSame(2, (int)$merged[1]['id']);
    }

    public function testLaFusionRendDesLignesConformesAuContratDuRag(): void
    {
        foreach (ConversationKnowledgeBase::mergeForTurn([$this->kb(1, 'A')], [$this->kb(2, 'B')]) as $row) {
            foreach (['id', 'name', 'embedding_provider', 'embedding_model'] as $key) {
                $this->assertArrayHasKey($key, $row);
            }
        }
    }

    public function testLaFusionDeDeuxListesVidesEstVide(): void
    {
        $this->assertSame([], ConversationKnowledgeBase::mergeForTurn([], []));
    }

    // =================== 3. traceDetail ===================

    /**
     * NON-RÉGRESSION : sans base ajoutée par l'utilisateur, le détail doit être
     * exactement celui d'avant la fonctionnalité.
     */
    public function testSansBaseUtilisateurLeDetailEstIdentiqueALAncien(): void
    {
        $kbs = [$this->kb(1, 'Qualité'), $this->kb(2, 'RH')];
        $this->assertSame(
            implode(', ', array_column($kbs, 'name')),
            ConversationKnowledgeBase::traceDetail($kbs, [])
        );
    }

    public function testSansAssistantLeDetailNeDistinguePas(): void
    {
        $this->assertSame(
            'Perso',
            ConversationKnowledgeBase::traceDetail([], [$this->kb(3, 'Perso')])
        );
    }

    /** Les bases de l'utilisateur d'abord : le détail est tronqué à 120 car. */
    public function testAvecLesDeuxOriginesLesBasesUtilisateurPassentEnPremier(): void
    {
        $detail = ConversationKnowledgeBase::traceDetail(
            [$this->kb(1, 'Qualité')],
            [$this->kb(2, 'Perso')]
        );
        $this->assertStringStartsWith('Perso (ajoutées par vous)', $detail);
        $this->assertStringContainsString('Qualité (assistant)', $detail);
        $this->assertLessThan(strpos($detail, 'Qualité'), strpos($detail, 'Perso'));
    }

    // =================== 4. Critère de vectorisation ===================

    private function kbCounters(int $docs, int $ready, int $pending, int $failed): array
    {
        return [
            'id'           => 1,
            'name'         => 'Base',
            'doc_count'    => $docs,
            'ready_docs'   => $ready,
            'pending_docs' => $pending,
            'failed_docs'  => $failed,
        ];
    }

    public function testUneBaseAvecUnDocumentVectoriseEstUtilisable(): void
    {
        $kb = $this->kbCounters(1, 1, 0, 0);
        $this->assertTrue(KnowledgeBase::isVectorized($kb));
        $this->assertNull(KnowledgeBase::unavailableReason($kb));
    }

    /**
     * LE PIÈGE : un document dont l'extraction ne rend aucun texte termine en
     * `vector_status = 'done'` avec `chunk_count = 0`. Compter les 'done' sans
     * la seconde condition ferait passer une base vide pour prête.
     */
    public function testUnDocumentDoneSansChunkNeRendPasLaBaseUtilisable(): void
    {
        $kb = $this->kbCounters(1, 0, 0, 0);   // 1 doc 'done', 0 chunk → ni pending ni failed
        $this->assertFalse(KnowledgeBase::isVectorized($kb));
        $this->assertSame('Aucun texte exploitable dans les documents', KnowledgeBase::unavailableReason($kb));
    }

    public function testUneBaseSansDocumentLeDit(): void
    {
        $this->assertSame('Aucun document', KnowledgeBase::unavailableReason($this->kbCounters(0, 0, 0, 0)));
    }

    public function testLaVectorisationEnCoursPrimeSurLEchec(): void
    {
        $this->assertSame('Vectorisation en cours', KnowledgeBase::unavailableReason($this->kbCounters(2, 0, 1, 1)));
    }

    public function testUnEchecSeulEstNomme(): void
    {
        $this->assertSame('Vectorisation en échec', KnowledgeBase::unavailableReason($this->kbCounters(1, 0, 0, 1)));
    }

    public function testLesDeuxLecturesDuCritereRestentCoherentes(): void
    {
        foreach ([[0,0,0,0], [1,0,0,0], [1,1,0,0], [3,1,1,1], [2,0,1,1], [2,0,0,2]] as $c) {
            $kb = $this->kbCounters(...$c);
            $this->assertSame(
                KnowledgeBase::isVectorized($kb),
                KnowledgeBase::unavailableReason($kb) === null,
                'Incohérence sur ' . implode('/', $c)
            );
        }
    }

    // =================== 5. Câblage serveur ===================

    /**
     * Les trois sites RAG passent par la résolution commune — et le quatrième
     * lecteur des bases du message, l'outil Image du menu « + », qui y prend
     * ses images de référence.
     */
    public function testLesTroisSitesRagResolventLesDeuxOrigines(): void
    {
        $src = $this->chatSource();
        $this->assertSame(4, substr_count($src, 'ConversationKnowledgeBase::mergeForTurn('));
        $this->assertSame(4, substr_count($src, 'ConversationKnowledgeBase::selectedKbsForTurn('));
    }

    /**
     * Garde de non-régression : aucun site ne doit revenir à « les bases du
     * profil, et rien d'autre ».
     */
    public function testAucunSiteNAssigneDirectementLesBasesDuProfil(): void
    {
        $this->assertStringNotContainsString(
            '$kbs = $profile ? KnowledgeBase::forProfile',
            $this->chatSource()
        );
    }

    /**
     * P1 : le budget RAG ne doit plus être calculé dans un `if ($profile)` —
     * sinon une conversation SANS assistant ne chercherait rien, en silence.
     */
    public function testLeBudgetRagNEstPlusConditionneAUnAssistant(): void
    {
        $src = $this->chatSource();
        $this->assertSame(2, substr_count($src, '$budget = $profile ? $ragBudget : 0;'));
        $this->assertStringNotContainsString("\$budget   = 0;\n        if (\$profile) {", $src);
        $this->assertStringNotContainsString("\$budget = 0;\n        if (\$profile) {", $src);
    }

    /**
     * P2 : sans assistant, $kbs peut être non vide — la garde est nécessaire.
     * Quatre sites : envoi, régénération, présentation, et la recherche
     * multi-sources (IntentTools) qui interroge aussi les bases du message.
     */
    public function testLImputationDeFacturationToleLAbsenceDAssistant(): void
    {
        $src = $this->chatSource();
        $this->assertSame(4, substr_count($src, "'profile_id'      => \$profile ? (int)\$profile['id'] : null,"));
        $this->assertStringNotContainsString("'profile_id'      => (int)\$profile['id'],", $src);
    }

    public function testLaTraceDistingueLesDeuxOrigines(): void
    {
        $this->assertSame(2, substr_count($this->chatSource(), 'ConversationKnowledgeBase::traceDetail('));
    }

    public function testLHistoriqueJsonPorteLesBasesDeLaConversation(): void
    {
        $src = $this->chatSource();
        $this->assertStringContainsString("'knowledge_bases' => [", $src);
        $this->assertStringContainsString("'selected' => ConversationKnowledgeBase::idsForConversation(\$convId),", $src);
    }

    /** Une seule validation : périmètre, vectorisation, plafond. */
    public function testLaValidationDUneSelectionEstUneSourceUnique(): void
    {
        $src = $this->chatSource();
        $this->assertSame(1, substr_count($src, 'private function resolveKbSelection('));
        $this->assertSame(2, substr_count($src, '$this->resolveKbSelection('));
    }

    public function testLaRouteDeSelectionEstDeclareeApresChatNew(): void
    {
        $src = (string)file_get_contents(ROOT . '/public/index.php');
        $route = "\$router->post('/chat/{id}/knowledge-bases', [ChatController::class, 'updateKnowledgeBases']);";
        $this->assertStringContainsString($route, $src);
        $this->assertLessThan(
            strpos($src, $route),
            strpos($src, "\$router->post('/chat/new',"),
            "La route littérale /chat/new doit précéder les routes paramétrées."
        );
    }

    // =================== 6. Migration & chargement ===================

    public function testLaMigrationSuitLaConversationEtLaBase(): void
    {
        $sql = (string)file_get_contents(ROOT . '/database/migrations/069_conversation_knowledge_bases.sql');
        $this->assertStringContainsString('conversation_knowledge_bases', $sql);
        $this->assertStringContainsString('PRIMARY KEY (conversation_id, kb_id)', $sql);
        $this->assertStringContainsString('conversation_id INT UNSIGNED NOT NULL', $sql);
        $this->assertStringContainsString('kb_id           INT UNSIGNED NOT NULL', $sql);
        // Les deux CASCADE : la sélection meurt avec la conversation ET avec la
        // base. Un RESTRICT ferait échouer KnowledgeBase::delete().
        $this->assertSame(2, substr_count($sql, 'ON DELETE CASCADE'));
    }

    public function testLaMigrationPorteLeNumeroLePlusEleve(): void
    {
        $nums = [];
        foreach (glob(ROOT . '/database/migrations/*.sql') as $file) {
            if (preg_match('/^(\d+)_/', basename($file), $m)) {
                $nums[] = (int)$m[1];
            }
        }
        // Figer le maximum à 69 revenait à interdire toute migration
        // ultérieure : la suivante (070, colonne `scope` des connecteurs) a
        // fait échouer ce test sans qu'aucune collision n'existe. Ce qui doit
        // rester vrai, c'est que 069 est bien LE numéro de cette
        // fonctionnalité, et qu'un seul fichier le porte.
        $this->assertCount(
            1,
            glob(ROOT . '/database/migrations/069_*.sql') ?: [],
            'Collision de numéro de migration sur 069.'
        );
        $this->assertContains(69, $nums, 'La migration de cette fonctionnalité a disparu.');
    }

    public function testLaClasseEstChargeeParLesDeuxListesDeRequire(): void
    {
        foreach (['/config/classes.php', '/tests/bootstrap.php'] as $file) {
            $this->assertStringContainsString(
                'Models/ConversationKnowledgeBase.php',
                (string)file_get_contents(ROOT . $file),
                $file . ' doit charger la classe (pas d\'autoloader).'
            );
        }
    }

    public function testLaTableEstVideeEntreDeuxTestsFonctionnels(): void
    {
        $this->assertStringContainsString(
            "'conversation_knowledge_bases'",
            (string)file_get_contents(ROOT . '/tests/Support/DatabaseTestCase.php')
        );
    }

    // =================== 7. Gabarit ===================

    public function testLeGabaritRendLeSelecteurAvecLaBonneConventionAria(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringContainsString('id="kbPickerPanel" hidden', $tpl);
        $this->assertStringContainsString('aria-expanded="false" aria-controls="kbPickerPanel"', $tpl);
        $this->assertStringContainsString('data-locked="1"', $tpl);
    }

    /** Une seule règle décide du grisé, partagée avec la validation serveur. */
    public function testLeGabaritLitLaMemeRegleDeGriseQueLeControleur(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringContainsString('KnowledgeBase::unavailableReason($kbRow)', $tpl);
    }

    public function testLePlafondNEstJamaisEnDurCoteJavaScript(): void
    {
        $tpl = (string)file_get_contents(ROOT . '/templates/chat/index.php');
        $this->assertStringContainsString("'max'      => ConversationKnowledgeBase::MAX_SELECTED,", $tpl);
        // Ce qui compte est que le plafond vienne de la CONSTANTE, pas qu'il soit
        // suivi d'un libellé donné : la mention est traduite, la constante non.
        $this->assertStringContainsString(
            "te('{n} bases maximum', ['n' => ConversationKnowledgeBase::MAX_SELECTED])",
            $tpl
        );
    }

    /** L'icône est celle de la tuile « Bases » du volet (AdminMenu::ICONS), pas une seconde. */
    public function testLIconeEstCelleDesBasesDeConnaissances(): void
    {
        $path = 'M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z';
        $this->assertStringContainsString($path, (string)file_get_contents(ROOT . '/templates/chat/index.php'));
        $this->assertStringContainsString($path, AdminMenu::ICONS['kb']);
        $this->assertSame('kb', NavRegistry::WORKSPACE['kb']['icon']);
    }

    /** Le panneau ET les lignes sont en display:… → [hidden] doit gagner. */
    public function testLAttributHiddenLEmporteSurLeDisplay(): void
    {
        $css = (string)file_get_contents(ROOT . '/public/assets/css/main.css');
        $this->assertStringContainsString('.kb-picker-panel[hidden] { display: none !important; }', $css);
        $this->assertStringContainsString('.kb-picker-row[hidden] { display: none !important; }', $css);
    }

    // =================== 8. Front ===================

    public function testLEtatAffichaNeBasculeQuApresLeOkServeur(): void
    {
        $js = $this->chatJs();
        $this->assertStringContainsString('selected = (data.kb_ids || []).map(Number);', $js);
        // Garde de séquence plutôt qu'un débounce : un délai laisserait envoyer
        // un message avant que l'écriture ne parte.
        $this->assertStringContainsString('if (seq !== reqSeq) return;', $js);
        $this->assertStringNotContainsString('kbCommitTimer', $js);
    }

    public function testLaSelectionLocaleNAQueDeuxEcrivains(): void
    {
        $js = $this->chatJs();
        $this->assertSame(
            2,
            preg_match_all('/^\s+selected\s+=\s+\(/m', $js),
            'selected ne doit être réaffectée que par commit() et syncKbForConversation().'
        );
    }

    public function testLeBrouillonEstBestEffort(): void
    {
        $js = $this->chatJs();
        $this->assertMatchesRegularExpression(
            '/localStorage\.setItem\(DRAFT_KEY[^\n]*\n\s*catch/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/localStorage\.getItem\(DRAFT_KEY[^\n]*\n\s*catch/',
            $js
        );
    }

    public function testLeBrouillonSuitLesDeuxCheminsDeCreation(): void
    {
        $this->assertSame(2, substr_count($this->chatJs(), 'window.kbDraftAppend?.(fd);'));
    }

    public function testLeChangementDeConversationRejoueLaSelection(): void
    {
        $this->assertStringContainsString(
            'window.syncKbForConversation?.(data.knowledge_bases);',
            $this->chatJs()
        );
    }

    /** Exclusion mutuelle des popovers : une seule implémentation. */
    public function testLExclusionMutuelleDesPopoversEstFactorisee(): void
    {
        $js = $this->chatJs();
        $this->assertSame(1, substr_count($js, 'window.closeComposerPopovers = function'));
        // 6 appelants : modèle, « + », puce des connecteurs (elle ouvre le « + »),
        // mention « @ », bouton assistants, bases.
        $this->assertSame(6, preg_match_all('/window\.closeComposerPopovers\(/', $js));
    }

    // =================== 9. Documentation ===================

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLeComportementEstDocumente(): void
    {
        $claude = (string)file_get_contents(ROOT . '/CLAUDE.md');
        $this->assertStringContainsString('## Bases de connaissances choisies dans le chat', $claude);
        $this->assertStringContainsString('conversation_knowledge_bases', $claude);

        $this->assertStringContainsString(
            'Choisir les bases de connaissances d\'une conversation',
            (string)file_get_contents(ROOT . '/docs/GUIDE-UTILISATEUR.md')
        );
        $this->assertStringContainsString(
            'que l\'utilisateur a lui-même choisies',
            (string)file_get_contents(ROOT . '/docs/GUIDE-CREATEUR.md')
        );
    }
}
