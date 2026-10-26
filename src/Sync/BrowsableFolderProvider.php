<?php

declare(strict_types=1);

/**
 * Fournisseur de dossier distant NAVIGABLE : en plus du miroir (listing
 * récursif de RemoteFolderProvider), il sait rendre UN niveau d'arborescence
 * pour l'explorateur de la modale « base de connaissances ».
 *
 * Deux surfaces distinctes, volontairement : le miroir parcourt tout en
 * profondeur pour ingérer, l'explorateur ne lit qu'un dossier à la fois pour
 * que l'utilisateur regarde et choisisse. Un fournisseur sans identité
 * utilisateur (clé API globale, app-only) ne peut PAS lister « ma racine » —
 * il n'implémente donc pas cette interface, et l'appelant sait alors qu'il
 * doit demander un lien collé.
 *
 * Retour de browse() :
 *   ref        : référence du dossier affiché ('' = racine virtuelle du compte)
 *   name       : nom lisible du dossier affiché
 *   parent_ref : référence du parent, ou null (racine / inconnu)
 *   selectable : ce niveau peut-il être retenu comme dossier synchronisé ?
 *                (faux pour les racines virtuelles, qui ne sont pas des dossiers)
 *   folders    : list<array{ref:string,name:string}>
 *   files      : list<entrée normalisée RemoteFolderProvider> — le classement
 *                « supporté / ignoré » est fait par l'appelant (KbSync), qui
 *                connaît les MIME acceptés et les plafonds de taille
 *   truncated  : true si le listing a été coupé au plafond demandé
 *   empty      : (facultatif) phrase à afficher quand le niveau ne contient
 *                rien, là où « Ce dossier est vide » ne veut rien dire (une
 *                racine virtuelle, comme la liste des Drive partagés)
 */
interface BrowsableFolderProvider
{
    /**
     * @return array{ref:string,name:string,parent_ref:?string,selectable:bool,folders:list<array{ref:string,name:string}>,files:list<array<string,mixed>>,truncated:bool,empty?:string}
     */
    public function browse(string $folderRef = '', int $limit = 300): array;
}
