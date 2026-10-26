<?php

declare(strict_types=1);

/**
 * URL signées à durée limitée pour les médias générés (`/assets/img/generated/`,
 * `/assets/video/generated/`).
 *
 * Ces fichiers ne sont plus servis en statique par Apache (cf. public/.htaccess)
 * mais par MediaController, qui exige soit une session, soit une signature valide.
 * La signature est le SEUL mécanisme utilisable dans les contextes SANS cookie :
 * les aperçus rendus dans une iframe `blob:` sandboxée (pages HTML, présentations)
 * ont une origine opaque, aucun cookie de session n'accompagne donc leurs requêtes
 * d'images. Une URL signée est une capacité bornée dans le temps : fuitée (mail
 * transféré, proxy, historique), elle expire ; elle n'ouvre jamais un accès
 * permanent comme le faisait le fichier statique public.
 *
 * SOURCE UNIQUE de la signature. La clé est `APP_SECRET` (déjà partagée par
 * l'app et le worker via docker-compose) : aucune nouvelle variable à gérer.
 * Pur, sans état, sans base — testable directement.
 */
final class MediaUrl
{
    /**
     * Durée de vie d'une URL signée. Généreuse par choix : une signature est
     * RÉÉMISE à chaque rendu de la page (l'utilisateur recharge bien avant),
     * elle n'a donc pas à survivre longtemps — mais assez pour couvrir une
     * lecture vidéo ou une consultation prolongée d'un aperçu déjà ouvert.
     */
    public const TTL = 86400; // 24 h

    /** Chemins signables : uniquement les dossiers de médias générés. */
    private const MOTIF_CHEMIN = '#^/assets/(?:img|video)/generated/[A-Za-z0-9._-]+$#';

    /**
     * Détection/réécriture en masse dans un document HTML.
     * La query éventuelle est CAPTURÉE (groupe 1) et consommée : sans ça, la
     * regex re-matcherait le chemin nu d'une URL déjà signée (elle s'arrête au
     * `.png`, le `?` n'étant pas dans la classe) et `signHtmlAssets` doublerait
     * la query — la ré-signature ne serait pas idempotente.
     */
    private const MOTIF_ASSET = '#/assets/(?:img|video)/generated/[A-Za-z0-9._-]+(\?[^\s"\'<>]*)?#';

    /**
     * Signe un chemin relatif de média et renvoie l'URL signée
     * (`/assets/img/generated/x.png?e=<exp>&s=<hmac>`).
     * Renvoie le chemin INCHANGÉ si le secret manque, si le chemin n'est pas
     * signable, ou s'il porte déjà une query (déjà signé) — jamais d'exception.
     */
    public static function sign(string $path, ?int $ttl = null): string
    {
        if (APP_SECRET === '' || str_contains($path, '?')) {
            return $path;
        }
        if (preg_match(self::MOTIF_CHEMIN, $path) !== 1) {
            return $path;
        }

        $exp = time() + ($ttl ?? self::TTL);
        return $path . '?e=' . $exp . '&s=' . self::hmac($path, $exp);
    }

    /**
     * Vérifie une signature pour un chemin donné. Fail-closed : secret absent,
     * expiration non numérique/dépassée, ou HMAC qui ne correspond pas → false.
     * Comparaison en temps constant (`hash_equals`).
     */
    public static function verify(string $path, mixed $exp, mixed $sig): bool
    {
        if (APP_SECRET === '' || !is_string($sig) || $sig === '') {
            return false;
        }
        // `$exp` arrive de la query : une chaîne de chiffres, rien d'autre.
        if (!is_string($exp) && !is_int($exp)) {
            return false;
        }
        $exp = (string)$exp;
        if (!ctype_digit($exp)) {
            return false;
        }
        if ((int)$exp < time()) {
            return false;
        }

        return hash_equals(self::hmac($path, (int)$exp), $sig);
    }

    /**
     * Réécrit tous les chemins de médias générés d'un document HTML en URL
     * signées. Utilisé sur le HTML injecté dans les iframes `blob:` (aperçu de
     * page, présentation) et sur la page de partage, où le cookie de session
     * n'accompagne pas les requêtes d'images.
     *
     * Blanket sur le chemin (comme `absolutizeGeneratedAssets` côté JS) : un
     * chemin déjà suivi d'une query est laissé tel quel par `sign()`. Signer un
     * chemin qui apparaîtrait hors attribut est sans danger — il pointe le même
     * fichier.
     */
    public static function signHtmlAssets(string $html): string
    {
        if ($html === '' || APP_SECRET === '') {
            return $html;
        }
        return (string)preg_replace_callback(
            self::MOTIF_ASSET,
            static function (array $m): string {
                // Déjà porteur d'une query (donc déjà signé) → inchangé.
                if (($m[1] ?? '') !== '') {
                    return $m[0];
                }
                return self::sign($m[0]);
            },
            $html
        );
    }

    /**
     * Retire la query de signature (`?e=&s=`) des médias générés d'un document,
     * et l'origine absolue éventuelle → chemins relatifs canoniques. Garantie
     * SERVEUR à la persistance (updateMessageHtml) : même si un client renvoie
     * un HTML porteur d'URL signées (aperçu blob:), le contenu stocké ne fige
     * jamais une signature expirante. Le `&` sérialisé en `&amp;` par le DOM de
     * l'éditeur est toléré.
     */
    public static function stripSignedAssets(string $html): string
    {
        return (string)preg_replace(
            '#(?:https?://[^/"\'\s]+)?(/assets/(?:img|video)/generated/[A-Za-z0-9._-]+)\?e=\d+&(?:amp;)?s=[a-f0-9]+#i',
            '$1',
            $html
        );
    }

    private static function hmac(string $path, int $exp): string
    {
        return hash_hmac('sha256', $path . '|' . $exp, APP_SECRET);
    }
}
