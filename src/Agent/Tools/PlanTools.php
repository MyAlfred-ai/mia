<?php

declare(strict_types=1);

/**
 * Plan de travail VISIBLE : le modèle affiche la liste de ses étapes et la
 * coche au fil de l'eau.
 *
 * Le plan annoncé « en une ou deux phrases » avant les premiers outils ne
 * s'affichait plus sur les modèles Claude récents (Opus 5.5, Sonnet 5.5,
 * Fable) : le texte écrit entre deux appels d'outils y revient en blocs de
 * raisonnement, masqués. Et même visible, une phrase ne dit pas où en est
 * l'agent au bout de trois minutes. Un OUTIL, lui, marche chez tous les
 * fournisseurs à function-calling et produit un état : une carte « Plan de
 * travail » au-dessus de la réponse (évènement SSE `plan`), persistée avec le
 * message (`messages.agent_json`), relue au tour suivant (ActionJournal) — c'est
 * elle qui permet de reprendre après une approbation.
 *
 * Exposé dès que des connecteurs sont engagés (même condition que la méthode
 * « agent ») ; il n'agit sur rien, ne lit rien : ni contenu tiers, ni trace.
 */
final class PlanTools extends AbstractToolProvider
{
    public const TOOL = 'plan_de_travail';

    public const MAX_STEPS   = 8;
    public const TITLE_MAX   = 100;
    public const COMMENT_MAX = 200;

    /** Statuts d'une étape, dans l'ordre d'avancement. */
    public const STATUSES = ['a_faire', 'en_cours', 'fait', 'abandonne'];

    /**
     * @param \Closure(array): void $onPlan reçoit le plan normalisé (état du tour)
     */
    public function __construct(AgentTurn $turn, private readonly \Closure $onPlan)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'plan';
    }

    public function definitions(): array
    {
        return [[
            'name'        => self::TOOL,
            'description' => "Affiche à l'utilisateur ton plan de travail (liste d'étapes) et le tient à jour. "
                . "À appeler pour une demande qui demande plusieurs outils à enchaîner : une première fois dans le "
                . "MÊME tour que tes premiers appels d'outils, puis à nouveau, avec les appels suivants, pour cocher "
                . "les étapes faites. Chaque appel remplace le plan précédent : renvoie toutes les étapes. Inutile "
                . "pour une question simple ou un seul outil.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'etapes' => [
                        'type'        => 'array',
                        'description' => 'Les étapes, dans l\'ordre (' . self::MAX_STEPS . ' au plus), formulées pour l\'utilisateur.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'titre'  => ['type' => 'string', 'description' => 'Ce que fait l\'étape, en quelques mots (ex. « Retrouver le mail de Marie »).'],
                                'statut' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'a_faire, en_cours, fait ou abandonne.'],
                            ],
                            'required'   => ['titre', 'statut'],
                        ],
                    ],
                    'commentaire' => [
                        'type'        => 'string',
                        'description' => 'Facultatif : une phrase sur l\'avancement (ce qui vient d\'être trouvé, un obstacle).',
                    ],
                ],
                'required'   => ['etapes'],
            ],
        ]];
    }

    public function guidance(): string
    {
        return "\n\nPlan de travail visible : pour une demande en plusieurs étapes, appelle `" . self::TOOL . "` "
            . "EN MÊME TEMPS que tes premiers outils (même tour, jamais seul), puis mets-le à jour avec les appels "
            . "suivants pour cocher ce qui est fait. Une étape qui attend l'approbation de l'utilisateur (carte) "
            . "reste `en_cours`. Termine toujours par une réponse rédigée : le plan ne la remplace pas.";
    }

    public function handlers(): array
    {
        return [self::TOOL => function (array $args): array {
            $plan = self::normalize($args);
            if ($plan === null) {
                return ['error' => 'Plan vide : donne au moins une étape, avec son titre et son statut.'];
            }
            ($this->onPlan)($plan);
            $this->sseEmit(['plan' => $plan]);
            return [
                'ok'      => true,
                'affiche' => 'Plan affiché à l\'utilisateur.',
                'restant' => count(self::unfinished($plan)),
            ];
        }];
    }

    /**
     * Plan propre ou null. Pure : titres bornés et d'une ligne, statut inconnu
     * → `a_faire`, étapes sans titre écartées, au plus MAX_STEPS. Accepte
     * `etapes` en tableau OU en chaîne JSON (certains modèles sérialisent).
     *
     * @return array{etapes: list<array{titre: string, statut: string}>, commentaire?: string}|null
     */
    public static function normalize(array $args): ?array
    {
        $raw = $args['etapes'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return null;
        }
        $steps = [];
        foreach ($raw as $step) {
            if (is_string($step)) {
                $step = ['titre' => $step];
            }
            if (!is_array($step)) {
                continue;
            }
            $title = self::line((string)($step['titre'] ?? ''), self::TITLE_MAX);
            if ($title === '') {
                continue;
            }
            $status = (string)($step['statut'] ?? '');
            $steps[] = ['titre' => $title, 'statut' => in_array($status, self::STATUSES, true) ? $status : 'a_faire'];
            if (count($steps) >= self::MAX_STEPS) {
                break;
            }
        }
        if ($steps === []) {
            return null;
        }
        $plan = ['etapes' => $steps];
        $comment = self::line((string)($args['commentaire'] ?? ''), self::COMMENT_MAX);
        if ($comment !== '') {
            $plan['commentaire'] = $comment;
        }
        return $plan;
    }

    /**
     * Titres des étapes qui restent à mener (à faire ou en cours). Pure.
     *
     * @return list<string>
     */
    public static function unfinished(array $plan): array
    {
        $out = [];
        foreach ((array)($plan['etapes'] ?? []) as $step) {
            if (is_array($step) && in_array($step['statut'] ?? '', ['a_faire', 'en_cours'], true)) {
                $out[] = (string)($step['titre'] ?? '');
            }
        }
        return $out;
    }

    /**
     * Clôture du plan en fin de tour. Le modèle met le plan à jour AVANT sa
     * dernière étape (« en cours »), mène cette étape, puis rédige sa réponse
     * sans rappeler l'outil — la consigne lui interdit un appel isolé, et un
     * aller-retour pour cocher une case serait payé pour rien. Le plan
     * persisté restait alors « 3/4 — en cours » sous une réponse terminée, et
     * le bouton « Continuer » proposait de reprendre un travail fini.
     *
     * Les étapes `en_cours` passent à `fait` quand TOUT confirme qu'elles ont
     * été menées : le modèle a conclu de lui-même (`$concluded` : ni erreur,
     * ni coupure, ni budget épuisé, une réponse écrite), au moins un outil a
     * réussi depuis la dernière mise à jour du plan, aucun n'a échoué, et
     * aucune carte d'approbation n'attend (une étape qui attend l'utilisateur
     * reste `en_cours`, c'est la consigne). Les étapes `a_faire` ne bougent
     * jamais : rien ne dit qu'elles ont été menées. Pure.
     */
    public static function settle(?array $plan, int $succeeded, int $failed, int $pending, bool $concluded): ?array
    {
        if ($plan === null || !$concluded || $pending > 0 || $failed > 0 || $succeeded === 0) {
            return $plan;
        }
        foreach ($plan['etapes'] as $i => $step) {
            if (($step['statut'] ?? '') === 'en_cours') {
                $plan['etapes'][$i]['statut'] = 'fait';
            }
        }
        return $plan;
    }

    private static function line(string $text, int $max): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
