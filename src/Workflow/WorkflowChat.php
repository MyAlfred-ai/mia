<?php

declare(strict_types=1);

/**
 * Discussion dans la conversation d'une exécution de workflow — SOURCE UNIQUE.
 *
 * LE PROBLÈME RÉSOLU (constaté chez un client, 2026-11-24, workflow « Piloter Amazon 1P EU »).
 * Une étape avait remis une fiche « Ad Spend à compléter », relue par une
 * validation. La personne a donné les montants DANS LA DISCUSSION (fichier
 * joint, « je confirme »), le chat a répondu « Saisie Ad Spend confirmée »…
 * mais un message ordinaire ne touche pas au dossier : la fiche est restée
 * vide, la validation a été approuvée, et toute la suite (calculs, email)
 * a écrit « Ad Spend en attente de saisie ». Pire, le message « continue le
 * workflow » a été pris par le chat pour une demande : il a mené lui-même
 * le reporting et préparé un second email, hors du workflow.
 *
 * Désormais, dans la conversation d'une exécution qui n'est pas terminée :
 *  - le modèle sait où il est (note du prompt) : ce qu'il écrit ne change pas
 *    le dossier, il ne mène jamais lui-même les étapes du workflow, et la
 *    suite part de la carte de validation — que lui ne peut pas approuver ;
 *  - pendant une validation, les pièces qu'elle relit (et qu'elle laisse
 *    corriger) se mettent à jour par l'outil `mettre_a_jour_piece_workflow`
 *    — le même geste que « Ajuster » sur la page de l'exécution, avec ses
 *    mêmes conditions. La carte de validation se redessine avec la pièce à
 *    jour : la personne approuve ce qu'elle voit.
 *
 * Rien n'est approuvé ici : la validation reste le geste de la personne
 * (un contenu lu par un connecteur ne doit jamais pouvoir faire avancer un
 * workflow à sa place).
 */
final class WorkflowChat
{
    public const TOOL = 'mettre_a_jour_piece_workflow';

    /** Contenu d'une pièce montré au modèle dans la note du prompt. */
    public const NOTE_PIECE_CHARS = 5000;

    /** Contenu rendu au modèle après une mise à jour. */
    public const RESULT_CHARS = 2500;

    // ==================================================================
    // Lecture
    // ==================================================================

    /**
     * Contexte d'un tour ORDINAIRE de chat dans une conversation d'exécution,
     * ou null (pas une conversation de workflow, exécution terminée, ou d'une
     * autre personne). Ne lève jamais : une panne ne prive pas d'une réponse.
     *
     * @return array{run: array, workflow: string, gate: ?array, step: ?array, pieces: array<string, array>}|null
     */
    public static function forTurn(int $convId, int $userId): ?array
    {
        try {
            $run = WorkflowRun::forConversation($convId);
            if ($run === null || (int)$run['user_id'] !== $userId || in_array($run['status'], WorkflowRun::TERMINAL, true)) {
                return null;
            }
            $workflow = Workflow::findById((int)$run['workflow_id']);
            [$gate, $step] = self::waitingGate($run);
            return [
                'run'      => $run,
                'workflow' => (string)($workflow['name'] ?? ''),
                'gate'     => $gate,
                'step'     => $step,
                'pieces'   => $gate !== null ? self::reviewed($run, $gate, $step) : [],
            ];
        } catch (\Throwable $e) {
            error_log('[Workflows] discussion de la conversation #' . $convId . ' : ' . $e->getMessage());
            return null;
        }
    }

    /** Des pièces que la discussion peut mettre à jour ? */
    public static function updatable(?array $ctx): bool
    {
        return $ctx !== null && array_filter($ctx['pieces'], static fn(array $p): bool => $p['correctable']) !== [];
    }

    /**
     * La validation qui attend la personne, si c'en est une qui relit des
     * pièces (pas un doute de condition, pas un choix de voie, pas la
     * relecture de la liste ou des actions d'une boucle).
     *
     * @return array{0: ?array, 1: ?array}
     */
    private static function waitingGate(array $run): array
    {
        if ($run['status'] !== WorkflowRun::WAITING || (string)$run['current_key'] === '') {
            return [null, null];
        }
        $step = WorkflowRun::lastStep($run['id'], (string)$run['current_key']);
        if ($step === null || $step['status'] !== WorkflowRun::STEP_WAITING || $step['pending_action_id'] === null) {
            return [null, null];
        }
        $gate = WorkflowEngine::nodeFor($run['definition'], (string)$step['node_key']);
        if ($gate === null || ($gate['type'] ?? '') !== WorkflowSpec::TYPE_GATE || !empty($gate['loop_gate'])) {
            return [null, null];
        }
        return [$gate, $step];
    }

    /**
     * Pièces relues par la validation, sous leur clé citable (celle du
     * modèle) : spécification, clé réelle au dossier (« clé#2 » dans une
     * boucle), contenu actuel, et si la validation laisse la corriger.
     *
     * @return array<string, array{piece: array, art_key: string, art: array, correctable: bool}>
     */
    private static function reviewed(array $run, array $gate, array $step): array
    {
        [, $iter] = WorkflowLoop::splitIter((string)$step['node_key']);
        $pieces = WorkflowSpec::pieces($run['definition']);
        $artifacts = WorkflowRun::artifacts($run['id']);
        $out = [];
        foreach ((array)($gate['reviews'] ?? []) as $key) {
            $key = (string)$key;
            $artKey = WorkflowLoop::iterKey($key, $iter);
            $art = $artifacts[$artKey] ?? ($artifacts[$key] ?? null);
            if (!isset($pieces[$key]) || $art === null) {
                continue;
            }
            $out[$key] = [
                'piece'       => $pieces[$key],
                'art_key'     => (string)$art['key'],
                'art'         => $art,
                'correctable' => !empty($gate['can_correct']) && WorkflowSpec::isCorrectable($pieces[$key]),
            ];
        }
        return $out;
    }

    // ==================================================================
    // Prompt
    // ==================================================================

    /**
     * Consigne de méthode (en français, comme les autres) ajoutée au prompt
     * d'un tour ordinaire dans la conversation d'une exécution.
     */
    public static function promptNote(array $ctx, bool $canUpdate): string
    {
        $name = $ctx['workflow'] !== '' ? '« ' . $ctx['workflow'] . ' »' : '';
        $lines = [
            "\n\nCONVERSATION D'UN WORKFLOW : cette conversation est celle d'une exécution du workflow {$name}. "
            . "Le workflow mène LUI-MÊME ses étapes et range ses pièces dans son dossier. Ce que tu écris ici ne modifie "
            . "PAS le dossier, et tu ne mènes JAMAIS toi-même les étapes du workflow (calculs, rédaction ou envoi d'un "
            . "email, documents à livrer) : ne prépare aucun email, aucun document ni aucune action qui doublerait une "
            . "étape. Réponds à la personne : explique, vérifie, aide-la à préparer ce que le workflow attend d'elle.",
        ];
        $status = (string)$ctx['run']['status'];
        if ($ctx['gate'] === null) {
            $lines[] = match ($status) {
                WorkflowRun::FAILED => "L'exécution est arrêtée sur une erreur : elle se reprend depuis le suivi de l'exécution (bouton « Reprendre »), pas depuis cette conversation.",
                WorkflowRun::PAUSED => "L'exécution est en pause : elle se reprend depuis le suivi de l'exécution, pas depuis cette conversation.",
                default             => "Le workflow est en train de travailler : sa suite arrive d'elle-même dans cette conversation.",
            };
            return implode("\n", $lines);
        }

        $gate = $ctx['gate'];
        $lines[] = "\nUNE VALIDATION ATTEND LA PERSONNE : « " . $gate['title'] . " ». Le workflow ne continue que quand la personne clique "
            . "« Approuver » sur la carte de validation (ou la renvoie avec une remarque). Tu ne peux PAS approuver à sa place : "
            . "si elle te dit de continuer, dis-lui d'approuver la carte de validation.";
        foreach ($ctx['pieces'] as $key => $p) {
            $piece = $p['piece'];
            $lines[] = "\nPièce relue « " . $piece['name'] . " » (clé `" . $key . "`, " . self::formHint($piece) . ") — contenu ACTUEL du dossier, "
                . "c'est lui que la suite du workflow lira :\n<<<\n"
                . ArtifactContract::render($piece, $p['art']['content'], self::NOTE_PIECE_CHARS) . "\n>>>";
            if (!$p['correctable']) {
                $lines[] = "Cette pièce ne se corrige pas ici : pour la changer, la personne renvoie l'étape avec une remarque depuis la carte de validation.";
            }
        }
        if ($canUpdate) {
            $lines[] = "\nSi la personne fournit, corrige ou confirme des valeurs d'une pièce relue (dans son message, ou dans un fichier "
                . "qu'elle joint), METS LA PIÈCE À JOUR avec l'outil `" . self::TOOL . "` AVANT de répondre — sinon la suite du "
                . "workflow lira l'ancienne version. Pour une fiche, donne les champs qui changent ET réécris le résumé pour qu'il "
                . "dise la situation à jour (un résumé resté « en attente » contredirait les valeurs). N'invente aucune valeur : "
                . "seulement ce que la personne a donné ou confirmé. Puis dis-lui que la pièce est à jour et qu'elle peut "
                . "approuver la carte de validation pour que le workflow continue.";
        } else {
            $lines[] = "\nTu ne peux pas modifier les pièces du dossier : si la personne veut les changer, indique-lui « Ajuster » "
                . "sur la carte de validation (correction à la main) ou « Renvoyer » avec une remarque.";
        }
        return implode("\n", $lines);
    }

    /** Forme d'une pièce, en mots, pour le modèle. */
    private static function formHint(array $piece): string
    {
        if ($piece['form'] === 'fiche') {
            $fields = array_map(
                static fn(array $f): string => '« ' . $f['label'] . ' » (' . $f['type'] . ')',
                (array)($piece['fields'] ?? [])
            );
            return 'fiche — champs : ' . implode(', ', $fields);
        }
        return match ($piece['form']) {
            'list'     => 'liste',
            'table'    => 'tableau Markdown',
            'document' => 'document (' . (string)($piece['format'] ?? 'docx') . ')',
            default    => 'texte',
        };
    }

    // ==================================================================
    // Mise à jour
    // ==================================================================

    /**
     * Contenu mis à jour d'une pièce (pure). Arguments de l'outil :
     *  - fiche : `champs` (objet JSON, ou texte JSON — clé ou libellé →
     *    valeur ; seuls les champs donnés changent, null ou "" les vident) et
     *    `resume` (le résumé réécrit ; absent, un résumé devenu faux est
     *    retiré dès qu'un champ change) ;
     *  - texte, liste, tableau, document : `contenu` (la pièce entière, dans
     *    sa forme) ou `remplacer` + `par` (une correction ponctuelle).
     *
     * @return array{ok: bool, content: array, changed: bool, error: string}
     */
    public static function updatedContent(array $piece, array $content, array $args): array
    {
        $fail = static fn(string $why): array => ['ok' => false, 'content' => [], 'changed' => false, 'error' => $why];

        if ($piece['form'] === 'fiche') {
            $champs = $args['champs'] ?? null;
            if (is_string($champs)) {
                $champs = trim($champs) === '' ? [] : (ArtifactContract::lastJsonObject($champs) ?? json_decode($champs, true));
                if (!is_array($champs)) {
                    return $fail("« champs » doit être un objet JSON (champ → valeur).");
                }
            }
            $champs = is_array($champs) ? $champs : [];
            $resume = isset($args['resume']) && is_string($args['resume']) && trim($args['resume']) !== '' ? trim($args['resume']) : null;
            if ($champs === [] && $resume === null) {
                return $fail("Rien à mettre à jour : donne « champs » (et le « resume » réécrit).");
            }
            $byKey = [];
            foreach ((array)($piece['fields'] ?? []) as $f) {
                $byKey[WorkflowSpec::slug((string)$f['key'])] = $f;
                $byKey[WorkflowSpec::slug((string)$f['label'])] = $f;
            }
            $fields = [];
            foreach ($champs as $name => $raw) {
                $f = $byKey[WorkflowSpec::slug((string)$name)] ?? null;
                if ($f === null) {
                    return $fail("Champ « " . $name . " » inconnu. Champs de la fiche : "
                        . implode(', ', array_map(static fn($x) => '« ' . $x['label'] . ' »', (array)$piece['fields'])) . '.');
                }
                $empty = $raw === null || (is_string($raw) && trim($raw) === '') || $raw === [];
                $value = $empty ? null : WorkflowValues::coerce($raw, (string)$f['type']);
                if (!$empty && ($value === null || $value === '' || $value === [])) {
                    return $fail("Valeur illisible pour « " . $f['label'] . " » (" . $f['type'] . ") : " . (is_scalar($raw) ? (string)$raw : json_encode($raw, JSON_UNESCAPED_UNICODE)) . '.');
                }
                $fields[(string)$f['key']] = $value;
            }
            $next = ArtifactContract::withCorrectedFields($piece, $content, $fields, $resume);
            return ['ok' => true, 'content' => $next, 'changed' => $next !== $content, 'error' => ''];
        }

        $remplacer = isset($args['remplacer']) && is_string($args['remplacer']) ? $args['remplacer'] : '';
        $contenu = isset($args['contenu']) && is_string($args['contenu']) ? $args['contenu'] : null;
        if ($remplacer !== '') {
            $text = ArtifactContract::render($piece, $content, PHP_INT_MAX);
            $count = substr_count($text, $remplacer);
            if ($count === 0) {
                return $fail("Texte à remplacer introuvable dans la pièce : « " . mb_substr($remplacer, 0, 120) . " ».");
            }
            $contenu = str_replace($remplacer, isset($args['par']) && is_string($args['par']) ? $args['par'] : '', $text);
        }
        if ($contenu === null || trim($contenu) === '') {
            return $fail("Rien à mettre à jour : donne « contenu » (la pièce entière) ou « remplacer » + « par ».");
        }
        $next = $content;
        switch ($piece['form']) {
            case 'list':
                $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $contenu))), 'strlen'));
                $items = ArtifactContract::listItems(implode("\n", array_map(
                    static fn(string $l): string => preg_match('/^(?:[-*•+]|\d+[.)])\s+/u', $l) ? $l : '- ' . $l,
                    $lines
                )));
                if ($items === []) {
                    return $fail("La liste est vide.");
                }
                $next['items'] = $items;
                break;
            case 'table':
                $rows = ArtifactContract::tableRows($contenu);
                if ($rows === null) {
                    return $fail("Le tableau doit garder sa ligne d'en-tête et sa ligne de tirets (tableau Markdown).");
                }
                $next['rows'] = $rows;
                break;
            case 'document':
                // Le document entier, sans l'enveloppe d'un bloc de code.
                $next['source'] = ArtifactContract::stripOuterFence($contenu);
                break;
            default:
                $next['text'] = trim($contenu);
        }
        return ['ok' => true, 'content' => $next, 'changed' => $next !== $content, 'error' => ''];
    }

    /**
     * Met à jour une pièce relue, depuis la discussion. Tout est relu AU
     * MOMENT T : la validation doit encore attendre, et laisser corriger
     * cette pièce. Rend le résultat pour le modèle, et l'aperçu redessiné de
     * la carte de validation (`card`) quand elle montre cette pièce.
     *
     * @return array{result: array, card: ?array}
     */
    public static function apply(int $convId, int $userId, array $args): array
    {
        $ctx = self::forTurn($convId, $userId);
        $key = WorkflowSpec::slug((string)($args['piece'] ?? ''), 0);
        if ($ctx === null || $ctx['gate'] === null) {
            return ['result' => ['error' => "Aucune validation n'attend dans ce workflow : le dossier ne se modifie pas depuis la conversation."], 'card' => null];
        }
        $p = $ctx['pieces'][$key] ?? null;
        if ($p === null) {
            foreach ($ctx['pieces'] as $k => $candidate) {
                if (WorkflowSpec::slug((string)$candidate['piece']['name'], 0) === $key) {
                    [$key, $p] = [$k, $candidate];
                    break;
                }
            }
        }
        if ($p === null) {
            return ['result' => ['error' => "Pièce inconnue. Pièces relues par la validation : "
                . implode(', ', array_map(static fn($k, $x) => '`' . $k . '` (« ' . $x['piece']['name'] . ' »)', array_keys($ctx['pieces']), $ctx['pieces'])) . '.'], 'card' => null];
        }
        if (!$p['correctable']) {
            return ['result' => ['error' => "Cette pièce ne se corrige pas ici : la personne la renvoie avec une remarque depuis la carte de validation."], 'card' => null];
        }

        $piece = $p['piece'];
        $run = $ctx['run'];
        $update = self::updatedContent($piece, $p['art']['content'], $args);
        if (!$update['ok']) {
            return ['result' => ['error' => $update['error']], 'card' => null];
        }
        $content = $update['content'];
        if ($update['changed']) {
            // Même chemin que « Ajuster » : ses repères remplis comme à sa remise.
            [, $iter] = WorkflowLoop::splitIter($p['art_key']);
            $producer = WorkflowSpec::node($run['definition'], (string)$piece['producer']);
            if ($producer !== null) {
                $content = WorkflowInserts::expandContent($run['definition'], $producer, $piece, $content, WorkflowRun::artifacts($run['id']), $iter);
            }
            WorkflowRun::saveArtifact($run['id'], $p['art_key'], (string)$p['art']['node_key'], $piece['name'], $piece['form'], $content, $p['art']['provenance']);
        }

        // Carte de validation : son aperçu montre la PREMIÈRE pièce relue.
        $card = null;
        $actionId = (int)$ctx['step']['pending_action_id'];
        $first = array_key_first($ctx['pieces']);
        if ($update['changed'] && $first === $key) {
            $action = DrivePendingAction::findPending($actionId, $userId, $convId);
            if ($action !== null) {
                $preview = (array)$action['preview'];
                $preview['apercu'] = ArtifactContract::render($piece, $content, 3000);
                if (DrivePendingAction::updatePending($actionId, $userId, $convId, (array)$action['args'], $preview)) {
                    $card = ['id' => $actionId, 'preview' => $preview];
                }
            }
        }

        return [
            'result' => [
                'statut'  => $update['changed'] ? 'mise_a_jour' : 'inchangee',
                'piece'   => $piece['name'],
                'contenu' => ArtifactContract::render($piece, $content, self::RESULT_CHARS),
                'note'    => "La pièce du dossier est à jour : la suite du workflow lira cette version. Le workflow continue quand la "
                    . "personne approuve la carte de validation « " . $ctx['gate']['title'] . " » — dis-le-lui.",
            ],
            'card' => $card,
        ];
    }

    /** Définition de l'outil, pour les pièces que la discussion peut corriger. */
    public static function toolDefinition(array $ctx): array
    {
        $keys = array_keys(array_filter($ctx['pieces'], static fn(array $p): bool => $p['correctable']));
        $names = array_map(static fn($k) => '`' . $k . '` = « ' . $ctx['pieces'][$k]['piece']['name'] . ' »', $keys);
        return [
            'name'        => self::TOOL,
            'description' => "Met à jour une pièce du dossier du workflow relue par la validation en attente ("
                . implode(', ', $names) . "), avec ce que la personne vient de donner, corriger ou confirmer. "
                . "Fiche : « champs » (objet JSON champ → valeur, seuls ceux qui changent) et « resume » réécrit. "
                . "Autre pièce : « contenu » (la pièce entière, dans sa forme) ou « remplacer » + « par ». "
                . "N'approuve rien : la personne approuve ensuite la carte de validation.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'piece'     => ['type' => 'string', 'enum' => $keys, 'description' => 'Clé de la pièce à mettre à jour.'],
                    'champs'    => ['type' => 'string', 'description' => 'Fiche : objet JSON {"Libellé ou clé du champ": valeur}. Nombre sans unité (15539), date AAAA-MM-JJ, oui/non en true/false, liste en tableau.'],
                    'resume'    => ['type' => 'string', 'description' => 'Fiche : le résumé réécrit pour la situation à jour.'],
                    'contenu'   => ['type' => 'string', 'description' => 'Texte, liste (une ligne par élément), tableau Markdown ou document : la pièce entière, mise à jour.'],
                    'remplacer' => ['type' => 'string', 'description' => 'Correction ponctuelle : le texte exact à remplacer.'],
                    'par'       => ['type' => 'string', 'description' => 'Correction ponctuelle : le nouveau texte.'],
                ],
                'required'   => ['piece'],
            ],
        ];
    }
}
