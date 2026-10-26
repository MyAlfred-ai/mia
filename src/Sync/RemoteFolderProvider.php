<?php

declare(strict_types=1);

/**
 * Fournisseur de dossier distant pour la synchronisation des bases de
 * connaissances (Google Drive, Microsoft SharePoint…). Le moteur KbSync ne
 * connaît que cette surface : chaque fournisseur normalise ses fichiers vers
 * un format commun.
 *
 * Format normalisé d'un fichier :
 *   id       : identifiant distant stable (clé du diff, unique par dossier)
 *   name     : nom d'affichage
 *   mime     : type MIME distant ('' si inconnu → ignoré par la sélection)
 *   size     : taille en octets (0 si inconnue, ex. export Google Docs)
 *   hash     : empreinte de contenu (md5 Drive, quickXorHash Graph) ou null
 *   modified : horodatage distant BRUT, comparé comme chaîne opaque, jamais parsé
 *   export   : ['mime' => …, 'ext' => …] si le fichier doit être converti au
 *              téléchargement (Google Docs natifs), null sinon
 *
 * Chaque implémentation expose aussi une statique parseFolderInput(string): ?string
 * (validation de la saisie utilisateur, appelable sans identifiants).
 */
interface RemoteFolderProvider
{
    /**
     * Vérifie l'accès au dossier (bouton « Tester » + début de cycle).
     * Throw RuntimeException à message utilisateur FR si inaccessible.
     *
     * @return array{name:string}
     */
    public function resolveFolder(string $folderRef): array;

    /**
     * Listing récursif des FICHIERS du dossier et de ses sous-dossiers,
     * au format normalisé (cf. en-tête).
     *
     * @return list<array{id:string,name:string,mime:string,size:int,hash:?string,modified:string,export:?array{mime:string,ext:string}}>
     */
    public function listFolderRecursive(string $folderRef, int $maxDepth = 10): array;

    /** Télécharge (ou exporte) une entrée normalisée vers $destPath. */
    public function fetchTo(array $file, string $destPath): void;
}
