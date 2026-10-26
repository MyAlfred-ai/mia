<?php
// Routeur pour le serveur PHP built-in
// Sert les fichiers statiques directement, sinon passe à index.php

$uri  = $_SERVER['REQUEST_URI'];
$path = __DIR__ . '/public' . parse_url($uri, PHP_URL_PATH);

$uriPath = (string) parse_url($uri, PHP_URL_PATH);

// Médias générés : JAMAIS servis en statique, même en dev — ils passent par
// MediaController (contrôle de session/signature), comme en production via
// public/.htaccess. Sans ça, le serveur dev livrerait le fichier en clair et
// masquerait un manquement au contrôle d'accès jusqu'au déploiement.
if (preg_match('#^/assets/(img|video)/generated/#', $uriPath) === 1) {
    require __DIR__ . '/public/index.php';
    return true;
}

if (is_file($path)) {
    return false; // Laisser PHP servir le fichier statique
}

require __DIR__ . '/public/index.php';
