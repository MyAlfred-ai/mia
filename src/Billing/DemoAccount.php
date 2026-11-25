<?php

declare(strict_types=1);

/**
 * Comptes de démonstration — SOURCE UNIQUE (migration 094).
 *
 * Le problème résolu : faire découvrir MIA à un prospect sans lui ouvrir un
 * quota MENSUEL, qui se renouvelle tous les 1er sans que personne ne l'ait
 * décidé. Un compte de démo reçoit une ENVELOPPE de crédits, utilisable une
 * seule fois : une fois épuisée, l'IA s'arrête (les conversations restent
 * lisibles) et un message invite à se rapprocher de l'éditeur.
 *
 * Décisions (2026-10-26) :
 *   - « Démo » est un ATTRIBUT du compte, pas un rôle : le rôle (utilisateur,
 *     créateur, rôle personnalisé) dit ce que la personne peut faire, la démo
 *     combien elle peut consommer. Jamais un administrateur (allowedForRole).
 *   - L'enveloppe REMPLACE le quota mensuel (individuel et de groupe) : deux
 *     plafonds qui se croisent seraient illisibles. Le budget IA de
 *     l'organisation reste le filet global (CreditQuota).
 *   - Ne compte que ce qui entame un quota (`quota_exempt = 0`), depuis
 *     `demo_started_at` : un compte existant passé en démo ne part pas entamé,
 *     et l'alimentation des bases d'un créateur n'est pas décomptée.
 *   - Aucune date de fin. Conversion en compte ordinaire d'un clic, historique
 *     conservé (seules les trois colonnes de démo sont remises à zéro).
 *   - Épuisée, une démo pose un point à régler sur « Personnes › Comptes »
 *     (AdminAttention::demoSignals), que l'administrateur peut masquer compte
 *     par compte (`demo_alert_muted`, remis à 0 quand l'enveloppe change).
 *
 * La barrière reste CreditQuota::status() : elle lit l'enveloppe ici et
 * l'applique à tous les points qui appliquaient déjà le quota.
 */
final class DemoAccount
{
    /** Enveloppe proposée à la création d'un compte de démo. */
    public const DEFAULT_CREDITS = 5_000;

    /** Borne de saisie (une faute de frappe ne doit pas ouvrir un puits sans fond). */
    public const MAX_CREDITS = 10_000_000;

    /** Objet du courriel proposé à la fin d'une démo. */
    private const MAIL_SUBJECT = 'Suite de ma démo de MIA';

    // ================================================================ Formes pures

    /** Le compte est-il un compte de démo ? */
    public static function isDemo(?array $user): bool
    {
        return $user !== null && isset($user['demo_credits']) && $user['demo_credits'] !== null && $user['demo_credits'] !== '';
    }

    /** Enveloppe du compte, en crédits (null = compte ordinaire). */
    public static function allowance(?array $user): ?int
    {
        return self::isDemo($user) ? max(0, (int)$user['demo_credits']) : null;
    }

    /**
     * Saisie d'une enveloppe : « 5000 », « 5 000 », « 5 000 » (espace
     * insécable), « 5,000 ». Null si ce n'est pas un entier positif dans les
     * bornes — le formulaire dit alors pourquoi, rien n'est enregistré.
     */
    public static function normalizeCredits(mixed $raw): ?int
    {
        if (is_int($raw)) {
            $n = $raw;
        } else {
            $s = preg_replace('/[\s\x{00A0}\x{202F},.\']+/u', '', (string)$raw) ?? '';
            if ($s === '' || preg_match('/^\d{1,9}$/', $s) !== 1) {
                return null;
            }
            $n = (int)$s;
        }
        return ($n >= 1 && $n <= self::MAX_CREDITS) ? $n : null;
    }

    /** Une démo ne se pose jamais sur un administrateur. */
    public static function allowedForRole(string $role): bool
    {
        return $role !== Role::ADMIN;
    }

    /** Épuisée : tout ce que l'enveloppe permettait a été consommé. */
    public static function isExhausted(int $allowance, int $used): bool
    {
        return $used >= $allowance;
    }

    /**
     * Adresse à qui écrire pour la suite : le contact de l'éditeur (Marque &
     * apparence → Liens & contact), seulement si c'est une adresse valide.
     */
    public static function contactEmail(): string
    {
        $email = trim((string)Settings::get('contact_email', ''));
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    /** Lien « écrire » avec un objet déjà rempli (vide sans adresse). */
    public static function mailtoHref(string $email): string
    {
        return $email === '' ? '' : 'mailto:' . $email . '?subject=' . rawurlencode(t(self::MAIL_SUBJECT));
    }

    /** Titre de la fin de démo (fenêtre, bandeau, page Usage). */
    public static function endedTitle(): string
    {
        return t('Votre démo de MIA est terminée');
    }

    /**
     * Message de fin de démo : remercier, dire ce qui reste possible, dire à
     * qui s'adresser. Sans adresse de contact, l'éditeur ; sans éditeur,
     * l'interlocuteur habituel.
     */
    public static function endedMessage(string $email, string $vendor = ''): string
    {
        $intro = t('Merci d’avoir pris le temps de découvrir MIA ! Les crédits de votre démonstration sont épuisés : vos conversations restent consultables, mais MIA ne peut plus répondre.');
        if ($email !== '') {
            return $intro . ' ' . t('Pour aller plus loin, écrivez-nous à {email} : nous serons ravis d’en parler avec vous.', ['email' => $email]);
        }
        $vendor = trim($vendor);
        return $intro . ' ' . ($vendor !== ''
            ? t('Pour aller plus loin, rapprochez-vous de l’équipe {vendeur} : elle sera ravie d’en parler avec vous.', ['vendeur' => $vendor])
            : t('Pour aller plus loin, rapprochez-vous de votre interlocuteur habituel.'));
    }

    /** Message de fin pour l'écran courant (adresse et éditeur lus dans les réglages). */
    public static function endedMessageForInstance(): string
    {
        return self::endedMessage(self::contactEmail(), (string)Settings::get('vendor_name', ''));
    }

    // ================================================================ Lectures

    /** Crédits consommés depuis le début de la démo (ce qui entame un quota seulement). */
    public static function usedCredits(array $user): int
    {
        if (!self::isDemo($user) || empty($user['id'])) {
            return 0;
        }
        $since = (string)($user['demo_started_at'] ?? '');
        if ($since === '') {
            $since = (string)($user['created_at'] ?? '1970-01-01 00:00:00');
        }
        return UsageLog::quotaCreditsSince((int)$user['id'], $since);
    }

    /**
     * Tous les comptes de démo avec leur consommation depuis LEUR début :
     * l'écran Comptes et les points à régler. Une requête, quel que soit le
     * nombre de comptes.
     *
     * @return array<int, array{id:int, name:string, email:string, is_active:bool, credits:int, used:int, remaining:int, exhausted:bool, muted:bool, started_at:string}>
     */
    public static function accounts(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT u.id, u.name, u.email, u.is_active, u.demo_credits, u.demo_alert_muted,
                    COALESCE(u.demo_started_at, u.created_at) AS started_at,
                    (SELECT COALESCE(SUM(l.credits_used), 0) FROM usage_logs l
                      WHERE l.user_id = u.id AND l.quota_exempt = 0
                        AND l.created_at >= COALESCE(u.demo_started_at, u.created_at))
                  + (SELECT COALESCE(SUM(t.credits_used), 0) FROM tool_usage_logs t
                      WHERE t.user_id = u.id AND t.quota_exempt = 0
                        AND t.created_at >= COALESCE(u.demo_started_at, u.created_at)) AS used
             FROM users u
             WHERE u.demo_credits IS NOT NULL
             ORDER BY u.name ASC, u.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $credits = max(0, (int)$r['demo_credits']);
            $used    = max(0, (int)$r['used']);
            $out[(int)$r['id']] = [
                'id'         => (int)$r['id'],
                'name'       => (string)$r['name'],
                'email'      => (string)$r['email'],
                'is_active'  => (int)$r['is_active'] === 1,
                'credits'    => $credits,
                'used'       => $used,
                'remaining'  => max(0, $credits - $used),
                'exhausted'  => self::isExhausted($credits, $used),
                'muted'      => (int)$r['demo_alert_muted'] === 1,
                'started_at' => (string)$r['started_at'],
            ];
        }
        return $out;
    }

    // ================================================================ Écritures

    /** Passe un compte en démo : enveloppe neuve, décompte à partir de maintenant. */
    public static function start(int $userId, int $credits): void
    {
        Database::getInstance()->prepare(
            'UPDATE users SET demo_credits = ?, demo_started_at = NOW(), demo_alert_muted = 0 WHERE id = ?'
        )->execute([$credits, $userId]);
    }

    /**
     * Nouvelle enveloppe pour une démo en cours (le décompte ne repart pas).
     * Une enveloppe qui change rallume l'alerte : une démo de nouveau épuisée
     * doit se voir, même si l'épuisement précédent avait été masqué.
     */
    public static function setCredits(int $userId, int $credits): void
    {
        // `demo_alert_muted` AVANT `demo_credits` : MySQL évalue les
        // affectations de gauche à droite, avec les valeurs déjà modifiées.
        Database::getInstance()->prepare(
            'UPDATE users SET demo_alert_muted = IF(demo_credits <=> ?, demo_alert_muted, 0), demo_credits = ?
             WHERE id = ? AND demo_credits IS NOT NULL'
        )->execute([$credits, $credits, $userId]);
    }

    /** Masque (ou rallume) la pastille « démo épuisée » de ce compte. */
    public static function setAlertMuted(int $userId, bool $muted): void
    {
        Database::getInstance()->prepare(
            'UPDATE users SET demo_alert_muted = ? WHERE id = ? AND demo_credits IS NOT NULL'
        )->execute([$muted ? 1 : 0, $userId]);
    }

    /**
     * Conversion en compte ordinaire : seules les colonnes de démo changent —
     * conversations, créations, assistants et bases restent. Le quota mensuel
     * (individuel, sinon des groupes) s'applique dès la requête suivante.
     */
    public static function convert(int $userId): void
    {
        Database::getInstance()->prepare(
            'UPDATE users SET demo_credits = NULL, demo_started_at = NULL, demo_alert_muted = 0 WHERE id = ?'
        )->execute([$userId]);
    }
}
