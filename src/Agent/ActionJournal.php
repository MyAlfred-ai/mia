<?php

declare(strict_types=1);

/**
 * Journal des actions menées aux réponses PRÉCÉDENTES de la conversation.
 *
 * Les résultats d'outils d'un message antérieur ne sont jamais réinjectés
 * dans l'historique (buildLLMMessages ne rejoue que le texte) : au message
 * suivant, le modèle ne sait plus qu'il a déjà cherché « facture EDF » sans
 * rien trouver, ni qu'il a préparé un brouillon. Il refait alors la même
 * recherche, ou affirme n'avoir rien fait. ConnectorContext rappelle les
 * OBJETS touchés (identifiants) ; ce journal rappelle les GESTES, tirés de la
 * trace d'actions déjà persistée (`messages.actions_json`) — aucune écriture,
 * aucune requête : l'historique du tour est déjà chargé.
 *
 * Forme pure, testable sans base. Borné (TURNS réponses, MAX_PER_TURN
 * actions chacune, détail raccourci) : quelques centaines de jetons au plus,
 * zéro quand rien n'a été fait.
 */
final class ActionJournal
{
    /** Réponses précédentes rappelées (les plus récentes qui portent des actions). */
    public const TURNS = 3;

    /** Actions rappelées par réponse ; au-delà, un compte. */
    public const MAX_PER_TURN = 8;

    /** Longueur d'un détail (requête, nom de fichier) et de la demande citée. */
    public const DETAIL_MAX  = 60;
    public const REQUEST_MAX = 70;

    /** Types écartés : ils disent QUEL modèle a répondu, pas ce qui a été fait. */
    private const SKIPPED = ['relais_secours', 'relais_refus', 'relais_conservation'];

    /**
     * Fragment de prompt système, ou '' (0 jeton) si aucune réponse
     * précédente ne porte d'action.
     *
     * @param list<array> $history messages ANTÉRIEURS à la demande courante (lignes `messages`)
     */
    public static function promptFragment(array $history): string
    {
        return self::gesturesFragment($history) . self::planFragment($history);
    }

    /**
     * Plan de travail resté INACHEVÉ à la dernière réponse (outil
     * plan_de_travail, `messages.agent_json`), ou ''. C'est lui qui permet de
     * reprendre après une approbation : « Continuer » arrive comme un message
     * ordinaire, et le modèle ne voit que le texte des réponses précédentes.
     * Seulement s'il précède la demande sans autre message de l'utilisateur
     * entre les deux : un plan d'un échange plus ancien n'engage plus rien. Pure.
     */
    public static function planFragment(array $history): string
    {
        $labels = ['a_faire' => 'à faire', 'en_cours' => 'en cours', 'fait' => 'fait', 'abandonne' => 'abandonnée'];
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $row = $history[$i];
            if (($row['role'] ?? '') === 'user') {
                return '';
            }
            $plan = Message::agentOf($row)['plan'] ?? null;
            if (!is_array($plan)) {
                continue;
            }
            $plan = PlanTools::normalize($plan);
            if ($plan === null || PlanTools::unfinished($plan) === []) {
                return '';
            }
            $steps = [];
            foreach ($plan['etapes'] as $n => $step) {
                $steps[] = ($n + 1) . '. ' . $step['titre'] . ' (' . ($labels[$step['statut']] ?? $step['statut']) . ')';
            }
            return "\n\nPlan de travail de ta réponse précédente, resté inachevé : " . implode(' ; ', $steps)
                . ". Si l'utilisateur te demande de continuer, reprends aux étapes restantes, en tenant compte des "
                . "issues des cartes d'approbation (messages ✅, 🚫 ou ⚠️ ci-dessus), et remets le plan à jour.";
        }
        return '';
    }

    /** Gestes des réponses précédentes (cf. en-tête de classe). Pure. */
    private static function gesturesFragment(array $history): string
    {
        $turns = [];
        for ($i = count($history) - 1; $i >= 0 && count($turns) < self::TURNS; $i--) {
            $row = $history[$i];
            if (($row['role'] ?? '') !== 'assistant' || empty($row['actions_json'])) {
                continue;
            }
            $actions = json_decode((string)$row['actions_json'], true);
            $line = is_array($actions) ? self::describe($actions) : '';
            if ($line === '') {
                continue;
            }
            $turns[] = '- ' . self::requestLabel($history, $i) . ' : ' . $line;
        }
        if ($turns === []) {
            return '';
        }
        return "\n\nActions que tu as menées dans les réponses précédentes de cette conversation "
            . "(leurs résultats ne te sont plus visibles : relance un outil si tu en as besoin, mais ne "
            . "refais pas à l'identique une recherche restée vide ou une action qui a échoué sans rien "
            . "changer ; une écriture « préparée » attendait l'approbation de l'utilisateur, son issue "
            . "est dans la conversation) :\n"
            . implode("\n", array_reverse($turns));
    }

    /**
     * Une réponse = ses actions, dans l'ordre, séparées par « ; ». Pure.
     *
     * @param list<array> $actions entrées `{t, l, d?, ok}` de la trace
     */
    public static function describe(array $actions): string
    {
        $parts = [];
        $extra = 0;
        foreach (Message::normalizeActions($actions) as $a) {
            if (in_array($a['t'], self::SKIPPED, true)) {
                continue;
            }
            if (count($parts) >= self::MAX_PER_TURN) {
                $extra++;
                continue;
            }
            $part = $a['l'];
            if (isset($a['d'])) {
                $part .= ' (« ' . self::shorten($a['d'], self::DETAIL_MAX) . ' »)';
            }
            if (!$a['ok']) {
                $part .= ' — échec';
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            return '';
        }
        return implode(' ; ', $parts) . ($extra > 0 ? ' ; et ' . $extra . ' autre(s)' : '');
    }

    /** « En réponse à « … » » : la demande de l'utilisateur qui précède la réponse. */
    private static function requestLabel(array $history, int $assistantIndex): string
    {
        for ($j = $assistantIndex - 1; $j >= 0; $j--) {
            if (($history[$j]['role'] ?? '') !== 'user') {
                continue;
            }
            $text = trim((string)preg_replace('/\s+/u', ' ', (string)($history[$j]['content'] ?? '')));
            return $text !== ''
                ? 'En réponse à « ' . self::shorten($text, self::REQUEST_MAX) . ' »'
                : 'En réponse à un envoi sans texte';
        }
        return 'Plus tôt dans la conversation';
    }

    private static function shorten(string $text, int $max): string
    {
        $text = trim($text);
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}
