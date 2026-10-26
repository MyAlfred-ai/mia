<?php

declare(strict_types=1);

/**
 * Bases de connaissances choisies par l'UTILISATEUR pour une conversation
 * (table `conversation_knowledge_bases`, migration 069).
 *
 * Elles S'AJOUTENT à celles que l'assistant porte déjà dans sa configuration
 * (`profile_knowledge_bases`) : l'utilisateur enrichit son tour de conversation
 * sans modifier l'assistant pour tout le monde.
 *
 * Deux sections, volontairement séparées comme dans ConnectorContext :
 *   - FORME       : fonctions PURES, testables sans base ;
 *   - PERSISTANCE : les seuls accès SQL.
 *
 * ASYMÉTRIE ASSUMÉE avec ConnectorContext, qui est best-effort partout :
 * l'écriture ici est STRICTE (une exception remonte), parce qu'une sélection
 * est une INTENTION de l'utilisateur et non un aide-mémoire — un écran qui
 * affiche coché ce qui n'est pas enregistré est pire que pas d'écran du tout.
 * La lecture, elle, reste best-effort : un incident ne doit jamais faire
 * échouer un tour de conversation.
 */
final class ConversationKnowledgeBase
{
    /**
     * Bases cochables simultanément par l'utilisateur, par conversation.
     *
     * Le budget RAG est GLOBAL et partagé (ProfileDocument::ragTokenBudget) :
     * au-delà de quelques bases, elles se disputent les mêmes tokens jusqu'à ce
     * qu'aucune ne contribue vraiment. Le plafond borne aussi le nombre de
     * recherches Qdrant et de vectorisations de la question.
     *
     * SOURCE UNIQUE : le gabarit du chat le publie dans window.KB_PICKER.max —
     * un 3 en dur côté JavaScript ferait mentir l'écran le jour où il bouge.
     */
    public const MAX_SELECTED = 3;

    // ===================== FORME (pur, testable sans base) =====================

    /**
     * Ids retenus parmi une demande : entiers > 0, dédupliqués, ordre d'arrivée
     * conservé, bornés au périmètre autorisé PUIS au plafond.
     *
     * L'ORDRE des filtres n'est pas indifférent : le plafond s'applique EN
     * DERNIER, pour qu'un identifiant invalide (hors périmètre, base non
     * vectorisée, base déjà portée par l'assistant) ne consomme jamais un des
     * trois crans.
     *
     * @param  array      $requested ids demandés — viennent du POST, aucune confiance
     * @param  list<int>  $allowed   ids réellement sélectionnables
     * @return array{ids:list<int>, rejected:list<int>, capped:bool}
     */
    public static function normalizeSelection(array $requested, array $allowed): array
    {
        $allow    = array_flip(array_map('intval', $allowed));
        $ids      = [];
        $rejected = [];

        foreach ($requested as $raw) {
            if (!is_scalar($raw)) {
                continue;
            }
            $id = (int)$raw;
            if ($id <= 0 || in_array($id, $ids, true) || in_array($id, $rejected, true)) {
                continue;
            }
            if (!isset($allow[$id])) {
                $rejected[] = $id;
                continue;
            }
            $ids[] = $id;
        }

        $capped = count($ids) > self::MAX_SELECTED;
        if ($capped) {
            $ids = array_slice($ids, 0, self::MAX_SELECTED);
        }

        return ['ids' => $ids, 'rejected' => $rejected, 'capped' => $capped];
    }

    /**
     * Liste finale des bases d'un tour : celles de l'assistant PUIS celles que
     * l'utilisateur a cochées, dédupliquées par id (l'assistant gagne — même
     * base, et c'est lui qui l'impose).
     *
     * Le dédoublonnage n'est PAS cosmétique : buildKnowledgeBaseContext lance
     * une recherche Qdrant PAR BASE puis fusionne tous les hits par score. Une
     * base présente deux fois verrait ses extraits comptés en double et
     * pourrait monopoliser les 50 chunks retenus.
     *
     * @param  list<array> $profileKbs sortie de KnowledgeBase::forProfile()
     * @param  list<array> $userKbs    sortie de self::selectedKbsForTurn()
     * @return list<array> prêtes pour ChatController::buildKnowledgeBaseContext()
     */
    public static function mergeForTurn(array $profileKbs, array $userKbs): array
    {
        $byId = [];
        foreach ($profileKbs as $kb) {
            $byId[(int)($kb['id'] ?? 0)] = $kb;
        }
        foreach ($userKbs as $kb) {
            $id = (int)($kb['id'] ?? 0);
            if (!isset($byId[$id])) {
                $byId[$id] = $kb;
            }
        }
        unset($byId[0]);

        return array_values($byId);
    }

    /**
     * Détail de la trace d'actions « Recherche dans les bases de
     * connaissances ». La pile sert à ATTRIBUER un changement de réponse : si
     * l'utilisateur coche une base et que la réponse change, il doit pouvoir
     * relier les deux.
     *
     * Les bases de l'UTILISATEUR passent en premier : le détail est tronqué à
     * Message::ACTION_DETAIL_MAX (120) à la persistance, et c'est l'information
     * NOUVELLE qui doit survivre à la troncature.
     *
     * Sortie strictement identique à l'ancienne (`implode(', ', noms)`) quand
     * $userKbs est vide — la non-régression est donc testable à l'octet près.
     */
    public static function traceDetail(array $profileKbs, array $userKbs): string
    {
        $names = static fn(array $kbs): string => implode(', ', array_filter(
            array_map(static fn(array $kb): string => trim((string)($kb['name'] ?? '')), $kbs),
            static fn(string $n): bool => $n !== ''
        ));

        $user    = $names($userKbs);
        $profile = $names($profileKbs);

        if ($user === '')    { return $profile; }
        if ($profile === '') { return $user; }

        return $user . ' (ajoutées par vous) + ' . $profile . ' (assistant)';
    }

    // ===================== PERSISTANCE =====================

    /**
     * Ids choisis pour cette conversation, TELS QU'ENREGISTRÉS — sans filtrage
     * d'accès ni de vectorisation. Sert à cocher les cases et à répondre au
     * client ; le tour de chat, lui, passe par selectedKbsForTurn().
     *
     * @return list<int>
     */
    public static function idsForConversation(int $conversationId): array
    {
        if ($conversationId <= 0) {
            return [];
        }
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT kb_id FROM conversation_knowledge_bases WHERE conversation_id = ?'
            );
            $stmt->execute([$conversationId]);

            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            error_log('[KB] lecture sélection conv ' . $conversationId . ' : ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Remplace la sélection d'une conversation. TRANSACTIONNEL : une sélection
     * à moitié écrite changerait le périmètre de données sans le dire.
     *
     * NE RATTRAPE PAS ses exceptions (cf. en-tête de classe) : l'appelant doit
     * pouvoir répondre en erreur au lieu d'afficher un état non enregistré.
     *
     * Appelée DANS une transaction (le planificateur écrit la sélection d'une
     * tâche programmée dans la sienne), elle s'y fond : PDO n'imbrique pas les
     * transactions, et l'atomicité est alors celle de l'appelant.
     *
     * @param list<int> $kbIds déjà validés par l'appelant (resolveKbSelection)
     */
    public static function setForConversation(int $conversationId, array $kbIds): void
    {
        if ($conversationId <= 0) {
            return;
        }
        $ids = [];
        foreach ($kbIds as $raw) {
            $id = (int)$raw;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $db = Database::getInstance();
        if ($db->inTransaction()) {
            $db->prepare('DELETE FROM conversation_knowledge_bases WHERE conversation_id = ?')->execute([$conversationId]);
            $ins = $db->prepare('INSERT INTO conversation_knowledge_bases (conversation_id, kb_id) VALUES (?, ?)');
            foreach ($ids as $id) {
                $ins->execute([$conversationId, $id]);
            }
            return;
        }
        $db->beginTransaction();
        try {
            $del = $db->prepare('DELETE FROM conversation_knowledge_bases WHERE conversation_id = ?');
            $del->execute([$conversationId]);

            if ($ids !== []) {
                $ins = $db->prepare(
                    'INSERT INTO conversation_knowledge_bases (conversation_id, kb_id) VALUES (?, ?)'
                );
                foreach ($ids as $id) {
                    $ins->execute([$conversationId, $id]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Bases choisies par l'utilisateur, PRÊTES pour un tour de chat :
     * re-filtrées sur le périmètre COURANT (partage révoqué, base désactivée ou
     * supprimée depuis le clic) et bornées à MAX_SELECTED.
     *
     * Le re-filtrage passe par KnowledgeBase::selectableForUser() plutôt que par
     * un SQL local : le prédicat de partage n'a qu'UNE implémentation, et une
     * requête de plus par tour est négligeable devant un appel LLM.
     *
     * BEST-EFFORT, à l'inverse de l'écriture : un incident de lecture rend une
     * liste vide et laisse la conversation se poursuivre.
     *
     * @return list<array> lignes `knowledge_bases` complètes, triées par nom
     */
    public static function selectedKbsForTurn(int $conversationId, array $user): array
    {
        if ($conversationId <= 0 || empty($user['id'])) {
            return [];
        }
        try {
            $ids = self::idsForConversation($conversationId);
            if ($ids === []) {
                return [];
            }
            $wanted = array_flip($ids);
            $rows   = [];
            foreach (KnowledgeBase::selectableForUser($user) as $kb) {
                if (isset($wanted[(int)$kb['id']])) {
                    $rows[] = $kb;
                }
            }

            return array_slice($rows, 0, self::MAX_SELECTED);
        } catch (\Throwable $e) {
            error_log('[KB] sélection du tour conv ' . $conversationId . ' : ' . $e->getMessage());
            return [];
        }
    }
}
