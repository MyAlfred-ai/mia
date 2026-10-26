<?php

declare(strict_types=1);

/**
 * Limitation des tentatives de connexion, par adresse IP et par compartiment.
 *
 * Le même bloc de code était recopié TROIS fois dans AuthController (mot de
 * passe global, e-mail/mot de passe, jeton natif), avec trois variantes de
 * gestion d'échec — et le retour OAuth, lui, n'en avait aucune : un callback
 * était rejouable sans limite. Une seule implémentation, un compartiment par
 * chemin d'entrée.
 *
 * Le nom de fichier est INCHANGÉ pour les trois compartiments historiques : un
 * suffixe différent aurait remis à zéro les compteurs en cours au moment du
 * déploiement, libérant une adresse en train d'être bloquée.
 */
final class LoginRateLimit
{
    public const MAX_TENTATIVES = 5;
    public const FENETRE_SEC    = 900;

    /** Mot de passe global (accès de secours) — compartiment historique sans suffixe. */
    public const ADMIN_LOCAL = '';
    /** Connexion e-mail + mot de passe. */
    public const EMAIL = '_email';
    /** Jeton Google natif de l'app mobile. */
    public const NATIF = '_gtoken';
    /** Retours OAuth (SSO). */
    public const SSO = '_sso';

    public static function depasse(string $compartiment, string $ip): bool
    {
        $etat = self::etatCourant(self::fichier($compartiment, $ip), time());
        return $etat['count'] >= self::MAX_TENTATIVES;
    }

    /** Enregistre un échec et renvoie le nombre de tentatives dans la fenêtre. */
    public static function echec(string $compartiment, string $ip): int
    {
        $fichier = self::fichier($compartiment, $ip);
        $etat    = self::etatCourant($fichier, time());
        $etat['count']++;

        $dossier = dirname($fichier);
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0700, true);
        }
        @file_put_contents($fichier, json_encode($etat));

        return (int)$etat['count'];
    }

    /** Connexion réussie : le compteur de cette adresse repart de zéro. */
    public static function reussite(string $compartiment, string $ip): void
    {
        @unlink(self::fichier($compartiment, $ip));
    }

    /**
     * Décision pure, isolée de tout accès disque (testable).
     *
     * Un état absent, corrompu, ou dont la fenêtre est écoulée repart d'une
     * fenêtre neuve. Sans le contrôle de forme, un fichier tronqué renvoyait
     * un `count` non entier et la comparaison devenait imprévisible.
     *
     * @param mixed $etat contenu décodé du fichier, quelle que soit sa forme
     * @return array{count:int, since:int}
     */
    public static function etatApres(mixed $etat, int $maintenant): array
    {
        if (!is_array($etat) || !isset($etat['count'], $etat['since'])
            || !is_numeric($etat['count']) || !is_numeric($etat['since'])) {
            return ['count' => 0, 'since' => $maintenant];
        }
        if (($maintenant - (int)$etat['since']) > self::FENETRE_SEC) {
            return ['count' => 0, 'since' => $maintenant];
        }
        return ['count' => (int)$etat['count'], 'since' => (int)$etat['since']];
    }

    /** @return array{count:int, since:int} */
    private static function etatCourant(string $fichier, int $maintenant): array
    {
        $brut = is_file($fichier) ? @file_get_contents($fichier) : false;
        $etat = is_string($brut) ? json_decode($brut, true) : null;
        return self::etatApres($etat, $maintenant);
    }

    private static function fichier(string $compartiment, string $ip): string
    {
        return ROOT . '/storage/ratelimit/' . md5($ip . $compartiment) . '.json';
    }
}
