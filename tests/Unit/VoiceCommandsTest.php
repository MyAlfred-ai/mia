<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Ordres dits à voix haute en conversation vocale.
 *
 * En conversation vocale, les boutons d'une carte en attente se DISENT
 * (« approuve », « envoie-le », « plus court », « confirme ») et la lecture
 * s'interrompt d'un « stop ». La reconnaissance vit dans
 * public/assets/js/voice-commands.js, module PUR : il est exécuté ici sous
 * node, phrase par phrase.
 *
 * Quatre choses à garder :
 *   1. la PRUDENCE : un ordre n'est reconnu que si la phrase entière est faite
 *      de mots attendus ; un « oui » nu n'approuve rien ; un email ne s'envoie
 *      qu'en deux temps. Une commande reconnue à tort enverrait un email ;
 *   2. pendant la lecture, SEUL un ordre d'arrêt compte — et la voix de MIA,
 *      revenue par le micro, n'en est pas un ;
 *   3. ce que MIA AFFICHE ou DIT de dire est reconnu, dans les deux langues —
 *      sinon elle demanderait de prononcer un mot qu'elle n'entend pas ;
 *   4. le câblage : la voix passe par le code du clic, le serveur ne synthétise
 *      que des phrases de sa liste, le modèle n'obtient aucun moyen d'agir.
 *
 * Sans node (conteneur applicatif), les tests d'exécution sont SKIPPÉS ; ils
 * tournent sur le poste de développement et en CI.
 */
final class VoiceCommandsTest extends TestCase
{
    private const APPROBATION = ['kind' => 'approval', 'title' => 'Créer un évènement Google Agenda'];

    private static function lire(string $relatif): string
    {
        $chemin = ROOT . '/' . $relatif;
        self::assertFileExists($chemin);
        return (string)file_get_contents($chemin);
    }

    private static function chatController(): string
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        require_once ROOT . '/src/Controllers/ChatController.php';
        return self::lire('src/Controllers/ChatController.php');
    }

    /** Carte de relecture d'un email, dans un mode donné. */
    private static function mail(string $mode = 'preview', bool $canUndo = false): array
    {
        return [
            'kind'       => 'mail',
            'mode'       => $mode,
            'recipients' => 'Jean Dupont <jean.dupont@acme.fr>',
            'presets'    => array_keys(MailDraftReview::PRESETS),
            'canUndo'    => $canUndo,
        ];
    }

    // ------------------------------------------------------------------
    // 1. Carte d'approbation
    // ------------------------------------------------------------------

    public function testApprouverOuAnnulerDemandeLeMotDeLAction(): void
    {
        $approuve = ['Approuve.', 'Oui, approuve', 'Vas-y, valide-le', 'Je confirme', 'Accepte', 'Fais-le',
            'Ok, lance', 'Approuve-la s\'il te plaît', 'Approve', 'Confirm it', 'Do it'];
        $annule = ['Annule', 'Ok annule', 'Non, annule', 'Laisse tomber', 'Abandonne', 'Oublie ça', 'Je refuse',
            'Cancel', 'Never mind'];
        // Un accord ou un refus NU ne décide de rien : MIA redit quoi dire.
        $nu = ['Oui', 'OK', 'D\'accord', 'Vas-y', 'C\'est bon', 'Non', 'Non merci', 'Non, vas-y', 'Yes', 'No', 'Oui oui'];
        // Ni négation, ni demande d'autre chose : ce sont des messages.
        $message = ['Ne l\'approuve pas', 'Je n\'approuve pas', 'N\'annule pas', 'Ajoute aussi Paul', 'Merci',
            'Approuve et préviens Paul', 'Change l\'heure à 15 h', 'Pas encore', 'Don\'t approve', 'Quelle heure est-il ?'];

        $r = self::cartes(array_merge($approuve, $annule, $nu, $message), self::APPROBATION);
        foreach ($approuve as $p) {
            $this->assertSame('approve', $r[$p]['act'] ?? null, "« $p » doit approuver");
        }
        foreach ($annule as $p) {
            $this->assertSame('cancel', $r[$p]['act'] ?? null, "« $p » doit annuler");
        }
        foreach ($nu as $p) {
            $this->assertSame('reask', $r[$p]['act'] ?? null, "« $p », seul, ne doit RIEN approuver ni annuler");
        }
        foreach ($message as $p) {
            $this->assertNull($r[$p], "« $p » est un message pour l'assistant, pas un ordre");
        }
    }

    public function testLeVerbeDeLaCarteNApprouveQueSaCarte(): void
    {
        $creer     = self::cartes(['Crée-le', 'Crée l\'évènement', 'Supprime-la', 'Publie'], self::APPROBATION);
        $supprimer = self::cartes(['Supprime-la', 'Crée-le'], ['kind' => 'approval', 'title' => 'Supprimer une tâche Google']);
        $publier   = self::cartes(['Publie', 'Publie le message'], ['kind' => 'approval', 'title' => 'Publier un message Slack']);

        $this->assertSame('approve', $creer['Crée-le']['act'] ?? null);
        $this->assertSame('approve', $creer['Crée l\'évènement']['act'] ?? null);
        $this->assertNull($creer['Supprime-la'], '« supprime-la » n\'approuve pas une création');
        $this->assertNull($creer['Publie']);
        $this->assertSame('approve', $supprimer['Supprime-la']['act'] ?? null);
        $this->assertNull($supprimer['Crée-le'], '« crée-le » n\'approuve pas une suppression');
        $this->assertSame('approve', $publier['Publie']['act'] ?? null);
        $this->assertSame('approve', $publier['Publie le message']['act'] ?? null);
    }

    /**
     * Un ordre ne désigne que ce que SA carte porte. « Crée une tâche » pendant
     * qu'un évènement attend est une nouvelle demande, pas l'approbation de
     * l'évènement ; « annule la réunion » n'abandonne pas un email en attente.
     * Avec une liste de noms commune à toutes les cartes, ces phrases
     * approuvaient ou annulaient la mauvaise chose.
     */
    public function testUnOrdreNeDesigneQueCeQueSaCartePorte(): void
    {
        $evenement = self::cartes(['Crée une tâche', 'Exécute le workflow', 'Annule la tâche', 'Valide ce document',
            'Crée l\'évènement', 'Annule l\'évènement', 'Approuve cette action'], self::APPROBATION);
        foreach (['Crée une tâche', 'Exécute le workflow', 'Annule la tâche', 'Valide ce document'] as $p) {
            $this->assertNull($evenement[$p], "« $p » ne vise pas la carte « évènement » en attente");
        }
        $this->assertSame('approve', $evenement['Crée l\'évènement']['act'] ?? null);
        $this->assertSame('cancel', $evenement['Annule l\'évènement']['act'] ?? null);
        $this->assertSame('approve', $evenement['Approuve cette action']['act'] ?? null);

        $tache = self::cartes(['Crée une tâche', 'Crée la tâche', 'Crée l\'évènement'], ['kind' => 'approval', 'title' => 'Créer une tâche Google']);
        $this->assertSame('approve', $tache['Crée une tâche']['act'] ?? null);
        $this->assertSame('approve', $tache['Crée la tâche']['act'] ?? null);
        $this->assertNull($tache['Crée l\'évènement']);

        $mail = self::cartes(['Annule la réunion', 'Annule le rendez-vous', 'Supprime le fichier', 'Annule le mail', 'Envoie le message'], self::mail());
        foreach (['Annule la réunion', 'Annule le rendez-vous', 'Supprime le fichier'] as $p) {
            $this->assertNull($mail[$p], "« $p » ne vise pas l'email en attente");
        }
        $this->assertSame(['act' => 'discard'], $mail['Annule le mail']);
        $this->assertSame(['act' => 'send'], $mail['Envoie le message']);
    }

    /**
     * Chaque carte d'approbation livrée s'approuve par le verbe de son
     * intitulé, en français comme en anglais. Un intitulé ajouté qui ne
     * commencerait pas par un verbe reconnu le ferait tomber ici.
     */
    public function testChaqueIntituleDeCarteSApprouveParSonVerbe(): void
    {
        $intitules = [];
        foreach ([
            'src/Drive/UserDriveService.php', 'src/Calendar/UserCalendarService.php',
            'src/Outlook/UserOutlookCalendarService.php', 'src/Slack/UserSlackService.php',
            'src/Tasks/UserTasksService.php', 'src/Gamma/GammaService.php', 'src/N8n/N8nService.php',
            'src/MyBrain/MyBrainFeed.php',
        ] as $fichier) {
            foreach (explode("\n", self::lire($fichier)) as $ligne) {
                if (!preg_match('/\'action\'\s*=>|\$action\s*=/', $ligne)) {
                    continue;
                }
                if (preg_match_all('/\bt\(\'((?:[^\'\\\\]|\\\\.)+)\'\)/', $ligne, $m)) {
                    foreach ($m[1] as $titre) {
                        $intitules[stripslashes($titre)] = true;
                    }
                }
            }
        }
        $intitules = array_keys($intitules);
        $this->assertGreaterThanOrEqual(15, count($intitules), 'Intitulés de cartes introuvables : la lecture des services a-t-elle changé ?');

        $dict   = (array)(require ROOT . '/lang/en.php');
        $appels = [];
        foreach ($intitules as $fr) {
            $appels[] = ['fn' => 'forms', 'text' => explode(' ', $fr)[0]];
        }
        $formes = self::js($appels);

        $appels = [];
        foreach ($intitules as $i => $fr) {
            $this->assertGreaterThan(1, count($formes[$i]), "« $fr » : le premier mot n'est pas un verbe reconnu");
            $appels[] = ['fn' => 'card', 'text' => $formes[$i][1], 'ctx' => ['kind' => 'approval', 'title' => $fr]];
            $this->assertArrayHasKey($fr, $dict, "« $fr » n'a pas de traduction");
            $en = (string)$dict[$fr];
            $appels[] = ['fn' => 'card', 'text' => explode(' ', $en)[0], 'ctx' => ['kind' => 'approval', 'title' => $en]];
        }
        foreach (self::js($appels) as $i => $rendu) {
            $titre = $intitules[intdiv($i, 2)];
            $this->assertSame('approve', $rendu['act'] ?? null, "« $titre » (" . ($i % 2 ? 'anglais' : 'français') . ') : son verbe doit l\'approuver');
        }
    }

    public function testToutApprouverSeDitEtToutDeSuiteNEstPasTout(): void
    {
        $r = self::cartes(['Approuve tout', 'Valide les deux', 'Annule tout', 'Approve all', 'Approuve tout de suite', 'Approuve'], self::APPROBATION);
        $this->assertSame(['act' => 'approve', 'all' => true], $r['Approuve tout']);
        $this->assertSame(['act' => 'approve', 'all' => true], $r['Valide les deux']);
        $this->assertSame(['act' => 'cancel', 'all' => true], $r['Annule tout']);
        $this->assertSame(['act' => 'approve', 'all' => true], $r['Approve all']);
        $this->assertSame(['act' => 'approve', 'all' => false], $r['Approuve tout de suite'], '« tout de suite » n\'est pas « tout »');
        $this->assertSame(['act' => 'approve', 'all' => false], $r['Approuve']);
    }

    // ------------------------------------------------------------------
    // 2. Carte de relecture d'un email
    // ------------------------------------------------------------------

    public function testUnEmailNeSEnvoieQuEnDeuxTempsEtJamaisSurUnOui(): void
    {
        // Premier temps : « envoie » ouvre la confirmation (act send), rien d'autre.
        $envoie = ['Envoie-le', 'Envoyer', 'Tu peux l\'envoyer', 'Oui, envoie', 'Vas-y envoie-le', 'Envoie-le à Jean',
            'Envoie le mail maintenant', 'Envoie le brouillon', 'Send it'];
        $pas = ['Oui', 'Ok', 'Vas-y', 'Non', 'Ne l\'envoie pas', 'N\'envoie rien', 'Envoie-le à Paul', 'Envoie-le à Paul plutôt',
            'Envoie-moi un résumé', 'Envoie-le demain', 'Non, envoie-le', 'Don\'t send it'];
        $r = self::cartes(array_merge($envoie, $pas), self::mail());
        foreach ($envoie as $p) {
            $this->assertSame(['act' => 'send'], $r[$p], "« $p » doit ouvrir la confirmation d'envoi");
        }
        foreach ($pas as $p) {
            $this->assertNull($r[$p], "« $p » ne doit PAS toucher à l'envoi");
        }

        // Second temps : la confirmation se DIT ; un accord nu redemande, un refus nu renonce.
        $c = self::cartes(['Confirme', 'Je confirme', 'Oui, confirme', 'Confirmer l\'envoi', 'Envoie', 'Confirm', 'Send it',
            'Oui', 'Ok', 'Vas-y', 'D\'accord', 'C\'est bon',
            'Non', 'Retour', 'Attends', 'Annule', 'Pas encore', 'Stop', 'Je veux relire', 'Back', 'Wait',
            'Non, en brouillon', 'Ne confirme pas', 'Change l\'objet', 'Confirme et préviens Paul'], self::mail('send-confirm'));
        foreach (['Confirme', 'Je confirme', 'Oui, confirme', 'Confirmer l\'envoi', 'Envoie', 'Confirm', 'Send it'] as $p) {
            $this->assertSame(['act' => 'confirm'], $c[$p], "« $p » confirme l'envoi");
        }
        foreach (['Oui', 'Ok', 'Vas-y', 'D\'accord', 'C\'est bon'] as $p) {
            $this->assertSame(['act' => 'reask'], $c[$p], "« $p », seul, ne doit JAMAIS envoyer un email");
        }
        foreach (['Non', 'Retour', 'Attends', 'Annule', 'Pas encore', 'Stop', 'Je veux relire', 'Back', 'Wait'] as $p) {
            $this->assertSame(['act' => 'back'], $c[$p], "« $p » renonce à l'envoi");
        }
        $this->assertSame(['act' => 'draft'], $c['Non, en brouillon']);
        foreach (['Ne confirme pas', 'Change l\'objet', 'Confirme et préviens Paul'] as $p) {
            $this->assertNull($c[$p], "« $p » n'est pas une confirmation");
        }
    }

    public function testBrouillonAbandonEtVersionPrecedente(): void
    {
        $r = self::cartes(['Crée le brouillon', 'Non, juste en brouillon', 'Mets-le en brouillon', 'En brouillon', 'Save it as a draft',
            'Pas de brouillon', 'Abandonne', 'Laisse tomber', 'Annule', 'Supprime le mail', 'Discard it', 'Oublie ça',
            'Version précédente', 'Annule la dernière modification'], self::mail());
        foreach (['Crée le brouillon', 'Non, juste en brouillon', 'Mets-le en brouillon', 'En brouillon', 'Save it as a draft'] as $p) {
            $this->assertSame(['act' => 'draft'], $r[$p], $p);
        }
        $this->assertNull($r['Pas de brouillon']);
        foreach (['Abandonne', 'Laisse tomber', 'Annule', 'Supprime le mail', 'Discard it', 'Oublie ça'] as $p) {
            $this->assertSame(['act' => 'discard'], $r[$p], $p);
        }
        // Sans version précédente, « version précédente » n'a rien à défaire.
        $this->assertNull($r['Version précédente']);
        $this->assertNull($r['Annule la dernière modification']);

        $u = self::cartes(['Version précédente', 'Reviens à la version précédente', 'Reviens en arrière',
            'Annule la dernière modification', 'Undo', 'Annule'], self::mail('preview', true));
        foreach (['Version précédente', 'Reviens à la version précédente', 'Reviens en arrière', 'Annule la dernière modification', 'Undo'] as $p) {
            $this->assertSame(['act' => 'undo'], $u[$p], $p);
        }
        $this->assertSame(['act' => 'discard'], $u['Annule'], '« annule » seul abandonne le brouillon, il ne défait pas une retouche');
    }

    public function testUneRetoucheVisibleSeDitUneDemandeEtrangereRepartVersLAssistant(): void
    {
        $r = self::cartes([
            'Améliore', 'Améliore-le',
            'Plus court', 'Rends-le un peu plus court', 'Fais-le plus formel', 'Corrige les fautes', 'Make it friendlier', 'Shorter',
            'Moins formel', 'Plus court et plus formel', 'Rends-le plus poli', 'Reformule la deuxième phrase',
            'Change l\'objet en Réunion de jeudi', 'Modifie le ton', 'Supprime la dernière phrase', 'Fix the subject',
            'Change le rendez-vous de demain', 'Ajoute une tâche plus urgente', 'Fais une recherche plus poussée',
            'Ajoute que je serai là à 10 h', 'Plus tard', 'Change-le', 'Supprime le message Slack', 'Quel temps fait-il ?',
        ], self::mail());

        $this->assertSame(['act' => 'improve'], $r['Améliore']);
        $this->assertSame(['act' => 'improve'], $r['Améliore-le']);
        foreach ([
            'Plus court' => 'plus_court', 'Rends-le un peu plus court' => 'plus_court', 'Shorter' => 'plus_court',
            'Fais-le plus formel' => 'plus_formel', 'Corrige les fautes' => 'corriger', 'Make it friendlier' => 'plus_chaleureux',
        ] as $p => $cle) {
            $this->assertSame(['act' => 'preset', 'preset' => $cle], $r[$p], $p);
        }
        // Consigne libre : la phrase telle qu'elle a été dite.
        foreach (['Moins formel', 'Plus court et plus formel', 'Rends-le plus poli', 'Reformule la deuxième phrase',
            'Change l\'objet en Réunion de jeudi', 'Modifie le ton', 'Supprime la dernière phrase', 'Fix the subject'] as $p) {
            $this->assertSame(['act' => 'instruct', 'instruction' => $p], $r[$p], $p);
        }
        // « Moins formel » n'est PAS le préréglage « plus formel ».
        $this->assertNotSame('preset', $r['Moins formel']['act']);
        // Rien de tout cela ne vise clairement l'email en attente.
        foreach (['Change le rendez-vous de demain', 'Ajoute une tâche plus urgente', 'Fais une recherche plus poussée',
            'Ajoute que je serai là à 10 h', 'Plus tard', 'Change-le', 'Supprime le message Slack', 'Quel temps fait-il ?'] as $p) {
            $this->assertNull($r[$p], "« $p » doit repartir vers l'assistant");
        }

        // Panneau ouvert à la voix : MIA vient de demander quoi changer, ce qui suit EST la consigne.
        $i = self::cartes(['Propose un créneau jeudi matin', 'Plus chaleureux', 'Non', 'Annule', 'Retour', 'Abandonne', 'Envoie'], self::mail('improve'));
        $this->assertSame(['act' => 'instruct', 'instruction' => 'Propose un créneau jeudi matin'], $i['Propose un créneau jeudi matin']);
        $this->assertSame(['act' => 'preset', 'preset' => 'plus_chaleureux'], $i['Plus chaleureux']);
        foreach (['Non', 'Annule', 'Retour'] as $p) {
            $this->assertSame(['act' => 'close'], $i[$p], "« $p » referme le panneau, sans rien perdre");
        }
        $this->assertSame(['act' => 'discard'], $i['Abandonne']);
        $this->assertSame(['act' => 'send'], $i['Envoie']);
        // … sauf un adieu ou un ordre d'arrêt : ils ne réécrivent pas un email.
        $hors = self::cartes(['Au revoir', 'Stop', 'Attends une seconde', 'Merci', 'Oui'], self::mail('improve'));
        foreach (['Au revoir', 'Stop', 'Attends une seconde', 'Merci', 'Oui'] as $p) {
            $this->assertNull($hors[$p], "« $p » n'est pas une consigne de réécriture");
        }
    }

    /** Un préréglage ajouté côté serveur sans son vocabulaire resterait muet à la voix. */
    public function testChaquePrereglageSeDemandeParSonLibelle(): void
    {
        $dict   = (array)(require ROOT . '/lang/en.php');
        $appels = [];
        $cles   = [];
        foreach (MailDraftReview::PRESETS as $cle => $preset) {
            $this->assertArrayHasKey($preset['label'], $dict, "« {$preset['label']} » n'a pas de traduction");
            foreach ([$preset['label'], (string)$dict[$preset['label']]] as $libelle) {
                $appels[] = ['fn' => 'card', 'text' => $libelle, 'ctx' => self::mail()];
                $cles[]   = [$cle, $libelle];
            }
        }
        foreach (self::js($appels) as $i => $rendu) {
            [$cle, $libelle] = $cles[$i];
            $this->assertSame(['act' => 'preset', 'preset' => $cle], $rendu, "dire « $libelle » doit demander le préréglage $cle");
        }
        // Un préréglage absent de la carte n'est jamais inventé.
        $sans = self::mail();
        $sans['presets'] = ['corriger'];
        $this->assertNotSame('preset', self::cartes(['Plus court'], $sans)['Plus court']['act'] ?? null);
    }

    // ------------------------------------------------------------------
    // 3. Arrêt, fin de conversation, écho
    // ------------------------------------------------------------------

    public function testSeulUnOrdreDArretInterromptLaLecture(): void
    {
        $franc = ['Stop', 'Stop !', 'MIA, stop', 'Arrête', 'Arrête-toi s\'il te plaît', 'Tais-toi', 'Attends', 'Attends une seconde',
            'Non non stop stop', 'Tu peux arrêter', 'Arrête de parler', 'Stop, merci beaucoup', 'Silence', 'Please stop', 'Hold on', 'Shut up'];
        $doux = ['C\'est bon', 'C\'est bon merci', 'Ça suffit', 'Ok merci', 'Merci, ça ira', 'Passe', 'That\'s enough'];
        $rien = ['Merci', 'Oui', 'D\'accord', 'Intéressant', 'C\'est bon à savoir', 'Passe à la suite', 'Ah bon', 'Je vois',
            'Approuve', 'Envoie-le', 'Et pour demain ?', ''];

        $appels = [];
        foreach (array_merge($franc, $doux, $rien) as $p) {
            $appels[] = ['fn' => 'stop', 'text' => $p];
        }
        $r = array_combine(array_merge($franc, $doux, $rien), self::js($appels));

        foreach ($franc as $p) {
            $this->assertSame(['remainder' => '', 'hard' => true], $r[$p], "« $p » doit faire taire MIA");
        }
        foreach ($doux as $p) {
            $this->assertSame(['remainder' => '', 'hard' => false], $r[$p], "« $p », dit seul, doit faire taire MIA");
        }
        foreach ($rien as $p) {
            $this->assertNull($r[$p], "« $p » n'est pas un ordre d'arrêt : une remarque, un ordre de carte ou une question sont ignorés pendant la lecture");
        }
    }

    public function testUnOrdreDArretPeutPorterLaSuite(): void
    {
        $r = self::js([
            ['fn' => 'stop', 'text' => 'Stop, et pour demain ?'],
            ['fn' => 'stop', 'text' => 'Attends, je voulais dire Lyon'],
            ['fn' => 'stop', 'text' => 'Wait, what about tomorrow?'],
            ['fn' => 'stop', 'text' => 'Arrête de me vouvoyer'],
        ]);
        $this->assertSame(['remainder' => 'et pour demain ?', 'hard' => true], $r[0]);
        $this->assertSame(['remainder' => 'je voulais dire Lyon', 'hard' => true], $r[1]);
        $this->assertSame(['remainder' => 'what about tomorrow?', 'hard' => true], $r[2]);
        // Une consigne, pas un simple arrêt : la phrase ENTIÈRE repart vers l'assistant.
        $this->assertSame(['remainder' => 'Arrête de me vouvoyer', 'hard' => true], $r[3]);
    }

    /**
     * Pendant la lecture, le micro entend aussi MIA (l'annulation d'écho
     * n'est jamais parfaite, surtout quand on parle par-dessus elle). La
     * décision d'interrompre compare donc la phrase au texte en cours de
     * lecture.
     */
    public function testLaVoixDeMiaNInterromptPasMiaMaisUnStopDitParDessusSi(): void
    {
        $lu   = 'Voici le plan. Passez à la boulangerie en rentrant, puis prenez le pain. Et pour la suite, je propose de '
            . 'continuer demain quand la météo sera meilleure pour la route.';
        $cas = [
            // L'ordre, seul ou suivi d'une demande.
            'Stop'                                       => ['remainder' => ''],
            'Stop, et demain on fait quoi ?'             => ['remainder' => 'et demain on fait quoi ?'],
            'Attends, je voulais dire Lyon'              => ['remainder' => 'je voulais dire Lyon'],
            // L'utilisateur a parlé PAR-DESSUS MIA : sa voix à elle suit ou précède l'ordre — on
            // interrompt, mais ses mots à elle ne repartent jamais comme une question.
            'Stop la météo sera meilleure pour la route' => ['remainder' => ''],
            'quand la météo sera meilleure stop'         => ['remainder' => ''],
            'puis prenez le pain arrête'                 => ['remainder' => ''],
            'Il faut que ça s\'arrête'                   => ['remainder' => ''],
            // La voix de MIA seule, une remarque, une négation : rien.
            'la météo sera meilleure pour la route'      => null,
            'C\'est intéressant'                         => null,
            'Ne t\'arrête pas'                           => null,
            'Oui'                                        => null,
            'Approuve'                                   => null,
        ];
        $appels = [];
        foreach (array_keys($cas) as $p) {
            $appels[] = ['fn' => 'interrupt', 'text' => $p, 'ref' => $lu];
        }
        foreach (self::js($appels) as $i => $rendu) {
            $p = array_keys($cas)[$i];
            $this->assertSame($cas[$p], $rendu, "« $p »");
        }

        // MIA lit elle-même un mot d'arrêt : sa phrase ne l'interrompt pas…
        $avecOrdre = 'Pour finir, arrête-toi à la boulangerie en rentrant, puis prends le pain.';
        $r = self::js([
            ['fn' => 'interrupt', 'text' => 'Arrête-toi à la boulangerie en rentrant', 'ref' => $avecOrdre],
            ['fn' => 'interrupt', 'text' => 'Stop', 'ref' => $avecOrdre],
            ['fn' => 'interrupt', 'text' => 'Stop, et pour demain ?', 'ref' => $avecOrdre],
        ]);
        $this->assertNull($r[0], 'la phrase entière, ordre compris, est dans le texte lu');
        // … mais un ordre dit SEUL, ou suivi d'une vraie demande, passe toujours.
        $this->assertSame(['remainder' => ''], $r[1]);
        $this->assertSame(['remainder' => 'et pour demain ?'], $r[2]);
    }

    public function testAuRevoirTermineEtCEstToutNeTerminePas(): void
    {
        $oui = ['Au revoir', 'Merci, au revoir MIA', 'Termine la conversation', 'Termine', 'Fin de la conversation',
            'Bonne journée, à bientôt', 'À plus', 'Goodbye', 'Ok, bye'];
        // « C'est tout » répond à « autre chose ? » : ce n'est pas un adieu.
        $non = ['C\'est tout', 'Termine le mail', 'Au revoir est un film', 'Merci', 'Stop', 'À plus de dix personnes'];
        $appels = [];
        foreach (array_merge($oui, $non) as $p) {
            $appels[] = ['fn' => 'end', 'text' => $p];
        }
        $r = array_combine(array_merge($oui, $non), self::js($appels));
        foreach ($oui as $p) {
            $this->assertTrue($r[$p], "« $p » doit terminer la conversation");
        }
        foreach ($non as $p) {
            $this->assertFalse($r[$p], "« $p » ne doit pas terminer la conversation");
        }
    }

    public function testLEchoEstUneSuiteContigueDuTexteLuPasUnSemisDeMots(): void
    {
        $lu = 'Voici le plan. Arrête-toi à la boulangerie en rentrant, puis prends le pain. Et pour la suite, je propose de '
            . 'continuer demain avec le reste de la liste, quand la météo sera meilleure pour la route.';
        $r = self::js([
            ['fn' => 'echo', 'text' => 'Arrête-toi à la boulangerie en rentrant', 'ref' => $lu],
            ['fn' => 'echo', 'text' => 'arrête toi à la boulangerie en rentrant puis prend le pain', 'ref' => $lu],
            ['fn' => 'echo', 'text' => 'Stop, et pour demain ?', 'ref' => $lu],
            // Tous ces mots sont dans le texte, mais pas À LA SUITE.
            ['fn' => 'echo', 'text' => 'Attends, et le pain pour la route demain ?', 'ref' => $lu],
            ['fn' => 'echo', 'text' => 'boulangerie', 'ref' => $lu],
            ['fn' => 'echo', 'text' => 'Stop', 'ref' => $lu],
            ['fn' => 'echo', 'text' => 'Stop', 'ref' => ''],
        ]);
        $this->assertTrue($r[0], 'la voix de MIA, revenue par le micro');
        $this->assertTrue($r[1], 'une transcription approximative du texte lu reste un écho');
        $this->assertFalse($r[2]);
        $this->assertFalse($r[3], 'des mots épars du texte lu ne font pas un écho');
        $this->assertTrue($r[4]);
        $this->assertFalse($r[5]);
        $this->assertFalse($r[6], 'sans texte de référence, rien n\'est un écho');
    }

    // ------------------------------------------------------------------
    // 4. Ce que MIA affiche ou dit de dire est reconnu
    // ------------------------------------------------------------------

    /**
     * Les cartes affichent « À la voix : … », MIA dit « Dites … », et le
     * modèle rappelle les mots : chacun de ces mots doit être COMPRIS. Un mot
     * ajouté à l'un de ces textes sans être couvert ici fait tomber le test —
     * c'est voulu.
     */
    public function testChaqueMotAfficheOuDitParMiaEstUnOrdreReconnu(): void
    {
        $chat = self::chatController();
        $js   = self::lire('public/assets/js/chat.js');
        $enServeur = (array)(require ROOT . '/lang/en.php');
        $enClient  = (array)(require ROOT . '/lang/en.client.php');

        // mot => [contexte, act attendu, préréglage éventuel]
        $attendus = [
            'approuver' => [self::APPROBATION, 'approve'], 'annuler' => [self::APPROBATION, 'cancel'],
            'approve'   => [self::APPROBATION, 'approve'], 'cancel'  => [self::APPROBATION, 'cancel'],
            'confirmer' => [self::mail('send-confirm'), 'confirm'], 'confirm' => [self::mail('send-confirm'), 'confirm'],
            'retour'    => [self::mail('send-confirm'), 'back'],    'back'    => [self::mail('send-confirm'), 'back'],
            'envoyer'   => [self::mail(), 'send'],                  'send'    => [self::mail(), 'send'],
            'créer le brouillon' => [self::mail(), 'draft'],        'create the draft' => [self::mail(), 'draft'],
            'plus court'  => [self::mail(), 'preset', 'plus_court'], 'shorter' => [self::mail(), 'preset', 'plus_court'],
            'plus formel' => [self::mail(), 'preset', 'plus_formel'],
            'abandonner'  => [self::mail(), 'discard'],             'discard' => [self::mail(), 'discard'],
        ];

        $textes = [];
        // 1. La ligne « À la voix » des cartes, et l'invite du bandeau.
        preg_match_all("/t\\('((?:À la voix : |Dites « stop »)(?:[^'\\\\]|\\\\.)*)'\\)/u", $js, $m);
        $reperes = array_values(array_unique($m[1]));
        $this->assertCount(5, $reperes, 'quatre repères de carte (approbation, email ×3) et l\'invite du bandeau');
        foreach ($reperes as $cle) {
            $cle = stripslashes($cle);
            $textes[] = $cle;
            $this->assertArrayHasKey($cle, $enClient, "« $cle » n'a pas de traduction client");
            $textes[] = (string)$enClient[$cle];
        }
        // 2. Les phrases dites en retour d'un ordre.
        foreach (ChatController::VOICE_CUES as $type => $phrase) {
            $textes[] = $phrase;
            $this->assertArrayHasKey($phrase, $enServeur, "La phrase de « $type » n'a pas de traduction : « $phrase ».");
            $textes[] = (string)$enServeur[$phrase];
        }
        // 3. Les mots que le modèle rappelle.
        $textes[] = ChatController::voiceCardWords(true);
        $textes[] = ChatController::voiceCardWords(false);

        $mots = [];
        foreach ($textes as $texte) {
            preg_match_all('/«\s*([^»]+?)\s*»|“([^”]+)”/u', $texte, $cites);
            foreach ($cites[1] as $i => $fr) {
                $mot = mb_strtolower(trim($fr !== '' ? $fr : $cites[2][$i]));
                if ($mot !== '') {
                    $mots[$mot] = true;
                }
            }
        }
        $mots = array_keys($mots);
        $this->assertContains('stop', $mots);
        $this->assertContains('approuver', $mots);
        $this->assertContains('shorter', $mots);

        $appels = [];
        foreach ($mots as $mot) {
            if ($mot === 'stop') {
                $appels[] = ['fn' => 'stop', 'text' => $mot];
                continue;
            }
            $this->assertArrayHasKey($mot, $attendus, "MIA demande de dire « $mot » : ce mot doit être couvert par ce test (et reconnu).");
            $appels[] = ['fn' => 'card', 'text' => $mot, 'ctx' => $attendus[$mot][0]];
        }
        foreach (self::js($appels) as $i => $rendu) {
            $mot = $mots[$i];
            if ($mot === 'stop') {
                $this->assertSame(['remainder' => '', 'hard' => true], $rendu);
                continue;
            }
            $this->assertSame($attendus[$mot][1], $rendu['act'] ?? null, "« $mot » est affiché ou dit par MIA mais n'est pas reconnu");
            if (isset($attendus[$mot][2])) {
                $this->assertSame($attendus[$mot][2], $rendu['preset'] ?? null);
            }
        }
        // « retour » referme aussi le panneau d'amélioration (troisième repère de carte).
        $this->assertSame(['act' => 'close'], self::cartes(['retour'], self::mail('improve'))['retour']);
        $this->assertSame(['act' => 'close'], self::cartes(['back'], self::mail('improve'))['back']);
    }

    // ------------------------------------------------------------------
    // 5. Serveur : phrases de retour, consignes au modèle
    // ------------------------------------------------------------------

    public function testLesPhrasesDeRetourSontUneListeFermeeServieParLaRouteDesAnnonces(): void
    {
        $src = self::chatController();

        foreach (array_keys(ChatController::VOICE_CUES) as $type) {
            $this->assertStringStartsWith('vc_', $type);
            $this->assertArrayNotHasKey($type, ChatController::ACTION_LABELS, "« $type » entre en collision avec un type de la trace d'actions");
        }
        // Une seule phrase par type, quel qu'il soit ; un type inconnu ne dit rien.
        $this->assertSame('C\'est fait.', ChatController::cuePhrase('vc_done'));
        $this->assertSame('Recherche sur Internet', ChatController::cuePhrase('web_search'));
        $this->assertSame('', ChatController::cuePhrase('vc_inconnu'));
        $this->assertSame('', ChatController::cuePhrase(''));
        $this->assertSame('', ChatController::voiceCommandCuePhrase('web_search'));
        I18n::use('en');
        try {
            $this->assertSame('Email sent.', ChatController::cuePhrase('vc_sent'));
        } finally {
            I18n::use('fr');
        }

        // La route ne dit QUE ce que cette liste contient : jamais un texte reçu du client.
        $debut = strpos($src, 'public function voiceCue(): void');
        $this->assertIsInt($debut);
        $corps = substr($src, $debut, 3500);
        $this->assertStringContainsString('$phrase = self::cuePhrase($type);', $corps);
        $this->assertStringNotContainsString("\$_GET['text']", $corps);
        $this->assertStringNotContainsString("\$_POST['text']", $corps);

        // Chaque annonce jouée par le navigateur existe côté serveur.
        $js = self::lire('public/assets/js/chat.js');
        preg_match_all("/'(vc_[a-z_]+)'/", $js, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $type) {
            $this->assertArrayHasKey($type, ChatController::VOICE_CUES, "chat.js annonce « $type », inconnu du serveur : MIA resterait muette");
        }
        foreach (array_keys(ChatController::VOICE_CUES) as $type) {
            $this->assertStringContainsString("'$type'", $js, "« $type » n'est annoncée nulle part");
        }
    }

    public function testLeModeleInviteARepondreALaVoixSansJamaisPouvoirAgir(): void
    {
        $src = self::chatController();

        // À la pose de la carte : le retour de l'outil porte l'invite, en conversation vocale seulement.
        $this->assertStringContainsString('use ($depositConnectorWrite, $voiceMode, $searchScope): array {', $src);
        $this->assertStringContainsString("return ['statut' => 'en_attente_approbation', 'message' => self::pendingWriteMessage(\$mailService !== null, \$voiceMode)];", $src);
        $this->assertStringContainsString('return $voiceMode ? $message . self::voiceApprovalHint($mail) : $message;', $src);
        $this->assertStringNotContainsString('tu ne peux ni approuver', ChatController::pendingWriteMessage(false), 'Hors conversation vocale : aucune invite.');
        $this->assertStringContainsString('tu ne peux ni approuver', ChatController::pendingWriteMessage(true, true));
        foreach ([true, false] as $mail) {
            $invite = ChatController::voiceApprovalHint($mail);
            $this->assertStringContainsString(ChatController::voiceCardWords($mail), $invite);
            $this->assertStringContainsString('tu ne peux ni approuver ni envoyer', $invite);
            $this->assertStringContainsString('aucune autre question', $invite);
            $this->assertStringNotContainsString('bouton', $invite, 'prompt de méthode : des mots à DIRE, aucun libellé d\'écran');
        }

        // Aux tours suivants : la note ne pèse rien quand rien n'attend, et vise la carte la plus récente.
        $this->assertSame('', ChatController::voicePendingNote([]));
        $this->assertSame('', ChatController::voicePendingNote(['']));
        $mail = ChatController::voicePendingNote(['creer_evenement_agenda', 'creer_brouillon_gmail']);
        $this->assertStringContainsString('un email à relire', $mail);
        $this->assertStringContainsString(ChatController::voiceCardWords(true), $mail);
        $carte = ChatController::voicePendingNote(['creer_brouillon_gmail', 'creer_evenement_agenda']);
        $this->assertStringContainsString('une action à approuver', $carte);
        $this->assertStringContainsString(ChatController::voiceCardWords(false), $carte);
        $this->assertStringContainsString('ne prétends jamais l\'avoir fait', $carte);
        $this->assertStringContainsString('self::voicePendingNote($pendingTools)', $src);
        $this->assertStringContainsString('DrivePendingAction::pendingForConversation($convId, (int)$user[\'id\'])', $src);

        // Aucun outil n'approuve ni n'envoie : la décision reste un geste de l'utilisateur.
        foreach (['approuver_action', 'confirmer_envoi', 'envoyer_email', 'envoyer_brouillon'] as $outil) {
            $this->assertStringNotContainsString("'$outil'", $src, "un outil « $outil » donnerait au modèle le geste de l'utilisateur");
        }
    }

    // ------------------------------------------------------------------
    // 6. Câblage du navigateur
    // ------------------------------------------------------------------

    public function testLaVoixPasseParLeCodeDuClic(): void
    {
        $js = self::lire('public/assets/js/chat.js');

        $debut = strpos($js, 'async function runVoiceCardCommand(card, cmd, say) {');
        $this->assertIsInt($debut);
        $corps = substr($js, $debut, (int)strpos($js, "\n}\n", $debut) - $debut);

        // Mêmes fonctions que le clic, aucun appel réseau propre à la voix.
        $this->assertStringContainsString('await resolveDriveAction(target, decision)', $corps);
        $this->assertStringContainsString("runMailDraftAct(card, 'send', null)", $corps);
        $this->assertStringContainsString("runMailDraftAct(card, 'send-confirm', null)", $corps);
        $this->assertStringContainsString("runMailDraftAct(card, 'approve', null)", $corps);
        foreach (['fetch(', 'drive-action', 'mail-draft/revise', 'FormData'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $corps, "la voix ne doit pas avoir son propre chemin d'exécution ($interdit)");
        }
        // « Envoie » n'envoie pas : seul l'ordre de confirmation atteint l'envoi réel,
        // qui reste écrit UNE fois dans tout le fichier.
        $envoyer   = substr($corps, (int)strpos($corps, "case 'send': {"), 320);
        $this->assertStringNotContainsString('send-confirm', $envoyer);
        $this->assertStringContainsString("'vc_send_confirm'", $envoyer);
        $this->assertSame(1, substr_count($js, "resolveDriveAction(card, 'send')"));
        // « Tout approuver » ne touche jamais un email.
        $this->assertStringContainsString("pendingActionCards().filter(c => !c.classList.contains('mail-draft-card'))", $corps);

        // Le clic et la voix partagent runMailDraftAct, qui enregistre d'abord les corrections.
        $act = substr($js, (int)strpos($js, 'async function runMailDraftAct(card, act, btn, extra) {'), 1800);
        $flush = strpos($act, "if (!(await flushMailDraftEdits(card))) return 'refused';");
        $this->assertIsInt($flush);
        $this->assertGreaterThan($flush, strpos($act, "case 'instruct':"));

        // La carte visée : en attente, non occupée, dans la conversation affichée.
        $cartes = substr($js, (int)strpos($js, 'function pendingActionCards() {'), 600);
        foreach (["'#chatMessages .drive-confirm-card'", "'is-done'", "'is-cancelled'", "'is-failed'", "'is-busy'", ':not(:disabled)'] as $garde) {
            $this->assertStringContainsString($garde, $cartes);
        }
        // Le contexte donné à la reconnaissance ne porte aucun CONTENU (corps d'email, aperçu).
        $contexte = substr($js, (int)strpos($js, 'function voiceCardContext(card) {'), 800);
        foreach (['corps', 'apercu', 'innerHTML', 'objet'] as $contenu) {
            $this->assertStringNotContainsString($contenu, $contexte, "la reconnaissance ne doit pas voir « $contenu »");
        }
    }

    public function testLaPhraseEstRouteeAvantDEtreEnvoyee(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $this->assertStringContainsString('const VC = window.MiaVoiceCommands || null;', $js);

        $debut = strpos($js, 'function routeTurn(text) {');
        $this->assertIsInt($debut);
        $corps = substr($js, $debut, 600);
        $carte = strpos($corps, 'cardOrder(text)');
        $fin   = strpos($corps, 'VC.endCommand(text)');
        $arret = strpos($corps, 'VC.stopCommand(text)');
        $envoi = strpos($corps, 'sendTurn(text)');
        foreach (['carte', 'fin', 'arret', 'envoi'] as $v) {
            $this->assertIsInt($$v, "$v absent de routeTurn");
        }
        $this->assertLessThan($fin, $carte, 'la carte en attente passe avant tout');
        $this->assertLessThan($arret, $fin);
        $this->assertLessThan($envoi, $arret, 'un message ne part qu\'en dernier recours');
        // Sans le module (script non chargé), tout ce qui est dit reste un message.
        $this->assertStringContainsString('if (VC) {', $corps);

        // Le module est chargé AVANT chat.js, sous la même condition que le bouton.
        $tpl    = self::lire('templates/chat/index.php');
        $module = strpos($tpl, '/assets/js/voice-commands.js?v=');
        $chat   = strpos($tpl, '/assets/js/chat.js?v=');
        $this->assertIsInt($module);
        $this->assertLessThan($chat, $module);
        $garde = strrpos(substr($tpl, 0, $module), "if (!empty(\$hasOpenaiKey) && !empty(\$voiceChatEnabled)):");
        $this->assertIsInt($garde);
        $this->assertLessThan(500, $module - $garde, 'le script suit sa condition');
        // Pur : ni DOM, ni réseau, ni traduction.
        $moduleJs = self::lire('public/assets/js/voice-commands.js');
        foreach (['document.', 'fetch(', 'localStorage', 'querySelector', " t('"] as $interdit) {
            $this->assertStringNotContainsString($interdit, $moduleJs, "voice-commands.js doit rester pur ($interdit)");
        }
        $this->assertStringContainsString('root.MiaVoiceCommands = {', $moduleJs);
    }

    public function testLesCartesDisentQuoiDireSeulementEnConversationVocale(): void
    {
        $js = self::lire('public/assets/js/chat.js');
        $this->assertSame(1, substr_count($js, 'function voiceHintHtml(text) {'));
        // Un appel par gabarit de carte : approbation, et email (selon son mode).
        $this->assertSame(1, substr_count($js, '${voiceHintHtml('));
        $this->assertSame(1, substr_count($js, 'html += voiceHintHtml('));
        $this->assertStringContainsString("document.body.classList.toggle('voice-chat-on', on);", $js);
        // Le repère disparaît avec les boutons (exécutée, annulée, en échec,
        // remplacée par une proposition révisée).
        $this->assertSame(4, substr_count($js, "card.querySelector('.voice-cmd-hint')?.remove();"));

        $css = self::lire('public/assets/css/main.css');
        $this->assertStringContainsString('.voice-cmd-hint { display: none; }', $css);
        $this->assertStringContainsString('body.voice-chat-on .drive-confirm-card .voice-cmd-hint {', $css);
        $this->assertLessThan(
            strpos($css, 'body.voice-chat-on .drive-confirm-card .voice-cmd-hint {'),
            strpos($css, '.voice-cmd-hint { display: none; }'),
            'la règle d\'affichage doit venir APRÈS le masquage par défaut'
        );
    }

    /**
     * La page d'aide donne des phrases à dire : chacune doit réellement
     * déclencher quelque chose. Une documentation qui promet « dites X » pour
     * un X que MIA n'entend pas est pire que pas de documentation.
     */
    public function testLesPhrasesDeLaDocumentationSontReconnues(): void
    {
        $page   = self::lire('docs/user/fonctionnalites/conversations/converser-a-la-voix.md');
        $appels = [];
        $phrases = [];
        foreach (explode("\n", $page) as $ligne) {
            if (!preg_match('/^\| (Action à approuver|Plusieurs actions|Email) \|([^|]+)\|/u', $ligne, $m)) {
                continue;
            }
            $ctx = $m[1] === 'Email' ? self::mail('preview', true) : self::APPROBATION;
            preg_match_all('/«\s*([^»]+?)\s*»/u', $m[2], $cites);
            foreach ($cites[1] as $phrase) {
                $appels[]  = ['fn' => 'card', 'text' => $phrase, 'ctx' => $ctx];
                $phrases[] = $phrase;
            }
        }
        $this->assertGreaterThanOrEqual(15, count($phrases), 'Tableau des ordres introuvable dans la page d\'aide.');
        foreach (self::js($appels) as $i => $rendu) {
            $this->assertNotNull($rendu, "La page d'aide dit de dire « {$phrases[$i]} », qui n'est pas reconnu.");
            $this->assertNotSame('reask', $rendu['act'], "« {$phrases[$i]} » ne déclenche rien");
        }
        // La confirmation d'envoi, citée dans la même ligne, se joue dans son propre mode.
        $c = self::cartes(['confirme', 'retour'], self::mail('send-confirm'));
        $this->assertSame(['act' => 'confirm'], $c['confirme']);
        $this->assertSame(['act' => 'back'], $c['retour']);

        // Les ordres d'arrêt et l'adieu cités dans la page.
        $arrets = ['stop', 'arrête', 'tais-toi', 'attends', 'ça suffit'];
        $appels = array_map(static fn(string $p): array => ['fn' => 'stop', 'text' => $p], $arrets);
        $appels[] = ['fn' => 'stop', 'text' => 'stop, et pour demain ?'];
        $appels[] = ['fn' => 'end', 'text' => 'au revoir'];
        $r = self::js($appels);
        foreach ($arrets as $i => $p) {
            $this->assertStringContainsString("« $p »", str_replace('**', '', $page), "la page doit citer « $p »");
            $this->assertSame('', $r[$i]['remainder'] ?? null, "« $p » doit interrompre la lecture");
        }
        $this->assertSame('et pour demain ?', $r[count($arrets)]['remainder'] ?? null);
        $this->assertTrue($r[count($arrets) + 1]);
    }

    #[\PHPUnit\Framework\Attributes\Group('doc-interne')]
    public function testLaDocumentationLeDit(): void
    {
        $page = self::lire('docs/user/fonctionnalites/conversations/converser-a-la-voix.md');
        foreach (['## Interrompre MIA à la voix', '## Répondre à une carte à la voix', 'Un « oui » ne suffit pas', '« Stop »', 'au revoir'] as $repere) {
            $this->assertStringContainsString($repere, $page);
        }
        $this->assertStringContainsString('converser-a-la-voix.md', self::lire('docs/user/fonctionnalites/connecteurs/utiliser-un-connecteur.md'));
        $this->assertStringContainsString('Interruption à la voix', self::lire('docs/user/admin/outils/regler-la-conversation-vocale.md'));
        $guide = self::lire('docs/GUIDE-UTILISATEUR.md');
        $this->assertStringContainsString('Interrompre MIA à la voix', $guide);
        $this->assertStringContainsString('Répondre à une carte à la voix', $guide);
        $this->assertStringContainsString('Interruption à la voix', self::lire('docs/GUIDE-ADMIN.md'));
        $this->assertStringContainsString('conversation-vocale-ordres', self::lire('docs/NOUVEAUTES.md'));
        $claude = self::lire('CLAUDE.md');
        $this->assertStringContainsString('voice-commands.js', $claude);
        $this->assertStringContainsString('Interruption à la voix', $claude);
    }

    // ------------------------------------------------------------------
    // Harnais node
    // ------------------------------------------------------------------

    /**
     * Rejoue des phrases contre une carte.
     *
     * @param  list<string> $phrases
     * @return array<string, array|null> phrase => ordre reconnu
     */
    private static function cartes(array $phrases, array $ctx): array
    {
        $appels = [];
        foreach ($phrases as $p) {
            $appels[] = ['fn' => 'card', 'text' => $p, 'ctx' => $ctx];
        }
        return array_combine($phrases, self::js($appels));
    }

    /**
     * Exécute le VRAI module sous node.
     *
     * @param  list<array<string, mixed>> $appels
     * @return list<mixed>
     */
    private static function js(array $appels): array
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('node indisponible : ce test tourne sur le poste de développement et en CI.');
        }

        $script  = tempnam(sys_get_temp_dir(), 'mia-voice-js-');
        $donnees = tempnam(sys_get_temp_dir(), 'mia-voice-in-');
        file_put_contents($script, self::HARNAIS);
        file_put_contents($donnees, json_encode($appels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        try {
            $processus = proc_open(
                [$node, $script, ROOT . '/public/assets/js/voice-commands.js', $donnees],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $tubes
            );
            if (!is_resource($processus)) {
                self::fail('Impossible de lancer node.');
            }
            $sortie  = stream_get_contents($tubes[1]);
            $erreurs = stream_get_contents($tubes[2]);
            fclose($tubes[1]);
            fclose($tubes[2]);
            $code = proc_close($processus);
        } finally {
            @unlink($script);
            @unlink($donnees);
        }

        $resultats = json_decode((string)$sortie, true);
        if ($code !== 0 || !is_array($resultats) || count($resultats) !== count($appels)) {
            self::fail("voice-commands.js a échoué sous node (code $code) :\n" . $erreurs);
        }
        return $resultats;
    }

    /** node explicite (NODE_BINARY), sinon le premier du PATH. */
    private static function node(): ?string
    {
        $explicite = getenv('NODE_BINARY');
        if (is_string($explicite) && $explicite !== '' && is_executable($explicite)) {
            return $explicite;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dossier) {
            $candidat = rtrim($dossier, '/') . '/node';
            if ($dossier !== '' && is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }
        return null;
    }

    private const HARNAIS = <<<'JS'
        'use strict';
        const fs = require('fs');
        require(process.argv[2]);
        const V = globalThis.MiaVoiceCommands;
        const appels = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
        const rendus = appels.map((a) => {
            switch (a.fn) {
                case 'card':  return V.cardCommand(a.text, a.ctx);
                case 'stop':  return V.stopCommand(a.text);
                case 'end':   return V.endCommand(a.text);
                case 'echo':  return V.looksLikeEcho(a.text, a.ref);
                case 'interrupt': return V.interruption(a.text, a.ref);
                case 'forms': return V.verbForms(a.text);
                default:      throw new Error('appel inconnu : ' + a.fn);
            }
        });
        process.stdout.write(JSON.stringify(rendus));
        JS;
}
