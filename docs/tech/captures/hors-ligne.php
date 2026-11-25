<?php
declare(strict_types=1);

/**
 * Chargé avant chaque page du serveur des captures (lancer.sh, auto_prepend_file) : PHP n'ouvre
 * plus aucune adresse https://. Les fournisseurs d'IA, Google et Microsoft ne s'appellent qu'en
 * HTTPS ; les clés d'API du jeu de données, factices, ne peuvent donc partir nulle part. Qdrant,
 * en http://127.0.0.1, reste joignable.
 */
stream_wrapper_unregister('https');
