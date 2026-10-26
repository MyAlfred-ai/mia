<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bouton « Envoyer » de la carte de relecture d'un email (Gmail, Outlook) :
 * l'email part réellement, sur le seul clic de l'utilisateur, après une
 * confirmation dans la carte.
 *
 * Ce que gardent ces tests, et pourquoi :
 *  - l'envoi est un GESTE DE L'UTILISATEUR, jamais un outil du modèle : les
 *    services n'envoient que depuis la décision `send` de driveAction, après
 *    re-vérification du droit ; une décision `send` sur une autre action est
 *    refusée AVANT toute réservation ;
 *  - ce qui part est EXACTEMENT ce que la carte montrait : Gmail assemble le
 *    message par le même chemin que le brouillon, Outlook envoie le brouillon
 *    qu'il vient de créer (`/messages/{id}/send`), jamais `/me/sendMail` ;
 *  - le droit : `gmail.compose` couvre l'envoi (Google : « Manage drafts and
 *    send emails »), `Mail.Send` est demandé côté Microsoft et exigé AVEC
 *    Mail.ReadWrite — une connexion antérieure ne l'a pas, la carte le dit ;
 *  - un envoi refusé avant exécution REND l'action à l'attente (release) : le
 *    brouillon reste possible, la carte se rouvre avec la raison.
 */
final class MailSendTest extends TestCase
{
    private static function lire(string $chemin): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($chemin, '/'));
    }

    /** Source d'un fichier PHP, commentaires retirés (garde textuelle honnête). */
    private static function codeSansCommentaires(string $chemin): string
    {
        $out = '';
        foreach (token_get_all(self::lire($chemin)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** Instance Gmail sur des scopes donnés, sans base ni réseau (constructeur protégé du socle). */
    private static function gmail(string $scopes): UserGmailService
    {
        return new class(7, 'refresh', null, null, $scopes, 'alex@ex.fr') extends UserGmailService {
            public function __construct(int $u, string $r, ?string $a, ?string $e, string $s, string $m)
            {
                parent::__construct($u, $r, $a, $e, $s, $m);
            }
        };
    }

    /** Instance Outlook sur des scopes donnés, sans base ni réseau. */
    private static function outlook(string $scopes): UserOutlookService
    {
        return new class(7, 'refresh', null, null, $scopes, 'alex@ex.fr') extends UserOutlookService {
            public function __construct(int $u, string $r, ?string $a, ?string $e, string $s, string $m)
            {
                parent::__construct($u, $r, $a, $e, $s, $m);
            }
        };
    }

    // ---------------------------------------------------------------------
    // 1. Le droit d'envoi, par messagerie
    // ---------------------------------------------------------------------

    public function testGmailEnvoieAvecLeScopeDuBrouillonEtJamaisGmailSend(): void
    {
        // gmail.compose = « Manage drafts and send emails » : brouillon et
        // envoi vont ensemble, gmail.send n'ajouterait rien.
        $compose = self::gmail(UserGmailService::SCOPE_READONLY . ' ' . UserGmailService::SCOPE_COMPOSE);
        $this->assertTrue($compose->canDraft());
        $this->assertTrue($compose->canSend());

        $lecture = self::gmail(UserGmailService::SCOPE_READONLY);
        $this->assertFalse($lecture->canDraft());
        $this->assertFalse($lecture->canSend());

        $this->assertContains(UserGmailService::SCOPE_COMPOSE, GoogleGmailOAuth::SCOPES);
        $this->assertNotContains('https://www.googleapis.com/auth/gmail.send', GoogleGmailOAuth::SCOPES);
    }

    public function testOutlookExigeMailSendETLeBrouillon(): void
    {
        $complet = self::outlook('Mail.Read Mail.ReadWrite Mail.Send User.Read');
        $this->assertTrue($complet->canDraft());
        $this->assertTrue($complet->canSend());

        // Connexion ANTÉRIEURE au bouton : Mail.Send absent → brouillon seul.
        $ancienne = self::outlook('Mail.Read Mail.ReadWrite User.Read');
        $this->assertTrue($ancienne->canDraft());
        $this->assertFalse($ancienne->canSend());

        // Mail.Send seul ne sert à rien : l'envoi passe par un brouillon créé d'abord.
        $envoiSeul = self::outlook('Mail.Read Mail.Send');
        $this->assertFalse($envoiSeul->canSend());

        // Le piège de préfixe vaut ici aussi : Mail.Send n'est pas Mail.SendBasic.
        $this->assertFalse(self::outlook('Mail.ReadWrite Mail.SendBasic')->canSend());

        $this->assertContains('Mail.Send', MicrosoftOutlookOAuth::SCOPES);
        // Et l'écran d'administration le déclare aux administrateurs Entra
        // (liste lue aux SCOPES, jamais recopiée).
        $this->assertContains('Mail.Send', ConnectorRegistry::delegatedScopes('microsoft')['Outlook']);
    }

    // ---------------------------------------------------------------------
    // 2. Ce qui part est ce que la carte montrait
    // ---------------------------------------------------------------------

    public function testGmailAssembleBrouillonEtEnvoiParLeMemeChemin(): void
    {
        $src = self::codeSansCommentaires('src/Gmail/UserGmailService.php');

        // Un seul point d'envoi, et le droit vérifié avant.
        $this->assertSame(1, substr_count($src, "'/messages/send'"));
        $this->assertLessThan(
            strpos($src, "'/messages/send'"),
            strpos($src, 'if (!$this->canSend())'),
            'le droit se vérifie avant l\'appel'
        );
        // createDraft ET sendMessage passent par composeMessage : contexte de
        // réponse, signature et MIME identiques — deux assemblages divergeraient.
        $this->assertSame(2, substr_count($src, '$this->composeMessage('), 'brouillon + envoi');
        $this->assertSame(1, substr_count($src, 'private function composeMessage('));
    }

    public function testGmailLieLeMessageEnvoyeDepuisLeDossierEnvoyes(): void
    {
        $this->assertSame(
            'https://mail.google.com/mail/u/alex%40ex.fr/#sent/18c3f4a2b1c9d0e1',
            UserGmailService::sentUrl('alex@ex.fr', '18c3f4a2b1c9d0e1')
        );
        // Identifiant absent ou d'une autre forme : le dossier suffit, jamais
        // une adresse inventée.
        $this->assertSame('https://mail.google.com/mail/u/alex%40ex.fr/#sent', UserGmailService::sentUrl('alex@ex.fr', ''));
        $this->assertSame('https://mail.google.com/mail/#sent', UserGmailService::sentUrl('', 'pas-un-id!'));
        $this->assertSame('https://mail.google.com/mail/u/alex%40ex.fr/', UserGmailService::mailboxUrl('alex@ex.fr'));
    }

    public function testOutlookEnvoieLeBrouillonQuIlVientDeCreer(): void
    {
        $src = self::codeSansCommentaires('src/Outlook/UserOutlookService.php');

        $this->assertStringContainsString("rawurlencode(\$draftId) . '/send'", $src);
        $this->assertStringNotContainsString('sendMail', $src);
        // Le brouillon est créé par le MÊME createDraft que le bouton
        // « Créer le brouillon » — réponse dans le fil et pièces jointes comprises.
        $envoi = substr($src, (int)strpos($src, 'public function sendMessage('));
        $this->assertStringContainsString('$this->createDraft($to, $subject, $body, $cc, $replyToMessageId, $attachments)', $envoi);
        $this->assertLessThan(strpos($envoi, '$this->createDraft('), strpos($envoi, 'if (!$this->canSend())'));
        // Un échec après la création dit que le brouillon est resté.
        $this->assertStringContainsString('self::MSG_DRAFT_KEPT', $envoi);
        // Un 403 « permission » nomme Mail.Send, pas Mail.ReadWrite.
        $this->assertStringContainsString('self::MSG_RECONNECT_SEND', $envoi);
        $this->assertStringContainsString('Mail.Send', UserOutlookService::MSG_RECONNECT_SEND);
    }

    public function testOutlookRenvoieVersLesElementsEnvoyesDuBonDomaine(): void
    {
        $this->assertSame(
            'https://outlook.live.com/mail/0/sentitems',
            UserOutlookService::sentItemsUrl('https://outlook.live.com/mail/0/deeplink/read/AAMk…')
        );
        $this->assertSame(
            'https://outlook.office.com/mail/sentitems',
            UserOutlookService::sentItemsUrl('https://outlook.office.com/owa/?ItemID=AAMk…&viewmodel=ReadMessageItem')
        );
        // Lien absent ou illisible : le domaine professionnel, jamais une erreur.
        $this->assertSame('https://outlook.office.com/mail/sentitems', UserOutlookService::sentItemsUrl(''));
    }

    public function testUnPostSansParametreVersGraphEmporteUnObjetVide(): void
    {
        // `[]` → `{}` : une action Graph sans paramètre (`/send`) veut un corps
        // bien formé — sans Content-Length, le front Azure répond 411, et `[]`
        // encodé tel quel serait un tableau là où l'API attend un objet.
        $src = self::lire('src/Connectors/ConnectorApiService.php');
        $this->assertStringContainsString("\$body === [] ? '{}' :", $src);
    }

    // ---------------------------------------------------------------------
    // 3. La disponibilité annoncée dans la carte
    // ---------------------------------------------------------------------

    public function testLaDisponibiliteDeLEnvoiSEcritDansLeBlocMail(): void
    {
        $preview = UserGmailService::actionPreview('creer_brouillon_gmail', ['destinataire' => 'a@ex.fr', 'objet' => 'X', 'corps' => 'Bonjour']);

        $ok = MailDraftReview::withSendAvailability($preview, true);
        $this->assertSame(['possible' => true], $ok['mail']['envoi']);

        $non = MailDraftReview::withSendAvailability($preview, false, 'Reconnectez Outlook.');
        $this->assertFalse($non['mail']['envoi']['possible']);
        $this->assertSame('Reconnectez Outlook.', $non['mail']['envoi']['raison']);
        // Sans raison fournie : une phrase, jamais un bouton muet.
        $this->assertNotSame('', MailDraftReview::withSendAvailability($preview, false)['mail']['envoi']['raison']);

        // Un aperçu sans bloc mail (autre connecteur) est rendu tel quel.
        $autre = ['action' => 'x', 'fichier' => 'y'];
        $this->assertSame($autre, MailDraftReview::withSendAvailability($autre, true));
    }

    // ---------------------------------------------------------------------
    // 4. Câblage serveur
    // ---------------------------------------------------------------------

    public function testLaDecisionSendNExisteQuePourUnEmailEtSeVerifieAvantLaReservation(): void
    {
        $chat  = self::lire('src/Controllers/ChatController.php');
        $debut = strpos($chat, 'public function driveAction(');
        $fin   = strpos($chat, 'private function resolveMailAction(');
        $this->assertNotFalse($debut);
        $this->assertNotFalse($fin);
        $action = substr($chat, (int)$debut, (int)$fin - (int)$debut);

        $this->assertStringContainsString("['approve', 'cancel', 'send']", $action);
        // Le contrôle « c'est un email ? » précède le claim : un POST forgé
        // `send` sur une autre action la consommerait sinon.
        $peek  = strpos($action, "if (\$decision === 'send') {");
        $claim = strpos($action, 'DrivePendingAction::claim(');
        $this->assertNotFalse($peek);
        $this->assertLessThan($claim, $peek);
        $this->assertStringContainsString('MailDraftReview::isMailTool((string)$peek[\'tool\'])', $action);
        $this->assertStringContainsString('DrivePendingAction::findPending(', $action);
        // Les deux messageries passent par la même exécution.
        $this->assertStringContainsString('$this->resolveMailAction($mailService, $decision,', $action);
    }

    public function testLEnvoiEstUnGesteDeLUtilisateurJamaisUnOutilDuModele(): void
    {
        $chat = AgentSource::chat();

        // sendMessage n'est appelée QUE dans resolveMailAction.
        $this->assertSame(1, substr_count($chat, '->sendMessage('), 'un seul appel d\'envoi dans tout le contrôleur');
        $bloc = substr($chat, (int)strpos($chat, 'private function resolveMailAction('));
        $bloc = substr($bloc, 0, (int)strpos($bloc, 'private function executeDriveWrite('));
        $this->assertStringContainsString('->sendMessage(', $bloc);
        // Droit re-vérifié au moment T ; refusé AVANT exécution = action rendue
        // à l'attente (release) et carte rouverte (retryable).
        $canSend = strpos($bloc, 'if (!$svc->canSend())');
        $release = strpos($bloc, 'DrivePendingAction::release($actionId);');
        $envoi   = strpos($bloc, '->sendMessage(');
        $this->assertNotFalse($canSend);
        $this->assertNotFalse($release);
        $this->assertLessThan($release, $canSend);
        $this->assertLessThan($envoi, $release);
        $this->assertStringContainsString("'retryable' => true", $bloc);
        // Issue persistée dans le fil, brouillon et envoi distingués.
        $this->assertStringContainsString("Email envoyé via {service} à {destinataire}", $bloc);
        $this->assertStringContainsString("Échec de l'envoi {service} : {erreur}", $bloc);
        $this->assertStringContainsString("Brouillon Gmail créé", $bloc);
        $this->assertStringContainsString("Brouillon Outlook créé", $bloc);

        // Aucun outil du modèle n'envoie : les définitions d'outils restent
        // celles de la préparation, et les consignes le disent.
        $this->assertStringNotContainsString("'name'        => 'envoyer_email", $chat);
        $this->assertSame(2, substr_count($chat, "Tu n'envoies JAMAIS "), 'Gmail et Outlook');
        // Le modèle sait que la carte offre les deux issues.
        $this->assertStringContainsString("de l'ENVOYER directement", $chat);
    }

    public function testLaDisponibiliteEstPoseeAuDepotEtAChaqueRevision(): void
    {
        $chat = self::lire('src/Controllers/ChatController.php');
        $this->assertSame(2, substr_count($chat, '$this->mailSendAvailability('), 'dépôt (queueConnectorWrite) + révision (reviseMailDraft)');
        $this->assertSame(2, substr_count($chat, 'MailDraftReview::withSendAvailability('));
        // Au dépôt : AVANT DrivePendingAction::create, sinon l'aperçu persisté ne la porte pas.
        // (Le dépôt est partagé par la carte et l'exécution sans validation d'une tâche.)
        $depot = substr($chat, (int)strpos($chat, '$depositConnectorWrite = function'));
        $this->assertLessThan(
            strpos($depot, 'DrivePendingAction::create((int)$user[\'id\'], $convId, $tool, $args, $preview)'),
            strpos($depot, 'MailDraftReview::withSendAvailability(')
        );
        // Une action rendue à l'attente ne touche qu'une action réservée.
        $model = self::lire('src/Models/DrivePendingAction.php');
        $this->assertStringContainsString("SET status = \\'pending\\' WHERE id = ? AND status = \\'processing\\'", $model);
    }

    // ---------------------------------------------------------------------
    // 5. Câblage client, page Connexions, documentation
    // ---------------------------------------------------------------------

    public function testLaCarteDemandeConfirmationAvantDEnvoyer(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        foreach (['data-act="send"', 'data-act="send-confirm"', 'data-act="send-cancel"'] as $act) {
            $this->assertStringContainsString($act, $js, $act);
        }
        // Le bouton ouvre la confirmation ; seule la confirmation envoie.
        // (Chaque issue est RENDUE : la conversation vocale l'annonce à voix haute.)
        $this->assertStringContainsString("case 'send':        return askMailDraftSend(card);", $js);
        $this->assertStringContainsString("if (act === 'send-confirm') return await resolveDriveAction(card, 'send');", $js);
        // Un seul endroit envoie, pour le clic comme pour la voix.
        $this->assertSame(1, substr_count($js, "resolveDriveAction(card, 'send')"));
        $this->assertStringContainsString("paintMailDraftCard(card, 'send-confirm')", $js);
        // Envoi indisponible : le bouton reste, le clic explique et mène à Connexions.
        $this->assertStringContainsString("'/settings/connections#connector-' + service", $js);
        // Un refus avant exécution rouvre la carte en relecture avec la raison.
        $this->assertStringContainsString("paintMailDraftCard(card, 'preview', { error: msg });", $js);
        // Aucune touche ne confirme un envoi : Échap seulement, en mode confirmation.
        $confirm = substr($js, (int)strpos($js, "card.dataset.mode === 'send-confirm' && e.key === 'Escape'"), 200);
        $this->assertStringNotContainsString("'Enter'", $confirm);

        $css = self::lire('public/assets/css/main.css');
        foreach (['.mail-draft-btn-send ', '.mail-draft-btn-soft ', '.mail-draft-send-confirm ', '.mail-draft-send-question '] as $sel) {
            $this->assertStringContainsString($sel, $css, $sel);
        }
    }

    public function testLaPageConnexionsAfficheLeDroitDEnvoi(): void
    {
        $ctrl = self::lire('src/Controllers/UserSettingsController.php');
        $this->assertStringContainsString('$gmailCanSend  = $gmailCanDraft;', $ctrl);
        $this->assertStringContainsString('UserOutlookService::scopeGranted($scope, UserOutlookService::SCOPE_SEND)', $ctrl);

        $tpl = self::lire('templates/settings/connections.php');
        $this->assertSame(2, substr_count($tpl, "te('Envoi')"), 'badge Envoi sur Gmail et Outlook');
        $this->assertStringContainsString('$gmailCanSend', $tpl);
        $this->assertStringContainsString('$outlookCanSend', $tpl);
        // Une connexion Outlook sans Mail.Send propose la reconnexion.
        $this->assertStringContainsString("(!\$outlookCanRead || !\$outlookCanDraft || empty(\$outlookCanSend))", $tpl);
        // Plus aucune promesse « jamais envoyé » : elle serait fausse.
        $this->assertStringNotContainsString("Aucun email n\\'est jamais envoyé", $tpl);
    }

    public function testLesGuidesEtLesNouveautesLeDisent(): void
    {
        $guide = self::lire('docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString('**Envoyer**', $guide);
        $this->assertStringContainsString('**Confirmer l\'envoi**', $guide);
        $this->assertStringNotContainsString("L'assistant ne peut JAMAIS envoyer d'email", $guide);

        $this->assertStringContainsString('envoi-email-carte', self::lire('docs/NOUVEAUTES.md'));

        $wiki = self::lire('docs/user/fonctionnalites/connecteurs/utiliser-un-connecteur.md');
        $this->assertStringContainsString('**Envoyer**', $wiki);
        $this->assertStringContainsString('**Confirmer l\'envoi**', $wiki);

        $index = self::lire('docs/user/fonctionnalites/connecteurs/index.md');
        $this->assertStringNotContainsString("MIA n'a aucune fonction d'envoi", $index);

        // Les administrateurs voient la permission à déclarer, et son usage.
        $entra = self::lire('docs/user/admin/connecteurs/autoriser-mia-dans-microsoft-entra-id.md');
        $this->assertStringContainsString('`Mail.Send`', $entra);
        $this->assertStringNotContainsString('aucun mail ne peut', $entra);
        $admin = self::lire('docs/GUIDE-ADMIN.md');
        $this->assertStringContainsString('`Mail.Send`', $admin);
        $this->assertStringNotContainsString("`Mail.Send` n'est jamais demandé", $admin);
        $this->assertStringNotContainsString("`gmail.send` n'est jamais demandé** : structurellement", $admin);
        $this->assertStringNotContainsString("l'envoi est impossible, pas interdit", self::lire('README.md'));
    }
}
