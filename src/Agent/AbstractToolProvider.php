<?php

declare(strict_types=1);

/**
 * Socle des fournisseurs d'outils : le contexte du tour et deux aides que
 * tous les handlers employaient déjà dans le contrôleur.
 *
 * `sseEmit()` et `hintIfEmpty()` gardent le nom qu'ils avaient dans
 * ChatController : les handlers ont été déplacés tels quels, et un nom changé
 * en route aurait été une occasion de plus de casser un détail.
 */
abstract class AbstractToolProvider implements ToolProvider
{
    public function __construct(protected readonly AgentTurn $turn)
    {
    }

    public function connector(): ?string
    {
        return null;
    }

    public function externalContentTools(): array
    {
        return [];
    }

    /** Évènement SSE du tour (phase, carte, image…) — canal unique vers le navigateur. */
    protected function sseEmit(array $event): void
    {
        ($this->turn->emit)($event);
    }

    /** Conseil de relance sur recherche vide (cf. ToolRegistry::hintIfEmpty). */
    protected static function hintIfEmpty(array $result, string $cle, string $conseil): array
    {
        return ToolRegistry::hintIfEmpty($result, $cle, $conseil);
    }

    /**
     * Partie demandée d'une lecture paginée (TextPager) : `partie` du modèle,
     * entier ou chaîne numérique, 1 par défaut. Une partie hors limites est
     * refusée par TextPager avec le nombre de parties disponibles.
     */
    protected static function partArg(array $args): int
    {
        $part = $args['partie'] ?? 1;
        return is_numeric($part) ? max(1, (int)$part) : 1;
    }

    /**
     * Arguments d'une RETOUCHE (`remplace`) d'une proposition en attente
     * (assistant, tâche programmée) : un champ vide ("", [], null) veut dire
     * « inchangé ». Constaté en essai réel (GPT-5.5) : pour « plutôt à 9 h »,
     * le modèle renvoie TOUS les paramètres, les inchangés vides — une liste
     * vide effaçait sinon les sources, les bases ou les outils de la
     * proposition. Seul `vider` retire une liste entière. Booléens et nombres
     * passent tels quels. Pure.
     *
     * @param list<string> $lists paramètres-listes que `vider` peut vider
     */
    public static function revisionArgs(array $args, array $lists): array
    {
        $vider = array_map('strval', (array)($args['vider'] ?? []));
        $out = [];
        foreach ($args as $key => $value) {
            if ($key === 'vider' || $value === null || $value === [] || (is_string($value) && trim($value) === '')) {
                continue;
            }
            $out[$key] = $value;
        }
        foreach (array_intersect($lists, $vider) as $list) {
            $out[$list] = [];
        }
        return $out;
    }

    /**
     * Listes passées VIDES dans une retouche, sans `vider`, alors que la
     * proposition en attente en porte : laissées telles quelles — et le modèle
     * doit le savoir, il pourrait croire les avoir retirées. Pure.
     *
     * @param array<string, string> $fields paramètre-liste → champ de la proposition
     * @return list<string> noms des paramètres
     */
    public static function ignoredEmptyLists(array $args, array $base, array $fields): array
    {
        $vider = array_map('strval', (array)($args['vider'] ?? []));
        $out = [];
        foreach ($fields as $arg => $field) {
            if (array_key_exists($arg, $args) && $args[$arg] === [] && !in_array($arg, $vider, true)
                && (array)($base[$field] ?? []) !== []) {
                $out[] = $arg;
            }
        }
        return $out;
    }
}
