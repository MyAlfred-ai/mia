<?php

declare(strict_types=1);

/**
 * Contexte d'UN tour de chat, partagé par les fournisseurs d'outils
 * (src/Agent/Tools/).
 *
 * Les handlers d'outils vivaient en closures dans ChatController::runSend et
 * capturaient ce qu'il leur fallait dans la portée de la méthode. Sortis du
 * contrôleur, ils reçoivent ici exactement ces dépendances — rien de plus :
 *
 *   - `emit` : l'évènement SSE du tour (phase, carte, image…). Dans le worker,
 *     c'est le canal du job (ChatController::setEmitSink) ; il reste l'UNIQUE
 *     sortie vers le navigateur ;
 *   - `queueWrite` : la file d'approbation des écritures (carte « Approuver »).
 *     Aucun fournisseur n'écrit chez un tiers pendant le flux : il dépose une
 *     action, l'utilisateur tranche ;
 *   - `textBudget` : budget (tokens) du texte qu'une lecture de connecteur
 *     peut rendre au modèle ;
 *   - `timeZone` : fuseau du NAVIGATEUR de l'utilisateur ('' = inconnu, celui
 *     du serveur s'applique). Le serveur tourne en UTC : les heures d'agenda
 *     (recherche, créneaux libres, création) se lisent dans ce fuseau ;
 *   - `scope` : le périmètre IMPOSÉ aux recherches et aux écritures (filtres
 *     d'une brique de workflow, SearchScope) — vide dans le chat ordinaire.
 */
final class AgentTurn
{
    /**
     * @param array<string, mixed>                  $user
     * @param \Closure(array<string, mixed>): void  $emit
     * @param \Closure(string, array, ?array): array $queueWrite
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $convId,
        public readonly array $user,
        public readonly int $textBudget,
        public readonly \Closure $emit,
        public readonly \Closure $queueWrite,
        public readonly string $timeZone = '',
        private readonly ?SearchScope $scope = null,
    ) {
    }

    /** Périmètre imposé du tour (vide hors workflow). */
    public function scope(): SearchScope
    {
        return $this->scope ?? SearchScope::none();
    }
}
