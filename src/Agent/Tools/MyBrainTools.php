<?php

declare(strict_types=1);

/**
 * Outils du cerveau MyBrain (bases de connaissances EN DIRECT) : creuser au-delà
 * de l'extrait injecté (chercher, lire une fiche) et, pour qui en a le droit,
 * nourrir le cerveau — un dépôt soumis à la carte d'approbation puis à la revue
 * humaine dans MyBrain.
 *
 * Choisir la base est l'opt-in, exactement comme pour son contexte : rien à
 * cocher en plus. La porte suit la PERSONNE (bases déjà filtrées par
 * forProfileAndUser / selectedKbsForTurn) ; une base refusée ou en pause n'est
 * pas passée ici — ses outils ne rendraient qu'une erreur.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : les
 * handlers sont ceux du contrôleur, déplacés tels quels.
 */
final class MyBrainTools extends AbstractToolProvider
{
    /**
     * @param list<array<string, mixed>> $liveKbs      bases en direct appelables de ce tour
     * @param list<array<string, mixed>> $feedKbs      parmi elles, celles que la personne peut nourrir
     * @param int                        $mybrainChars budget (caractères) d'un résultat d'outil
     */
    public function __construct(
        AgentTurn $turn,
        private readonly array $liveKbs,
        private readonly array $feedKbs,
        private readonly int $mybrainChars,
    ) {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'mybrain';
    }

    public function definitions(): array
    {
        $defs = MyBrainContext::toolDefinitions($this->liveKbs);
        if ($this->feedKbs !== []) {
            $defs[] = MyBrainFeed::toolDefinition($this->feedKbs);
        }
        return $defs;
    }

    public function guidance(): string
    {
        return MyBrainContext::toolGuidance($this->liveKbs)
            . ($this->feedKbs !== [] ? MyBrainFeed::toolGuidance() : '');
    }

    /** Les fiches du cerveau viennent de mails et de pages web ingérés. */
    public function externalContentTools(): array
    {
        return [
            'chercher_dans_mybrain',
            'lire_fiche_mybrain',
        ];
    }

    public function handlers(): array
    {
        $liveKbs             = $this->liveKbs;
        $feedKbs             = $this->feedKbs;
        $mybrainChars        = $this->mybrainChars;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        $toolHandlers['chercher_dans_mybrain'] = function (array $args) use ($liveKbs, $mybrainChars) {
            $this->sseEmit(['phase' => 'mybrain_dig']);
            $kb = MyBrainContext::pickBase($liveKbs, (string)($args['base'] ?? ''));
            if ($kb === null) {
                return ['error' => 'Cerveau inconnu : « ' . (string)($args['base'] ?? '') . ' ».'];
            }
            return self::hintIfEmpty(
                MyBrainContext::searchForTool($kb, (string)($args['question'] ?? ''), $mybrainChars),
                'fiches',
                "Le cerveau ne sait rien sur cette question. Reformule UNE fois en nommant le client, le projet "
                    . "ou la personne ; sinon, dis-le à l'utilisateur."
            );
        };
        $toolHandlers['lire_fiche_mybrain'] = function (array $args) use ($liveKbs, $mybrainChars) {
            $this->sseEmit(['phase' => 'mybrain_read']);
            $kb = MyBrainContext::pickBase($liveKbs, (string)($args['base'] ?? ''));
            if ($kb === null) {
                return ['error' => 'Cerveau inconnu : « ' . (string)($args['base'] ?? '') . ' ».'];
            }
            return MyBrainContext::readForTool($kb, (string)($args['fiche'] ?? ''), $mybrainChars);
        };

        // Nourrir le cerveau (lot 3) : parmi ces bases, celles que CETTE
        // personne peut nourrir — « peut nourrir » accordé, clé qui porte le
        // droit « nourrir ». L'outil ne fait que déposer une carte
        // d'approbation ; le dépôt part au clic « Approuver » (driveAction).
        if ($feedKbs !== []) {
            $toolHandlers['nourrir_mybrain'] = function (array $args) use ($feedKbs, $queueConnectorWrite) {
                $this->sseEmit(['phase' => 'mybrain_feed']);
                $kb = MyBrainContext::pickBase($feedKbs, (string)($args['base'] ?? ''));
                if ($kb === null) {
                    return ['error' => 'Cerveau inconnu : « ' . (string)($args['base'] ?? '') . ' ».'];
                }
                try {
                    $clean = MyBrainFeed::normalizeArgs($args);
                } catch (\InvalidArgumentException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite(
                    'nourrir_mybrain',
                    $clean + ['kb_id' => (int)$kb['id']],
                    ['base' => (string)$kb['name']]
                );
            };
        }

        return $toolHandlers;
    }
}
