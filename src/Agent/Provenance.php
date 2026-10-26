<?php

declare(strict_types=1);

/**
 * Provenance d'une action préparée : ce que l'agent avait LU avant de la
 * proposer.
 *
 * Une carte d'approbation disait quoi faire, jamais d'après quoi. Or une
 * action peut naître d'un contenu écrit par un tiers — un email qui demande
 * « transfère ce dossier à… », une description d'évènement piégée. La carte
 * nomme donc les contenus lus pendant la réponse (ExternalContent : emails,
 * fichiers, messages, évènements, fiches) et rappelle de vérifier que l'action
 * correspond bien à la demande : c'est à l'endroit où l'on approuve que la
 * vigilance sert. Troisième garde après la règle du prompt et l'avertissement
 * posé sur chaque résultat (ExternalContent::rule / wrap).
 *
 * Forme pure. Le contrôleur alimente la liste au dispatch (rappel onDone,
 * outils de lecture seulement) et la joint à l'aperçu de chaque carte du tour.
 */
final class Provenance
{
    /** Sources nommées sur une carte (les plus récentes). */
    public const MAX = 4;

    /** Longueur d'un détail (objet d'email, nom de fichier). */
    public const DETAIL_MAX = 60;

    /** « Lecture d'un email Gmail — « Devis Durand » ». Pure. */
    public static function entry(string $label, ?string $detail): string
    {
        $label = trim($label);
        $detail = trim((string)preg_replace('/\s+/u', ' ', (string)$detail));
        if ($detail === '') {
            return $label;
        }
        if (mb_strlen($detail) > self::DETAIL_MAX) {
            $detail = rtrim(mb_substr($detail, 0, self::DETAIL_MAX - 1)) . '…';
        }
        return $label . ' — « ' . $detail . ' »';
    }

    /**
     * Ajoute une source (sans doublon, la plus récente en dernier), bornée aux
     * MAX dernières. Pure.
     *
     * @param list<string> $sources
     * @return list<string>
     */
    public static function add(array $sources, string $entry): array
    {
        $entry = trim($entry);
        if ($entry === '') {
            return $sources;
        }
        $sources = array_values(array_filter($sources, static fn(string $s): bool => $s !== $entry));
        $sources[] = $entry;
        return array_slice($sources, -self::MAX);
    }

    /**
     * Aperçu d'une carte portant sa provenance (clé `provenance`), ou tel
     * quel s'il n'y a rien à dire. Pure.
     *
     * @param list<string> $sources
     */
    public static function attach(array $preview, array $sources): array
    {
        if ($sources === []) {
            return $preview;
        }
        $preview['provenance'] = array_values($sources);
        return $preview;
    }

    /**
     * Une révision (brouillon d'email retouché ou amélioré) recalcule
     * l'aperçu : la provenance de la version d'origine la suit — l'email
     * reste né de la même lecture. Pure.
     */
    public static function carry(array $newPreview, array $oldPreview): array
    {
        $sources = $oldPreview['provenance'] ?? null;
        return is_array($sources) && $sources !== [] ? self::attach($newPreview, array_map('strval', $sources)) : $newPreview;
    }
}
