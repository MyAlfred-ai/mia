<?php

declare(strict_types=1);

/**
 * Outils Google Drive du chat (Drive PERSONNEL de l'utilisateur, OAuth) : recherche,
 * lecture, parcours de dossiers, écritures Docs/Sheets, mise en forme, copie et rangement.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : les
 * définitions, la consigne et les handlers sont ceux du contrôleur, déplacés
 * tels quels. Toute erreur revient AU MODÈLE en ['error' => message FR] —
 * jamais une exception qui casserait le flux ; toute écriture passe par la
 * file d'approbation ($this->turn->queueWrite), rien n'est exécuté pendant
 * le flux.
 */
final class DriveTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly UserDriveService $drive)
    {
        parent::__construct($turn);
    }

    public function key(): string
    {
        return 'drive';
    }

    public function connector(): ?string
    {
        return 'drive';
    }

    public function definitions(): array
    {
        return $this->driveToolDefinitions($this->drive);
    }

    public function guidance(): string
    {
        return $this->driveToolGuidance($this->drive);
    }

    public function externalContentTools(): array
    {
        return [
            'rechercher_google_drive',
            'lire_google_drive',
            'lister_dossier_google_drive',
        ];
    }

    public function handlers(): array
    {
        $driveService        = $this->drive;
        $driveTextBudget     = $this->turn->textBudget;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        // Handlers Drive : toute erreur (fichier introuvable, connexion
        // révoquée, quota…) revient AU MODÈLE en ['error' => message FR]
        // — jamais d'exception qui casserait le flux SSE.
        $scope = $this->turn->scope();
        $toolHandlers['rechercher_google_drive'] = function (array $args) use ($driveService, $scope) {
            $this->sseEmit(['phase' => 'drive_search']);
            // Filtres d'une brique de workflow : le dossier imposé remplace
            // celui du modèle ; nature, nom et période trient les résultats.
            $dossier = $scope->driveFolder() !== '' ? $scope->driveFolder() : trim((string)($args['dossier'] ?? ''));
            try {
                return self::hintIfEmpty(['resultats' => $scope->filterDrive($driveService->search(
                    $scope->driveQuery((string)($args['requete'] ?? '')),
                    (int)($args['max_resultats'] ?? 10),
                    $dossier,
                    (string)($args['type'] ?? '')
                ))], 'resultats', $dossier !== ''
                    ? "Rien de ce nom sous « {$dossier} ». Liste ce dossier avec `lister_dossier_google_drive` "
                        . "pour voir ce qu'il contient réellement, plutôt que d'élargir à tout le Drive."
                    : "Aucun fichier trouvé. Élargis : moins de mots-clés, ou un seul mot distinctif du nom du fichier.");
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lire_google_drive'] = function (array $args) use ($driveService, $driveTextBudget) {
            $this->sseEmit(['phase' => 'drive_read']);
            try {
                return $driveService->readFile((string)($args['fichier'] ?? ''), $driveTextBudget, self::partArg($args));
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lister_dossier_google_drive'] = function (array $args) use ($driveService) {
            $this->sseEmit(['phase' => 'drive_list']);
            try {
                return self::hintIfEmpty($driveService->listFolder(
                    (string)($args['dossier'] ?? ''),
                    (int)($args['max_resultats'] ?? 50),
                    (string)($args['filtre'] ?? '')
                ), 'elements', trim((string)($args['filtre'] ?? '')) !== ''
                    ? "Aucun élément de ce nom dans ce dossier. Relance SANS `filtre` pour voir tout son contenu."
                    : "Ce dossier est vide. Vérifie auprès de l'utilisateur qu'il s'agit bien du bon dossier.");
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        // Écritures : via la file d'approbation ($queueConnectorWrite,
        // définie plus haut) — rien n'est exécuté pendant le stream.
        if ($driveService->canWriteDocs()) {
            $toolHandlers['creer_google_doc'] = function (array $args) use ($queueConnectorWrite) {
                return $queueConnectorWrite('creer_google_doc', $args, null);
            };
            $toolHandlers['modifier_google_doc'] = function (array $args) use ($driveService, $queueConnectorWrite) {
                // Résolution amont du fichier (nom réel, bon type) : une
                // erreur revient au modèle, jamais dans une carte cassée.
                try {
                    $ref = $driveService->fileRef((string)($args['fichier'] ?? ''), 'doc');
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('modifier_google_doc', $args, $ref);
            };
        }
        if ($driveService->canWriteSheets()) {
            $toolHandlers['creer_google_sheet'] = function (array $args) use ($queueConnectorWrite) {
                $values = $args['valeurs'] ?? [];
                if ($values !== [] && $values !== null) {
                    try {
                        UserDriveService::normalizeValues($values);
                    } catch (\RuntimeException $e) {
                        return ['error' => $e->getMessage()];
                    }
                }
                return $queueConnectorWrite('creer_google_sheet', $args, null);
            };
            $toolHandlers['modifier_google_sheet'] = function (array $args) use ($driveService, $queueConnectorWrite) {
                try {
                    UserDriveService::normalizeValues($args['valeurs'] ?? []);
                    $ref = $driveService->fileRef((string)($args['fichier'] ?? ''), 'sheet');
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('modifier_google_sheet', $args, $ref);
            };
        }
        if ($driveService->canWriteDocs()) {
            // Mise en forme d'un texte existant : le style est validé et
            // les occurrences comptées AVANT la carte — une cible
            // introuvable revient au modèle pendant le stream (il ajuste
            // ou relit le document) au lieu d'échouer après le clic.
            $toolHandlers['mettre_en_forme_google_doc'] = function (array $args) use ($driveService, $queueConnectorWrite) {
                $this->sseEmit(['phase' => 'drive_format']);
                try {
                    $style = DocsFormat::styleDepuisArgs($args);
                    $info  = $driveService->inspectDocText(
                        (string)($args['fichier'] ?? ''),
                        (string)($args['rechercher'] ?? '')
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                if ($info['occurrences'] === 0) {
                    return ['error' => 'Texte « ' . trim((string)($args['rechercher'] ?? '')) . ' » introuvable dans « '
                        . $info['nom'] . ' ». Fournis le texte EXACT (accents compris) — lis le document au besoin.'];
                }
                return $queueConnectorWrite('mettre_en_forme_google_doc', $args,
                    $info + ['styles' => DocsFormat::resumeStyles($style)]);
            };
        }
        if ($driveService->canWriteSheets()) {
            // Plage, onglet et nombre de cellules résolus AVANT la carte :
            // une plage mal écrite ou un onglet inexistant revient au
            // modèle pendant le stream, pas après le clic.
            $toolHandlers['mettre_en_forme_google_sheet'] = function (array $args) use ($driveService, $queueConnectorWrite) {
                $this->sseEmit(['phase' => 'sheets_format']);
                try {
                    $demande = SheetsFormat::demandeDepuisArgs($args);
                    $info    = $driveService->inspectSheetRange(
                        (string)($args['fichier'] ?? ''),
                        (string)($args['plage'] ?? '')
                    );
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('mettre_en_forme_google_sheet', $args, $info + [
                    'styles' => SheetsFormat::resumeStyles($demande['style'])
                        . ($demande['geler'] ? ($demande['style'] === [] ? 'gel des lignes' : ', gel des lignes') : ''),
                ]);
            };
        }
        if ($driveService->canCopy()) {
            // Comme le rangement : source, nom de la copie et emplacement
            // résolus AVANT la carte, pour qu'elle les annonce en clair.
            $toolHandlers['dupliquer_google_drive'] = function (array $args) use ($driveService, $queueConnectorWrite) {
                $this->sseEmit(['phase' => 'drive_copy']);
                try {
                    $ref  = $driveService->anyFileRef((string)($args['fichier'] ?? ''));
                    $nom  = trim((string)($args['nom'] ?? ''));
                    $copie = $nom !== ''
                        ? UserDriveService::sanitizeFileName($nom)
                        : UserDriveService::defaultCopyName($ref['nom']);
                    if (trim((string)($args['dossier'] ?? '')) !== '') {
                        $folder = $driveService->folderRef((string)$args['dossier']);
                        $args['dossier_id'] = $folder['id'];
                        $args['dossier']    = $folder['nom'];
                        $destination        = $folder['nom'];
                    } else {
                        $destination = $ref['dossier'] !== '' ? $ref['dossier'] : 'Mon Drive';
                    }
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                if (($stop = UserDriveService::organizeBlocker($ref['droits'] ?? [], 'dupliquer', $ref['nom'])) !== null) {
                    return ['error' => $stop];
                }
                return $queueConnectorWrite('dupliquer_google_drive', $args,
                    $ref + ['copie' => $copie, 'destination' => $destination]);
            };
        }
        // Rangement (renommer / déplacer) : le fichier — et le dossier de
        // destination — sont résolus ICI, avant la carte. Sinon elle
        // annoncerait « déplacer 1a2B3c… vers 9xY… », illisible, et une
        // destination ambiguë ne serait découverte qu'après approbation.
        if ($driveService->canOrganize()) {
            $toolHandlers['renommer_google_drive'] = function (array $args) use ($driveService, $queueConnectorWrite) {
                $this->sseEmit(['phase' => 'drive_organize']);
                try {
                    UserDriveService::sanitizeFileName((string)($args['nouveau_nom'] ?? ''));
                    $ref = $driveService->anyFileRef((string)($args['fichier'] ?? ''));
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                // Ce que Google déclare possible sur CE fichier, lu avant
                // la carte : faire approuver un rangement voué à l'échec
                // fait porter la déception au clic, quand plus rien n'est
                // rattrapable dans la conversation.
                if (($stop = UserDriveService::organizeBlocker($ref['droits'] ?? [], 'renommer', $ref['nom'])) !== null) {
                    return ['error' => $stop];
                }
                return $queueConnectorWrite('renommer_google_drive', $args, $ref);
            };
            $toolHandlers['deplacer_google_drive'] = function (array $args) use ($driveService, $queueConnectorWrite) {
                $this->sseEmit(['phase' => 'drive_organize']);
                try {
                    $ref    = $driveService->anyFileRef((string)($args['fichier'] ?? ''));
                    $folder = $driveService->folderRef((string)($args['dossier'] ?? ''));
                } catch (\RuntimeException $e) {
                    // Destination introuvable ou ambiguë : le message
                    // nomme les candidats, le modèle demande à l'utilisateur.
                    return ['error' => $e->getMessage()];
                }
                if (in_array($folder['id'], $ref['parents'], true)) {
                    return ['error' => 'Le fichier « ' . $ref['nom'] . ' » est déjà dans le dossier « '
                        . $folder['nom'] . ' » : aucun déplacement nécessaire.'];
                }
                if (($stop = UserDriveService::organizeBlocker($ref['droits'] ?? [], 'deplacer', $ref['nom'])) !== null) {
                    return ['error' => $stop];
                }
                // Le dossier RETENU est réinjecté : au clic « Approuver »,
                // la destination exécutée est celle que la carte a
                // annoncée, pas une nouvelle résolution par nom qui
                // pourrait avoir changé entre-temps. Id et nom voyagent
                // séparément — écraser `dossier` par l'id rendrait la
                // ligne de trace illisible (« Déplacement vers 1a2B3c… »).
                $args['dossier_id'] = $folder['id'];
                $args['dossier']    = $folder['nom'];
                return $queueConnectorWrite('deplacer_google_drive', $args, $ref + ['destination' => $folder['nom']]);
            };
        }

        return $toolHandlers;
    }

    /**
     * Définitions des outils Google Drive exposés au LLM quand l'utilisateur
     * a connecté son Drive (Réglages → Comptes connectés). Lecture toujours ;
     * création/modification Docs et Sheets selon les scopes accordés.
     */
    private function driveToolDefinitions(UserDriveService $drive): array
    {
        $defs = [
            [
                'name'        => 'rechercher_google_drive',
                'description' => "Recherche fichiers ET DOSSIERS dans le Google Drive de l'utilisateur "
                    . "(par nom ou contenu) : Mon Drive, fichiers partagés avec lui et Drives partagés. "
                    . "La casse et les accents sont ignorés. Renvoie id, nom, type, date de modification, "
                    . "`dossier` (vrai pour un dossier) et `lisible`. Un DOSSIER a toujours "
                    . "`lisible: false` — ce n'est pas une erreur, on n'en lit pas le contenu : "
                    . "utilise `lister_dossier_google_drive` pour voir ce qu'il contient. "
                    . "À utiliser quand l'utilisateur mentionne un document de son Drive sans lien.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete' => [
                            'type'        => 'string',
                            'description' => "Mots-clés (nom de fichier ou contenu). Donne les mots "
                                . "distinctifs, pas la phrase de l'utilisateur : les mots sont cherchés "
                                . "séparément, accents et casse ignorés.",
                        ],
                        'dossier' => [
                            'type'        => 'string',
                            'description' => "FACULTATIF mais À PRIVILÉGIER dès que l'utilisateur "
                                . "indique où chercher : borne la recherche à ce dossier et à sa "
                                . "descendance. Nom, chemin « 01-CLIENTS/INITECH », identifiant ou URL. "
                                . "Sans lui, la recherche porte sur tout le Drive et le bruit passe "
                                . "devant le bon résultat.",
                        ],
                        'type' => [
                            'type'        => 'string',
                            'enum'        => ['dossier', 'fichier'],
                            'description' => "FACULTATIF. « dossier » quand l'utilisateur cherche un "
                                . "DOSSIER (« le dossier INITECH », « le répertoire client ») — sans ce "
                                . "filtre, les documents qui mentionnent le mot occupent les résultats "
                                . "et le dossier peut n'apparaître nulle part. « fichier » pour "
                                . "l'inverse. Vide = les deux.",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de résultats (1 à 20). Défaut : 10.",
                        ],
                    ],
                    'required'   => ['requete'],
                ],
            ],
            [
                'name'        => 'lire_google_drive',
                'description' => "Lit le contenu d'un fichier du Google Drive PERSONNEL de l'utilisateur "
                    . "(Google Docs, Sheets, Slides, Word, Excel, PDF, texte…). Lecture seule. "
                    . "Renvoie le nom, le type et le texte extrait ; un long document se lit par "
                    . "parties (`partie`).",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fichier' => [
                            'type'        => 'string',
                            'description' => "ID du fichier (obtenu via rechercher_google_drive), "
                                . "URL Google Drive/Docs/Sheets collée par l'utilisateur, ou NOM du "
                                . "fichier — utile quand tu n'as plus son ID sous les yeux. Un nom "
                                . "ambigu renvoie la liste des candidats : demande alors lequel viser.",
                        ],
                        'partie' => [
                            'type'        => 'integer',
                            'description' => "Partie à lire pour un long contenu (1 = début, défaut). Le résultat indique le nombre de parties (`parties`) et comment lire la suite.",
                        ],
                    ],
                    'required'   => ['fichier'],
                ],
            ],
            [
                'name'        => 'lister_dossier_google_drive',
                'description' => "Liste le contenu d'un dossier du Google Drive de l'utilisateur "
                    . "(sous-dossiers puis fichiers). C'est LA façon d'ouvrir un dossier — un dossier "
                    . "ne se lit pas avec lire_google_drive. À utiliser EN PREMIER dès que "
                    . "l'utilisateur donne un dossier de départ : c'est plus fiable qu'une recherche "
                    . "par mot-clé sur tout le Drive. Renvoie `chemin`, `total` (contenu réel du "
                    . "dossier), `elements` (id, nom, type, `dossier`, `lisible`) et `tronque`.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'dossier' => [
                            'type'        => 'string',
                            'description' => "NOM du dossier (accents et casse indifférents), CHEMIN "
                                . "complet séparé par des « / » (« 01-CLIENTS/INITECH/03-CR et echanges » "
                                . "descend les trois niveaux en UN seul appel), identifiant, URL Google "
                                . "Drive, ou « racine » pour Mon Drive.",
                        ],
                        'filtre' => [
                            'type'        => 'string',
                            'description' => "FACULTATIF : ne garde que les éléments dont le nom "
                                . "contient ces mots (accents et casse ignorés). Indispensable quand "
                                . "`tronque` vaut vrai — c'est ainsi qu'on atteint un élément au-delà "
                                . "des premiers listés, sans relister le dossier entier.",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum d'éléments renvoyés (1 à 200). Défaut : 50.",
                        ],
                    ],
                    'required'   => ['dossier'],
                ],
            ],
        ];

        if ($drive->canWriteDocs()) {
            $defs[] = [
                'name'        => 'creer_google_doc',
                'description' => "Crée un nouveau Google Doc dans le Drive de l'utilisateur, avec un titre "
                    . "et un contenu optionnel. Le contenu est du MARKDOWN RENDU avec sa mise en forme "
                    . "(# titres, **gras**, *italique*, listes -, liens) — écris-le mis en forme. "
                    . "Renvoie l'URL du document créé.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'titre'   => ['type' => 'string', 'description' => "Titre du document."],
                        'contenu' => ['type' => 'string', 'description' => "Contenu initial en Markdown (titres #, ##, gras **, listes -, 1., liens ; les tableaux ne sont pas convertis)."],
                    ],
                    'required'   => ['titre'],
                ],
            ];
            $defs[] = [
                'name'        => 'modifier_google_doc',
                'description' => "Modifie un Google Doc existant de l'utilisateur. Modes : 'ajouter' "
                    . "(insère du texte en fin de document), 'remplacer_texte' (remplace toutes les "
                    . "occurrences d'un texte recherché), 'remplacer_tout' (remplace l'intégralité du "
                    . "contenu). En modes 'ajouter' et 'remplacer_tout', le texte est du MARKDOWN RENDU "
                    . "avec sa mise en forme (# titres, **gras**, listes) ; en 'remplacer_texte' il est "
                    . "inséré tel quel, sans interprétation. Lis le document AVANT une modification ciblée : "
                    . "'remplacer_texte' exige le texte EXACT tel qu'il figure dans le document (la casse est "
                    . "ignorée, mais pas les accents, les apostrophes typographiques, les espaces insécables ni "
                    . "les tirets longs) et ÉCHOUE si rien ne correspond — au moindre doute sur la ponctuation, "
                    . "préfère 'remplacer_tout' en réécrivant la section entière.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fichier'    => ['type' => 'string', 'description' => "ID, URL ou NOM du Google Doc (un nom ambigu renvoie les candidats)."],
                        'mode'       => ['type' => 'string', 'enum' => ['ajouter', 'remplacer_texte', 'remplacer_tout'],
                                         'description' => "Type de modification."],
                        'texte'      => ['type' => 'string', 'description' => "Texte à insérer ou texte de remplacement."],
                        'rechercher' => ['type' => 'string', 'description' => "Texte exact à remplacer (mode remplacer_texte uniquement)."],
                    ],
                    'required'   => ['fichier', 'mode', 'texte'],
                ],
            ];
        }
        if ($drive->canWriteSheets()) {
            $valeursSchema = [
                'type'        => 'array',
                'description' => "Lignes du tableau : liste de lignes, chaque ligne = liste de cellules "
                    . "(chaînes ; nombres, dates et formules acceptés en chaîne, interprétés par Google).",
                'items'       => ['type' => 'array', 'items' => ['type' => 'string']],
            ];
            $defs[] = [
                'name'        => 'creer_google_sheet',
                'description' => "Crée un nouveau Google Sheet dans le Drive de l'utilisateur, avec un titre "
                    . "et des données optionnelles écrites depuis A1. La PREMIÈRE LIGNE est automatiquement "
                    . "présentée en en-tête (gras, fond gris, ligne gelée, colonnes ajustées) : mets donc les "
                    . "intitulés de colonnes en première ligne. Renvoie l'URL de la feuille créée.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'titre'   => ['type' => 'string', 'description' => "Titre de la feuille de calcul."],
                        'valeurs' => $valeursSchema,
                        'entete'  => ['type' => 'boolean', 'description' => "false si la première ligne n'est PAS un en-tête (défaut : true)."],
                    ],
                    'required'   => ['titre'],
                ],
            ];
            $defs[] = [
                'name'        => 'modifier_google_sheet',
                'description' => "Modifie un Google Sheet existant de l'utilisateur. Modes : 'ecrire_plage' "
                    . "(écrit les valeurs sur une plage précise, ex. Feuille1!B2:D5) et 'ajouter_lignes' "
                    . "(ajoute les lignes après les données existantes). Lis la feuille AVANT une "
                    . "modification ciblée pour connaître sa structure.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fichier' => ['type' => 'string', 'description' => "ID, URL ou NOM du Google Sheet (un nom ambigu renvoie les candidats)."],
                        'mode'    => ['type' => 'string', 'enum' => ['ecrire_plage', 'ajouter_lignes'],
                                      'description' => "Type de modification."],
                        'plage'   => ['type' => 'string', 'description' => "Plage cible en notation A1 (ex. Feuille1!B2 ou A1:C10). Requis pour ecrire_plage ; optionnel pour ajouter_lignes."],
                        'valeurs' => $valeursSchema,
                    ],
                    'required'   => ['fichier', 'mode', 'valeurs'],
                ],
            ];
        }
        if ($drive->canWriteDocs()) {
            $defs[] = [
                'name'        => 'mettre_en_forme_google_doc',
                'description' => "Met en forme un texte DÉJÀ présent dans un Google Doc de l'utilisateur, sans "
                    . "le réécrire : gras, italique, souligné, couleur, taille, ou transformation du paragraphe "
                    . "en titre. Fournis le texte EXACT à cibler (accents compris — lis le document avant si "
                    . "besoin). Pour ÉCRIRE du contenu mis en forme, utilise plutôt creer_google_doc ou "
                    . "modifier_google_doc : leur contenu Markdown est rendu avec sa mise en forme. "
                    . "L'action est soumise à l'approbation de l'utilisateur.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fichier'    => ['type' => 'string', 'description' => "ID, URL ou NOM du Google Doc (un nom ambigu renvoie les candidats)."],
                        'rechercher' => ['type' => 'string', 'description' => "Texte exact à mettre en forme (tel qu'il apparaît dans le document)."],
                        'gras'       => ['type' => 'boolean', 'description' => "true = gras, false = retirer le gras. Omettre pour ne pas toucher."],
                        'italique'   => ['type' => 'boolean', 'description' => "true = italique, false = retirer. Omettre pour ne pas toucher."],
                        'souligne'   => ['type' => 'boolean', 'description' => "true = souligné, false = retirer. Omettre pour ne pas toucher."],
                        'couleur'    => ['type' => 'string', 'description' => "Couleur du texte : nom français (" . implode(', ', array_keys(DocsFormat::COULEURS)) . ") ou code #RRGGBB."],
                        'taille'     => ['type' => 'integer', 'description' => "Taille de police en points (6 à 96)."],
                        'style_paragraphe' => ['type' => 'string', 'enum' => array_keys(DocsFormat::STYLES_PARAGRAPHE),
                                               'description' => "Transforme le paragraphe contenant le texte : titre_1, titre_2, titre_3 ou normal."],
                        'portee'     => ['type' => 'string', 'enum' => ['toutes', 'premiere'],
                                         'description' => "toutes (défaut) = toutes les occurrences ; premiere = la première seulement."],
                    ],
                    'required'   => ['fichier', 'rechercher'],
                ],
            ];
        }
        if ($drive->canWriteSheets()) {
            $defs[] = [
                'name'        => 'mettre_en_forme_google_sheet',
                'description' => "Met en forme une plage de cellules d'un Google Sheet de l'utilisateur SANS "
                    . "toucher aux valeurs : gras, italique, taille, couleur du texte, couleur de fond, "
                    . "alignement, format de nombre (monnaie, pourcentage, date…), gel des lignes. "
                    . "La plage s'écrit en notation A1 (ex. « A1:D1 » pour une ligne d'en-tête, "
                    . "« Feuille2!B2:B50 » pour une colonne d'un autre onglet, « C:C » pour une colonne entière). "
                    . "L'action est soumise à l'approbation de l'utilisateur.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fichier'       => ['type' => 'string', 'description' => "ID, URL ou NOM du Google Sheet (un nom ambigu renvoie les candidats)."],
                        'plage'         => ['type' => 'string', 'description' => "Plage en notation A1 : A1:D1, B2, Feuille2!A1:C10, C:C, 2:5."],
                        'gras'          => ['type' => 'boolean', 'description' => "true = gras, false = retirer. Omettre pour ne pas toucher."],
                        'italique'      => ['type' => 'boolean', 'description' => "true = italique, false = retirer. Omettre pour ne pas toucher."],
                        'taille'        => ['type' => 'integer', 'description' => "Taille de police en points (6 à 96)."],
                        'couleur_texte' => ['type' => 'string', 'description' => "Couleur du texte : nom français (" . implode(', ', array_keys(DocsFormat::COULEURS)) . ") ou #RRGGBB."],
                        'couleur_fond'  => ['type' => 'string', 'description' => "Couleur de fond des cellules : nom français ou #RRGGBB."],
                        'alignement'    => ['type' => 'string', 'enum' => array_keys(SheetsFormat::ALIGNEMENTS),
                                            'description' => "Alignement horizontal du contenu."],
                        'format'        => ['type' => 'string', 'enum' => array_keys(SheetsFormat::FORMATS_NOMBRE),
                                            'description' => "Format des nombres de la plage (monnaie = euro, date = jj/mm/aaaa…)."],
                        'geler'         => ['type' => 'boolean', 'description' => "true pour geler les lignes jusqu'à la fin de la plage (en-tête qui reste visible au défilement)."],
                    ],
                    'required'   => ['fichier', 'plage'],
                ],
            ];
        }
        if ($drive->canCopy()) {
            $defs[] = [
                'name'        => 'dupliquer_google_drive',
                'description' => "Duplique un fichier du Google Drive de l'utilisateur (Doc, Sheet, PDF, image…). "
                    . "La copie est FIDÈLE : mise en forme, images et tableaux conservés — n'essaie jamais de "
                    . "copier un document en le lisant puis en le recréant, tu perdrais tout le formatage. "
                    . "L'original n'est pas modifié. Utile pour repartir d'un modèle : duplique, puis modifie "
                    . "la COPIE (son identifiant est renvoyé). L'action est soumise à l'approbation de l'utilisateur.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fichier' => ['type' => 'string', 'description' => "ID, URL ou NOM du fichier à dupliquer (un nom ambigu renvoie les candidats)."],
                        'nom'     => ['type' => 'string', 'description' => "Nom de la copie (optionnel ; par défaut « Copie de <nom d'origine> »)."],
                        'dossier' => ['type' => 'string', 'description' => "Dossier où déposer la copie : nom, lien, identifiant, ou « racine » (optionnel ; par défaut à côté de l'original)."],
                    ],
                    'required'   => ['fichier'],
                ],
            ];
        }
        if ($drive->canOrganize()) {
            $defs[] = [
                'name'        => 'renommer_google_drive',
                'description' => "Renomme un fichier du Google Drive de l'utilisateur (n'importe quel type : "
                    . "Doc, Sheet, PDF, image…). Ne touche PAS au contenu du fichier ni à ses partages. "
                    . "Utilise rechercher_google_drive avant si tu n'as pas l'identifiant du fichier. "
                    . "L'action est soumise à l'approbation de l'utilisateur.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fichier'     => ['type' => 'string', 'description' => "ID, URL ou NOM du fichier à renommer (un nom ambigu renvoie les candidats)."],
                        'nouveau_nom' => ['type' => 'string', 'description' => "Nouveau nom complet du fichier (extension comprise si le fichier en a une)."],
                    ],
                    'required'   => ['fichier', 'nouveau_nom'],
                ],
            ];
            $defs[] = [
                'name'        => 'deplacer_google_drive',
                'description' => "Déplace un fichier du Google Drive de l'utilisateur vers un autre dossier. "
                    . "Ne touche PAS au contenu du fichier. Le dossier de destination peut être donné par son "
                    . "NOM (ex. « Factures »), son lien, son identifiant, ou « racine » pour Mon Drive. Si le nom "
                    . "correspond à plusieurs dossiers, l'outil renvoie la liste : demande alors à l'utilisateur "
                    . "lequel viser. L'action est soumise à son approbation.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'fichier' => ['type' => 'string', 'description' => "ID, URL ou NOM du fichier à déplacer (un nom ambigu renvoie les candidats)."],
                        'dossier' => ['type' => 'string', 'description' => "Dossier de destination : nom, lien, identifiant, ou « racine »."],
                    ],
                    'required'   => ['fichier', 'dossier'],
                ],
            ];
        }
        return $defs;
    }

    /** Consigne ajoutée au system prompt quand les outils Drive sont disponibles. */
    private function driveToolGuidance(UserDriveService $drive): string
    {
        $txt = "\n\nTu disposes des outils `rechercher_google_drive`, `lister_dossier_google_drive` et "
            . "`lire_google_drive` pour consulter le Google Drive personnel de l'utilisateur. Quand il "
            . "mentionne un document de son Drive sans lien, commence par `rechercher_google_drive` puis "
            . "lis le bon fichier. "
            . "S'il colle une URL Google Docs/Sheets/Drive, passe-la directement à `lire_google_drive`. "
            . "Quand il parle d'un DOSSIER (« dans mon dossier Factures », « qu'y a-t-il dans X ? »), "
            . "utilise `lister_dossier_google_drive` : un dossier ressort toujours `lisible: false` de la "
            . "recherche — c'est normal, il se PARCOURT, il ne se lit pas. Ne conclus jamais qu'un dossier "
            . "est inaccessible sur cette seule base.\n"
            . "NAVIGUER PLUTÔT QUE CHERCHER — règle prioritaire : dès que l'utilisateur donne un point "
            . "d'entrée dans l'arborescence (un lien de dossier, un nom de dossier, « dans mon dossier X », "
            . "« sous 01-CLIENTS »), sa structure est une information plus sûre que n'importe quel "
            . "mot-clé. Dans ce cas :\n"
            . "1. `lister_dossier_google_drive` sur CE dossier — jamais une recherche globale d'abord ;\n"
            . "2. donne le CHEMIN complet quand tu le connais (« 01-CLIENTS/INITECH/03-CR et echanges ») : "
            . "les trois niveaux sont descendus en un seul appel ;\n"
            . "3. si `tronque` vaut vrai (le dossier contient plus d'éléments que ceux listés, cf. "
            . "`total`), relance avec `filtre` sur le nom cherché — n'annonce JAMAIS qu'un élément est "
            . "absent d'un dossier tronqué ;\n"
            . "4. tu peux aussi appeler `rechercher_google_drive` avec son paramètre `dossier` pour "
            . "chercher dans ce seul sous-arbre.\n"
            . "Ne pars en recherche globale (`rechercher_google_drive` sans `dossier`) que si aucun "
            . "point d'entrée n'est connu. "
            . "La recherche ignore la casse et les accents et cherche les mots séparément : donne 1 à "
            . "3 mots distinctifs plutôt que la phrase entière. Si elle ne rend rien, réessaie avec UN "
            . "seul mot du nom avant de conclure. "
            . "Quand l'utilisateur cherche un DOSSIER (« le dossier INITECH », « le répertoire client »), "
            . "passe `type: dossier` : un dossier n'a pas de contenu indexé, il ne remonte que par son "
            . "nom, et sans ce filtre les documents qui mentionnent le mot prennent toute la place. "
            . "Les paramètres `fichier` acceptent un identifiant, une URL OU un NOM de fichier : quand tu "
            . "n'as plus l'identifiant sous les yeux, donne le nom plutôt que d'en inventer un. Un nom "
            . "ambigu te revient avec la liste des candidats — transmets-la et demande lequel viser. "
            . "Cite toujours le nom du fichier que tu as lu. Si le contenu est marqué tronqué, signale-le. "
            . "Si un outil renvoie un champ `error`, transmets ce message tel quel à l'utilisateur "
            . "sans l'inventer ni le reformuler.";

        if (!$drive->canRead()) {
            // Autorisation de lecture non accordée : les outils répondent
            // « rien trouvé » sur un Drive pourtant plein, et le modèle en
            // conclut que le fichier n'existe pas. Le lui dire AVANT qu'il
            // cherche évite une conversation entière passée à ré-épeler des
            // noms de fichiers que MIA n'a pas le droit de voir.
            $txt .= "\nATTENTION : la connexion Drive de cet utilisateur ne porte PAS l'autorisation de "
                . "lecture de ses fichiers — MIA ne voit que ceux qu'elle a elle-même créés. Une recherche "
                . "qui ne donne rien ne prouve donc pas qu'un document est absent. Dis-lui de reconnecter "
                . "son Google Drive depuis Réglages → Comptes connectés en laissant TOUTES les cases cochées, "
                . "et n'affirme jamais que son fichier ou son dossier n'existe pas.";
        }

        $canDocs   = $drive->canWriteDocs();
        $canSheets = $drive->canWriteSheets();
        if ($canDocs || $canSheets) {
            $outils = [];
            if ($canDocs)   { $outils[] = '`creer_google_doc`, `modifier_google_doc`'; }
            if ($canSheets) { $outils[] = '`creer_google_sheet`, `modifier_google_sheet`'; }
            $txt .= " Tu peux aussi écrire dans son Drive avec " . implode(' et ', $outils) . ". "
                . "Le contenu des Google Docs (creer_google_doc, modifier_google_doc en modes ajouter et "
                . "remplacer_tout) est du MARKDOWN RENDU : utilise # / ## / ### pour les titres et "
                . "sous-titres, **gras**, *italique*, les listes - et 1., les liens — la mise en forme "
                . "apparaît réellement dans le document (les tableaux Markdown, eux, ne sont pas convertis). "
                . "Pour styler un texte DÉJÀ présent (gras, couleur, taille, passer une ligne en titre), "
                . "utilise mettre_en_forme_google_doc au lieu de réécrire le document. "
                . "Côté feuilles de calcul, creer_google_sheet présente automatiquement la PREMIÈRE LIGNE en "
                . "en-tête (gras, fond, ligne gelée, colonnes ajustées) : place-y les intitulés de colonnes. "
                . "Et mettre_en_forme_google_sheet met en forme une plage sans toucher aux valeurs — pense-y "
                . "après avoir rempli une feuille : format monnaie sur une colonne de montants, pourcentage "
                . "sur des taux, date sur des dates, fond coloré pour distinguer un total. "
                . "N'écris ou ne modifies JAMAIS un fichier sans demande claire de l'utilisateur ; "
                . "avant une modification ciblée, lis d'abord le fichier pour viser juste "
                . "(texte exact à remplacer, plage correcte). IMPORTANT : chaque écriture est soumise "
                . "à l'approbation explicite de l'utilisateur — l'outil renvoie `en_attente_approbation` "
                . "et une carte de confirmation s'affiche sous ta réponse. N'affirme JAMAIS qu'un fichier "
                . "a été créé ou modifié : annonce que l'action est prête et qu'elle attend son approbation "
                . "dans la carte ci-dessous, en résumant ce qu'elle fera.";
        } else {
            $txt .= " Tu ne peux PAS créer ni modifier de fichiers : la connexion actuelle est en lecture "
                . "seule. Si l'utilisateur demande une écriture, invite-le à reconnecter son Google Drive "
                . "dans Réglages → Comptes connectés pour accorder l'écriture.";
        }

        if ($drive->canCopy()) {
            $txt .= " Tu peux DUPLIQUER un fichier avec `dupliquer_google_drive` : la copie est fidèle "
                . "(mise en forme, images, tableaux) et l'original n'est pas touché. Ne simule JAMAIS une "
                . "copie en lisant un document puis en le recréant : tu perdrais toute sa mise en forme. "
                . "Pour travailler à partir d'un modèle, duplique-le puis modifie la COPIE avec l'identifiant "
                . "renvoyé — jamais l'original.";
        }
        if ($drive->canOrganize()) {
            $txt .= " Tu peux aussi RANGER ses fichiers avec `renommer_google_drive` et "
                . "`deplacer_google_drive` (tout type de fichier, pas seulement les Docs/Sheets) : ces "
                . "outils ne touchent jamais au contenu. Identifie d'abord le fichier visé avec "
                . "`rechercher_google_drive`, et si la recherche ramène plusieurs candidats, demande "
                . "lequel plutôt que d'en choisir un. Pour un déplacement, donne le dossier par son NOM ; "
                . "si l'outil répond que plusieurs dossiers correspondent, transmets la liste à "
                . "l'utilisateur et demande-lui de trancher. Comme les autres écritures, chaque rangement "
                . "attend son approbation dans la carte : n'affirme jamais qu'un fichier a été renommé ou "
                . "déplacé. Tu ne peux ni supprimer un fichier, ni le mettre à la corbeille — si on te le "
                . "demande, dis-le et propose de le déplacer dans un dossier dédié. "
                . "Ces outils fonctionnent sur TOUS les fichiers de l'utilisateur, pas seulement sur ceux "
                . "créés par MIA : si l'un d'eux échoue, transmets le message d'erreur tel quel — il dit "
                . "précisément ce qui manque (une autorisation à réaccorder en se reconnectant, ou le rôle "
                . "« Éditeur » à demander au propriétaire du fichier) — et n'invente jamais d'explication "
                . "à la place, ne conclus jamais de toi-même qu'un rangement est impossible par nature.";
        } else {
            $txt .= " Tu ne peux ni renommer ni déplacer de fichier : cette autorisation n'a pas été "
                . "accordée. Si l'utilisateur le demande, invite-le à reconnecter son Google Drive dans "
                . "Réglages → Comptes connectés.";
        }
        return $txt;
    }
}
