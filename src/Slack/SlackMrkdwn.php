<?php

declare(strict_types=1);

/**
 * Traduction entre le Markdown et le « mrkdwn » de Slack — SOURCE UNIQUE des
 * DEUX sens, et pure (aucun appel réseau, aucune base).
 *
 * ── POURQUOI CE FICHIER EXISTE ─────────────────────────────────────────────
 * Slack n'écrit pas du Markdown. Il en reprend l'idée avec une syntaxe qui lui
 * est propre, et les différences tombent exactement là où ça se voit :
 *
 *   |                | Markdown          | Slack (mrkdwn)        |
 *   | gras           | `**texte**`       | `*texte*`             |
 *   | italique       | `*texte*`         | `_texte_`             |
 *   | barré          | `~~texte~~`       | `~texte~`             |
 *   | lien           | `[label](url)`    | `<url|label>`         |
 *   | titre          | `## Titre`        | (n'existe pas)        |
 *
 * Conséquence dans les deux sens, et les deux ont été constatées :
 *   - EN ÉCRITURE, le modèle écrit spontanément du Markdown. Publié tel quel,
 *     `**Compte rendu**` s'affiche avec ses astérisques et `[le doc](http://…)`
 *     en toutes lettres — même symptôme que les brouillons d'email avant
 *     `MailHtml`, même remède : convertir CÔTÉ SERVEUR, jamais demander au
 *     modèle de connaître la syntaxe d'un fournisseur.
 *   - EN LECTURE, un message Slack arrive plein de `<@U04F3KJ1>`,
 *     `<#C02AB|général>` et `<https://…|le doc>`. Donnés bruts au modèle, il
 *     les recopie dans sa réponse : l'utilisateur lit des identifiants au lieu
 *     de noms.
 *
 * ── LE PIÈGE D'ORDRE, PAYÉ UNE FOIS ────────────────────────────────────────
 * Slack exige que `&`, `<` et `>` soient échappés dans le texte publié. Cet
 * échappement doit venir AVANT la fabrication des liens (sinon nos propres
 * `<url|label>` seraient échappés et s'afficheraient en clair) et APRÈS la
 * mise de côté des citations `>` en début de ligne (sinon `&gt; ` remplacerait
 * la citation par son entité, visible à l'écran). C'est le même raisonnement
 * que `MailHtml` : on échappe d'abord le texte de l'utilisateur, on ajoute nos
 * balises ensuite.
 */
final class SlackMrkdwn
{
    /** Marqueurs de travail — hors du plan Unicode utilisable dans un message. */
    private const JALON_CODE  = "\x01C%d\x02";
    private const JALON_GRAS  = "\x01G\x02";
    private const JALON_CITE  = "\x01Q\x02";

    /** Limite Slack d'un message (`chat.postMessage`). */
    public const MAX_MESSAGE_LEN = 39000;

    // ===================== Markdown → mrkdwn (écriture) =====================

    /**
     * Markdown (ce que le modèle écrit) → mrkdwn (ce que Slack affiche).
     *
     * Les blocs de code sont mis de côté AVANT toute transformation : leur
     * contenu ne doit être ni échappé ni reformaté (un `**` dans un exemple de
     * code doit rester un `**`). Slack utilise la même syntaxe ``` et ` que
     * Markdown, ils reviennent donc tels quels.
     */
    public static function toMrkdwn(string $markdown): string
    {
        $texte = str_replace(["\r\n", "\r"], "\n", $markdown);

        // 1. Blocs et fragments de code hors du chemin.
        $codes = [];
        $texte = self::extraireCode($texte, $codes);

        // 2. Citations : le marqueur `>` est le MÊME chez Slack, mais
        //    l'échappement de l'étape 3 le transformerait en `&gt;`.
        $texte = (string)preg_replace('/^[ \t]{0,3}>[ \t]?/m', self::JALON_CITE, $texte);

        // 3. Échappement exigé par Slack, sur le texte de l'utilisateur SEUL.
        $texte = strtr($texte, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);

        // 4. Titres : Slack n'en a pas. Le gras est la seule hiérarchie
        //    disponible — le retirer purement et simplement ferait disparaître
        //    la structure d'un compte rendu.
        $texte = (string)preg_replace('/^[ \t]{0,3}#{1,6}[ \t]+(.+?)[ \t]*$/mu', self::JALON_GRAS . '$1' . self::JALON_GRAS, $texte);

        // 5. Listes AVANT l'italique : une puce `* ` serait sinon lue comme une
        //    ouverture d'emphase et mangerait la moitié du paragraphe.
        $texte = (string)preg_replace('/^([ \t]*)[-*+][ \t]+/mu', '$1• ', $texte);

        // 6. Gras d'abord (`**` et `__`), mis en jalon pour que l'italique ne
        //    reconnaisse pas ses astérisques.
        $texte = (string)preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*/su', self::JALON_GRAS . '$1' . self::JALON_GRAS, $texte);
        $texte = (string)preg_replace('/__(?!\s)(.+?)(?<!\s)__/su', self::JALON_GRAS . '$1' . self::JALON_GRAS, $texte);

        // 7. Italique : `*x*` devient `_x_`, `_x_` l'est déjà.
        $texte = (string)preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '_$1_', $texte);

        // 8. Barré : `~~x~~` → `~x~`.
        $texte = (string)preg_replace('/~~(?!\s)(.+?)(?<!\s)~~/su', '~$1~', $texte);

        // 9. Liens Markdown → liens Slack. Après l'échappement : les chevrons
        //    posés ici sont les NÔTRES, ils doivent rester des chevrons.
        $texte = (string)preg_replace_callback(
            '/\[([^\]\n]*)\]\(\s*(https?:\/\/[^\s)]+|mailto:[^\s)]+)\s*\)/u',
            static function (array $m): string {
                $url = trim($m[2]);
                // Le `|` sépare l'URL du libellé : le laisser dans le libellé
                // couperait le lien en deux au milieu d'un mot.
                $label = trim(str_replace('|', '/', $m[1]));
                return $label === '' ? '<' . $url . '>' : '<' . $url . '|' . $label . '>';
            },
            $texte
        );

        // 10. Restitution des jalons, puis du code.
        $texte = str_replace([self::JALON_GRAS, self::JALON_CITE], ['*', '> '], $texte);
        $texte = self::restituerCode($texte, $codes);

        return trim($texte);
    }

    /**
     * Met les blocs ``` et les fragments ` de côté. Les blocs d'abord : un
     * backtick isolé à l'intérieur d'un bloc ne doit pas ouvrir un fragment.
     *
     * @param list<string> $codes
     */
    private static function extraireCode(string $texte, array &$codes): string
    {
        foreach (['/```.*?```/su', '/`[^`\n]+`/u'] as $motif) {
            $texte = (string)preg_replace_callback($motif, static function (array $m) use (&$codes): string {
                $codes[] = $m[0];
                return sprintf(self::JALON_CODE, count($codes) - 1);
            }, $texte);
        }
        return $texte;
    }

    /** @param list<string> $codes */
    private static function restituerCode(string $texte, array $codes): string
    {
        foreach ($codes as $i => $code) {
            $texte = str_replace(sprintf(self::JALON_CODE, $i), $code, $texte);
        }
        return $texte;
    }

    // ===================== mrkdwn → texte lisible (lecture) =====================

    /**
     * mrkdwn (ce que Slack stocke) → texte lisible par un LLM et par un humain.
     *
     * $noms : identifiant Slack → nom affiché (`U04F3KJ1` => `Alice Martin`),
     * résolu par le service. Un identifiant absent de la table est rendu tel
     * quel : mieux vaut `@U04F3KJ1` qu'un nom inventé.
     *
     * @param array<string,string> $noms
     */
    public static function toText(string $mrkdwn, array $noms = []): string
    {
        $texte = $mrkdwn;

        // Mentions globales — `<!here|here>` et `<!here>` existent tous deux.
        $texte = (string)preg_replace('/<!(here|channel|everyone)(\|[^>]*)?>/u', '@$1', $texte);

        // Groupes d'utilisateurs : le libellé est déjà préfixé d'un @.
        $texte = (string)preg_replace_callback(
            '/<!subteam\^[A-Z0-9]+(?:\|([^>]*))?>/u',
            static fn(array $m): string => trim($m[1] ?? '') !== '' ? trim($m[1]) : '@groupe',
            $texte
        );

        // Dates Slack : `<!date^1720000000^{date_short}|15 juillet>` — le
        // repli après le `|` est précisément ce qui est écrit pour les clients
        // qui ne savent pas les rendre. Nous en sommes un.
        $texte = (string)preg_replace_callback(
            '/<!date\^\d+\^[^>|]*(?:\|([^>]*))?>/u',
            static fn(array $m): string => trim($m[1] ?? ''),
            $texte
        );

        // Personnes.
        $texte = (string)preg_replace_callback(
            '/<@([UWB][A-Z0-9]+)(?:\|([^>]*))?>/u',
            static function (array $m) use ($noms): string {
                $libelle = trim($m[2] ?? '');
                if ($libelle === '') {
                    $libelle = (string)($noms[$m[1]] ?? $m[1]);
                }
                return '@' . $libelle;
            },
            $texte
        );

        // Canaux.
        $texte = (string)preg_replace_callback(
            '/<#([CGD][A-Z0-9]+)(?:\|([^>]*))?>/u',
            static function (array $m) use ($noms): string {
                $libelle = trim($m[2] ?? '');
                if ($libelle === '') {
                    $libelle = (string)($noms[$m[1]] ?? $m[1]);
                }
                return '#' . $libelle;
            },
            $texte
        );

        // Adresses.
        $texte = (string)preg_replace_callback(
            '/<mailto:([^>|]+)(?:\|([^>]*))?>/u',
            static fn(array $m): string => trim($m[2] ?? '') !== '' ? trim($m[2]) : $m[1],
            $texte
        );

        // Liens : « libellé (url) » garde les deux informations — le modèle a
        // besoin de l'URL pour la citer, l'utilisateur du libellé pour lire.
        $texte = (string)preg_replace_callback(
            '/<((?:https?|slack):\/\/[^>|]+)(?:\|([^>]*))?>/u',
            static function (array $m): string {
                $label = trim($m[2] ?? '');
                return $label === '' || $label === $m[1] ? $m[1] : $label . ' (' . $m[1] . ')';
            },
            $texte
        );

        // Déséchappement EN DERNIER : fait plus tôt, un `&lt;` redevenu `<`
        // aurait été pris pour l'ouverture d'un lien par les règles ci-dessus.
        $texte = strtr($texte, ['&lt;' => '<', '&gt;' => '>', '&amp;' => '&']);

        return trim($texte);
    }

    /**
     * Identifiants de personnes et de canaux cités SANS libellé dans un texte :
     * ce sont les seuls qu'il faut résoudre par un appel à Slack. Ceux qui
     * portent déjà leur libellé (`<@U04F|alice>`) n'en ont pas besoin — et les
     * demander ferait un aller-retour réseau par message lu, pour rien.
     *
     * @return list<string>
     */
    public static function unresolvedIds(string $mrkdwn): array
    {
        $ids = [];
        foreach (['/<@([UWB][A-Z0-9]+)>/u', '/<#([CGD][A-Z0-9]+)>/u'] as $motif) {
            if (preg_match_all($motif, $mrkdwn, $m) > 0) {
                $ids = array_merge($ids, $m[1]);
            }
        }
        return array_values(array_unique($ids));
    }
}
