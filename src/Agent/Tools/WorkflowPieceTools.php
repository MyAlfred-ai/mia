<?php

declare(strict_types=1);

/**
 * `mettre_a_jour_piece_workflow` : dans la conversation d'une exécution de
 * workflow, pendant une validation, met à jour une pièce qu'elle relit avec
 * ce que la personne vient de donner dans la discussion (WorkflowChat).
 *
 * Exposé seulement quand une validation attend ET laisse corriger une de ses
 * pièces. N'approuve jamais rien : la carte de validation reste le geste de
 * la personne. Pas un connecteur : aucune donnée externe.
 */
final class WorkflowPieceTools extends AbstractToolProvider
{
    /** @param \Closure(array): array{result: array, card: ?array} $apply */
    public function __construct(AgentTurn $turn, private readonly array $ctx, private readonly \Closure $apply)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'workflow_piece';
    }

    public function definitions(): array
    {
        return [WorkflowChat::toolDefinition($this->ctx)];
    }

    /** La méthode vit dans la note du prompt (WorkflowChat::promptNote). */
    public function guidance(): string
    {
        return '';
    }

    public function handlers(): array
    {
        return [
            WorkflowChat::TOOL => function (array $args): array {
                $out = ($this->apply)($args);
                // Carte de validation à l'écran : redessinée avec la pièce à
                // jour, pour que la personne approuve ce qu'elle voit.
                if (is_array($out['card'] ?? null)) {
                    $this->sseEmit(['drive_card_update' => $out['card']]);
                }
                return (array)($out['result'] ?? ['error' => 'Mise à jour impossible.']);
            },
        ];
    }
}
