<?php

declare(strict_types=1);

/**
 * « Laisser MIA choisir » : les connecteurs RELIÉS mais éteints deviennent
 * activables par le modèle lui-même, au besoin — outil `activer_connecteur`.
 *
 * Deux problèmes, une réponse :
 *   - l'opt-in par message obligeait à cocher Drive, Gmail, Agenda… AVANT de
 *     poser la question ; oublié, l'assistant répondait « je n'ai pas
 *     accès », et cocher tout en permanence exposait des dizaines d'outils à
 *     chaque message ;
 *   - exposer d'office tous les outils des connecteurs reliés coûterait des
 *     milliers de jetons de définitions à chaque message, même pour « merci ».
 *
 * Seul cet outil léger part donc au modèle, avec la liste des connecteurs
 * activables. L'appeler charge les outils du connecteur POUR LA SUITE DE LA
 * RÉPONSE (le registre s'agrandit, l'adaptateur relit la liste à chaque aller
 * — option `tools_source`) ; sa consigne revient dans le résultat, sans
 * toucher au prompt système (cache et raisonnement préservés). Les écritures
 * restent sous carte d'approbation : activer un connecteur ne donne aucun
 * droit de plus que le cocher.
 *
 * Opt-in de l'utilisateur (interrupteur du menu « + »), permis par
 * l'administrateur (ConnectorRegistry::isAutoModeEnabled).
 */
final class ConnectorLoaderTools extends AbstractToolProvider
{
    public const TOOL = 'activer_connecteur';

    /** @var array<string, true> connecteurs déjà chargés dans ce tour */
    private array $loaded = [];

    /**
     * @param array<string, string>                 $available clé → libellé des connecteurs activables
     * @param \Closure(string): ?ToolProvider        $load      charge le connecteur dans le registre (null = indisponible)
     */
    public function __construct(AgentTurn $turn, private readonly array $available, private readonly \Closure $load)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'connector_loader';
    }

    public function definitions(): array
    {
        $liste = [];
        foreach ($this->available as $key => $label) {
            $liste[] = $key . ' (' . $label . ')';
        }
        return [[
            'name'        => self::TOOL,
            'description' => "Active un connecteur relié par l'utilisateur mais éteint pour ce message, et rend ses "
                . "outils disponibles au tour suivant. Connecteurs activables : " . implode(', ', $liste) . ". "
                . "À n'appeler que si la demande a besoin de ses données (fichiers, emails, agenda, tâches, "
                . "messages) — jamais « au cas où ».",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'connecteur' => [
                        'type'        => 'string',
                        'enum'        => array_keys($this->available),
                        'description' => 'Le connecteur à activer.',
                    ],
                ],
                'required'   => ['connecteur'],
            ],
        ]];
    }

    public function guidance(): string
    {
        return "\n\nConnecteurs à la demande : l'utilisateur t'autorise à activer toi-même ses connecteurs reliés "
            . "(" . implode(', ', array_values($this->available)) . ") quand sa demande en a besoin. Appelle `"
            . self::TOOL . "` avec le connecteur voulu, puis sers-toi de ses outils au tour suivant, en suivant la "
            . "consigne rendue par l'activation. N'active que ce qui sert la demande ; pour une question générale, "
            . "n'active rien.";
    }

    public function handlers(): array
    {
        return [self::TOOL => function (array $args): array {
            $key = trim((string)($args['connecteur'] ?? ''));
            if (!isset($this->available[$key])) {
                return ['error' => 'Connecteur inconnu ou non activable : « ' . $key . ' ». Activables : '
                    . implode(', ', array_keys($this->available)) . '.'];
            }
            if (isset($this->loaded[$key])) {
                return ['deja_actif' => true, 'connecteur' => $this->available[$key]];
            }
            $provider = ($this->load)($key);
            if ($provider === null) {
                return ['error' => 'Le connecteur ' . $this->available[$key] . ' est indisponible pour cette réponse '
                    . '(connexion expirée ou droits insuffisants) : dis-le à l\'utilisateur, sans rien inventer.'];
            }
            $this->loaded[$key] = true;
            return [
                'actif'      => true,
                'connecteur' => $this->available[$key],
                'outils'     => array_values(array_map(
                    static fn(array $d): string => (string)($d['name'] ?? ''),
                    $provider->definitions()
                )),
                // Consigne du connecteur (et règle « contenus lus = données »
                // s'il lit des contenus tiers) : dans le RÉSULTAT, le prompt
                // système ne bouge pas.
                'consignes'  => trim($provider->guidance()
                    . ($provider->externalContentTools() !== [] ? ExternalContent::rule() : '')),
            ];
        }];
    }
}
