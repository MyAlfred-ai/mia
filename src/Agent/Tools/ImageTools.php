<?php

declare(strict_types=1);

/**
 * Outil de génération d'image confié à l'assistant (function-calling).
 *
 * L'image produite est insérée DANS la réponse (markdown, à sa place dans le
 * texte streamé) et affichée en direct (évènement `tool_image`). Une image
 * produite dans ce tour devient la base des appels suivants : « et maintenant
 * en bleu » enchaîné dans la même réponse part de celle qu'on vient de
 * générer, pas de zéro.
 *
 * Images de référence (ReferenceImages) : celles des documents de référence
 * de l'assistant et des bases de connaissances du message. Le modèle les
 * désigne par leur nom (`references`) ; leurs fichiers d'origine partent au
 * modèle image avec la consigne, après l'image de départ éventuelle. Au-delà
 * de ReferenceImages::MAX_LISTED, seules les plus proches de la demande sont
 * listées et `chercher_images_reference` trouve les autres.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : la
 * définition, la consigne et le handler sont ceux du contrôleur. Les deux
 * effets qui débordaient sur la méthode (texte de la réponse, images à
 * facturer) passent désormais par `appendToResponse` et `producedImages()`.
 */
final class ImageTools extends AbstractToolProvider
{
    /** Source d'image-to-image au moment de l'enregistrement (définition, consigne). */
    private readonly ?array $initialSource;

    /** @var list<array{result: array}> images produites dans ce tour (facturation) */
    private array $produced = [];

    /** @var \Closure(array, string, ?array, ?string): array */
    private readonly \Closure $generate;

    /** @var \Closure(list<array<string, mixed>>): array{images: list<array>, failed: list<array{name:string, reason:string}>} */
    private readonly \Closure $loadReferences;

    /**
     * @param array<string, mixed>                 $tool             ligne chat_tools de l'outil image
     * @param array{origin?: string}|null          $editSource       image de départ (conversation, ou celle produite dans ce tour)
     * @param \Closure(?array): ?array             $materialize      charge l'image source (base64) au moment de l'appel
     * @param \Closure(string): void               $appendToResponse ajoute le markdown de l'image à la réponse
     * @param list<array<string, mixed>>           $references       catalogue ReferenceImages du message, déjà ordonné (rank)
     * @param (\Closure(list<array<string, mixed>>): array)|null $loadReferences charge les références choisies (défaut : ReferenceImages::loadMany)
     * @param (\Closure(array, string, ?array, ?string): array)|null $generate appel du modèle image (défaut : ImageGenerator::generate)
     */
    public function __construct(
        AgentTurn $turn,
        private readonly array $tool,
        private ?array $editSource,
        private readonly bool $canEdit,
        private readonly ?string $instructions,
        private readonly \Closure $materialize,
        private readonly \Closure $appendToResponse,
        private readonly array $references = [],
        ?\Closure $loadReferences = null,
        ?\Closure $generate = null,
    ) {
        parent::__construct($turn);
        $this->initialSource  = $editSource;
        $this->loadReferences = $loadReferences ?? static fn(array $entries): array => ReferenceImages::loadMany($entries);
        $this->generate       = $generate ?? static fn(array $tool, string $prompt, ?array $images, ?string $instructions): array
            => ImageGenerator::generate($tool, $prompt, $images, $instructions);
    }

    public function key(): string
    {
        return 'image';
    }

    public function definitions(): array
    {
        $definitions = [$this->imageToolDefinition($this->initialSource, $this->canEdit)];
        if ($this->searchable()) {
            $definitions[] = $this->searchToolDefinition();
        }
        return $definitions;
    }

    public function guidance(): string
    {
        return $this->imageToolGuidance($this->initialSource, $this->canEdit);
    }

    /** @return list<array{result: array}> */
    public function producedImages(): array
    {
        return $this->produced;
    }

    /** Références utilisables : il en faut, et un modèle capable de partir d'images. */
    private function usesReferences(): bool
    {
        return $this->canEdit && $this->references !== [];
    }

    /** Plus d'images que la liste n'en montre : la recherche prend le relais. */
    private function searchable(): bool
    {
        return $this->usesReferences() && count($this->references) > ReferenceImages::MAX_LISTED;
    }

    public function handlers(): array
    {
        $handlers = [
            'generer_image' => function (array $args): array {
                $prompt = trim((string)($args['prompt'] ?? ''));
                if ($prompt === '') {
                    return ['error' => 'Prompt vide pour la génération d\'image.'];
                }
                // Certains modèles renvoient la chaîne "false" : un cast (bool)
                // la rendrait vraie et retoucherait l'image à tort.
                $useRef = !array_key_exists('utiliser_reference', $args)
                    || filter_var($args['utiliser_reference'], FILTER_VALIDATE_BOOLEAN);

                $refs = $this->chosenReferences($args, $useRef);
                if (isset($refs['error'])) {
                    return ['error' => $refs['error']];
                }

                $images = [];
                $base = $useRef ? ($this->materialize)($this->editSource) : null;
                if ($base !== null) {
                    $images[] = $base;
                }
                if ($refs !== []) {
                    $loaded = ($this->loadReferences)($refs);
                    if (($loaded['failed'] ?? []) !== []) {
                        return ['error' => 'Image(s) de référence impossible(s) à transmettre : '
                            . implode(', ', array_map(
                                static fn(array $f): string => '« ' . $f['name'] . ' » (' . $f['reason'] . ')',
                                $loaded['failed']
                            ))
                            . '. Aucune image n\'a été générée : réessaie sans elle(s) en le disant à l\'utilisateur.'];
                    }
                    foreach ((array)($loaded['images'] ?? []) as $img) {
                        $images[] = ['b64' => (string)$img['b64'], 'mime' => (string)$img['mime']];
                    }
                }

                $this->sseEmit(['phase' => 'generating_image']);
                $result = ($this->generate)(
                    $this->tool,
                    $prompt,
                    $images === [] ? null : $images,
                    $this->instructions
                );
                if (isset($result['error'])) {
                    return ['error' => $result['error']];
                }
                $url = (string)$result['image_url'];
                ($this->appendToResponse)("\n\n![Image générée](" . $url . ")\n\n");
                $this->produced[] = ['result' => $result];
                if ($this->canEdit) {
                    $this->editSource = ['origin' => 'generated', 'url' => $url];
                }
                $this->sseEmit(['tool_image' => ['url' => $url]]);
                $out = ['ok' => true, 'image_url' => $url];
                if ($refs !== []) {
                    $out['references'] = array_map(static fn(array $e): string => (string)$e['key'], $refs);
                }
                return $out;
            },
        ];

        if ($this->searchable()) {
            $handlers['chercher_images_reference'] = function (array $args): array {
                $query = trim((string)($args['requete'] ?? ''));
                if ($query === '') {
                    return ['error' => 'Indique ce que tu cherches : un nom, un sujet, une couleur…'];
                }
                $hits = ReferenceImages::search($this->references, $query, 10);
                return self::hintIfEmpty([
                    'images' => array_map(static fn(array $e): array => array_filter([
                        'nom'         => $e['key'],
                        'origine'     => ReferenceImages::originLabel($e),
                        'description' => $e['description'],
                    ], static fn($v): bool => $v !== ''), $hits),
                    'total'  => count($this->references),
                ], 'images', 'Aucune image ne correspond : essaie un autre mot (sujet, couleur, nom de fichier) '
                    . 'ou génère sans référence en le disant à l\'utilisateur.');
            };
        }

        return $handlers;
    }

    /**
     * Références de cet appel : celles que le modèle nomme, sinon — au premier
     * appel, sans image de départ — la référence principale de l'assistant,
     * comme avant que les références ne se choisissent. `references: []`
     * n'en veut aucune ; `utiliser_reference: false` écarte aussi la
     * référence par défaut (« une image entièrement nouvelle »).
     *
     * @return list<array<string, mixed>>|array{error:string}
     */
    private function chosenReferences(array $args, bool $useRef): array
    {
        if (!$this->usesReferences()) {
            return [];
        }
        if (!array_key_exists('references', $args) || $args['references'] === null) {
            if ($this->editSource === null && $useRef) {
                $default = ReferenceImages::defaultReference($this->references);
                return $default !== null ? [$default] : [];
            }
            return [];
        }
        $wanted = ReferenceImages::normalizeWanted($args['references']);
        if ($wanted === []) {
            return [];
        }
        if (count($wanted) > ReferenceImages::MAX_PER_GENERATION) {
            return ['error' => ReferenceImages::MAX_PER_GENERATION . ' images de référence au plus par image générée : '
                . 'garde celles qui comptent le plus. Aucune image n\'a été générée.'];
        }
        $pick = ReferenceImages::pick($this->references, $wanted);
        if ($pick['unknown'] !== [] || $pick['ambiguous'] !== []) {
            return ['error' => ReferenceImages::pickError($pick, $this->references)];
        }
        return $pick['picked'];
    }

    /**
     * Définition agnostique de l'outil de génération d'image exposé au LLM.
     * La description de `utiliser_reference` nomme la source réellement
     * disponible : sans cela le modèle ne peut pas décider en connaissance de
     * cause entre retoucher l'image précédente et en créer une nouvelle.
     *
     * @param array{origin?:string}|null $editSource
     */
    private function imageToolDefinition(?array $editSource = null, bool $canEdit = true): array
    {
        $properties = [
            'prompt' => [
                'type'        => 'string',
                'description' => "Description détaillée et autoportante de l'image à générer "
                    . "(sujet, style, composition, couleurs). Le modèle image ne voit pas la "
                    . "conversation : rappelle ici tout élément utile déjà décidé dans l'échange.",
            ],
        ];
        $required = ['prompt'];

        // Modèle incapable d'image-to-image (DALL-E, Imagen) : le paramètre
        // n'a pas d'objet, on ne l'expose pas du tout.
        if ($canEdit) {
            $origin      = (string)($editSource['origin'] ?? '');
            $sourceLabel = match ($origin) {
                'generated' => "la DERNIÈRE IMAGE GÉNÉRÉE dans cette conversation",
                'user'      => "la dernière image envoyée par l'utilisateur",
                default     => "l'image que tu viens de générer dans ce même tour",
            };

            $properties['utiliser_reference'] = [
                'type'        => 'boolean',
                'description' => "true pour partir de " . $sourceLabel . " et n'appliquer que "
                    . "les transformations décrites dans `prompt` (image-to-image). "
                    . "false pour créer une image entièrement nouvelle, sans lien avec la précédente.",
            ];

            // Quand la source est une image de la CONVERSATION, le choix
            // retouche/création n'a pas de défaut sûr : le modèle doit
            // trancher explicitement.
            if ($origin === 'generated' || $origin === 'user') {
                $required[] = 'utiliser_reference';
            }

            if ($this->references !== []) {
                $items = ['type' => 'string'];
                // Liste complète montrée : les noms possibles sont fermés.
                if (count($this->references) <= ReferenceImages::MAX_LISTED) {
                    $items['enum'] = array_map(static fn(array $e): string => (string)$e['key'], $this->references);
                }
                $default = ReferenceImages::defaultReference($this->references);
                $properties['references'] = [
                    'type'        => 'array',
                    'items'       => $items,
                    'maxItems'    => ReferenceImages::MAX_PER_GENERATION,
                    'description' => "Images de référence à transmettre au modèle image ("
                        . ReferenceImages::MAX_PER_GENERATION . " au plus), désignées par leur nom EXACT tel que "
                        . "listé dans tes consignes. Le modèle image les VOIT et reprend fidèlement ce qu'elles "
                        . "montrent : elles lui sont envoyées dans l'ordre donné, après l'image de départ quand "
                        . "`utiliser_reference` vaut true. Dans `prompt`, dis ce qu'il doit reprendre de chacune "
                        . "(« le personnage de la première référence », « le logo de la deuxième »)."
                        . ($default !== null
                            ? " Si tu omets ce paramètre au premier appel, l'image « " . $default['key']
                                . " » de l'assistant est utilisée ; passe une liste vide pour n'en utiliser aucune."
                            : " Liste vide ou paramètre omis : aucune référence."),
                ];
            }
        }

        return [
            'name'        => 'generer_image',
            'description' => "Génère une image personnalisée à partir d'une consigne, en s'appuyant sur les "
                . "instructions et les images de référence de l'assistant. À utiliser quand l'utilisateur "
                . "demande de créer, dessiner, illustrer, générer ou retoucher une image.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => $properties,
                'required'   => $required,
            ],
        ];
    }

    private function searchToolDefinition(): array
    {
        return [
            'name'        => 'chercher_images_reference',
            'description' => "Cherche parmi les " . count($this->references) . " images de référence disponibles "
                . "(documents de l'assistant et bases de connaissances) celles dont le nom ou la description "
                . "correspondent à une requête. Rend leur nom exact, à passer ensuite dans `references` de "
                . "`generer_image`.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'requete' => [
                        'type'        => 'string',
                        'description' => "Mots à chercher : nom de fichier, sujet, personnage, produit, couleur…",
                    ],
                ],
                'required'   => ['requete'],
            ],
        ];
    }

    /**
     * Consigne ajoutée au system prompt quand l'outil image est disponible.
     *
     * @param array{origin?:string}|null $editSource image servant de base
     * @param bool                       $canEdit    l'outil sait-il éditer une image existante
     */
    private function imageToolGuidance(?array $editSource = null, bool $canEdit = true): string
    {
        $guidance = "\n\nTu disposes de l'outil `generer_image` pour produire des images. "
            . "Quand l'utilisateur demande une image, appelle cet outil avec une consigne détaillée "
            . "plutôt que de décrire l'image en texte. Une seule image par demande sauf indication contraire. "
            . "Ta persona et tes instructions de style sont automatiquement transmises au modèle image — "
            . "tu n'as PAS besoin de les répéter dans le prompt. En revanche, le `prompt` que tu passes à "
            . "l'outil doit décrire précisément le RÉSULTAT VISUEL attendu : sujet, composition, ambiance, "
            . "couleurs, médium.\n"
            . "IMPORTANT : le modèle image ne voit NI la conversation NI tes messages précédents. Le `prompt` "
            . "doit donc être AUTOPORTANT : résous les références implicites (« ça », « le même », « lui ») et "
            . "rappelle explicitement les éléments déjà validés dans l'échange (sujet, personnages, style, "
            . "contraintes, palette).";

        if (!$canEdit) {
            $guidance .= "\nCe modèle image ne sait pas partir d'une image existante : décris toujours "
                . "l'image complète.";
            if ($this->references !== []) {
                $guidance .= " Les images de référence de l'assistant ne peuvent pas lui être transmises : "
                    . "si l'utilisateur veut qu'une image en reprenne une à l'identique, dis-le-lui.";
            }
            return $guidance;
        }

        $origin = (string)($editSource['origin'] ?? '');
        if ($origin === 'generated' || $origin === 'user') {
            $source = $origin === 'generated'
                ? "La DERNIÈRE IMAGE GÉNÉRÉE dans cette conversation"
                : "La dernière image envoyée par l'utilisateur";

            $guidance .= "\n" . $source . " est disponible comme base de travail : tu DOIS renseigner "
                . "`utiliser_reference` à chaque appel.\n"
                . "- Ajustement de cette image (« mets-lui un chapeau », « en bleu », « plutôt en format "
                . "paysage », « enlève le texte », « la même mais… ») → `utiliser_reference: true`, et le "
                . "`prompt` décrit UNIQUEMENT les transformations à appliquer. Ne redécris JAMAIS la scène "
                . "de zéro, sinon l'image change entièrement.\n"
                . "- Image nouvelle, sans rapport avec la précédente → `utiliser_reference: false`, et le "
                . "`prompt` décrit l'image complète.";
        } elseif ($this->references === []) {
            $guidance .= "\nAucune image source n'est disponible : décris l'image complète et passe "
                . "`utiliser_reference: false`. Si tu génères plusieurs images dans ce même tour, "
                . "`utiliser_reference: true` fait partir la suivante de celle que tu viens de produire.";
        } else {
            $guidance .= "\nAucune image de la conversation ne sert de base : décris l'image complète. Si tu "
                . "génères plusieurs images dans ce même tour, `utiliser_reference: true` fait partir la "
                . "suivante de celle que tu viens de produire.";
        }

        return $guidance . $this->referencesGuidance();
    }

    /** Liste des images de référence et règles de leur emploi ; vide sans référence. */
    private function referencesGuidance(): string
    {
        if ($this->references === []) {
            return '';
        }
        $total  = count($this->references);
        $listed = min($total, ReferenceImages::MAX_LISTED);
        $text = "\n\nImages de référence disponibles (" . ($listed < $total ? $listed . ' sur ' . $total : $total)
            . ") — documents de référence de l'assistant et images des bases de connaissances interrogées :\n"
            . ReferenceImages::promptList($this->references, ReferenceImages::MAX_LISTED)
            . "\nQuand la demande porte sur ce que montre une de ces images (personnage, produit, logo, lieu, "
            . "style, mise en page) ou que tes instructions l'imposent, passe-la dans `references` : le modèle "
            . "image la VOIT et la reprend fidèlement, ce qu'aucune description ne permet. Ne nomme que des "
            . "images de cette liste, " . ReferenceImages::MAX_PER_GENERATION . " au plus, et dis dans `prompt` "
            . "ce qu'il faut reprendre de chacune, par son rang. Le modèle image redessine : la reprise est "
            . "fidèle mais pas identique au pixel près — si l'utilisateur veut l'image elle-même, sans "
            . "changement, ne la régénère pas : dis-lui où elle se trouve.";
        if ($listed < $total) {
            $text .= "\nSeules les plus proches de la demande sont listées : `chercher_images_reference` trouve "
                . "les autres par leur nom ou leur description.";
        }
        $default = ReferenceImages::defaultReference($this->references);
        if ($default !== null && $this->initialSource === null) {
            $text .= "\nSans `references`, le premier appel part de l'image « " . $default['key']
                . " » de l'assistant ; pour une image sans aucun lien avec elle, passe `references: []` et "
                . "`utiliser_reference: false`.";
        }
        return $text;
    }
}
