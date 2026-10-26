<?php

declare(strict_types=1);

/**
 * Historique BORNÉ envoyé au modèle — SOURCE UNIQUE du plan d'ajustement.
 *
 * LE PROBLÈME RÉSOLU. `buildLLMMessages` renvoyait TOUT l'historique à chaque
 * message, pièces jointes comprises : un PDF de 40 pages joint au premier
 * message repartait, en base64, avec chacune des cinquante questions
 * suivantes. Une longue conversation finissait par échouer en « conversation
 * trop longue », après avoir coûté de plus en plus cher à chaque tour.
 *
 * Le plan, du plus doux au plus fort, et RIEN tant que tout tient :
 *
 *   1. l'historique entier tient dans le budget (la moitié de la fenêtre du
 *      modèle) → il part tel quel : une conversation ordinaire ne change pas ;
 *   2. sinon, les pièces jointes des tours ANCIENS (tous sauf les deux
 *      derniers messages de l'utilisateur) sont remplacées par une ligne qui
 *      les nomme — relisibles sur demande (`relire_fichier_conversation`,
 *      exposé quand la boucle d'outils tourne déjà ou que le message évoque
 *      un fichier : jamais pour forcer la boucle sur un message ordinaire) ;
 *   3. sinon, les messages les plus anciens laissent la place à un RÉSUMÉ,
 *      produit une fois et réutilisé tant que la partie gardée tient : la
 *      coupure avance par blocs (la partie gardée vise la moitié du budget),
 *      le résumé n'est donc pas refait à chaque message.
 *
 * La partie gardée commence toujours par un message de l'utilisateur
 * (alternance exigée par certains fournisseurs) et compte au moins deux
 * échanges. Le message en cours n'est jamais retiré.
 *
 * Estimations volontairement prudentes (une image ≈ 1 600 jetons, un PDF
 * natif ≈ 1 jeton pour 6 octets) : surestimer ne fait qu'alléger plus tôt.
 * Pure, testable sans base.
 */
final class ContextWindow
{
    /** Part de la fenêtre du modèle laissée à l'historique. */
    public const HISTORY_SHARE = 0.5;

    /** Budget plancher (jetons) : un modèle local de 8 000 jetons garde de quoi converser. */
    public const MIN_BUDGET = 2000;

    /** Messages récents toujours gardés intacts (deux échanges). */
    public const KEEP_RECENT_MIN = 4;

    /** Derniers messages de l'utilisateur dont les pièces jointes restent jointes. */
    public const KEEP_FILES_TURNS = 2;

    /** Après une coupure, la partie gardée vise cette part du budget. */
    public const AFTER_SUMMARY_SHARE = 0.5;

    /** Réserve du budget pour le résumé lui-même (jetons). */
    public const SUMMARY_RESERVE = 1200;

    public const IMAGE_TOKENS = 1600;

    /** Budget d'historique (jetons) pour une fenêtre de modèle. */
    public static function budget(int $contextWindow): int
    {
        return max(self::MIN_BUDGET, (int)floor(max(0, $contextWindow) * self::HISTORY_SHARE));
    }

    /**
     * Pièces jointes d'une ligne d'historique (files_json, sinon colonnes
     * historiques file_*).
     *
     * @return list<array{name:string, type:string, extracted:string, path:string}>
     */
    public static function files(array $row): array
    {
        $files = [];
        if (!empty($row['files_json'])) {
            $decoded = json_decode((string)$row['files_json'], true);
            $files = is_array($decoded) ? $decoded : [];
        } elseif (!empty($row['file_name'])) {
            $files = [['name' => $row['file_name'], 'type' => $row['file_type'] ?? '', 'extracted' => $row['file_extracted'] ?? '']];
        }
        $out = [];
        foreach ($files as $f) {
            if (!is_array($f)) {
                continue;
            }
            $out[] = [
                'name'      => (string)($f['name'] ?? ''),
                'type'      => (string)($f['type'] ?? ''),
                'extracted' => (string)($f['extracted'] ?? ''),
                'path'      => (string)($f['path'] ?? ''),
            ];
        }
        return $out;
    }

    /** Une pièce jointe envoyée au modèle (les autres sont des marqueurs de création). */
    public static function isSentFile(array $file): bool
    {
        return $file['type'] !== '' && $file['extracted'] !== '';
    }

    /** Coût estimé (jetons) d'une ligne : texte, plus ses pièces jointes si `$withFiles`. */
    public static function rowTokens(array $row, bool $withFiles = true): int
    {
        $files  = self::files($row);
        // Une présentation part au modèle sous la forme d'un résumé d'une ligne
        // (buildLLMMessages), jamais sa spec JSON : la compter entière ferait
        // résumer une conversation qui tient très bien.
        $isDeck = array_filter($files, static fn(array $f): bool => $f['type'] === 'slide_deck') !== [];
        $tokens = ($isDeck ? 60 : TextChunker::estimateTokens((string)($row['content'] ?? ''))) + 4;
        foreach ($files as $f) {
            if (!self::isSentFile($f)) {
                continue;
            }
            if (!$withFiles) {
                $tokens += 40; // la ligne qui nomme le fichier
                continue;
            }
            $tokens += match (true) {
                str_starts_with($f['type'], 'image/') => self::IMAGE_TOKENS,
                $f['type'] === 'application/pdf'       => max(1500, intdiv((int)(strlen($f['extracted']) * 3 / 4), 6)),
                default                                => TextChunker::estimateTokens($f['extracted']),
            };
        }
        return $tokens;
    }

    /**
     * Plan d'ajustement. `$history` va du plus ancien au plus récent (lignes de
     * la table messages) ; `$summary` = résumé existant `{upto, text}` ou null.
     *
     * @return array{total:int, budget:int, strip_files_before:int, cutoff:int, reuse_summary:bool, needs_summary:bool, dropped:int}
     */
    public static function plan(array $history, int $budget, ?array $summary): array
    {
        $plan = [
            'total' => 0, 'budget' => $budget, 'strip_files_before' => 0,
            'cutoff' => 0, 'reuse_summary' => false, 'needs_summary' => false, 'dropped' => 0,
        ];
        $rows = array_values($history);
        $n    = count($rows);
        if ($n === 0) {
            return $plan;
        }
        foreach ($rows as $r) {
            $plan['total'] += self::rowTokens($r);
        }
        if ($plan['total'] <= $budget) {
            return $plan;
        }

        // 2. Pièces jointes des tours anciens : seules celles des
        //    KEEP_FILES_TURNS derniers messages de l'utilisateur restent.
        $keepFilesFrom = self::nthUserFromEnd($rows, self::KEEP_FILES_TURNS);
        $hasOldFiles   = false;
        for ($i = 0; $i < $keepFilesFrom; $i++) {
            foreach (self::files($rows[$i]) as $f) {
                if (self::isSentFile($f)) {
                    $hasOldFiles = true;
                    break 2;
                }
            }
        }
        $costs = [];
        foreach ($rows as $i => $r) {
            $costs[$i] = self::rowTokens($r, $i >= $keepFilesFrom);
        }
        if ($hasOldFiles) {
            $plan['strip_files_before'] = (int)$rows[$keepFilesFrom]['id'];
        }
        if (array_sum($costs) <= $budget) {
            return $plan;
        }

        // 3. Fenêtre glissante. Un résumé existant est réutilisé tant que ce
        //    qui le suit tient dans le budget.
        $room = max(0, $budget - self::SUMMARY_RESERVE);
        if ($summary !== null && (int)($summary['upto'] ?? 0) > 0) {
            $upto  = (int)$summary['upto'];
            $start = self::indexAfter($rows, $upto);
            if ($start !== null && $start <= $n - self::KEEP_RECENT_MIN
                && ($rows[$start]['role'] ?? '') === 'user'
                && array_sum(array_slice($costs, $start)) <= $room) {
                $plan['cutoff']        = $upto;
                $plan['reuse_summary'] = true;
                $plan['dropped']       = $start;
                return $plan;
            }
        }

        // Nouvelle coupure : la partie gardée vise AFTER_SUMMARY_SHARE du budget,
        // pour grandir quelques messages avant la prochaine coupure.
        $target = (int)floor($budget * self::AFTER_SUMMARY_SHARE);
        $start  = $n;
        $kept   = 0;
        while ($start > 0) {
            $next = $kept + $costs[$start - 1];
            if (($n - $start) >= self::KEEP_RECENT_MIN && $next > $target) {
                break;
            }
            $kept = $next;
            $start--;
        }
        // La partie gardée commence par un message de l'utilisateur.
        while ($start > 0 && $start < $n && ($rows[$start]['role'] ?? '') !== 'user') {
            $start--;
        }
        if ($start <= 0) {
            return $plan; // rien à retirer sans toucher aux échanges récents
        }
        $plan['cutoff']        = (int)$rows[$start - 1]['id'];
        $plan['dropped']       = $start;
        $plan['needs_summary'] = !($summary !== null && (int)($summary['upto'] ?? 0) === $plan['cutoff']);
        $plan['reuse_summary'] = !$plan['needs_summary'];
        return $plan;
    }

    /**
     * Historique ajusté selon le plan : lignes retirées jusqu'à la coupure,
     * pièces jointes anciennes remplacées par une ligne qui les nomme. Ce
     * texte ne dépend PAS de la disponibilité de l'outil de relecture (elle
     * change d'un message à l'autre) : la conduite à tenir vit dans
     * `filesNote()`, au prompt système.
     *
     * @return list<array>
     */
    public static function apply(array $history, array $plan): array
    {
        $out = [];
        foreach (array_values($history) as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($plan['cutoff'] > 0 && $id <= $plan['cutoff']) {
                continue;
            }
            if ($plan['strip_files_before'] > 0 && $id < $plan['strip_files_before']) {
                $r = self::stripFiles($r);
            }
            $out[] = $r;
        }
        return $out;
    }

    /** Ligne sans ses pièces jointes, chacune nommée à la fin du texte. */
    public static function stripFiles(array $row): array
    {
        $lines = [];
        foreach (self::files($row) as $f) {
            if (!self::isSentFile($f)) {
                continue;
            }
            $lines[] = '[Fichier « ' . ($f['name'] !== '' ? $f['name'] : 'sans nom') . ' » joint à ce message : son contenu '
                . 'n\'est plus renvoyé à chaque message (conversation longue).]';
        }
        if ($lines === []) {
            return $row;
        }
        $row['content']        = rtrim((string)($row['content'] ?? '')) . "\n\n" . implode("\n", $lines);
        $row['files_json']     = null;
        $row['file_name']      = null;
        $row['file_extracted'] = null;
        return $row;
    }

    /**
     * Conduite à tenir quand des pièces jointes anciennes ont été retirées
     * (fragment de prompt système, joint au résumé éventuel).
     */
    public static function filesNote(): string
    {
        return "\n\nConversation longue : les fichiers joints aux messages anciens ne te sont plus renvoyés, seul leur "
            . "nom reste (« [Fichier … joint à ce message …] »). Si la question porte sur l'un d'eux, relis-le avec "
            . "`relire_fichier_conversation` quand cet outil t'est proposé ; sinon, demande à l'utilisateur de le "
            . "joindre à nouveau. Ne réponds jamais de mémoire sur son contenu.";
    }

    /** Noms des pièces jointes retirées par le plan (dédoublonnés, dans l'ordre). */
    public static function strippedFileNames(array $history, array $plan): array
    {
        $names = [];
        foreach ($history as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($plan['strip_files_before'] <= 0 || $id >= $plan['strip_files_before'] || ($plan['cutoff'] > 0 && $id <= $plan['cutoff'])) {
                continue;
            }
            foreach (self::files($r) as $f) {
                if (self::isSentFile($f) && $f['name'] !== '' && !in_array($f['name'], $names, true)) {
                    $names[] = $f['name'];
                }
            }
        }
        return $names;
    }

    /**
     * Le message en cours évoque-t-il un fichier retiré de la fenêtre ? Son
     * nom (ou son nom sans extension, 4 caractères au moins), ou un mot qui
     * désigne un fichier (fichier, document, pièce jointe, PDF, image…). Une
     * réponse positive vaut DEMANDE EXPLICITE : l'outil de relecture est
     * exposé même quand la boucle d'outils ne tourne pas. Pure.
     */
    public static function mentionsCompactedFile(string $content, array $history, array $plan): bool
    {
        if ($plan['strip_files_before'] <= 0) {
            return false;
        }
        $names = self::strippedFileNames($history, $plan);
        if ($names === []) {
            return false;
        }
        $text = self::fold($content);
        foreach ($names as $name) {
            $full = self::fold($name);
            $base = self::fold((string)pathinfo($name, PATHINFO_FILENAME));
            if (str_contains($text, $full) || (mb_strlen($base) >= 4 && str_contains($text, $base))) {
                return true;
            }
        }
        return preg_match(
            '/\b(?:fichiers?|documents?|pieces? jointes?|pj|pdf|annexes?|ci-joints?|joints?|jointes?|images?|photos?|captures?'
            . '|files?|attachments?|attached|screenshots?)\b/u',
            $text
        ) === 1;
    }

    /**
     * Fragment de prompt système qui remplace les messages retirés.
     */
    public static function summaryFragment(?string $summary, int $dropped): string
    {
        if ($dropped <= 0) {
            return '';
        }
        $summary = trim((string)$summary);
        if ($summary !== '') {
            return "\n\nRésumé du début de cette conversation (ses " . $dropped . " premiers messages ne te sont "
                . "plus montrés en entier) :\n" . $summary
                . "\nAppuie-toi sur ce résumé ; si un détail précis y manque, dis-le plutôt que de l'inventer.";
        }
        return "\n\nNote : les " . $dropped . " premiers messages de cette conversation ne te sont plus transmis "
            . "(conversation longue). Si l'utilisateur y fait référence, demande-lui de préciser plutôt que d'inventer.";
    }

    /**
     * Transcription des messages d'id dans ]$afterId, $uptoId], pour le
     * résumé : un message borné à `$perMessage` caractères, les pièces jointes
     * nommées, les réponses en erreur écartées. Au-delà de `$maxChars`, le
     * début et la fin sont gardés (le milieu se résume le moins bien).
     */
    public static function transcript(array $history, int $afterId, int $uptoId, int $maxChars = 60000, int $perMessage = 1500): string
    {
        $lines = [];
        foreach ($history as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= $afterId || $id > $uptoId) {
                continue;
            }
            $text = trim((string)($r['content'] ?? ''));
            if (str_starts_with($text, '⚠')) {
                continue;
            }
            $text = (string)preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '[image]', $text);
            if (mb_strlen($text) > $perMessage) {
                $text = mb_substr($text, 0, $perMessage) . ' […]';
            }
            $names = array_map(static fn(array $f): string => $f['name'], array_filter(self::files($r), [self::class, 'isSentFile']));
            if ($names !== []) {
                $text .= ($text !== '' ? ' ' : '') . '[fichier(s) joint(s) : ' . implode(', ', $names) . ']';
            }
            if ($text === '') {
                continue;
            }
            $lines[] = '[' . (($r['role'] ?? '') === 'user' ? 'Utilisateur' : 'MIA') . '] ' . $text;
        }
        $out = implode("\n", $lines);
        if (mb_strlen($out) > $maxChars) {
            $half = intdiv($maxChars, 2);
            $out  = mb_substr($out, 0, $half) . "\n[… messages intermédiaires omis …]\n" . mb_substr($out, -$half);
        }
        return $out;
    }

    /**
     * Pièces jointes de TOUTE la conversation, numérotées dans l'ordre où
     * elles ont été jointes (1 = la première) — la liste que relit
     * `relire_fichier_conversation`. Seuls les fichiers réellement envoyés au
     * modèle comptent (pas les marqueurs de création).
     *
     * @return list<array{numero:int, nom:string, type:string, extracted:string, message_id:int}>
     */
    public static function conversationFiles(array $history): array
    {
        $out = [];
        foreach ($history as $r) {
            foreach (self::files($r) as $f) {
                if (!self::isSentFile($f)) {
                    continue;
                }
                $out[] = [
                    'numero'     => count($out) + 1,
                    'nom'        => $f['name'] !== '' ? $f['name'] : 'sans nom',
                    'type'       => $f['type'],
                    'extracted'  => $f['extracted'],
                    'message_id' => (int)($r['id'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /**
     * Fichier désigné par son numéro ou son nom : nom exact (casse ignorée),
     * puis partie unique du nom (accents ignorés). Le DERNIER de même nom
     * l'emporte — un fichier joint deux fois est le même document, dans sa
     * version la plus récente. Plusieurs noms différents → erreur qui les
     * liste : relire le mauvais fichier ferait répondre faux, avec aplomb.
     *
     * @throws \RuntimeException message FR
     */
    public static function pickFile(array $files, string $wanted): array
    {
        if ($files === []) {
            throw new \RuntimeException('Aucun fichier n\'a été joint dans cette conversation.');
        }
        $wanted = trim($wanted);
        if ($wanted === '') {
            throw new \RuntimeException('Indique le fichier à relire : son nom ou son numéro. ' . self::fileList($files));
        }
        if (preg_match('/^(?:n°|no|#)?\s*(\d{1,3})$/iu', $wanted, $m) === 1) {
            foreach ($files as $f) {
                if ((int)$f['numero'] === (int)$m[1]) {
                    return $f;
                }
            }
            throw new \RuntimeException('Aucun fichier n°' . (int)$m[1] . '. ' . self::fileList($files));
        }
        $exact = array_values(array_filter($files, static fn(array $f): bool => strcasecmp($f['nom'], $wanted) === 0));
        if ($exact !== []) {
            return $exact[count($exact) - 1];
        }
        $needle = self::fold($wanted);
        $hits   = array_values(array_filter(
            $files,
            static fn(array $f): bool => $needle !== '' && str_contains(self::fold($f['nom']), $needle)
        ));
        $names = array_values(array_unique(array_map(static fn(array $f): string => $f['nom'], $hits)));
        if (count($names) === 1) {
            return $hits[count($hits) - 1];
        }
        if (count($names) > 1) {
            throw new \RuntimeException('Plusieurs fichiers correspondent à « ' . $wanted . ' » : '
                . implode(', ', array_map(
                    static fn(array $f): string => $f['numero'] . '. ' . $f['nom'],
                    $hits
                )) . '. Précise le numéro.');
        }
        throw new \RuntimeException('Aucun fichier de cette conversation ne s\'appelle « ' . $wanted . ' ». ' . self::fileList($files));
    }

    /** « Fichiers joints : 1. a.pdf, 2. b.docx » (borné). */
    private static function fileList(array $files): string
    {
        $items = array_map(static fn(array $f): string => $f['numero'] . '. ' . $f['nom'], array_slice($files, 0, 20));
        return 'Fichiers joints dans cette conversation : ' . implode(', ', $items)
            . (count($files) > 20 ? ', …' : '') . '.';
    }

    /** Minuscules sans accents, pour comparer des noms de fichiers. */
    private static function fold(string $s): string
    {
        $s = mb_strtolower($s);
        $t = class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_D) : $s;
        return (string)preg_replace('/\p{Mn}+/u', '', is_string($t) ? $t : $s);
    }

    /** Index du N-ième message de l'utilisateur en partant de la fin (0 si moins de N). */
    private static function nthUserFromEnd(array $rows, int $nth): int
    {
        $seen = 0;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if (($rows[$i]['role'] ?? '') === 'user' && ++$seen >= $nth) {
                return $i;
            }
        }
        return 0;
    }

    /** Index de la première ligne d'id > $id, ou null. */
    private static function indexAfter(array $rows, int $id): ?int
    {
        foreach ($rows as $i => $r) {
            if ((int)($r['id'] ?? 0) > $id) {
                return $i;
            }
        }
        return null;
    }
}
