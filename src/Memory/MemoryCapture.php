<?php

declare(strict_types=1);

/**
 * Demandes EXPLICITES faites à la mémoire depuis le chat : « souviens-toi
 * que… », « corrige ta mémoire : … », « oublie que… » — en français et en
 * anglais.
 *
 * Formes pures (porte lexicale, consignes du juge, lecture de sa réponse),
 * testables sans base ni modèle. L'appel au juge et la facturation restent
 * dans ChatController::captureUserMemory (contrat UsageMeterContractTest),
 * l'écriture dans UserMemory::applyExplicit.
 *
 * La porte est LARGE pour un ajout (comme avant) mais exige un OBJET mémoire
 * pour un oubli ou une correction : « oublie la version précédente » ou
 * « corrige ce paragraphe » visent le texte en cours, pas la mémoire, et ne
 * doivent pas coûter un appel au modèle avant chaque réponse. En anglais,
 * « remember to call him » est un rappel, pas une mémorisation (même cas que
 * « rappelle-moi demain »).
 */
final class MemoryCapture
{
    /** Opérations au plus par message. */
    public const MAX_OPS = 3;

    /** Oublis au plus par message : « tout oublier » passe par la page. */
    public const MAX_FORGETS = 3;

    /** Souvenirs présentés au juge au plus, et leur longueur. */
    public const LIST_MAX   = 70;
    public const ITEM_CHARS = 160;

    private const PATTERNS = [
        // Retenir (français) — la porte historique.
        '/\bsouvien(?:s|ne|nes|nent|drai|dras)\b/iu',
        '/\b(?:se|te|t\')\s*souvenir\b/iu',
        '/\brappell?e[- ]?toi\b/iu',
        '/\b(?:te|t\')\s*rappeler\b/iu',
        '/\breti(?:ens|ent|enne|ennes)\b/iu',
        '/\bretenir\b/iu',
        '/\bm[ée]moris\w*/iu',
        '/\bgarde[- ]?(?:le|la|les)?\s*(?:bien\s+)?en\s+(?:t[êe]te|m[ée]moire)\b/iu',
        '/\ben\s+m[ée]moire\b/iu',
        '/\bn[\'’]?oublie\s+pas\s+que\b/iu',
        '/\bprends?\s+note\b/iu',
        // Oublier, corriger (français) : un objet mémoire est exigé.
        '/\boubli(?:e|ez)\s+(?:que|qu[\'’])/iu',
        '/\bne\s+(?:retiens|retenez|m[ée]morise[sz]?)\s+plus\b/iu',
        '/\b(?:ta|votre)\s+m[ée]moire\b/iu',
        // English.
        '/\bremember\s+(?:that|this|me\b|my\b|i\b|i[\'’]m\b)/iu',
        '/\bkeep\s+(?:it\s+|this\s+|that\s+)?in\s+mind\b/iu',
        '/\bdon[\'’]?t\s+forget\s+(?:that|i\b|my\b)/iu',
        '/\bmemori[sz]e\b/iu',
        '/\bmake\s+a\s+note\s+(?:that|of)\b/iu',
        '/\bnote\s+that\s+i\b/iu',
        '/\bforget\s+(?:that|about\s+(?:me|my|the\s+fact))\b/iu',
        '/\byour\s+memory\b/iu',
    ];

    /**
     * Le message demande-t-il quelque chose à la mémoire ? Porte bon marché
     * qui évite un appel au juge sur la quasi-totalité des messages.
     */
    public static function looksLikeRequest(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 2000) {
            return false;
        }
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $t) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Portée lue dans une opération d'un juge (`portee`) : `['scope' => …]`
     * seulement quand elle est donnée et restreinte (emails, documents,
     * code) — absente, le souvenir vaut partout. Partagée par la capture
     * explicite et l'apprentissage.
     *
     * @return array{scope?: string}
     */
    public static function scopeOf(array $item): array
    {
        $raw = is_scalar($item['portee'] ?? null) ? (string)$item['portee'] : '';
        $scope = UserMemory::normalizeScope($raw);
        return $scope === UserMemory::SCOPE_DEFAULT ? [] : ['scope' => $scope];
    }

    /** Étiquette d'une portée restreinte dans la liste montrée aux juges (« [emails] »), rien partout. */
    public static function scopeTag(string $scope): string
    {
        return match (UserMemory::normalizeScope($scope)) {
            'email'    => '[emails] ',
            'document' => '[documents] ',
            'code'     => '[code] ',
            default    => '',
        };
    }

    /** Consigne de méthode du juge (en français : elle ne cite rien à l'écran). */
    public static function systemPrompt(): string
    {
        return "Tu gères la mémoire long terme d'un utilisateur dans un assistant conversationnel.\n"
            . "Analyse SON dernier message : demande-t-il EXPLICITEMENT de retenir, de corriger ou d'oublier "
            . "une information durable le concernant ?\n\n"
            . "Réponds UNIQUEMENT par un objet JSON :\n"
            . "{\"operations\": [ … ], \"tout_effacer\": false}\n\n"
            . "Opérations possibles (" . self::MAX_OPS . " au plus) :\n"
            . "- {\"action\": \"ajouter\", \"theme\": \"…\", \"texte\": \"…\"} : il demande de retenir "
            . "(« souviens-toi que… », « retiens que… », « remember that… »).\n"
            . "- {\"action\": \"modifier\", \"numero\": N, \"texte\": \"…\"} : il corrige un souvenir de la liste "
            . "(« corrige : je ne suis plus chez X »).\n"
            . "- {\"action\": \"oublier\", \"numero\": N} : il demande d'oublier un souvenir de la liste "
            . "(« oublie que… », « forget that… »).\n\n"
            . "`portee` (facultatif, sur « ajouter » et « modifier ») : \"emails\", \"documents\" ou \"code\" quand la "
            . "préférence ne vaut QUE pour ce genre de réponse (« pour mes emails, vouvoie toujours ») ; sinon ne la mets pas.\n\n"
            . "Thèmes : profil (métier, rôle, équipe, entreprise, secteur), reponses (longueur, présentation, "
            . "ton, langue des réponses), methode (façon de travailler : clarifier d'abord ou proposer, sources, "
            . "options), outils (outils et connecteurs utilisés, ce que l'assistant peut y faire), sujets "
            . "(projets et dossiers en cours), autre.\n\n"
            . "Règles :\n"
            . "- Aucune opération si le message ne le demande pas explicitement : un rappel (« rappelle-moi "
            . "demain », « remember to call him »), une question (« tu te souviens du devis ? »), une consigne "
            . "sur le texte en cours (« oublie la version précédente », « corrige ce paragraphe ») donnent "
            . "{\"operations\": [], \"tout_effacer\": false}.\n"
            . "- `texte` : une phrase COURTE (200 caractères au plus), autoportante, compréhensible hors de "
            . "cette conversation, déclarative et sans pronom initial (« Préfère les réunions du matin », "
            . "« Travaille au service marketing »), dans la langue de l'utilisateur.\n"
            . "- `numero` : le numéro d'un souvenir de la liste fournie, jamais un numéro absent de la liste. "
            . "Résous les références implicites (« ça », « ce point ») grâce à la conversation ; si rien ne "
            . "correspond, n'invente pas de numéro.\n"
            . "- « Oublie tout », « efface ta mémoire » : aucune opération et \"tout_effacer\": true.\n"
            . "- Aucune information sensible qu'il n'a pas explicitement confiée, aucun méta-commentaire, aucune "
            . "date ponctuelle.";
    }

    /**
     * Message du juge : souvenirs actuels numérotés (rang = numéro), extrait
     * de la conversation, dernier message de l'utilisateur.
     *
     * @param list<array> $memories
     */
    public static function userPrompt(string $digest, string $message, array $memories): string
    {
        $lines = [];
        foreach (array_slice($memories, 0, self::LIST_MAX) as $i => $row) {
            $text = UserMemory::sanitize((string)($row['content'] ?? ''), self::ITEM_CHARS);
            $lines[] = ($i + 1) . '. [' . UserMemory::normalizeTheme((string)($row['theme'] ?? '')) . '] '
                . self::scopeTag((string)($row['scope'] ?? '')) . $text;
        }

        return "Souvenirs actuels (numérotés) :\n" . ($lines === [] ? '(aucun)' : implode("\n", $lines)) . "\n\n"
            . ($digest !== '' ? "Conversation en cours :\n" . $digest . "\n\n" : '')
            . "Dernier message de l'utilisateur :\n" . mb_substr($message, 0, 1500)
            . "\n\nJSON :";
    }

    /**
     * Lit la réponse du juge, tolérante aux enveloppes markdown et au texte
     * autour du JSON ; l'ancienne forme {"memoriser", "faits"} est comprise.
     * Bornée : 3 opérations, 3 oublis, numéros présents dans la liste.
     *
     * @return array{ops: list<array{action:string, numero?:int, theme?:string, texte?:string}>, wipe: bool}|null
     *         null = réponse illisible
     */
    public static function parseOps(string $raw, int $count): ?array
    {
        $data = self::decodeJson($raw);
        if ($data === null) {
            return null;
        }

        $items = [];
        if (isset($data['operations']) && is_array($data['operations'])) {
            $items = $data['operations'];
        } elseif (isset($data['faits']) && is_array($data['faits'])
            && filter_var($data['memoriser'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            foreach ($data['faits'] as $fait) {
                $items[] = ['action' => 'ajouter', 'texte' => $fait];
            }
        }

        $ops     = [];
        $forgets = 0;
        $touched = [];
        foreach ($items as $item) {
            if (count($ops) >= self::MAX_OPS) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            $action = strtolower(trim((string)($item['action'] ?? '')));
            $text   = is_scalar($item['texte'] ?? null) ? UserMemory::sanitize((string)$item['texte']) : '';
            $numero = isset($item['numero']) && is_numeric($item['numero']) ? (int)$item['numero'] : 0;

            if ($action === 'ajouter') {
                if ($text === '') {
                    continue;
                }
                $ops[] = [
                    'action' => 'ajouter',
                    'theme'  => UserMemory::normalizeTheme(is_scalar($item['theme'] ?? null) ? (string)$item['theme'] : ''),
                    'texte'  => $text,
                ] + self::scopeOf($item);
                continue;
            }
            if ($numero < 1 || $numero > $count || isset($touched[$numero])) {
                continue; // numéro inventé, ou deux opérations sur le même souvenir
            }
            if ($action === 'modifier' && $text !== '') {
                $op = ['action' => 'modifier', 'numero' => $numero, 'texte' => $text];
                if (is_scalar($item['theme'] ?? null) && trim((string)$item['theme']) !== '') {
                    $op['theme'] = UserMemory::normalizeTheme((string)$item['theme']);
                }
                $ops[] = $op + self::scopeOf($item);
                $touched[$numero] = true;
            } elseif ($action === 'oublier' && $forgets < self::MAX_FORGETS) {
                $ops[] = ['action' => 'oublier', 'numero' => $numero];
                $touched[$numero] = true;
                $forgets++;
            }
        }

        return [
            'ops'  => $ops,
            'wipe' => filter_var($data['tout_effacer'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Rien à dire ni à montrer ? (aucun changement, aucun doublon, rien de
     * plein, de refusé, ni demande de tout effacer)
     *
     * @param array<string, mixed> $result
     */
    public static function isEmptyResult(array $result): bool
    {
        return ($result['faits'] ?? []) === []
            && ($result['modifies'] ?? []) === []
            && ($result['oublies'] ?? []) === []
            && (int)($result['doublons'] ?? 0) === 0
            && empty($result['plein'])
            && (int)($result['refuses'] ?? 0) === 0
            && empty($result['tout']);
    }

    /**
     * Détail de la ligne de trace « Mise à jour de votre mémoire ».
     *
     * @param array<string, mixed> $result
     */
    public static function traceDetail(array $result): string
    {
        $parts = array_map('strval', (array)($result['faits'] ?? []));
        foreach ((array)($result['modifies'] ?? []) as $text) {
            $parts[] = '✎ ' . (string)$text;
        }
        foreach ((array)($result['oublies'] ?? []) as $forgotten) {
            $parts[] = '− ' . (string)(is_array($forgotten) ? ($forgotten['content'] ?? '') : $forgotten);
        }
        return implode(' · ', $parts);
    }

    /** @return array<string, mixed>|null */
    private static function decodeJson(string $raw): ?array
    {
        $txt = trim($raw);
        if ($txt === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $txt, $m) === 1) {
            $txt = trim($m[1]);
        }
        $start = strpos($txt, '{');
        $end   = strrpos($txt, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($txt, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }
}
