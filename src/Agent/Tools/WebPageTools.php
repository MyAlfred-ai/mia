<?php

declare(strict_types=1);

/**
 * Lecture d'une page web publique (`lire_page_web`) — le complément de la
 * recherche Internet des fournisseurs : elle trouve des pages, cet outil lit
 * celle que l'utilisateur a collée, ou une page trouvée qu'il faut lire en
 * entier plutôt que d'après un extrait.
 *
 * Exposé seulement quand la Recherche Internet est autorisée pour ce message
 * (interrupteur du menu « + », que l'administrateur peut masquer) : couper
 * Internet coupe aussi la lecture de pages. Toute la sécurité (adresses
 * internes, redirections, taille) vit dans WebPageReader.
 *
 * Ce n'est pas un connecteur : aucune donnée personnelle, rien à relier.
 */
final class WebPageTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly WebPageReader $reader = new WebPageReader())
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'web_page';
    }

    public function definitions(): array
    {
        return [[
            'name'        => 'lire_page_web',
            'description' => "Lit le texte d'une page web PUBLIQUE (article, documentation, page produit, PDF en "
                . "ligne…) à partir de son adresse. À utiliser quand l'utilisateur donne un lien, ou pour lire "
                . "en entier une page trouvée par une recherche. Les pages qui demandent une connexion, les "
                . "adresses de réseau interne et les fichiers de plus de 5 Mo sont refusés. Un long texte se "
                . "lit par parties (`partie`).",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'url'    => ['type' => 'string', 'description' => "Adresse complète de la page (https://…)."],
                    'partie' => ['type' => 'integer', 'description' => "Partie à lire pour un long texte (1 = début, défaut). Le résultat indique le nombre de parties."],
                ],
                'required'   => ['url'],
            ],
        ]];
    }

    public function guidance(): string
    {
        return "\n\nTu peux lire une page web publique avec `lire_page_web` : quand l'utilisateur te donne une "
            . "adresse, lis-la plutôt que de deviner son contenu, et cite l'adresse lue dans ta réponse. Si "
            . "l'outil refuse une adresse (page privée, réseau interne, fichier trop lourd), dis-le simplement : "
            . "ne contourne pas le refus.";
    }

    public function externalContentTools(): array
    {
        // Une page web est écrite par n'importe qui : c'est LA source type
        // d'injection de consignes (cf. ExternalContent).
        return ['lire_page_web'];
    }

    public function handlers(): array
    {
        $reader = $this->reader;
        $budget = $this->turn->textBudget;

        return [
            'lire_page_web' => function (array $args) use ($reader, $budget): array {
                $this->sseEmit(['phase' => 'web_read']);
                $url = $args['url'] ?? '';
                try {
                    return $reader->read(is_scalar($url) ? (string)$url : '', max(1000, $budget * 4), (int)($args['partie'] ?? 1));
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
            },
        ];
    }
}
