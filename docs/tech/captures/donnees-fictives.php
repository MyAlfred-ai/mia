<?php
declare(strict_types=1);

/**
 * Le jeu de données FICTIF des captures : Camille Martin, sept conversations, deux assistants et
 * deux bases de connaissances partagées. Tout est inventé — personnes, entreprise, projet.
 *
 *   php -d session.save_path=<dossier> docs/tech/captures/donnees-fictives.php   (lancé par lancer.sh)
 *
 * Il écrit aussi la session PHP de Camille Martin dans ce dossier : l'outil de capture ouvre MIA
 * avec le cookie `myLLM_session` qui la désigne, sans mot de passe. MIA garde l'utilisateur
 * connecté dans `$_SESSION['user']` et le relit en base à chaque page (AuthMiddleware::refreshUser) :
 * son identifiant suffit.
 *
 * Les dates partent de maintenant. Idempotent : tout ce qu'il écrit est effacé puis réécrit.
 */

const EMAIL = 'camille.martin@example.com';
const NOM = 'Camille Martin';
// Doit être la valeur du cookie posé par ecran-principal.capture.mjs.
const SESSION = 'capturesdocumentation';
// L'adresse de la conversation ouverte sur la capture : /chat/101.
const CONVERSATION_OUVERTE = 101;

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
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([EMAIL]);
$pdo->exec("DELETE FROM profiles WHERE name IN ('Rédaction commerciale', 'Conseil RH') AND created_by IS NULL");
$pdo->exec("DELETE FROM knowledge_bases WHERE name IN ('Procédures qualité', 'Catalogue produits 2026') AND created_by IS NULL");

// ─── L'utilisatrice : un compte ordinaire, actif, en français ─────────────────
// Un compte Google ou Microsoft arrive avec sa photo ; sans elle, les messages portent un « ? ».
// Ses initiales dans un disque, dessinées ici : aucune image à aller chercher.
$avatar = 'data:image/svg+xml;base64,' . base64_encode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><circle cx="48" cy="48" r="48" fill="#7c3aed"/>'
    . '<text x="48" y="61" font-family="Arial, sans-serif" font-size="36" font-weight="700" fill="#fff" text-anchor="middle">CM</text></svg>'
);
$pdo->prepare('INSERT INTO users (email, name, avatar_url, role, is_active, lang, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)')
    ->execute([EMAIL, NOM, $avatar, 'user', 'fr', $date(40, '09:00')]);
$userId = (int) $pdo->lastInsertId();

// ─── Le modèle : un compte sans groupe ne voit que le modèle par défaut de l'instance ─
$pdo->exec("INSERT IGNORE INTO llm_models (provider, model, display_name, is_active, sort_order)
            VALUES ('anthropic', 'claude-opus-5', 'Claude Opus 5', 1, 0)");
$pdo->exec("REPLACE INTO settings (`key`, value) VALUES ('default_llm', 'anthropic')");
$modele = ['anthropic', 'claude-opus-5'];

// ─── Deux assistants de l'organisation, visibles de tous ──────────────────────
$assistant = $pdo->prepare('INSERT INTO profiles (name, description, system_prompt, is_active, is_global, color, created_at)
                            VALUES (?, ?, ?, 1, 1, ?, ?)');
$assistant->execute(['Rédaction commerciale', 'Propositions, relances et comptes rendus clients.',
    'Tu aides à rédiger des documents commerciaux clairs et courtois.', '#2563eb', $date(30, '10:00')]);
$assistant->execute(['Conseil RH', 'Congés, accueil des nouveaux arrivants, entretiens.',
    'Tu réponds aux questions RH à partir des règles de l’entreprise.', '#16a34a', $date(30, '10:05')]);

// ─── Deux bases de connaissances, partagées avec elle ─────────────────────────
$base = $pdo->prepare("INSERT INTO knowledge_bases (name, description, embedding_provider, embedding_model, embedding_dims, created_at)
                       VALUES (?, ?, 'openai', 'text-embedding-3-small', 1536, ?)");
$partage = $pdo->prepare('INSERT INTO knowledge_base_shares (kb_id, user_id, can_manage) VALUES (?, ?, 0)');
foreach ([['Procédures qualité', 'Les procédures et formulaires du service qualité.'],
          ['Catalogue produits 2026', 'Fiches produits, tarifs publics et conditions de vente.']] as [$nom, $description]) {
    $base->execute([$nom, $description, $date(25, '11:00')]);
    $partage->execute([(int) $pdo->lastInsertId(), $userId]);
}

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

$conversations = [
    // [id, titre, jours, heure, épinglée, question, réponse]
    [CONVERSATION_OUVERTE, 'Ordre du jour du lancement Atlas', 0, '09:40', false,
        'Prépare l’ordre du jour de la réunion de lancement du projet Atlas : une heure, huit participants, dont deux personnes du client.',
        $reponseAtlas],
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
    [106, 'Planning des congés d’été', 15, '09:05', false,
        'Construis un tableau des congés d’été pour une équipe de six personnes.',
        "| Semaine | Absents | Présents |\n|---|---|---|\n| 28 | Léa, Thomas | 4 |"],
    [107, 'Mail de bienvenue aux nouveaux arrivants', 22, '17:30', false,
        'Écris un mail de bienvenue pour les deux personnes qui arrivent lundi.',
        "Bonjour à tous les deux,\n\nToute l’équipe est ravie de vous accueillir lundi…"],
];

$conversation = $pdo->prepare('INSERT INTO conversations (id, user_id, llm_used, title, created_at, updated_at, is_pinned, pinned_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$message = $pdo->prepare('INSERT INTO messages (conversation_id, role, llm_provider, llm_model, response_ms, content, tokens_used, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($conversations as [$id, $titre, $jours, $heure, $epinglee, $question, $reponse]) {
    $debut = $date($jours, $heure);
    $fin = (new DateTimeImmutable($debut))->modify('+1 minute')->format('Y-m-d H:i:s');
    $conversation->execute([$id, $userId, implode('::', $modele), $titre, $debut, $fin, $epinglee ? 1 : 0, $epinglee ? $fin : null]);
    $message->execute([$id, 'user', null, null, null, $question, 0, $debut]);
    // Pas de temps de réponse : son étiquette affiche aujourd'hui « 6,4&nbsp;s » en toutes lettres
    // (te() échappe l'entité, templates/partials/message.php). À remettre une fois corrigé.
    $message->execute([$id, 'assistant', $modele[0], $modele[1], null, $reponse, 900, $fin]);
}

// ─── La session : Camille Martin connectée, sans mot de passe ─────────────────
session_id(SESSION);
session_start();
$_SESSION['user'] = ['id' => $userId, 'email' => EMAIL, 'name' => NOM, 'role' => 'user', 'is_active' => 1];
session_write_close();

echo "Données fictives : " . NOM . ", " . count($conversations) . " conversations, 2 assistants, 2 bases.\n";
