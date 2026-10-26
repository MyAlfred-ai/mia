<?php

declare(strict_types=1);

/**
 * Mémoire long terme d'un utilisateur — section « MIA pour vous ».
 *
 * Un souvenir = une phrase courte et autoportante décrivant durablement
 * l'utilisateur (rôle, préférences, contraintes). Alimentée depuis le chat
 * (« souviens-toi que… », cf. ChatController::captureUserMemory) ou saisie à
 * la main dans Paramètres. Le bloc est injecté en tête de CHAQUE conversation,
 * juste après les préférences IA ([[UserPreferences]]).
 *
 * Source unique de vérité : limites, normalisation, déduplication et fragment
 * de system prompt vivent ici — jamais dupliqués dans le contrôleur.
 */
final class UserMemory
{
    /** Nombre maximum de souvenirs par utilisateur. */
    public const MAX_ENTRIES = 50;

    /** Longueur maximale d'un souvenir (caractères). */
    public const MAX_LEN = 300;

    /** Budget d'injection dans le system prompt (caractères). */
    public const MAX_PROMPT_CHARS = 3000;

    public const SOURCE_CHAT   = 'chat';
    public const SOURCE_MANUAL = 'manuel';

    /** @return list<array> souvenirs du plus récent au plus ancien */
    public static function allForUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, content, source, created_at, updated_at
               FROM user_memories
              WHERE user_id = ?
              ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function countForUser(int $userId): int
    {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM user_memories WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Ajoute un souvenir. Ne lève jamais : le statut décrit l'issue afin que
     * l'appelant (chat ou UI) l'explique à l'utilisateur.
     *
     * @return array{status:'ok'|'empty'|'duplicate'|'full', content:string, id?:int}
     */
    public static function add(int $userId, string $raw, string $source = self::SOURCE_CHAT): array
    {
        $content = self::sanitize($raw);
        if ($content === '') {
            return ['status' => 'empty', 'content' => ''];
        }

        $existingId = self::findEquivalent($userId, $content);
        if ($existingId !== null) {
            return ['status' => 'duplicate', 'content' => $content, 'id' => $existingId];
        }

        if (self::countForUser($userId) >= self::MAX_ENTRIES) {
            return ['status' => 'full', 'content' => $content];
        }

        $db = Database::getInstance();
        $db->prepare('INSERT INTO user_memories (user_id, content, source) VALUES (?,?,?)')
           ->execute([$userId, $content, self::normalizeSource($source)]);

        return ['status' => 'ok', 'content' => $content, 'id' => (int)$db->lastInsertId()];
    }

    /**
     * Réécrit un souvenir existant (édition manuelle dans les paramètres).
     *
     * @return array{status:'ok'|'empty'|'duplicate'|'not_found', content:string}
     */
    public static function update(int $userId, int $id, string $raw): array
    {
        $content = self::sanitize($raw);
        if ($content === '') {
            return ['status' => 'empty', 'content' => ''];
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM user_memories WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 'not_found', 'content' => $content];
        }

        $twin = self::findEquivalent($userId, $content, $id);
        if ($twin !== null) {
            return ['status' => 'duplicate', 'content' => $content];
        }

        $db->prepare('UPDATE user_memories SET content = ? WHERE id = ? AND user_id = ?')
           ->execute([$content, $id, $userId]);

        return ['status' => 'ok', 'content' => $content];
    }

    public static function delete(int $userId, int $id): bool
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM user_memories WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** Fragment injecté dans le system prompt, ou '' si l'utilisateur n'a rien mémorisé. */
    public static function promptFragment(int $userId): string
    {
        return self::promptFragmentFromRows(self::allForUser($userId));
    }

    /**
     * Assemblage du fragment depuis des lignes déjà chargées.
     * Statique et pure → testable sans base.
     *
     * @param list<array> $rows
     */
    public static function promptFragmentFromRows(array $rows): string
    {
        $lines = [];
        $total = 0;
        foreach ($rows as $row) {
            $content = self::sanitize((string)($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $line   = '- ' . $content;
            $total += mb_strlen($line) + 1;
            if ($total > self::MAX_PROMPT_CHARS) {
                break; // budget épuisé : les souvenirs les plus récents priment
            }
            $lines[] = $line;
        }

        if ($lines === []) {
            return '';
        }

        // Traduit (lot 3) : « MIA pour vous » est le titre de la carte des
        // Préférences IA, et la garde renvoie vers cet écran. Le nom de la
        // section est une DÉCISION PRODUIT, pas une traduction libre : il doit
        // rester identique au libellé rendu par le gabarit.
        return "\n\n" . t("MIA pour vous — ce que l'utilisateur t'a demandé de retenir sur lui :") . "\n"
            . implode("\n", $lines) . "\n"
            . t("Tiens-en compte spontanément quand c'est pertinent, sans les énumérer ni les rappeler à chaque réponse — SAUF s'il demande ce que tu sais, retiens ou mémorises de lui : restitue alors la liste ci-dessus INTÉGRALEMENT et telle quelle, sous le titre « MIA pour vous », sans en omettre ni en inventer. Tu ne peux pas supprimer ni corriger ces informations toi-même : si l'utilisateur veut en retirer ou en modifier une, renvoie-le vers Réglages → Préférences IA → « MIA pour vous ».");
    }

    /**
     * Note injectée quand MIA ne sait RIEN de l'utilisateur : ni souvenir, ni
     * « Votre contexte ». Sans elle, « que sais-tu de moi ? » tombe sur un modèle
     * sans aucune consigne — qui invente, ou nie l'existence même de la mémoire.
     * Le coût en tokens est donc porté par les comptes vides seulement, ceux qui
     * ne paient déjà ni la liste ni le contexte.
     *
     * Statique et pure → testable sans base.
     */
    public static function emptyStateNote(bool $hasMemories, bool $hasContext): string
    {
        if ($hasMemories || $hasContext) {
            return '';
        }

        return "\n\n" . t("Ce que tu sais de l'utilisateur : rien pour l'instant — sa mémoire « MIA pour vous » est vide et « Votre contexte » n'est pas renseigné. S'il demande ce que tu sais ou retiens de lui, dis-le franchement, n'invente aucune information, et indique qu'il peut renseigner les deux dans Réglages → Préférences IA (ou te demander en conversation « souviens-toi que… »).");
    }

    /**
     * Le texte ressemble-t-il à une donnée qui n'a rien à faire dans une
     * mémoire réinjectée dans chaque conversation : mot de passe, code secret,
     * numéro de carte, IBAN, numéro de sécurité sociale ? Garde de l'outil
     * `memoriser` (le modèle peut proposer de retenir n'importe quoi) — un
     * faux positif coûte une phrase, un faux négatif expose un secret à chaque
     * message. Statique et pure.
     */
    public static function looksSensitive(string $text): bool
    {
        $t = mb_strtolower($text);
        if (preg_match('/\b(mot de passe|mots de passe|password|passwd|passphrase|code (pin|secret|d[\'’]acc[eè]s)|'
            . 'cvv|cryptogramme|identifiants? de connexion|cl[eé] (api|secr[eè]te|priv[eé]e)|'
            . 'num[eé]ro de s[eé]curit[eé] sociale|carte (bancaire|bleue|de cr[eé]dit))\b/u', $t) === 1) {
            return true;
        }
        // Numéro de carte (13 à 19 chiffres, espaces ou tirets tolérés).
        if (preg_match('/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', $text) === 1) {
            return true;
        }
        // IBAN (deux lettres, deux chiffres, puis 11 à 30 caractères).
        return preg_match('/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,7}(?:[ ]?[A-Z0-9]{1,4})?\b/', strtoupper($text)) === 1;
    }

    /** Normalise un souvenir : une ligne, sans caractères de contrôle, bornée. */
    public static function sanitize(string $raw): string
    {
        $clean = preg_replace('/[\p{C}]+/u', ' ', $raw) ?? $raw; // sauts de ligne + contrôles
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        $clean = trim($clean);
        if (mb_strlen($clean) > self::MAX_LEN) {
            $clean = rtrim(mb_substr($clean, 0, self::MAX_LEN - 1)) . '…';
        }
        return $clean;
    }

    /**
     * Forme canonique servant à la déduplication : minuscules, sans accents ni
     * ponctuation. « Je préfère les réunions du matin ! » et « je prefere les
     * reunions du matin » sont le même souvenir.
     *
     * Statique et pure → testable sans base.
     */
    public static function normalizeForCompare(string $content): string
    {
        $s = mb_strtolower(self::sanitize($content));
        $s = strtr($s, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae',
        ]);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** Id d'un souvenir équivalent (hors $excludeId), ou null. */
    private static function findEquivalent(int $userId, string $content, ?int $excludeId = null): ?int
    {
        $needle = self::normalizeForCompare($content);
        if ($needle === '') {
            return null;
        }
        foreach (self::allForUser($userId) as $row) {
            if ($excludeId !== null && (int)$row['id'] === $excludeId) {
                continue;
            }
            if (self::normalizeForCompare((string)$row['content']) === $needle) {
                return (int)$row['id'];
            }
        }
        return null;
    }

    private static function normalizeSource(string $source): string
    {
        return $source === self::SOURCE_MANUAL ? self::SOURCE_MANUAL : self::SOURCE_CHAT;
    }
}
