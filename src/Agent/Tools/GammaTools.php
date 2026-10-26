<?php

declare(strict_types=1);

/**
 * Outil Gamma du chat : présentations gamma.app, un OUTIL confié à l'assistant
 * (ligne chat_tools de type gamma). Chaque génération — payante, en crédits
 * Gamma — passe par la carte d'approbation ; le POST facturant part au clic.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : la
 * définition, la consigne et le handler sont ceux du contrôleur, déplacés
 * tels quels.
 */
final class GammaTools extends AbstractToolProvider
{
    /**
     * @param array<string, mixed> $tool   ligne chat_tools de l'outil Gamma
     * @param array<string, mixed> $config config_json décodée (GammaService::decodeToolConfig)
     */
    public function __construct(AgentTurn $turn, private readonly array $tool, private readonly array $config)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'gamma';
    }

    public function definitions(): array
    {
        return [$this->gammaToolDefinition($this->config)];
    }

    public function guidance(): string
    {
        return $this->gammaToolGuidance();
    }

    public function handlers(): array
    {
        $gammaTool           = $this->tool;
        $gammaConfig         = $this->config;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        $toolHandlers['generer_presentation_gamma'] = function (array $args) use ($gammaTool, $gammaConfig, $queueConnectorWrite) {
            $this->sseEmit(['phase' => 'gamma_generate']);
            // Validation AMONT, 100 % locale — send() tourne dans le
            // worker : AUCUN appel réseau ici, le POST facturant part au
            // clic « Approuver ». Une erreur revient au modèle plutôt que
            // de produire une carte qui échouera.
            try {
                GammaService::buildGenerationPayload($gammaConfig, $args);
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
            return $queueConnectorWrite('generer_presentation_gamma', [
                'sujet'         => (string)($args['sujet'] ?? ''),
                'titre'         => (string)($args['titre'] ?? ''),
                'nombre_cartes' => (int)($args['nombre_cartes'] ?? 0),
                'instructions'  => (string)($args['instructions'] ?? ''),
                'mode_texte'    => (string)($args['mode_texte'] ?? ''),
                // Quel outil génèrera : au clic « Approuver », il est
                // re-résolu par cet id et ses droits re-vérifiés au
                // moment T (l'assistant a pu perdre l'outil entre-temps).
                'tool_id'       => (int)$gammaTool['id'],
            ], null);
        };

        return $toolHandlers;
    }

    /**
     * Définition function-calling de l'outil Gamma.
     * Paramètres exclusivement scalaires : aucun objet à propriétés libres
     * (Gemini refuse un `object` sans `properties`).
     *
     * @param array<string,mixed> $config config_json de l'outil (plafond de cartes)
     */
    private function gammaToolDefinition(array $config): array
    {
        $plafond = GammaService::sanitizeMaxCards($config['max_cards'] ?? null);
        return [
            'name'        => 'generer_presentation_gamma',
            'description' => "Génère une présentation (deck de slides) via gamma.app à partir d'un sujet ou d'un "
                . "contenu source. À n'utiliser QUE sur demande EXPLICITE d'une présentation : chaque génération "
                . "consomme des crédits Gamma payants, et elle est soumise à l'approbation de l'utilisateur avant "
                . "de partir.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'sujet' => [
                        'type'        => 'string',
                        'description' => "Sujet à développer OU contenu source complet de la présentation "
                            . "(texte, plan, notes). Autoportant : gamma.app ne voit pas la conversation.",
                    ],
                    'titre' => [
                        'type'        => 'string',
                        'description' => "Titre de la présentation (optionnel — déduit du sujet sinon).",
                    ],
                    'nombre_cartes' => [
                        'type'        => 'integer',
                        'description' => "Nombre de slides souhaité (1 à " . $plafond . "). Défaut : "
                            . GammaService::DEFAULT_CARDS . ".",
                    ],
                    'instructions' => [
                        'type'        => 'string',
                        'description' => "Consignes de style ou de structure (optionnel — ex. « une slide de "
                            . "conclusion chiffrée », « ton institutionnel »).",
                    ],
                    'mode_texte' => [
                        'type'        => 'string',
                        'description' => "Comment traiter « sujet » : `generate` (développer, défaut), `condense` "
                            . "(résumer un texte long fourni), `preserve` (garder le texte tel quel, slide par slide).",
                    ],
                ],
                'required'   => ['sujet'],
            ],
        ];
    }

    /** Consigne ajoutée au system prompt quand l'outil Gamma est disponible. */
    private function gammaToolGuidance(): string
    {
        return "\n\nTu disposes de l'outil `generer_presentation_gamma` (présentations générées par gamma.app). "
            . "Ne l'appelle QUE sur demande explicite d'une présentation — jamais « pour voir », jamais deux fois "
            . "pour la même demande : chaque génération consomme des crédits payants. CHAQUE génération est "
            . "soumise à l'approbation de l'utilisateur : l'outil renvoie `en_attente_approbation` et une carte "
            . "s'affiche sous ta réponse. N'affirme JAMAIS que la présentation est générée ou disponible : annonce "
            . "qu'elle attend l'approbation, en résumant le sujet et le nombre de slides ; après approbation, le "
            . "lien arrivera dans la conversation au bout de 1 à 3 minutes. Si l'outil renvoie un champ `error`, "
            . "transmets ce message tel quel sans le reformuler.";
    }
}
