<?php

declare(strict_types=1);

/**
 * Fabricant d'un format de fichier.
 *
 * Contrat : du texte en entrée, les OCTETS du fichier en sortie. Un fabricant
 * ne connaît ni la requête HTTP, ni l'utilisateur, ni le nom final du fichier —
 * tout cela appartient à FileFactory. Il lève FileBuildException quand la
 * source ne permet pas de produire le format ; toute autre exception est
 * traitée par la fabrique comme une panne (journalisée, jamais affichée).
 *
 * Ajouter un format = implémenter cette interface et déclarer la classe au
 * catalogue (FileFormats).
 */
interface FileBuilder
{
    /**
     * @throws FileBuildException Source inexploitable pour ce format.
     */
    public function build(FileSource $source): string;
}
