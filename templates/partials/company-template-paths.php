<?php
/**
 * « Comment MIA se sert de votre modèle » (Organisation → Entreprise) : les
 * DEUX chemins d'un fichier livré dans la conversation, et le choix laissé à
 * la personne. Inclus par company-template-status.php (PowerPoint) et
 * company-word-template-status.php (Word) — un seul texte pour les deux, sans
 * quoi ils divergeraient au premier ajustement.
 *
 *   - Version rapide : MIA remplit le modèle elle-même (PptxTemplate::fill,
 *     DocxTemplate::fill), tout de suite, sans crédit ;
 *   - Version soignée : le modèle de l'entreprise part EN PREMIER, tel quel,
 *     chez le modèle IA, puis les fichiers joints et la demande (NativeDeck) ;
 *     il construit le fichier dans le modèle ;
 *   - la personne embarque ou non le modèle et le logo (cases « Embarquer »
 *     de la carte du fichier, et de la question posée avant l'écriture).
 *
 * Variable : $pathsKind ('pptx' | 'docx').
 */
$pathsKind = ($pathsKind ?? 'pptx') === 'docx' ? 'docx' : 'pptx';
$pptx      = $pathsKind === 'pptx';
?>
<div class="company-template-paths">
    <h4><?= $pptx ? te('Comment MIA se sert de votre modèle PowerPoint') : te('Comment MIA se sert de votre modèle Word') ?></h4>
    <ol class="ctp-steps">
        <li>
            <strong><?= te('Version rapide — MIA remplit le modèle') ?></strong>
            <span class="brand-hint"><?= $pptx
                ? te('Tout de suite, sans crédit : chaque slide que MIA écrit prend la mise en page correspondante du modèle (tableau ci-dessous).')
                : te('Tout de suite, sans crédit : le document est construit dans le modèle, avec ses styles de titres et de texte, ses en-têtes et ses pieds de page.') ?></span>
        </li>
        <li>
            <strong><?= te('Version soignée — un modèle IA construit dans votre modèle') ?></strong>
            <span class="brand-hint"><?= te('Une à trois minutes, en crédits : MIA envoie d’abord votre modèle tel quel, puis les fichiers joints à la conversation et la demande ; le modèle IA les lit dans cet ordre et compose le fichier dans votre modèle. Ses éléments d’exemple ne sont pas repris, et tout est effacé chez le fournisseur une fois le fichier reçu.') ?></span>
        </li>
        <li>
            <strong><?= te('La personne choisit ce qu’elle embarque') ?></strong>
            <span class="brand-hint"><?= te('Sur la carte de chaque fichier — et sous la question « Version rapide / Version soignée » posée avant l’écriture —, les cases « Embarquer : Modèle … · Logo … » sont cochées d’office. Décochées, le fichier suit la mise en page de MIA, sans le modèle ou sans le logo. Le choix est retenu pour les fichiers suivants.') ?></span>
        </li>
    </ol>
</div>
