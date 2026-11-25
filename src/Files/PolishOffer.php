<?php

declare(strict_types=1);

/**
 * « Version soignée » proposée AVANT d'écrire le fichier — SOURCE UNIQUE.
 *
 * Le problème résolu : quand on demandait un PDF, un document Word ou une
 * présentation, MIA rédigeait d'abord sa version rapide ; déçu de la mise en
 * page, on cliquait ensuite « Version soignée » sur la carte — le document
 * était donc produit DEUX fois. Désormais :
 *
 *   1. la personne demande un fichier (requestedKind, garde lexicale) : la
 *      réponse, au lieu d'écrire le fichier, lui propose le choix en boutons
 *      (offerFragment, bloc ```choix) — version rapide, ou version soignée
 *      par tel modèle, pour tant de crédits ;
 *   2. elle touche « Version soignée » (ou l'écrit d'emblée : « fais-moi un
 *      PDF en version soignée ») : choiceIn le lit, le modèle écrit le
 *      contenu du fichier (chosenFragment) et, sa réponse terminée, MIA confie
 *      aussitôt ce contenu au modèle choisi (ChatController::runSend →
 *      launchNativeDeck) — exactement le chemin du bouton de la carte, sans
 *      fenêtre : la personne a déjà choisi, le coût lui a été annoncé.
 *
 * La version rapide reste toujours là : la carte du fichier se télécharge
 * comme avant, et son bouton « Version soignée » aussi.
 *
 * Formes PURES : la garde lexicale ne décide que d'AJOUTER une consigne — la
 * consigne elle-même dit au modèle de l'ignorer si la personne parle d'un
 * fichier qu'elle a joint plutôt que d'en demander un.
 */
final class PolishOffer
{
    public const POLISH = 'polish';
    public const QUICK  = 'quick';

    /** Messages de l'assistant relus pour savoir si l'offre a déjà été faite. */
    private const HISTORY_SCAN = 12;

    /** Genres proposés avant écriture : ceux dont la mise en page compte. */
    public const OFFERED_KINDS = [NativeDeck::KIND_PDF, NativeDeck::KIND_DOCX, NativeDeck::KIND_PPTX];

    /**
     * Genre de fichier que la personne demande de CRÉER, ou null (pure).
     * Il faut une intention (verbe de création ou souhait) ET un format nommé
     * comme tel (« en PDF », « un document Word », « un PowerPoint ») :
     * « résume ce PDF » ou « lis le document Word joint » ne déclenchent rien.
     */
    public static function requestedKind(string $message): ?string
    {
        $t = UserMemory::normalizeForCompare($message);
        if ($t === '' || preg_match('/\b(?:' . self::INTENT . ')\b/u', $t) !== 1) {
            return null;
        }
        if (preg_match('/\b(?:powerpoint|pptx)\b/u', $t) === 1) {
            return NativeDeck::KIND_PPTX;
        }
        $cue = '(?:en|au format|format|sous forme d|version|fichier|document|doc|un|une|in|as|a|an|' . self::DOC_NOUNS . ')';
        if (preg_match('/\b' . $cue . ' (?:word|docx)\b|\b(?:word|docx) (?:file|document|doc)\b/u', $t) === 1) {
            return NativeDeck::KIND_DOCX;
        }
        if (preg_match('/\b' . $cue . ' pdf\b|\bpdf (?:file|document|report)\b/u', $t) === 1) {
            return NativeDeck::KIND_PDF;
        }

        return null;
    }

    /** Verbes de création et souhaits, sur un texte sans accents (normalizeForCompare). */
    private const INTENT = 'fai[st]?|faire|faites|cree[sz]?|creer|creez|genere[sz]?|generer|redige[sz]?|rediger|prepare[sz]?|preparer'
        . '|produi[st]|produire|ecri[st]|ecrire|ecrivez|exporte[sz]?|exporter|converti[st]?|convertir|transforme[sz]?|transformer'
        . '|met[sz]?|mettre|mettez|donne[sz]?|donner|sors|sortir|elabore[sz]?|elaborer|realise[sz]?|realiser|construi[st]|construire'
        . '|compose[sz]?|composer|je veux|je voudrais|j aimerais|il me faut|besoin d|peux tu|pourrais tu|pouvez vous'
        . '|create|make|generate|write|draft|prepare|produce|export|convert|turn|build|put|give|i want|i need|i would like|can you|could you';

    /** Noms de documents qui annoncent un format (« un rapport PDF »). */
    private const DOC_NOUNS = 'rapport|compte rendu|note|memo|courrier|lettre|contrat|devis|proposition|guide|fiche|cv|synthese|offre'
        . '|report|letter|proposal|summary|brief|resume|quote|handout';

    /**
     * Version choisie dans le message, ou null (pure) : « Version soignée »
     * (le libellé du bouton, ou écrit d'emblée) ; « Version rapide ». Les deux
     * à la fois ne disent rien.
     */
    public static function choiceIn(string $message): ?string
    {
        $t      = UserMemory::normalizeForCompare($message);
        $polish = preg_match('/\b(?:version soignee|polished version)\b/u', $t) === 1;
        $quick  = preg_match('/\b(?:version rapide|quick version)\b/u', $t) === 1;

        return $polish === $quick ? null : ($polish ? self::POLISH : self::QUICK);
    }

    /**
     * L'offre a-t-elle déjà sa place dans la conversation ? Oui si une réponse
     * récente l'a faite, ou a déjà livré un fichier : on ne redemande pas à
     * chaque retouche — la carte porte son bouton « Version soignée » (pure).
     *
     * @param list<array<string, mixed>> $history lignes de `messages`
     */
    public static function alreadyHandled(array $history): bool
    {
        $seen = 0;
        for ($i = count($history) - 1; $i >= 0 && $seen < self::HISTORY_SCAN; $i--) {
            if (($history[$i]['role'] ?? '') !== 'assistant') {
                continue;
            }
            $seen++;
            $content = (string)($history[$i]['content'] ?? '');
            if (self::firstFile($content) !== null) {
                return true;
            }
            $t = UserMemory::normalizeForCompare($content);
            if (preg_match('/\b(?:version soignee|polished version)\b/u', $t) === 1
                && preg_match('/\b(?:version rapide|quick version)\b/u', $t) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Premier fichier livré par une réponse — bloc de code dont la ligne
     * d'ouverture porte un nom (convention FileDelivery) —, ou null (pure).
     * Fermé par la première ligne de délimiteur seul, au moins aussi long :
     * un document qui contient du code se délimite par quatre accents graves.
     *
     * @return array{name: string, language: string, source: string}|null
     */
    public static function firstFile(string $reply): ?array
    {
        $attrs = implode('|', array_map(static fn (string $a): string => preg_quote($a, '/'), FileDelivery::ATTRIBUTES));
        $lines = explode("\n", str_replace("\r\n", "\n", $reply));
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})\s*([A-Za-z0-9_+.-]*)[^\n]*?\b(?:' . $attrs . ')\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|(\S+))/u', $line, $m) !== 1) {
                continue;
            }
            $fence = $m[1];
            $name  = trim($m[3] !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : (string)($m[5] ?? '')));
            $body  = [];
            $closed = false;
            for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
                $trim = trim($lines[$j]);
                if ($trim !== '' && $trim[0] === $fence[0] && strlen($trim) >= strlen($fence) && trim($trim, $fence[0]) === '') {
                    $closed = true;
                    break;
                }
                $body[] = $lines[$j];
            }
            if (!$closed || $name === '') {
                return null;
            }

            return ['name' => $name, 'language' => strtolower(trim($m[2], '.')), 'source' => trim(implode("\n", $body), "\n")];
        }

        return null;
    }

    /** Nom du fichier demandé, tel qu'il se dit (« un document PDF »). */
    private static function kindPhrase(string $kind): string
    {
        return match ($kind) {
            NativeDeck::KIND_DOCX => t('un document Word'),
            NativeDeck::KIND_PPTX => t('une présentation PowerPoint'),
            default               => t('un document PDF'),
        };
    }

    /**
     * Consigne qui fait proposer le choix AVANT d'écrire le fichier. Les
     * libellés des boutons sont ceux que choiceIn reconnaît : la consigne
     * demande de recopier le bloc tel quel.
     */
    public static function offerFragment(string $kind, string $model, ?int $credits): string
    {
        $cost = $credits !== null && $credits > 0
            ? t(', ≈ {n} crédits', ['n' => Format::credits($credits)])
            : '';

        return "\n\n" . t('Version soignée d’un fichier — consigne de cette interface :')
            . "\n" . t("L'utilisateur demande de créer {fichier}. Dans CE message, n'écris pas encore le fichier : propose-lui d'abord d'en choisir la version, en une phrase suivie de ce bloc, recopié tel quel :", ['fichier' => self::kindPhrase($kind)])
            . "\n" . t("```choix\nQuelle version voulez-vous ?\n- Version rapide — prête tout de suite, mise en page automatique de MIA\n- Version soignée — mise en page réalisée par {model}, une à trois minutes{cout}\n```", ['model' => $model, 'cout' => $cost])
            . "\n" . t("Ne pose aucune autre question dans ce message. Si l'utilisateur ne demande pas de créer un fichier (il parle d'un fichier qu'il a joint, par exemple), ou s'il a déjà dit quelle version il veut, ignore cette consigne.");
    }

    /** Consigne quand la version soignée est choisie : écrire le contenu, MIA s'occupe du reste. */
    public static function chosenFragment(string $model): string
    {
        return "\n\n" . t('Version soignée d’un fichier — consigne de cette interface :')
            . "\n" . t("L'utilisateur a choisi la version soignée de son fichier : écris maintenant son contenu COMPLET dans le bloc nommé, comme d'habitude (Markdown pour un document ou une présentation). Dès ta réponse terminée, MIA confie ce contenu à {model}, qui en réalise la mise en page ; le fichier arrivera dans la conversation dans une à trois minutes. Annonce-le en une phrase, sans dire que le fichier soigné est déjà prêt.", ['model' => $model]);
    }
}
