<?php

declare(strict_types=1);

use PhpOffice\PhpWord\Element\Section;

/**
 * Habillage des documents Word produits par MIA aux couleurs de l'entreprise
 * (Organisation → Entreprise) — SOURCE UNIQUE, pour les deux chemins :
 * fichier livré dans la conversation (DocxFileBuilder) et export d'une
 * réponse (DocxExportService).
 *
 *  - SANS modèle Word : en-tête avec le logo de l'entreprise, pied avec sa
 *    mention (ou son nom) et le numéro de page — ajoutés à la section ;
 *  - AVEC modèle : le document est greffé dans le modèle (DocxTemplate), qui
 *    apporte ses propres en-têtes, pieds, styles et polices. Un modèle qui
 *    ne se remplit pas (fichier abîmé depuis son dépôt) ne prive jamais la
 *    personne de son document : il part tel quel, et l'échec au journal.
 */
final class CompanyDocx
{
    /** En-tête (logo) et pied (mention · numéro de page) d'une section PhpWord. */
    public static function addChrome(Section $section): void
    {
        try {
            $logo = CompanyProfile::logoPath();
            if ($logo !== null) {
                $section->addHeader()->addImage($logo, ['height' => 32, 'alignment' => 'right']);
            }
            $footer = CompanyProfile::documentFooter();
            if ($footer !== '') {
                // Les accolades délimitent les champs de PreserveText : celles du
                // texte saisi deviennent des parenthèses.
                $text = strtr($footer, ['{' => '(', '}' => ')']);
                $section->addFooter()->addPreserveText(
                    $text . ' · {PAGE}',
                    ['size' => 8, 'color' => '7F7F7F'],
                    ['alignment' => 'center', 'spaceAfter' => 0]
                );
            }
        } catch (Throwable $e) {
            // Habillage accessoire : jamais un document perdu pour lui.
            error_log('[Fichiers] Habillage Word de l’entreprise impossible : ' . $e->getMessage());
        }
    }

    /**
     * Octets d'un document Word → ces octets dans le modèle Word de
     * l'entreprise s'il y en a un, sinon tels quels.
     */
    public static function dress(string $bytes, string $title = '', string $author = ''): string
    {
        $template = CompanyProfile::wordTemplatePath();
        if ($template === null || $bytes === '') {
            return $bytes;
        }
        try {
            return DocxTemplate::fill($template, $bytes, [
                'title'  => $title,
                'author' => $author,
                'footer' => CompanyProfile::documentFooter(),
            ]);
        } catch (Throwable $e) {
            error_log('[Fichiers] Modèle Word de l’entreprise inutilisable, document sans modèle : '
                . get_class($e) . ' — ' . $e->getMessage());

            return $bytes;
        }
    }
}
