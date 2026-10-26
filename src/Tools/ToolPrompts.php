<?php

declare(strict_types=1);

/**
 * Prompts par défaut des outils à LLM — SOURCE UNIQUE.
 *
 * Quatre types d'outils passent par un LLM avec un prompt système : la page
 * HTML, le site illustré, la carte / itinéraire et la présentation. Chacun
 * arrive désormais avec un texte par défaut RÉDIGÉ, que l'administrateur voit
 * pré-rempli dans le formulaire et peut réécrire librement.
 *
 * Frontière volontaire — ce que ce fichier porte, et ce qu'il ne porte PAS :
 *  - ICI vit la partie ÉDITORIALE (rôle, exigences de qualité, ton, réalisme) :
 *    la modifier change le résultat, jamais sa lisibilité par l'application ;
 *  - le CONTRAT DE SORTIE (« réponds uniquement par du HTML », le format JSON
 *    exact d'une carte ou d'un deck) reste dans le code appelant et s'ajoute
 *    TOUJOURS après. Le laisser éditer permettrait de casser le rendu d'un
 *    coup de touche Retour, sans le moindre message d'erreur.
 *
 * Champ vidé côté admin = retour au défaut (cf. `resolve`) : c'est la seule
 * façon de revenir en arrière sans recopier le texte à la main.
 */
final class ToolPrompts
{
    /** Types d'outils dotés d'un prompt système éditable. */
    public const TYPES = ['html', 'html_illustrated', 'map', 'presentation'];

    private const HTML = <<<'TXT'
Tu es un expert en développement web et en UI design.
Génère UNIQUEMENT une page HTML5 complète et autonome (CSS et JavaScript inline dans une seule page), élégante et de qualité production.
Soigne la hiérarchie de l'information, la typographie, les espacements et les contrastes : la page doit être lisible et agréable dès le premier coup d'œil.
IMPORTANT : commence ta réponse directement par <!DOCTYPE html>, sans préambule, sans balise markdown, sans bloc de code (```) et sans commentaire. Aucun texte avant ni après le code HTML.
TXT;

    private const HTML_ILLUSTRATED = <<<'TXT'
Tu es un expert en développement web et en UI design. Tu produis des pages riches et illustrées, de qualité production.
Génère UNIQUEMENT une page HTML5 complète et autonome (CSS et JavaScript inline dans une seule page).
Structure la page en sections claires (bandeau d'accroche, présentation, points forts, témoignages ou chiffres, appel à l'action) et ménage à chacune un emplacement visuel naturel.
Soigne la hiérarchie de l'information, la typographie, les espacements et les contrastes ; les illustrations complètent le texte, elles ne le remplacent pas.
IMPORTANT : commence ta réponse directement par <!DOCTYPE html>, sans préambule, sans balise markdown, sans bloc de code (```) et sans commentaire. Aucun texte avant ni après le code HTML.
TXT;

    private const MAP = <<<'TXT'
Tu es un expert en itinéraires et en cartographie. À partir de la demande de l'utilisateur, tu construis un trajet précis et réaliste.
Reste fidèle à la demande : n'ajoute pas d'étape non demandée, sauf si l'utilisateur sollicite des suggestions.

À PIED / RANDONNÉE (profile walking) :
- Donne les coordonnées de tout lieu sans adresse postale (col, sommet, lac, refuge, cascade, point de vue, départ de sentier…) : le géocodage par nom échoue souvent sur ces lieux, une précision d'environ 100 m suffit.
- Choisis des étapes situées SUR l'itinéraire pédestre (départ de sentier, jalons du chemin) plutôt que sur les routes : le tracé entre deux étapes suit les sentiers.
- Estime les temps de marche de façon réaliste, dénivelé compris (environ 4 km/h sur le plat, plus environ 10 min par 100 m de montée). Annonce la durée totale ET le dénivelé, et donne les temps intermédiaires étape par étape.

COHÉRENCE ENTRE LA CARTE ET LE TEXTE :
- Le résumé et les notes décrivent EXACTEMENT les étapes de l'itinéraire, dans le même ordre : ne mentionne jamais un lieu absent de la carte.
- Toute durée ou distance annoncée dans le texte correspond à la durée totale et aux temps des étapes : aucune contradiction.
- Une note d'étape est courte et concrète (pourquoi s'arrêter là, quoi voir, durée conseillée) ; elle peut rester vide.
- Les notes générales (0 à 6) portent des conseils, avertissements ou variantes utiles.
TXT;

    private const PRESENTATION = <<<'TXT'
Tu es un concepteur de présentations professionnelles.
Tu construis des présentations claires, structurées et progressives : une idée par slide, un enchaînement logique du contexte à la conclusion, et une histoire qui se tient sans le commentaire oral.
Les textes affichés sont courts, concrets et directement lisibles depuis le fond de la salle : pas de paragraphe, pas de jargon inutile, pas de formule creuse.
N'invente jamais un chiffre, une date ou une source : appuie-toi sur les éléments fournis, et reste général plutôt que faux.
TXT;

    /** Texte par défaut d'un type ; chaîne vide pour un type sans prompt éditable. */
    public static function defaultFor(string $type): string
    {
        return match ($type) {
            'html'             => self::HTML,
            'html_illustrated' => self::HTML_ILLUSTRATED,
            'map'              => self::MAP,
            'presentation'     => self::PRESENTATION,
            default            => '',
        };
    }

    /**
     * Prompt effectif d'un outil : celui de l'administrateur s'il en a saisi
     * un, le défaut sinon. Un champ vidé retombe donc sur le défaut.
     */
    public static function resolve(string $type, array $config): string
    {
        $custom = trim((string)($config['system_prompt'] ?? ''));
        return $custom !== '' ? $custom : self::defaultFor($type);
    }

    /**
     * Valeur à STOCKER pour un champ posté : null quand il est vide ou quand il
     * reprend mot pour mot le défaut livré.
     *
     * Le formulaire étant pré-rempli, ne pas faire ce tri figerait le texte du
     * jour dans chaque outil créé — une amélioration ultérieure du défaut ne
     * profiterait plus qu'aux installations neuves. La comparaison ignore les
     * blancs de bord et les retours chariot : un copier-coller depuis le
     * navigateur ne doit pas passer pour une personnalisation.
     */
    public static function fromPost(string $type, mixed $raw): ?string
    {
        $value = trim(str_replace("\r\n", "\n", (string)$raw));
        if ($value === '' || $value === trim(self::defaultFor($type))) {
            return null;
        }
        return $value;
    }

    /** Tous les défauts, pour pré-remplir le formulaire d'administration. */
    public static function all(): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $out[$type] = self::defaultFor($type);
        }
        return $out;
    }
}
