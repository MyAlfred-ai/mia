<?php
declare(strict_types=1);

/**
 * Le jeu de données FICTIF des captures. Tout est inventé — personnes, entreprises, projets :
 *   - Camille Martin, créatrice, de l'Équipe commerciale (outils de création, quota de 8 000
 *     crédits) ; ses comptes Google reliés, dix conversations — dont une réunion Outlook qui attend
 *     son approbation —, quatre assistants, sa base de
 *     connaissances, deux workflows tirés des modèles de MIA et trois lancements, sa consommation
 *     du mois ;
 *   - Antoine Lefèvre, administrateur, qui partage une base avec elle ;
 *   - l'instance : thème clair, trois fournisseurs aux clés factices, défaut et secours, deux
 *     assistants de l'organisation.
 *
 *   php -d session.save_path=<dossier> docs/tech/captures/donnees-fictives.php   (lancé par lancer.sh)
 *
 * Il écrit aussi leurs sessions PHP dans ce dossier : l'outil de capture ouvre MIA avec le cookie
 * `myLLM_session` qui en désigne une, sans mot de passe. MIA garde l'utilisateur connecté dans
 * `$_SESSION['user']` et le relit en base à chaque page (AuthMiddleware::refreshUser) : son
 * identifiant suffit.
 *
 * Les dates partent de maintenant. Idempotent : tout ce qu'il écrit est effacé puis réécrit.
 */

const EMAIL = 'camille.martin@example.com';
const NOM = 'Camille Martin';
// Doit être la valeur du cookie que posent les captures (*.capture.mjs).
const SESSION = 'capturesdocumentation';
// L'adresse de la conversation ouverte sur la capture : /chat/101.
const CONVERSATION_OUVERTE = 101;
// L'administrateur des captures de l'administration, et sa session.
const ADMIN_EMAIL = 'antoine.lefevre@example.com';
const ADMIN_NOM = 'Antoine Lefèvre';
const SESSION_ADMIN = 'capturesadministration';

// Le code de MIA lui-même, comme ses scripts en ligne de commande : le chiffrement des clés, les
// modèles de workflows et leur forme canonique viennent de là, à jour avec l'application.
require dirname(__DIR__, 3) . '/scripts/_cli_bootstrap.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME')),
    (string) getenv('DB_USER'),
    (string) getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$maintenant = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
/** Il y a `$jours` jours, à l'heure dite. */
$date = static fn(int $jours, string $heure): string =>
    $maintenant->modify("-{$jours} days")->modify($heure)->format('Y-m-d H:i:s');

// ─── Nettoyage : les conversations et leurs messages partent avec l'utilisatrice ─
// L'historique de consommation survit à la suppression d'un compte (l'utilisateur y devient NULL) :
// on efface d'abord le sien.
$pdo->prepare('DELETE usage_logs FROM usage_logs JOIN users ON users.id = usage_logs.user_id WHERE users.email IN (?, ?)')
    ->execute([EMAIL, ADMIN_EMAIL]);
$pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')->execute([EMAIL, ADMIN_EMAIL]);
$pdo->exec("DELETE FROM profiles WHERE name IN ('Rédaction commerciale', 'Conseil RH') AND created_by IS NULL");
$pdo->exec("DELETE FROM knowledge_bases WHERE name IN ('Procédures qualité', 'Catalogue produits 2026', 'Accueil des nouveaux arrivants', 'Pistes salons 2027')");

// ─── L'utilisatrice : une créatrice, active, en français ─────────────────────
// Le rôle Créateur ouvre les espaces Assistants, Bases et Workflows, sans l'administration.
// Un compte Google ou Microsoft arrive avec sa photo ; sans elle, les messages portent un « ? ».
// Ses initiales dans un disque, dessinées ici : aucune image à aller chercher.
$avatar = static fn(string $initiales, string $couleur): string => 'data:image/svg+xml;base64,' . base64_encode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><circle cx="48" cy="48" r="48" fill="' . $couleur . '"/>'
    . '<text x="48" y="61" font-family="Arial, sans-serif" font-size="36" font-weight="700" fill="#fff" text-anchor="middle">'
    . $initiales . '</text></svg>'
);
$compte = $pdo->prepare('INSERT INTO users (email, name, avatar_url, role, is_active, lang, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
$compte->execute([EMAIL, NOM, $avatar('CM', '#7c3aed'), 'creator', 'fr', $date(40, '09:00')]);
$userId = (int) $pdo->lastInsertId();
// L'administrateur : propriétaire de la base qu'il partage avec elle, et compte des captures de
// l'administration.
$compte->execute([ADMIN_EMAIL, ADMIN_NOM, $avatar('AL', '#0f766e'), 'admin', 'fr', $date(60, '09:00')]);
$adminId = (int) $pdo->lastInsertId();
// Les nouveautés majeures, vues et visitées : ni fenêtre « Quoi de neuf », ni pastille verte. Elles
// changent à chaque version majeure, une capture qui les montrerait vieillirait avec elles.
$vue = $pdo->prepare('INSERT INTO user_feature_news (user_id, feature_id, shown_at, visited_at) VALUES (?, ?, NOW(), NOW())');
foreach ([$userId, $adminId] as $id) {
    foreach (array_keys(MajorFeatures::catalog()) as $nouveaute) {
        $vue->execute([$id, $nouveaute]);
    }
}

// ─── Les modèles : Claude Opus 5 par défaut, GPT-5.6 en secours, Mistral Large en plus ─
$pdo->exec("INSERT IGNORE INTO llm_models (provider, model, display_name, is_active, sort_order) VALUES
            ('anthropic', 'claude-opus-5', 'Claude Opus 5', 1, 0),
            ('openai', 'gpt-5.6', 'GPT-5.6', 1, 1),
            ('mistral', 'mistral-large-latest', 'Mistral Large', 1, 2)");
$pdo->exec("REPLACE INTO settings (`key`, value) VALUES ('default_llm', 'anthropic::claude-opus-5'),
            ('default_llm_fallback', 'openai::gpt-5.6')");
// Les captures se prennent en thème clair : le thème de l'instance, que Camille Martin garde
// (« Par défaut » dans Réglages → Affichage). MIA part en sombre sans ce réglage.
$pdo->exec("REPLACE INTO settings (`key`, value) VALUES ('theme', 'light')");
$modele = ['anthropic', 'claude-opus-5'];
// Claude dans Office, ouvert par jeton personnel (sans connexion Microsoft à configurer) dans
// Excel, Word et PowerPoint : la carte de Réglages → Comptes connectés, et son pas-à-pas.
$pdo->exec("REPLACE INTO settings (`key`, value) VALUES ('office_addin_enabled', '1'),
            ('office_addin_auth', 'token'), ('office_addin_apps', 'excel,word,powerpoint')");

// ─── Des clés d'API factices : une instance réelle en a, ses écrans aussi ─────
// Sans clé, la page des bases de connaissances réclame sa « Configuration requise » et celle des
// fournisseurs n'en montre aucun de branché. Chiffrées par MIA (Encryption, avec l'ENCRYPTION_KEY de
// lancer.sh). Elles ne partent nulle part : hors-ligne.php.
$pdo->exec("DELETE FROM api_keys WHERE provider IN ('anthropic', 'openai', 'mistral')");
// La page des fournisseurs en montre le début : il ressemble à celui d'une vraie clé, sans en être une.
$cle = $pdo->prepare('INSERT INTO api_keys (provider, key_enc, is_active, created_at) VALUES (?, ?, 1, ?)');
foreach (['anthropic' => 'sk-ant-captures-documentation', 'openai' => 'sk-captures-documentation',
          'mistral' => 'captures-documentation'] as $fournisseur => $factice) {
    $cle->execute([$fournisseur, Encryption::encrypt($factice), $date(60, '09:30')]);
}

// ─── Les outils de création, ouverts à son groupe ─────────────────────────────
// Un outil actif n'est proposé qu'aux groupes qui l'autorisent (les administrateurs mis à part) :
// sans groupe, le menu « + » n'aurait que les fichiers. Aucun ne sert : rien ne sort de la machine.
$pdo->exec("DELETE FROM chat_tools WHERE name IN ('Image', 'Page web', 'Site illustré', 'Itinéraire')");
$pdo->exec("DELETE FROM user_groups WHERE name = 'Équipe commerciale'");
$outil = $pdo->prepare('INSERT INTO chat_tools (name, type, provider, model, config_json, is_active, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)');
$outil->execute(['Image', 'image', 'openai', 'gpt-image-2', null, 1]);
$outilImage = (int) $pdo->lastInsertId();
$outils = [$outilImage];
foreach ([['Page web', 'html', null], ['Site illustré', 'html_illustrated', json_encode(['image_tool_id' => $outilImage])],
          ['Itinéraire', 'map', null]] as $i => [$nom, $type, $config]) {
    $outil->execute([$nom, $type, $modele[0], $modele[1], $config, $i + 2]);
    $outils[] = (int) $pdo->lastInsertId();
}
// 8 000 crédits par mois : la page Usage & crédits a une jauge, la barre latérale un reste.
$pdo->exec("INSERT INTO user_groups (name, is_active, credit_quota_k) VALUES ('Équipe commerciale', 1, 8)");
$groupe = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_group_members (user_id, group_id) VALUES (?, ?)')->execute([$userId, $groupe]);
$autorise = $pdo->prepare('INSERT INTO user_group_tools (group_id, tool_id) VALUES (?, ?)');
foreach ($outils as $id) {
    $autorise->execute([$groupe, $id]);
}

// ─── Ses comptes Google reliés : Drive, Gmail, Agenda et Tasks ────────────────
// lancer.sh déclare un client Google factice : sans lui, aucun connecteur Google n'est proposé.
// MIA ne lit ici que la présence du jeton et son état ; ces jetons ne déchiffrent rien et
// n'expirent pas avant un an : aucune page n'appelle Google.
$jeton = $pdo->prepare("INSERT INTO user_drive_tokens (user_id, provider, google_email, refresh_token_enc, access_token_enc,
                        access_token_expires_at, scope, status) VALUES (?, ?, ?, 'factice', 'factice', ?, '', 'ok')");
foreach (['google_drive', 'gmail', 'google_calendar', 'google_tasks'] as $fournisseur) {
    $jeton->execute([$userId, $fournisseur, EMAIL, $maintenant->modify('+1 year')->format('Y-m-d H:i:s')]);
}

// ─── Deux assistants de l'organisation, visibles de tous ──────────────────────
// Les avatars sont ceux que MIA livre (public/assets/img/profils/).
$assistant = $pdo->prepare('INSERT INTO profiles (name, description, system_prompt, is_active, is_global, color, avatar, created_at)
                            VALUES (?, ?, ?, 1, 1, ?, ?, ?)');
$assistant->execute(['Rédaction commerciale', 'Propositions, relances et comptes rendus clients.',
    'Tu aides à rédiger des documents commerciaux clairs et courtois.', '#2563eb', 'alfred-mabel.png', $date(30, '10:00')]);
$assistant->execute(['Conseil RH', 'Congés, accueil des nouveaux arrivants, entretiens.',
    'Tu réponds aux questions RH à partir des règles de l’entreprise.', '#16a34a', 'alfred-agnes.png', $date(30, '10:05')]);

// ─── Quatre bases : les siennes (dont une privée), une que l'administrateur lui partage, une publique ─
// Les documents sont décrits, pas déposés : la fiche d'une base lit leurs compteurs en base.
$base = $pdo->prepare("INSERT INTO knowledge_bases (name, description, embedding_provider, embedding_model, embedding_dims,
                       created_by, query_count, last_used_at, created_at) VALUES (?, ?, 'openai', 'text-embedding-3-small', 1536, ?, ?, ?, ?)");
$document = $pdo->prepare("INSERT INTO knowledge_base_documents (kb_id, file_name, stored_name, mime_type, file_size, token_count,
                           extraction_status, vector_status, chunk_count, created_at) VALUES (?, ?, ?, ?, ?, ?, 'done', 'done', ?, ?)");
$extrait = $pdo->prepare('INSERT INTO knowledge_base_chunks (document_id, kb_id, chunk_index, content, token_count) VALUES (?, ?, ?, ?, ?)');
$types = ['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$bases = [];
foreach ([
    ['Catalogue produits 2026', 'Fiches produits, tarifs publics et conditions de vente.', $userId, 37, 0, [
        ['Fiche produit — Portail client Atlas.pdf', 493_568, 3_120, 9],
        ['Fiche produit — Suivi des livraisons.pdf', 400_384, 2_840, 8],
        ['Tarifs publics 2026.xlsx', 59_392, 1_960, 6],
        ['Conditions générales de vente.docx', 75_776, 5_410, 14],
        ['Guide de démarrage rapide.pdf', 1_258_291, 4_380, 12],
    ]],
    ['Procédures qualité', 'Les procédures et formulaires du service qualité.', $adminId, 12, 3, [
        ['Procédure de traitement des réclamations.pdf', 288_768, 2_210, 7],
        ['Formulaire de non-conformité.docx', 41_984, 640, 2],
        ['Plan de contrôle des livraisons.xlsx', 33_792, 1_150, 4],
    ]],
    ['Accueil des nouveaux arrivants', 'Le livret d’accueil, l’organigramme et les usages de la maison.', $adminId, 58, 1, [
        ['Livret d’accueil 2026.pdf', 845_312, 4_020, 11],
        ['Organigramme.pdf', 120_832, 380, 1],
    ]],
    ['Pistes salons 2027', 'Mes notes sur les salons à envisager, pas encore partageables.', $userId, 4, 8, [
        ['Notes salons.docx', 18_432, 520, 2],
    ]],
] as [$nom, $description, $proprietaire, $questions, $dernierUsage, $fichiers]) {
    $base->execute([$nom, $description, $proprietaire, $questions, $date($dernierUsage, '14:20'), $date(25, '11:00')]);
    $bases[$nom] = (int) $pdo->lastInsertId();
    foreach ($fichiers as $i => [$fichier, $octets, $tokens, $extraits]) {
        $extension = pathinfo($fichier, PATHINFO_EXTENSION);
        $document->execute([$bases[$nom], $fichier, bin2hex(random_bytes(8)) . '.' . $extension, $types[$extension],
            $octets, $tokens, $extraits, $date(24 - $i, '10:00')]);
        $documentId = (int) $pdo->lastInsertId();
        // Les compteurs d'extraits de la page se lisent dans knowledge_base_chunks.
        for ($n = 0; $n < $extraits; $n++) {
            $extrait->execute([$documentId, $bases[$nom], $n, 'Extrait ' . ($n + 1) . ' de « ' . $fichier . ' ».',
                intdiv($tokens, $extraits)]);
        }
    }
}
$pdo->prepare('INSERT INTO knowledge_base_shares (kb_id, user_id, can_manage) VALUES (?, ?, 0)')
    ->execute([$bases['Procédures qualité'], $userId]);
// Les trois visibilités de la liste : une base publique (toute l'organisation), une privée (elle seule).
$pdo->prepare('UPDATE knowledge_bases SET is_public = 1 WHERE id = ?')->execute([$bases['Accueil des nouveaux arrivants']]);
$pdo->prepare('UPDATE knowledge_bases SET is_private = 1 WHERE id = ?')->execute([$bases['Pistes salons 2027']]);

// ─── Ses propres assistants : la page Assistants d'une créatrice ne liste que les siens ─
// Ils partent avec elle (created_by). [nom, description, couleur, avatar, actif, jours, base, outil]
$personnel = $pdo->prepare("INSERT INTO profiles (name, description, system_prompt, default_llm, is_active, created_by,
                            is_personal, is_global, color, avatar, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?, ?)");
$lienBase = $pdo->prepare('INSERT INTO profile_knowledge_bases (profile_id, kb_id) VALUES (?, ?)');
$lienOutil = $pdo->prepare('INSERT INTO profile_tools (profile_id, tool_id) VALUES (?, ?)');
$profils = [];
foreach ([
    ['Comptes rendus de réunion', 'Transforme vos notes en compte rendu : décisions, actions, responsables.', '#7c3aed', 'alfred-lecteur.png', 1, 3, null, null],
    ['Fiches produits', 'Rédige et met à jour les fiches du catalogue, au ton de la marque.', '#ea580c', 'alfred-peintre.png', 1, 6, 'Catalogue produits 2026', $outilImage],
    ['Relances clients', 'Relances de devis et de factures, polies mais fermes.', '#0891b2', 'alfred-serveur.png', 1, 9, null, null],
    ['Veille salons', 'Repère les salons du secteur et prépare la participation.', '#64748b', 'alfred-loupe.png', 0, 12, null, null],
] as [$nom, $description, $couleur, $image, $actif, $jours, $baseLiee, $outilLie]) {
    $personnel->execute([$nom, $description, 'Tu es l’assistant « ' . $nom . ' » de Camille Martin. ' . $description,
        implode('::', $modele), $actif, $userId, $couleur, $image, $date($jours, '15:00'), $date($jours, '15:00')]);
    $profil = (int) $pdo->lastInsertId();
    $profils[$nom] = $profil;
    if ($baseLiee !== null) {
        $lienBase->execute([$profil, $bases[$baseLiee]]);
    }
    if ($outilLie !== null) {
        $lienOutil->execute([$profil, $outilLie]);
    }
}

// ─── Deux workflows tirés des modèles de MIA, adaptés à ses comptes Google ─────
// Le premier ouvre l'éditeur ; les deux ont tourné ces derniers jours : l'historique montre, pour
// chaque lancement, chaque brique, sa pièce et son issue. Passages et pièces écrits comme le moteur.
$camille = User::findById($userId);
$choix = WorkflowCatalog::choices($camille);
$workflows = [];
foreach (['tri_demandes', 'compte_rendu'] as $cle) {
    $modeleWf = WorkflowTemplates::get($cle, $choix);
    $id = Workflow::create($userId, $modeleWf['name'], $modeleWf['definition'], $cle, $modeleWf['color']);
    Workflow::publish($id, $userId, $maintenant->modify('-5 days'));
    $workflows[$cle] = Workflow::findById($id);
}
// Un lancement terminé : ses passages dans l'ordre, chacun avec son issue, son détail et sa pièce.
$etape = $pdo->prepare('UPDATE workflow_run_steps SET outcome = ?, started_at = ?, finished_at = ? WHERE id = ?');
$lancer = static function (array $wf, string $declencheur, array $depart, DateTimeImmutable $debut, array $passages)
    use ($pdo, $userId, $etape): void {
    $run = WorkflowRun::create($wf['id'], $wf['published_version'], $userId, $wf['published'], $declencheur, null, $depart, 'Europe/Paris');
    foreach ($passages as $i => [$noeud, $issue, $detail, $piece]) {
        $passage = WorkflowRun::insertStep($run, $noeud, WorkflowRun::STEP_DONE, $detail);
        $etape->execute([$issue, $debut->modify('+' . ($i * 40) . ' seconds')->format('Y-m-d H:i:s'),
            $debut->modify('+' . ($i * 40 + 35) . ' seconds')->format('Y-m-d H:i:s'), $passage]);
        if ($piece !== null) {
            [$cle, $nom, $forme, $contenu] = $piece;
            WorkflowRun::saveArtifact($run, $cle, $noeud, $nom, $forme, $contenu);
        }
    }
    WorkflowRun::setState($run, WorkflowRun::DONE, WorkflowSpec::END_KEY);
    $pdo->prepare('UPDATE workflow_runs SET started_at = ?, finished_at = ? WHERE id = ?')->execute([
        $debut->format('Y-m-d H:i:s'), $debut->modify('+' . (count($passages) * 40) . ' seconds')->format('Y-m-d H:i:s'), $run]);
};
// [brique, issue, détail, pièce remise ou null]
$compteRenduDe = static fn(string $reunion, array $decisions, array $actions): array => [
    [WorkflowSpec::START_KEY, 'done', [], null],
    ['redaction', 'done', [], ['compte_rendu', 'Compte rendu', 'document', ['format' => 'docx',
        'source' => "# {$reunion}\n\n## Décisions\n\n- " . implode("\n- ", $decisions)]]],
    ['actions', 'done', [], ['tableau_actions', 'Tableau des actions', 'table',
        ['rows' => array_merge([['Action', 'Responsable', 'Échéance', 'Priorité']], $actions)]]],
    ['taches', 'done', [], null],
    ['recap', 'done', [], null],
    [WorkflowSpec::END_KEY, 'done', [], null],
];
$jour = static fn(int $dans): string => $maintenant->modify("{$dans} days")->format('Y-m-d');

$lancer($workflows['compte_rendu'], 'manual', [
    'reunion' => 'Point hebdomadaire de l’équipe commerciale',
    'date' => $jour(-6),
    'notes' => 'Trois devis en cours, dont Durand Logistique. Salon de la logistique : stand réservé.',
    'participants' => 'julien.moreau@example.com',
], $maintenant->modify('-6 days')->setTime(10, 30), $compteRenduDe('Point hebdomadaire de l’équipe commerciale', [
    'Le devis Durand Logistique part avant vendredi.',
    'Le stand du salon de la logistique est confirmé.',
], [
    ['Envoyer le devis Durand Logistique', 'Camille Martin', $jour(-3), 'Haute'],
    ['Préparer les supports du salon', 'Julien Moreau', $jour(10), 'Moyenne'],
]));

$lancer($workflows['tri_demandes'], 'email', [], $maintenant->modify('-2 days')->setTime(9, 12), [
    [WorkflowSpec::START_KEY, 'done', [], [WorkflowSpec::EVENT_PIECE, 'Email reçu', 'fiche', ['fields' => [
        'expediteur' => 'sophie.bernard@example.com',
        'objet' => 'Demande de devis : 20 accès supplémentaires au portail Atlas',
        'recu_le' => $jour(-2),
        'contenu' => 'Bonjour, nous souhaitons ouvrir le portail Atlas à vingt personnes de plus. Pourriez-vous nous faire une proposition ?',
        'pieces_jointes' => [],
    ], 'summary' => '']]],
    ['lecture', 'done', [], ['demande', 'Fiche de la demande', 'fiche', ['fields' => [
        'client' => 'Sophie Bernard', 'entreprise' => 'Durand Logistique',
        'objet' => '20 accès supplémentaires au portail Atlas',
        'points' => ['Tarif par accès', 'Délai de mise en service'], 'urgent' => false,
    ]]]],
    ['aiguillage', 'devis', ['reason' => 'La cliente demande un prix pour vingt accès supplémentaires.', 'mode' => 'route'], null],
    ['proposition', 'done', [], ['proposition_commerciale', 'Proposition', 'text', ['text' =>
        'Madame Bernard, voici notre proposition pour vingt accès supplémentaires au portail Atlas, mis en service sous cinq jours ouvrés.']]],
    ['reponse_devis', 'done', [], null],
    [WorkflowSpec::END_KEY, 'done', [], null],
]);

$lancer($workflows['compte_rendu'], 'manual', [
    'reunion' => 'Comité de pilotage Atlas',
    'date' => $jour(-1),
    'notes' => 'Planning validé, recette repoussée d’une semaine. Julien relance Durand Logistique pour les accès. Sophie Bernard envoie la liste des utilisateurs pilotes.',
    'participants' => 'julien.moreau@example.com, sophie.bernard@example.com',
], $maintenant->modify('-1 day')->setTime(16, 5), $compteRenduDe('Comité de pilotage Atlas', [
    'Le planning général est validé.',
    'La recette est repoussée d’une semaine, au lundi suivant.',
], [
    ['Relancer Durand Logistique pour les accès', 'Julien Moreau', $jour(3), 'Haute'],
    ['Envoyer la liste des utilisateurs pilotes', 'Sophie Bernard', $jour(5), 'Moyenne'],
    ['Mettre à jour le planning de recette', 'Camille Martin', $jour(2), 'Haute'],
]));

// ─── Les conversations ────────────────────────────────────────────────────────
$reponseAtlas = <<<'MD'
Voici une proposition pour une réunion d'une heure :

## Ordre du jour — lancement du projet Atlas

| Horaire | Sujet | Animé par |
|---|---|---|
| 0:00 – 0:10 | Tour de table et objectifs de la réunion | Camille Martin |
| 0:10 – 0:25 | Le projet en bref : périmètre, calendrier, budget | Julien Moreau |
| 0:25 – 0:40 | Attentes du client et critères de réussite | Sophie Bernard (Durand Logistique) |
| 0:40 – 0:55 | Organisation : rôles, rituels, outils partagés | Équipe projet |
| 0:55 – 1:00 | Prochaines étapes et date du premier comité | Camille Martin |

**À envoyer avant la réunion :** la note de cadrage et le calendrier prévisionnel.

Voulez-vous que je rédige l'invitation ?
MD;

// Le bloc ```choix devient une carte de boutons (public/assets/js/choice-buttons.js).
$reponseInvitation = <<<'MD'
Avec plaisir. Les huit participants n'attendent pas le même message : deux personnes de Durand
Logistique, et l'équipe projet, qui se connaît déjà.

```choix
Quel ton pour l'invitation ?
- Formel — vouvoiement, pour les deux personnes du client
- Cordial — pour l'équipe projet
- Deux versions — une pour le client, une pour l'équipe
```
MD;

// Une réunion Outlook prête, qui attend l'approbation : capture reunion-outlook. Le mardi qui vient,
// à 10 h, à l'heure de Paris — la date suit le jour de la capture.
$mardi = $maintenant->modify('next tuesday')->setTime(10, 0);
$questionReunion = 'Organise un point Teams d’une heure mardi prochain à 10 h en salle Everest avec '
    . 'sophie.bernard@example.com et julien.moreau@example.com, puis chaque semaine, dix fois '
    . 'en tout. Ajoute Léa Petit (lea.petit@example.com) en facultative, avec un rappel un quart d’heure avant.';
$reponseReunion = <<<'MD'
Vous êtes tous les trois disponibles mardi de 10 h à 11 h, et la salle Everest est libre. La réunion
est prête : elle attend votre approbation dans la carte ci-dessous.

Dès que vous approuvez, Outlook envoie l'invitation à Sophie Bernard et Julien Moreau, et à Léa Petit
en facultative, et la demande de réservation à la salle ; le lien de la réunion Teams figurera dans
l'évènement.
MD;

$conversations = [
    // [id, titre, jours, heure, épinglée, question, réponse]
    // Une réunion Outlook avec invités, en attente d'approbation : capture reunion-outlook.
    [110, 'Point d’étape Atlas sur Teams', 0, '08:30', false, $questionReunion, $reponseReunion],
    [CONVERSATION_OUVERTE, 'Ordre du jour du lancement Atlas', 0, '09:40', false,
        'Prépare l’ordre du jour de la réunion de lancement du projet Atlas : une heure, huit participants, dont deux personnes du client.',
        $reponseAtlas],
    // Une réponse qui se termine par des choix en boutons : capture choix-en-boutons.
    [108, 'Invitation au lancement Atlas', 0, '08:55', false,
        'Rédige l’invitation à la réunion de lancement du projet Atlas, mardi prochain à 10 h.',
        $reponseInvitation],
    [102, 'Relance du devis Durand', 1, '16:10', false,
        'Rédige une relance courtoise pour le devis envoyé à Durand Logistique il y a deux semaines.',
        "Bonjour Madame Bernard,\n\nJe me permets de revenir vers vous au sujet de notre proposition du 1er septembre…"],
    [103, 'Synthèse du rapport annuel', 2, '11:25', true,
        'Résume le rapport annuel en dix points pour le comité de direction.',
        "## Les dix points à retenir\n\n1. Chiffre d’affaires en hausse de 8 %…"],
    [104, 'Traduire la fiche produit en anglais', 4, '14:00', false,
        'Traduis cette fiche produit en anglais, en gardant les unités métriques.',
        "**Product sheet — Atlas client portal**\n\nTrack every order in real time…"],
    [105, 'Idées de stand pour le salon', 8, '10:15', false,
        'Donne-moi cinq idées pour rendre notre stand plus visible au salon de la logistique.',
        "1. Une démonstration en direct du suivi des commandes…"],
    // Son titre répond, avec des pages et des actions, à la recherche « cre » : capture rechercher-ou-aller-a.
    [109, 'Créer le support du salon de la logistique', 10, '11:20', false,
        'Aide-moi à créer le support de présentation de notre stand au salon de la logistique.',
        "Je vous propose six diapositives : le portail Atlas en une phrase, trois cas clients, la démonstration, l’offre salon…"],
    [106, 'Planning des congés d’été', 15, '09:05', false,
        'Construis un tableau des congés d’été pour une équipe de six personnes.',
        "| Semaine | Absents | Présents |\n|---|---|---|\n| 28 | Léa, Thomas | 4 |"],
    [107, 'Mail de bienvenue aux nouveaux arrivants', 22, '17:30', false,
        'Écris un mail de bienvenue pour les deux personnes qui arrivent lundi.',
        "Bonjour à tous les deux,\n\nToute l’équipe est ravie de vous accueillir lundi…"],
];

$reponses = [];
$conversation = $pdo->prepare('INSERT INTO conversations (id, user_id, llm_used, title, created_at, updated_at, is_pinned, pinned_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$message = $pdo->prepare('INSERT INTO messages (conversation_id, role, llm_provider, llm_model, response_ms, content, tokens_used, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($conversations as [$id, $titre, $jours, $heure, $epinglee, $question, $reponse]) {
    $debut = $date($jours, $heure);
    $fin = (new DateTimeImmutable($debut))->modify('+1 minute')->format('Y-m-d H:i:s');
    $conversation->execute([$id, $userId, implode('::', $modele), $titre, $debut, $fin, $epinglee ? 1 : 0, $epinglee ? $fin : null]);
    $message->execute([$id, 'user', null, null, null, $question, 0, $debut]);
    // L'étiquette sous la réponse : modèle, crédits (la ligne d'usage plus bas) et temps de réponse.
    $message->execute([$id, 'assistant', $modele[0], $modele[1], 3_800 + ($id % 10) * 650, $reponse, 900, $fin]);
    $reponses[$id] = [(int) $pdo->lastInsertId(), $jours, 45 + ($id % 10) * 4];
}

// ─── La réunion Outlook préparée par MIA, qui attend l'approbation ─────────────
// Exactement ce que dépose l'outil creer_evenement_agenda_outlook : ses arguments, et l'aperçu que
// calcule le service (les invités nommés, la salle, la réunion Teams, la répétition). Aucun compte Microsoft
// n'est relié : la carte s'affiche quand même, et rien ne partirait — hors-ligne.php.
$argsReunion = [
    'titre'                  => 'Point d’étape Atlas',
    'debut'                  => $mardi->format('Y-m-d H:i'),
    'fin'                    => $mardi->modify('+1 hour')->format('Y-m-d H:i'),
    'invites'                => 'sophie.bernard@example.com, julien.moreau@example.com',
    'invites_optionnels'     => 'lea.petit@example.com',
    'salle'                  => 'Salle Everest <salle.everest@example.com>',
    'visio'                  => true,
    'recurrence'             => 'hebdomadaire',
    'recurrence_occurrences' => 10,
    'rappel_minutes'         => 15,
    'description'            => 'Avancement du projet, points de blocage, décisions à prendre.',
    'fuseau'                 => 'Europe/Paris',
];
$carte = DrivePendingAction::create($userId, 110, UserOutlookCalendarService::TOOL_CREATE, $argsReunion,
    UserOutlookCalendarService::actionPreview(UserOutlookCalendarService::TOOL_CREATE, $argsReunion,
        // Ce que le service a vu de l'agenda de la salle, sur la première des dix occurrences.
        ['salle_dispo' => 'libre', 'salle_verifiee' => 'Salle Everest <salle.everest@example.com>', 'premiere_occurrence' => true]));
DrivePendingAction::attachToMessage([$carte], $reponses[110][0], $userId);
// Une semaine, comme une carte de tâche programmée : la capture reste possible toute la journée.
DrivePendingAction::extend($carte, $userId, 7 * 24 * 60);
// La pile d'actions au-dessus de la réponse : le créneau cherché, puis la réunion préparée.
Message::setActions($reponses[110][0], [
    ['t' => 'mscalendar_slots', 'l' => ChatController::ACTION_LABELS['mscalendar_slots'],
     'd' => 'mardi, 1 h, avec 2 personnes et la salle Everest', 'ok' => true],
    ['t' => UserOutlookCalendarService::TOOL_CREATE, 'l' => ChatController::ACTION_LABELS[UserOutlookCalendarService::TOOL_CREATE],
     'd' => $argsReunion['titre'], 'ok' => true],
]);

// ─── Sa consommation du mois : des réponses surtout, quelques créations ──────
// Écrite comme MIA l'écrit (UsageLog), datée des dix-huit derniers jours sans sortir du mois en
// cours : le total ne dépend pas du jour où l'on capture.
$debutDuMois = $maintenant->modify('first day of this month')->setTime(9, 0);
$quand = static fn(int $jours, string $heure): string =>
    max($debutDuMois, $maintenant->modify("-{$jours} days")->modify($heure))->format('Y-m-d H:i:s');
$dater = static fn(string $table, int $id, string $le) =>
    $pdo->prepare("UPDATE {$table} SET created_at = ? WHERE id = ?")->execute([$le, $id]);
// Rattachée à aucune conversation : le total affiché dans chacune reste celui de ses réponses.
for ($j = 0; $j < 18; $j++) {
    // L'après-midi, un jour sur deux avec « Fiches produits » ; un matin sur cinq, avec « Comptes rendus de réunion ».
    foreach ([[55 + ($j * 7) % 30, '10:15', $j % 5 === 0 ? $profils['Comptes rendus de réunion'] : null],
              [48 + ($j * 11) % 25, '15:40', $j % 2 === 0 ? $profils['Fiches produits'] : null]] as [$credits, $heure, $profil]) {
        $dater('usage_logs', UsageLog::insertLlm(['user_id' => $userId, 'profile_id' => $profil, 'llm_provider' => 'anthropic',
            'model' => 'claude-opus-5', 'tokens_input' => $credits * 90, 'tokens_output' => $credits * 14,
            'credits_used' => $credits, 'source' => UsageSource::CHAT]), $quand($j, $heure));
    }
    foreach ([[UsageSource::TITLE, 2], [UsageSource::FOLLOW_UP, 3]] as [$source, $credits]) {
        $dater('usage_logs', UsageLog::insertLlm(['user_id' => $userId, 'llm_provider' => 'anthropic',
            'model' => 'claude-opus-5', 'tokens_input' => 600, 'tokens_output' => 40, 'credits_used' => $credits,
            'source' => $source]), $quand($j, '10:16'));
    }
    if ($j % 3 === 0) {
        $dater('usage_logs', UsageLog::insertLlm(['user_id' => $userId, 'llm_provider' => 'openai',
            'model' => 'gpt-5.6', 'tokens_input' => 3_100, 'tokens_output' => 520, 'credits_used' => 35,
            'source' => UsageSource::CHAT]), $quand($j, '11:30'));
        $dater('usage_logs', UsageLog::insertLlm(['user_id' => $userId, 'llm_provider' => 'anthropic',
            'model' => 'claude-opus-5', 'quantity' => 1, 'credits_used' => 13, 'source' => UsageSource::WEB_SEARCH]), $quand($j, '11:31'));
    }
    if ($j % 4 === 0) {
        $dater('usage_logs', UsageLog::insertLlm(['user_id' => $userId, 'llm_provider' => 'mistral',
            'model' => 'mistral-large-latest', 'tokens_input' => 2_400, 'tokens_output' => 380, 'credits_used' => 12,
            'source' => UsageSource::CHAT]), $quand($j, '17:05'));
    }
}
// Le coût de chaque réponse des conversations, que son étiquette affiche.
foreach ($reponses as $fil => [$messageId, $jours, $credits]) {
    $dater('usage_logs', UsageLog::insertLlm(['user_id' => $userId, 'conversation_id' => $fil, 'message_id' => $messageId,
        'llm_provider' => 'anthropic', 'model' => 'claude-opus-5', 'tokens_input' => $credits * 90, 'tokens_output' => $credits * 14,
        'credits_used' => $credits, 'source' => UsageSource::CHAT]), $quand($jours, '09:00'));
}
// [jours, outil, type, crédits]
foreach ([[2, $outils[0], 'image', 40], [5, $outils[0], 'image', 40], [9, $outils[0], 'image', 40],
          [6, $outils[1], 'html', 300], [11, $outils[3], 'map', 95]] as [$j, $outilId, $type, $credits]) {
    $dater('tool_usage_logs', UsageLog::insertTool(['user_id' => $userId, 'tool_id' => $outilId, 'tool_type' => $type, 'credits_used' => $credits]), $quand($j, '14:00'));
}

// ─── La session : Camille Martin connectée, sans mot de passe ─────────────────
session_id(SESSION);
session_start();
$_SESSION['user'] = ['id' => $userId, 'email' => EMAIL, 'name' => NOM, 'role' => 'creator', 'is_active' => 1];
session_write_close();
session_id(SESSION_ADMIN);
session_start();
$_SESSION['user'] = ['id' => $adminId, 'email' => ADMIN_EMAIL, 'name' => ADMIN_NOM, 'role' => 'admin', 'is_active' => 1];
session_write_close();

echo 'Données fictives : ' . NOM . ', ' . count($conversations) . ' conversations, ' . count($profils) . ' assistants personnels, '
    . count($bases) . ' bases, ' . count($workflows) . " workflows ; et l'administrateur " . ADMIN_NOM . ".\n";
