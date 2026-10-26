<?php

declare(strict_types=1);

/**
 * Modèles de workflows livrés — des usages réels, choisis pour montrer
 * ensemble ce que savent faire les workflows : chaque déclencheur (à la main,
 * depuis le chat, programmé, un email reçu, un fichier déposé dans Drive,
 * l'appel d'une adresse), chaque brique (étape, action dans un outil,
 * création, condition par règle ou par question, décision de MIA, boucle
 * « pour chaque » et « jusqu'à », validation, attente, notification, arrêt),
 * les boucles qui parcourent un fichier Excel ligne par ligne ou les pièces
 * jointes d'un email, avec leur filtre, leur condition et leur relecture groupée,
 * les filtres, et chaque livraison du livrable.
 *
 * Un modèle ne lie aucun assistant ni aucune base : chaque étape est confiée
 * à MIA, la personne choisit ensuite ses assistants dans l'éditeur. Textes
 * traduits à l'instanciation (le workflow créé est du contenu, dans la langue
 * de la personne). Instancié pour une personne (`get($key, $choix)`), un
 * modèle s'adapte à ce qu'elle a (`adapt`) : Outlook à la place de Gmail,
 * calendrier Outlook à la place de l'agenda Google, Microsoft To Do à la
 * place de Google Tasks, l'outil de création qui lui est ouvert.
 */
final class WorkflowTemplates
{
    public const KEYS = [
        'tri_demandes', 'appel_offres', 'compte_rendu', 'rendez_vous', 'facture',
        'veille', 'lancement', 'accueil', 'demande_site', 'relance_clients', 'pieces_jointes',
    ];

    /** Connecteurs interchangeables : un modèle prend celui que la personne a. */
    public const PARTNERS = WorkflowFit::PARTNERS;

    /**
     * Liste affichée : clé, nom, description, couleur, définition (la
     * couverture la dessine), silhouette, comptes, briques mises en avant.
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $tpl = self::get($key);
            $def = $tpl['definition'];
            $out[] = [
                'key'         => $key,
                'name'        => $tpl['name'],
                'description' => $tpl['description'],
                'definition'  => $def,
                'silhouette'  => WorkflowSpec::silhouette($def),
                'counts'      => WorkflowSpec::counts($def),
                'features'    => WorkflowCover::features($def),
                'color'       => $tpl['color'],
            ];
        }
        return $out;
    }

    /**
     * Un modèle, en forme canonique. `$choices` (WorkflowCatalog::choices de
     * la personne) l'adapte à ses connecteurs et à ses outils ; vide, le
     * modèle tel qu'il est écrit.
     *
     * @return array{name: string, description: string, color: string, definition: array}|null
     */
    public static function get(string $key, array $choices = []): ?array
    {
        $tpl = match ($key) {
            'tri_demandes' => self::triDemandes(),
            'appel_offres' => self::appelOffres(),
            'compte_rendu' => self::compteRendu(),
            'rendez_vous'  => self::rendezVous(),
            'facture'      => self::facture(),
            'veille'       => self::veille(),
            'lancement'    => self::lancement(),
            'accueil'      => self::accueil(),
            'demande_site' => self::demandeSite(),
            'relance_clients' => self::relanceClients(),
            'pieces_jointes'  => self::piecesJointes(),
            default        => null,
        };
        if ($tpl === null) {
            return null;
        }
        $tpl['definition'] = WorkflowSpec::normalize($tpl['definition']);
        if ($choices !== []) {
            $tpl['definition'] = self::adapt($tpl['definition'], $choices);
        }
        return $tpl;
    }

    /**
     * Le modèle ajusté à la personne, sans rien inventer (WorkflowFit) :
     * l'équivalent du connecteur qu'elle a, ses outils de création, aucune
     * livraison vers un service absent. Pure.
     */
    public static function adapt(array $def, array $choices): array
    {
        return WorkflowFit::apply($def, $choices);
    }

    // ==================================================================
    // Les modèles
    // ==================================================================

    /** Un email reçu → MIA comprend, choisit la voie, et chaque voie agit. */
    private static function triDemandes(): array
    {
        $replyTo = ['recipients' => '@{evenement.expediteur}'];
        return [
            'name' => t('Trier les demandes reçues par email'),
            'description' => t('Chaque email reçu est compris puis aiguillé par MIA : devis préparé, réclamation suivie, rendez-vous proposé, publicité classée sans suite.'),
            'color' => '#0ea5e9',
            'definition' => [
                'objective' => t('Que chaque demande reçue par email ait une réponse adaptée, sans tri à la main.'),
                'start' => ['title' => t('Départ'), 'chat' => false, 'fields' => [],
                    'event' => ['kind' => 'email', 'source' => 'gmail', 'query' => '', 'piece_name' => t('Email reçu'), 'timezone' => 'Europe/Paris']],
                'nodes' => [
                    ['key' => 'lecture', 'type' => 'step', 'title' => t('Comprendre la demande'),
                     'instruction' => t('Lis @{evenement} et résume la demande : qui écrit, pour quelle entreprise, ce qui est attendu de nous, et s’il y a une urgence. N’invente rien : laisse vide ce que l’email ne dit pas.'),
                     'output' => ['key' => 'demande', 'name' => t('Fiche de la demande'), 'form' => 'fiche', 'fields' => [
                         ['key' => 'client', 'label' => t('Client'), 'type' => 'text'],
                         ['key' => 'entreprise', 'label' => t('Entreprise'), 'type' => 'text'],
                         ['key' => 'objet', 'label' => t('Ce qui est demandé'), 'type' => 'text'],
                         ['key' => 'points', 'label' => t('Points à traiter'), 'type' => 'list'],
                         ['key' => 'urgent', 'label' => t('Urgent'), 'type' => 'yesno'],
                     ]]],
                    ['key' => 'aiguillage', 'type' => 'route', 'title' => t('Aiguiller la demande'), 'reads' => ['demande'],
                     'instruction' => t('Choisis la voie d’après ce que la personne attend de nous dans @{demande}, pas d’après les mots de son email. Une demande qui mêle plusieurs sujets va dans la voie de son sujet principal.'),
                     'fallback' => 'ask',
                     'routes' => [
                         ['key' => 'devis', 'label' => t('Demande de devis'),
                          'when' => t('La personne veut un prix, une offre ou une proposition commerciale.'),
                          'nodes' => [
                              ['key' => 'proposition', 'type' => 'step', 'title' => t('Préparer la proposition'), 'sources' => ['drive'], 'review' => true,
                               'instruction' => t('Retrouve dans Google Drive nos offres et nos tarifs les plus proches de @{demande}, puis prépare la réponse : ce que nous proposons, une fourchette de prix si nos documents la donnent, et la prochaine étape. Cite les documents utilisés.'),
                               'output' => ['key' => 'proposition_commerciale', 'name' => t('Proposition'), 'form' => 'text']],
                              ['key' => 'reponse_devis', 'type' => 'action', 'title' => t('Répondre avec la proposition'), 'target' => 'gmail',
                               'instruction' => t('Réponds dans le fil à @{evenement} en reprenant @{proposition_commerciale}. Ton cordial, phrases courtes.'),
                               'write' => 'approve', 'filters' => $replyTo],
                          ]],
                         ['key' => 'reclamation', 'label' => t('Réclamation'),
                          'when' => t('La personne signale un problème : un retard, une erreur, un produit défectueux, un service qui l’a déçue.'),
                          'nodes' => [
                              ['key' => 'alerte', 'type' => 'notify', 'title' => t('Me prévenir tout de suite'),
                               'message' => t('Réclamation de @{demande.client} (@{demande.entreprise}) : @{demande.objet}')],
                              ['key' => 'suivi', 'type' => 'action', 'title' => t('Créer la tâche de suivi'), 'target' => 'tasks',
                               'instruction' => t('Crée une tâche « Traiter la réclamation » pour le client de @{demande}, avec les points à traiter en note, à échéance de demain.'),
                               'write' => 'approve'],
                              ['key' => 'accuse', 'type' => 'action', 'title' => t('Accuser réception'), 'target' => 'gmail',
                               'instruction' => t('Réponds dans le fil à @{evenement} : nous avons bien reçu le signalement et nous revenons vers vous sous 48 heures. Ne promets rien d’autre.'),
                               'write' => 'approve', 'filters' => $replyTo],
                          ]],
                         ['key' => 'rendez_vous', 'label' => t('Prise de rendez-vous'),
                          'when' => t('La personne demande un rendez-vous, une démonstration ou un appel.'),
                          'nodes' => [
                              ['key' => 'creneaux', 'type' => 'step', 'title' => t('Trouver des créneaux'), 'sources' => ['calendar'],
                               'instruction' => t('Cherche dans mon agenda trois créneaux libres d’une heure dans les cinq prochains jours ouvrés, entre 9 h et 18 h.'),
                               'output' => ['key' => 'creneaux_libres', 'name' => t('Créneaux proposés'), 'form' => 'list']],
                              ['key' => 'reponse_rdv', 'type' => 'action', 'title' => t('Proposer les créneaux'), 'target' => 'gmail',
                               'instruction' => t('Réponds dans le fil à @{evenement} en proposant @{creneaux_libres}. Demande de confirmer celui qui convient.'),
                               'write' => 'approve', 'filters' => $replyTo],
                          ]],
                         ['key' => 'sans_suite', 'label' => t('Rien à traiter'),
                          'when' => t('Publicité, prospection, lettre d’information, réponse automatique ou message sans demande.'),
                          'nodes' => [
                              ['key' => 'classer', 'type' => 'stop', 'title' => t('Classer sans suite'), 'outcome' => 'done',
                               'message' => t('Rien à faire pour cet email : classé sans suite.')],
                          ]],
                     ]],
                ],
                'end' => ['title' => t('Demande traitée'), 'pieces' => ['demande']],
            ],
        ];
    }

    /** Un dossier de consultation → décision, réponse exigence par exigence, mémoire relu, remise. */
    private static function appelOffres(): array
    {
        return [
            'name' => t('Réponse à un appel d’offres'),
            'description' => t('Le dossier analysé, la décision d’y aller, une réponse argumentée exigence par exigence, un mémoire relu et l’email de remise prêt.'),
            'color' => '#6366f1',
            'definition' => [
                'objective' => t('Remettre dans les délais un mémoire technique solide et relu, avec l’email de remise prêt à partir.'),
                'start' => ['title' => t('Départ'), 'fiche_name' => t('Consultation'), 'fields' => [
                    ['key' => 'dossier', 'label' => t('Dossier de consultation'), 'type' => 'file', 'required' => true, 'help' => t('Le règlement et le cahier des charges, en PDF ou Word.')],
                    ['key' => 'client', 'label' => t('Client'), 'type' => 'text', 'required' => true],
                    ['key' => 'contact', 'label' => t('Contact acheteur'), 'type' => 'email', 'required' => true],
                    ['key' => 'date_limite', 'label' => t('Date limite de remise'), 'type' => 'date', 'required' => false],
                ]],
                'nodes' => [
                    ['key' => 'analyse', 'type' => 'step', 'title' => t('Analyser le dossier'),
                     'instruction' => t('Lis @{dossier} et fais la synthèse de la consultation : objet du marché, budget estimé, date limite, exigences à satisfaire (une par ligne, formulée comme une obligation) et critères de choix avec leur pondération.'),
                     'output' => ['key' => 'synthese', 'name' => t('Synthèse de l’appel d’offres'), 'form' => 'fiche', 'fields' => [
                         ['key' => 'objet', 'label' => t('Objet'), 'type' => 'text'],
                         ['key' => 'budget', 'label' => t('Budget estimé (€)'), 'type' => 'number'],
                         ['key' => 'date_limite', 'label' => t('Date limite'), 'type' => 'date'],
                         ['key' => 'exigences', 'label' => t('Exigences'), 'type' => 'list'],
                         ['key' => 'criteres', 'label' => t('Critères de choix'), 'type' => 'list'],
                         ['key' => 'vigilance', 'label' => t('Points de vigilance'), 'type' => 'list'],
                     ]]],
                    ['key' => 'go', 'type' => 'condition', 'title' => t('On y va ?'), 'mode' => 'question',
                     'question' => t('Au vu de @{synthese}, cet appel d’offres correspond-il à ce que nous savons faire, avec un budget et un délai tenables ?'),
                     'doubt' => 'ask',
                     'yes' => ['then' => 'continue', 'nodes' => []],
                     'no' => ['then' => 'stop', 'nodes' => [
                         ['key' => 'decliner', 'type' => 'action', 'title' => t('Décliner poliment'), 'target' => 'gmail',
                          'instruction' => t('Écris au contact acheteur de @{depart} pour décliner la consultation décrite par @{synthese} : remercie-le, explique en une phrase que nous ne pouvons pas y répondre cette fois, reste disponible pour la suivante.'),
                          'write' => 'approve', 'filters' => ['recipients' => '@{depart.contact}']],
                         ['key' => 'renoncer', 'type' => 'stop', 'title' => t('Ne pas répondre'), 'outcome' => 'stopped',
                          'message' => t('Nous ne répondons pas à cette consultation.')],
                     ]]],
                    ['key' => 'exigences', 'type' => 'loop', 'title' => t('Répondre à chaque exigence'), 'mode' => 'each',
                     'over' => 'synthese', 'over_field' => 'exigences', 'max' => 15,
                     'item' => ['key' => 'exigence', 'name' => t('Exigence en cours')],
                     'collect' => 'reponse_exigence',
                     'output' => ['key' => 'reponses', 'name' => t('Réponses aux exigences')],
                     'body' => [
                         ['key' => 'repondre', 'type' => 'step', 'title' => t('Rédiger la réponse'), 'sources' => ['drive'],
                          'instruction' => t('Pour @{exigence}, explique comment nous y répondons : méthode, moyens, délais. Appuie-toi sur nos références et nos documents dans Google Drive, et cite-les. Cinq à dix lignes.'),
                          'output' => ['key' => 'reponse_exigence', 'name' => t('Réponse à l’exigence'), 'form' => 'text']],
                     ]],
                    ['key' => 'memoire', 'type' => 'step', 'title' => t('Rédiger le mémoire technique'),
                     'instruction' => t('Rédige le mémoire technique pour @{depart} : reprends la structure demandée par @{dossier}, intègre @{reponses} et montre comment nous répondons aux critères de @{synthese}. Ton sobre et factuel, aucun engagement que nos réponses ne portent pas.'),
                     'output' => ['key' => 'memoire_technique', 'name' => t('Mémoire technique'), 'form' => 'document', 'format' => 'docx', 'template' => true]],
                    ['key' => 'relecture', 'type' => 'gate', 'title' => t('Relecture avant remise'), 'reviews' => ['memoire_technique'],
                     'can_correct' => true, 'can_return' => true, 'days' => 3,
                     'note' => t('Vérifiez les engagements, les prix et le respect de la structure demandée. Renvoyez avec une remarque pour faire reprendre le mémoire.')],
                ],
                'end' => [
                    'title' => t('Dossier de réponse'),
                    'pieces' => ['memoire_technique', 'synthese', 'reponses'],
                    'deliveries' => [
                        ['type' => 'email', 'source' => 'gmail', 'to_field' => 'depart.contact', 'mode' => 'approve', 'attach' => ['memoire_technique'],
                         'subject' => t('Réponse à votre consultation — @{synthese.objet}'),
                         'message' => implode("\n\n", [t('Bonjour,'), t('Veuillez trouver ci-joint notre réponse à votre consultation.'),
                             t('Nous restons à votre disposition pour toute précision.'), t('Cordialement')])],
                        ['type' => 'drive', 'pieces' => ['memoire_technique', 'synthese'], 'folder' => t('Appels d’offres'), 'mode' => 'approve'],
                    ],
                ],
            ],
        ];
    }

    /** Des notes de réunion → compte rendu Word, actions relues, tâches, récapitulatif. */
    private static function compteRendu(): array
    {
        return [
            'name' => t('Compte rendu → actions'),
            'description' => t('Des notes de réunion au compte rendu Word, au tableau des actions relu, aux tâches créées et au récapitulatif envoyé aux participants.'),
            'color' => '#14b8a6',
            'definition' => [
                'objective' => t('Ne rien perdre d’une réunion : un compte rendu propre, des actions suivies, des participants informés.'),
                'start' => ['title' => t('Départ'), 'fiche_name' => t('Réunion'), 'fields' => [
                    ['key' => 'reunion', 'label' => t('Réunion'), 'type' => 'text', 'required' => true],
                    ['key' => 'date', 'label' => t('Date'), 'type' => 'date', 'required' => false],
                    ['key' => 'notes', 'label' => t('Notes ou transcription'), 'type' => 'longtext', 'required' => true, 'help' => t('Collez vos notes, ou la transcription de l’enregistrement.')],
                    ['key' => 'participants', 'label' => t('Emails des participants'), 'type' => 'text', 'required' => false, 'help' => t('Séparés par des virgules.')],
                ]],
                'nodes' => [
                    ['key' => 'redaction', 'type' => 'step', 'title' => t('Rédiger le compte rendu'),
                     'instruction' => t('À partir de @{depart}, rédige le compte rendu : contexte, décisions prises, points ouverts, prochaines échéances. Attribue chaque décision à qui l’a portée quand les notes le disent.'),
                     'output' => ['key' => 'compte_rendu', 'name' => t('Compte rendu'), 'form' => 'document', 'format' => 'docx', 'template' => true]],
                    ['key' => 'actions', 'type' => 'step', 'title' => t('Dresser le tableau des actions'), 'review' => true,
                     'instruction' => t('Relève dans @{compte_rendu} toutes les actions décidées. Colonnes : Action, Responsable, Échéance (AAAA-MM-JJ), Priorité. Une ligne par action ; laisse l’échéance vide si personne ne l’a fixée.'),
                     'output' => ['key' => 'tableau_actions', 'name' => t('Tableau des actions'), 'form' => 'table']],
                    ['key' => 'taches', 'type' => 'action', 'title' => t('Créer mes tâches'), 'target' => 'mstodo',
                     'instruction' => t('Crée une tâche pour chaque ligne de @{tableau_actions} dont je suis responsable, avec son échéance.'),
                     'write' => 'approve', 'on_error' => ['retries' => 1, 'then' => 'skip']],
                    ['key' => 'recap', 'type' => 'action', 'title' => t('Envoyer le récapitulatif'), 'target' => 'outlook',
                     'instruction' => t('Prépare un email aux participants de @{depart} : trois lignes de synthèse de @{compte_rendu}, puis @{tableau_actions}. Objet : « Compte rendu » suivi du nom de la réunion.'),
                     'write' => 'approve'],
                ],
                'end' => [
                    'title' => t('Livrable'),
                    'pieces' => ['compte_rendu', 'tableau_actions'],
                    'deliveries' => [
                        ['type' => 'drive', 'pieces' => ['compte_rendu', 'tableau_actions'], 'folder' => t('Comptes rendus'), 'mode' => 'approve'],
                    ],
                ],
            ],
        ];
    }

    /** Chaque matin : l'agenda du jour → une fiche par rendez-vous extérieur. */
    private static function rendezVous(): array
    {
        return [
            'name' => t('Préparer mes rendez-vous du jour'),
            'description' => t('Chaque matin de semaine, une fiche par rendez-vous extérieur : derniers échanges, documents, actualité de l’interlocuteur, questions à poser.'),
            'color' => '#84cc16',
            'definition' => [
                'objective' => t('Arriver préparé à chaque rendez-vous de la journée.'),
                'start' => ['title' => t('Départ'), 'fields' => [],
                    'schedule' => ['rule' => ['freq' => 'weekdays', 'time' => '07:30'], 'timezone' => 'Europe/Paris']],
                'nodes' => [
                    ['key' => 'agenda', 'type' => 'step', 'title' => t('Lire l’agenda du jour'), 'sources' => ['calendar'],
                     'instruction' => t('Relève mes rendez-vous d’aujourd’hui avec des personnes extérieures à l’entreprise : pour chacun, sur une ligne, l’heure, le sujet, les interlocuteurs et leur entreprise. Ignore les réunions internes et les créneaux bloqués.'),
                     'output' => ['key' => 'journee', 'name' => t('Ma journée'), 'form' => 'fiche', 'fields' => [
                         ['key' => 'rendez_vous', 'label' => t('Rendez-vous'), 'type' => 'list'],
                         ['key' => 'nombre', 'label' => t('Nombre de rendez-vous'), 'type' => 'number'],
                     ]]],
                    ['key' => 'aucun', 'type' => 'condition', 'title' => t('Aucun rendez-vous ?'), 'mode' => 'rule',
                     'rule' => ['join' => 'all', 'items' => [['piece' => 'journee', 'field' => 'nombre', 'op' => 'eq', 'value' => '0']]],
                     'yes' => ['then' => 'stop', 'nodes' => [
                         ['key' => 'rien', 'type' => 'stop', 'title' => t('Rien à préparer'), 'outcome' => 'done',
                          'message' => t('Aucun rendez-vous extérieur aujourd’hui.')],
                     ]],
                     'no' => ['then' => 'continue', 'nodes' => []]],
                    ['key' => 'preparer', 'type' => 'loop', 'title' => t('Préparer chaque rendez-vous'), 'mode' => 'each',
                     'over' => 'journee', 'over_field' => 'rendez_vous', 'max' => 8,
                     'item' => ['key' => 'rdv', 'name' => t('Rendez-vous en cours')],
                     'collect' => 'fiche_rdv',
                     'output' => ['key' => 'fiches', 'name' => t('Fiches de la journée')],
                     'body' => [
                         ['key' => 'fiche', 'type' => 'step', 'title' => t('Préparer la fiche'), 'sources' => ['gmail', 'drive', 'web'],
                          'filters' => ['mail' => ['days' => 60], 'drive' => ['days' => 180]],
                          'instruction' => t('Pour @{rdv}, retrouve nos derniers échanges par email, les documents partagés et l’actualité récente de l’entreprise. Écris une fiche courte : contexte, ce qui est en cours, points ouverts, trois questions à poser.'),
                          'output' => ['key' => 'fiche_rdv', 'name' => t('Fiche de préparation'), 'form' => 'text']],
                     ]],
                    ['key' => 'pret', 'type' => 'notify', 'title' => t('Prévenir que c’est prêt'),
                     'message' => t('Vos fiches sont prêtes : @{journee.nombre} rendez-vous préparés pour aujourd’hui.')],
                ],
                'end' => ['title' => t('Livrable'), 'pieces' => ['fiches']],
            ],
        ];
    }

    /** Une facture déposée dans Drive → contrôle, accord, paiement programmé, classement, rappel. */
    private static function facture(): array
    {
        return [
            'name' => t('Facture fournisseur → paiement'),
            'description' => t('Chaque facture déposée dans un dossier Drive est lue, contrôlée, validée par la direction au-delà de 5 000 €, programmée, classée et rappelée avant l’échéance.'),
            'color' => '#f43f5e',
            'definition' => [
                'objective' => t('Payer à temps des factures vérifiées, avec l’accord de la direction quand le montant le demande.'),
                'start' => ['title' => t('Départ'), 'chat' => false, 'fields' => [],
                    'event' => ['kind' => 'file', 'folder' => t('Factures fournisseurs'), 'folder_name' => t('Factures fournisseurs'),
                        'filters' => ['drive' => ['kind' => 'pdf']], 'piece_name' => t('Facture reçue'), 'timezone' => 'Europe/Paris']],
                'nodes' => [
                    ['key' => 'lecture', 'type' => 'step', 'title' => t('Lire la facture'),
                     'instruction' => t('Lis @{evenement} et relève ses informations. Vérifie qu’elle porte un numéro, une date, l’identification du fournisseur, le détail hors taxes, TVA et TTC, et que les montants sont cohérents. Note chaque anomalie.'),
                     'output' => ['key' => 'infos', 'name' => t('Informations de la facture'), 'form' => 'fiche', 'fields' => [
                         ['key' => 'fournisseur', 'label' => t('Fournisseur'), 'type' => 'text'],
                         ['key' => 'email_fournisseur', 'label' => t('Email du fournisseur'), 'type' => 'email'],
                         ['key' => 'numero', 'label' => t('Numéro'), 'type' => 'text'],
                         ['key' => 'montant_ttc', 'label' => t('Montant TTC'), 'type' => 'number'],
                         ['key' => 'echeance', 'label' => t('Échéance'), 'type' => 'date'],
                         ['key' => 'conforme', 'label' => t('Conforme'), 'type' => 'yesno'],
                         ['key' => 'anomalies', 'label' => t('Anomalies'), 'type' => 'list'],
                     ]]],
                    ['key' => 'controle', 'type' => 'condition', 'title' => t('Une anomalie ?'), 'mode' => 'rule',
                     'rule' => ['join' => 'all', 'items' => [['piece' => 'infos', 'field' => 'conforme', 'op' => 'no', 'value' => '']]],
                     'yes' => ['then' => 'stop', 'nodes' => [
                         ['key' => 'signaler', 'type' => 'action', 'title' => t('Signaler l’anomalie au fournisseur'), 'target' => 'gmail',
                          'instruction' => t('Écris au fournisseur de @{infos} : la facture ne peut pas être réglée en l’état, à cause des anomalies relevées. Demande une facture rectifiée, poliment et précisément.'),
                          'write' => 'approve', 'filters' => ['recipients' => '@{infos.email_fournisseur}']],
                         ['key' => 'attente', 'type' => 'stop', 'title' => t('Mettre en attente'), 'outcome' => 'stopped',
                          'message' => t('Facture @{infos.numero} mise en attente : anomalie signalée au fournisseur.')],
                     ]],
                     'no' => ['then' => 'continue', 'nodes' => []]],
                    ['key' => 'seuil', 'type' => 'condition', 'title' => t('Au-delà de 5 000 € ?'), 'mode' => 'rule',
                     'rule' => ['join' => 'all', 'items' => [['piece' => 'infos', 'field' => 'montant_ttc', 'op' => 'gt', 'value' => '5000']]],
                     'yes' => ['then' => 'continue', 'nodes' => [
                         ['key' => 'accord', 'type' => 'gate', 'title' => t('Accord de la direction'), 'reviews' => ['infos', 'evenement'], 'days' => 5,
                          'note' => t('Vérifiez le montant et le fournisseur avant d’approuver le paiement.')],
                     ]],
                     'no' => ['then' => 'continue', 'nodes' => []]],
                    ['key' => 'paiement', 'type' => 'action', 'title' => t('Programmer le paiement'), 'target' => 'tasks',
                     'instruction' => t('Crée une tâche de paiement pour la facture décrite par @{infos} : « Payer la facture » suivi du numéro et du fournisseur, le montant TTC en note, à échéance de sa date d’échéance.'),
                     'write' => 'approve'],
                    ['key' => 'classer', 'type' => 'action', 'title' => t('Classer la facture'), 'target' => 'drive',
                     'instruction' => t('Range @{evenement} dans le sous-dossier « Traitées » de son dossier et renomme-la « date d’échéance — fournisseur — numéro » d’après @{infos}.'),
                     'write' => 'approve'],
                    ['key' => 'avant_echeance', 'type' => 'wait', 'title' => t('Trois jours avant l’échéance'), 'mode' => 'date',
                     'piece' => 'infos', 'field' => 'echeance', 'offset' => -3, 'time' => '09:00'],
                    ['key' => 'rappel', 'type' => 'notify', 'title' => t('Rappeler le paiement'),
                     'message' => t('La facture @{infos.numero} de @{infos.fournisseur} (@{infos.montant_ttc} € TTC) arrive à échéance le @{infos.echeance}.')],
                ],
                'end' => ['title' => t('Facture traitée'), 'pieces' => ['infos']],
            ],
        ];
    }

    /** Chaque lundi : chercher, juger, affiner jusqu'à une note sourcée, présenter, publier. */
    private static function veille(): array
    {
        return [
            'name' => t('Veille hebdomadaire'),
            'description' => t('Chaque lundi, les faits de la semaine trouvés sur Internet, une note reprise jusqu’à être sourcée, une présentation, publiées dans Slack et rangées dans Drive.'),
            'color' => '#8b5cf6',
            'definition' => [
                'objective' => t('Savoir chaque semaine ce qui a changé sur un sujet, en deux minutes de lecture.'),
                'start' => [
                    'title' => t('Départ'),
                    'fiche_name' => t('Veille'),
                    'fields' => [
                        ['key' => 'sujet', 'label' => t('Sujet suivi'), 'type' => 'text', 'required' => true, 'default' => t('Nos concurrents')],
                        ['key' => 'perimetre', 'label' => t('Ce qui compte pour nous'), 'type' => 'longtext', 'required' => false,
                         'default' => t('Nouveaux produits, prix, levées de fonds, recrutements clés, partenariats.')],
                    ],
                    'schedule' => ['rule' => ['freq' => 'weekly', 'days' => [1], 'time' => '08:00'], 'timezone' => 'Europe/Paris'],
                ],
                'nodes' => [
                    ['key' => 'recherche', 'type' => 'step', 'title' => t('Chercher les nouveautés'), 'sources' => ['web'],
                     'instruction' => t('Cherche sur Internet ce qui s’est passé ces sept derniers jours à propos de @{depart}. Garde seulement les faits datés de la semaine, chacun avec sa source (titre et lien). Écarte les rumeurs et les reprises d’une même information.'),
                     'output' => ['key' => 'faits', 'name' => t('Faits de la semaine'), 'form' => 'list'],
                     'on_error' => ['retries' => 2, 'then' => 'stop']],
                    ['key' => 'nouveau', 'type' => 'condition', 'title' => t('Du nouveau cette semaine ?'), 'mode' => 'question',
                     'question' => t('@{faits} contient-il au moins un fait nouveau et important au regard de ce qui compte pour nous dans @{depart} ?'),
                     'doubt' => 'no',
                     'yes' => ['then' => 'continue', 'nodes' => []],
                     'no' => ['then' => 'stop', 'nodes' => [
                         ['key' => 'calme', 'type' => 'stop', 'title' => t('Rien à signaler'), 'outcome' => 'done',
                          'message' => t('Rien de notable cette semaine : pas de note.')],
                     ]]],
                    ['key' => 'affiner', 'type' => 'loop', 'title' => t('Affiner la note'), 'mode' => 'until', 'max' => 3, 'exhausted' => 'continue',
                     'collect' => 'note',
                     'output' => ['key' => 'note_finale', 'name' => t('Note de la semaine')],
                     'until' => ['mode' => 'question',
                         'question' => t('@{note} cite-t-elle une source pour chaque fait, sans répétition, et se lit-elle en deux minutes ?')],
                     'body' => [
                         ['key' => 'rediger', 'type' => 'step', 'title' => t('Rédiger la note'),
                          'instruction' => t('À partir de @{faits}, écris la note de veille : trois à cinq faits marquants, pour chacun ce qui change pour nous et sa source entre parenthèses. Une page au plus.'),
                          'output' => ['key' => 'note', 'name' => t('Note de veille'), 'form' => 'text']],
                     ]],
                    ['key' => 'slides', 'type' => 'step', 'title' => t('Mettre en présentation'),
                     'instruction' => t('Transforme @{note_finale} en présentation de cinq slides : une slide de titre, une slide par fait marquant, une slide « Ce que nous devrions faire ».'),
                     'output' => ['key' => 'presentation', 'name' => t('Présentation de veille'), 'form' => 'document', 'format' => 'pptx', 'template' => true]],
                ],
                'end' => [
                    'title' => t('Livrable'),
                    'pieces' => ['note_finale', 'presentation', 'faits'],
                    'deliveries' => [
                        ['type' => 'slack', 'channel' => '#veille', 'mode' => 'approve',
                         'message' => t('La veille de la semaine — @{depart.sujet}') . "\n\n@{note_finale}"],
                        ['type' => 'drive', 'pieces' => ['presentation'], 'folder' => t('Veille'), 'mode' => 'approve'],
                    ],
                ],
            ],
        ];
    }

    /** Un évènement → brief, affiche, page d'invitation, plan d'accès, agenda, invitation envoyée. */
    private static function lancement(): array
    {
        return [
            'name' => t('Lancer un évènement'),
            'description' => t('Un brief, puis une affiche, une page d’invitation et un plan d’accès faits par vos outils de création, validés ; la date bloquée dans l’agenda et l’invitation envoyée.'),
            'color' => '#f59e0b',
            'definition' => [
                'objective' => t('Annoncer un évènement avec des supports soignés et cohérents, en une seule fois.'),
                'start' => ['title' => t('Départ'), 'fiche_name' => t('Évènement'), 'fields' => [
                    ['key' => 'evenement', 'label' => t('Nom de l’évènement'), 'type' => 'text', 'required' => true],
                    ['key' => 'date', 'label' => t('Date'), 'type' => 'date', 'required' => true],
                    ['key' => 'lieu', 'label' => t('Adresse du lieu'), 'type' => 'text', 'required' => true],
                    ['key' => 'public', 'label' => t('Pour qui'), 'type' => 'text', 'required' => false, 'default' => t('Nos clients et partenaires')],
                    ['key' => 'ton', 'label' => t('Ton'), 'type' => 'choice', 'required' => true, 'options' => [t('Chaleureux'), t('Professionnel'), t('Festif')], 'default' => t('Chaleureux')],
                    ['key' => 'destinataires', 'label' => t('Envoyer l’invitation à'), 'type' => 'email', 'required' => true, 'help' => t('Une adresse, par exemple une liste de diffusion.')],
                ]],
                'nodes' => [
                    ['key' => 'brief', 'type' => 'step', 'title' => t('Écrire le brief'),
                     'instruction' => t('À partir de @{depart}, écris le brief de l’évènement : un titre court, une accroche d’une phrase, le programme en quelques temps forts et trois mots qui disent l’ambiance, fidèles au ton choisi.'),
                     'output' => ['key' => 'brief_evenement', 'name' => t('Brief'), 'form' => 'fiche', 'fields' => [
                         ['key' => 'titre', 'label' => t('Titre'), 'type' => 'text'],
                         ['key' => 'accroche', 'label' => t('Accroche'), 'type' => 'text'],
                         ['key' => 'programme', 'label' => t('Programme'), 'type' => 'list'],
                         ['key' => 'ambiance', 'label' => t('Ambiance'), 'type' => 'text'],
                     ]]],
                    ['key' => 'affiche', 'type' => 'create', 'kind' => 'image', 'title' => t('Créer l’affiche'), 'review' => true,
                     'options' => ['format' => 'portrait'],
                     'instruction' => t('Affiche de l’évènement décrit par @{brief_evenement} : composition simple et lumineuse dans l’ambiance du brief, aucun texte, de la place en haut pour le titre.'),
                     'output' => ['key' => 'affiche_evenement', 'name' => t('Affiche')]],
                    ['key' => 'invitation', 'type' => 'create', 'kind' => 'page', 'title' => t('Créer la page d’invitation'),
                     'instruction' => t('Page d’invitation à l’évènement de @{brief_evenement} : @{affiche_evenement} en bannière, le titre et l’accroche, la date et l’adresse de @{depart}, le programme, un bouton « Je m’inscris » qui ouvre un email de réponse. Sobre et lisible sur téléphone.'),
                     'output' => ['key' => 'page_invitation', 'name' => t('Page d’invitation')]],
                    ['key' => 'plan', 'type' => 'create', 'kind' => 'map', 'title' => t('Tracer le plan d’accès'),
                     'instruction' => t('Itinéraire à pied depuis la gare la plus proche jusqu’à l’adresse du lieu de @{depart}, avec les repères utiles en chemin.'),
                     'output' => ['key' => 'plan_acces', 'name' => t('Plan d’accès')]],
                    ['key' => 'validation', 'type' => 'gate', 'title' => t('Valider l’invitation'), 'reviews' => ['page_invitation', 'plan_acces'],
                     'returns_to' => 'invitation', 'can_correct' => false, 'can_return' => true, 'days' => 3,
                     'note' => t('Renvoyez avec une remarque pour faire reprendre la page : elle repart de sa version.')],
                    ['key' => 'agenda', 'type' => 'action', 'title' => t('Bloquer la date'), 'target' => 'calendar',
                     'instruction' => t('Crée dans mon agenda l’évènement de @{brief_evenement} à la date et à l’adresse de @{depart}, avec l’accroche en description.'),
                     'write' => 'approve'],
                ],
                'end' => [
                    'title' => t('Supports de l’évènement'),
                    'pieces' => ['affiche_evenement', 'page_invitation', 'plan_acces', 'brief_evenement'],
                    'deliveries' => [
                        ['type' => 'email', 'source' => 'gmail', 'to_field' => 'depart.destinataires', 'mode' => 'approve',
                         'attach' => ['affiche_evenement', 'page_invitation', 'plan_acces'],
                         'subject' => t('Invitation : @{brief_evenement.titre}'),
                         'message' => implode("\n\n", [t('Bonjour,'), '@{brief_evenement.accroche}', t('Rendez-vous le @{depart.date}, @{depart.lieu}.'),
                             t('L’invitation et le plan d’accès sont joints. Au plaisir de vous y retrouver !')])],
                    ],
                ],
            ],
        ];
    }

    /** Une arrivée → livret relu, poste prêt, bienvenue la veille, point à un mois. */
    private static function accueil(): array
    {
        return [
            'name' => t('Accueil d’un nouveau collaborateur'),
            'description' => t('Un livret d’accueil relu, les rendez-vous et le poste préparés, un mot de bienvenue la veille de l’arrivée, puis le point à un mois préparé pour le responsable.'),
            'color' => '#d946ef',
            'definition' => [
                'objective' => t('Que chaque arrivée soit préparée, et suivie jusqu’au premier mois.'),
                'start' => ['title' => t('Départ'), 'fiche_name' => t('Arrivée'), 'fields' => [
                    ['key' => 'personne', 'label' => t('Nom de la personne'), 'type' => 'text', 'required' => true],
                    ['key' => 'poste', 'label' => t('Poste'), 'type' => 'text', 'required' => true],
                    ['key' => 'equipe', 'label' => t('Équipe'), 'type' => 'text', 'required' => false],
                    ['key' => 'email', 'label' => t('Email de la personne'), 'type' => 'email', 'required' => true],
                    ['key' => 'responsable', 'label' => t('Email du responsable'), 'type' => 'email', 'required' => true],
                    ['key' => 'arrivee', 'label' => t('Date d’arrivée'), 'type' => 'date', 'required' => true],
                ]],
                'nodes' => [
                    ['key' => 'livret', 'type' => 'step', 'title' => t('Préparer le livret d’accueil'), 'review' => true,
                     'instruction' => t('Écris le livret d’accueil de @{depart} pour sa première semaine : mot d’accueil, programme jour par jour (rencontres, outils à ouvrir, formations), personnes à connaître et premier objectif.'),
                     'output' => ['key' => 'livret_accueil', 'name' => t('Livret d’accueil'), 'form' => 'document', 'format' => 'pdf', 'template' => true]],
                    ['key' => 'rencontres', 'type' => 'action', 'title' => t('Poser les rendez-vous'), 'target' => 'calendar',
                     'instruction' => t('Crée dans mon agenda les rendez-vous de la première semaine prévus par @{livret_accueil}, à partir de la date d’arrivée de @{depart}.'),
                     'write' => 'approve'],
                    ['key' => 'poste_pret', 'type' => 'action', 'title' => t('Préparer le poste'), 'target' => 'tasks',
                     'instruction' => t('Crée les tâches pour que tout soit prêt à l’arrivée de @{depart} : compte et accès, matériel, badge, place. Échéance : trois jours ouvrés avant la date d’arrivée.'),
                     'write' => 'approve'],
                    ['key' => 'veille_arrivee', 'type' => 'wait', 'title' => t('La veille de l’arrivée'), 'mode' => 'date',
                     'piece' => 'depart', 'field' => 'arrivee', 'offset' => -1, 'time' => '16:00'],
                    ['key' => 'bienvenue', 'type' => 'action', 'title' => t('Envoyer le mot de bienvenue'), 'target' => 'gmail',
                     'instruction' => t('Écris à la personne de @{depart} un mot de bienvenue chaleureux pour demain : heure et lieu d’arrivée, qui l’accueillera, programme de sa première journée d’après @{livret_accueil}.'),
                     'write' => 'approve', 'filters' => ['recipients' => '@{depart.email}']],
                    ['key' => 'un_mois', 'type' => 'wait', 'title' => t('Un mois plus tard'), 'mode' => 'delay', 'amount' => 30, 'unit' => 'days'],
                    ['key' => 'point', 'type' => 'step', 'title' => t('Préparer le point à un mois'),
                     'instruction' => t('Prépare la trame du point à un mois de @{depart} avec son responsable : ce qui était prévu dans @{livret_accueil}, les questions à poser (intégration, outils, objectifs, besoins) et trois pistes pour la suite.'),
                     'output' => ['key' => 'trame_point', 'name' => t('Trame du point à un mois'), 'form' => 'text']],
                    ['key' => 'rappel_point', 'type' => 'notify', 'title' => t('Prévenir du point à un mois'),
                     'message' => t('C’est le moment du point à un mois avec @{depart.personne} : la trame est prête.')],
                ],
                'end' => [
                    'title' => t('Livrable'),
                    'pieces' => ['livret_accueil', 'trame_point'],
                    'deliveries' => [
                        ['type' => 'email', 'source' => 'gmail', 'to_field' => 'depart.responsable', 'mode' => 'approve',
                         'subject' => t('Point à un mois — @{depart.personne}'),
                         'message' => implode("\n\n", [t('Bonjour,'), t('Cela fait un mois que @{depart.personne} nous a rejoints. Voici une trame pour votre point d’étape :'), '@{trame_point}'])],
                    ],
                ],
            ],
        ];
    }

    /** Le formulaire du site appelle l'adresse du workflow → qualification, alerte, réponse adaptée. */
    private static function demandeSite(): array
    {
        return [
            'name' => t('Demande de démo depuis le site'),
            'description' => t('Le formulaire de votre site appelle l’adresse du workflow : MIA qualifie le prospect, vous prévient s’il est prometteur et prépare une réponse adaptée.'),
            'color' => '#f97316',
            'definition' => [
                'objective' => t('Répondre vite et juste à chaque demande de démonstration.'),
                'start' => ['title' => t('Départ'), 'fiche_name' => t('Formulaire'), 'chat' => false,
                    'event' => ['kind' => 'webhook', 'piece_name' => t('Formulaire reçu'), 'timezone' => 'Europe/Paris'],
                    'fields' => [
                        ['key' => 'nom', 'label' => t('Nom'), 'type' => 'text', 'required' => true],
                        ['key' => 'email', 'label' => t('Email'), 'type' => 'email', 'required' => true],
                        ['key' => 'entreprise', 'label' => t('Entreprise'), 'type' => 'text', 'required' => false],
                        ['key' => 'message', 'label' => t('Message'), 'type' => 'longtext', 'required' => false],
                    ]],
                'nodes' => [
                    ['key' => 'qualifier', 'type' => 'step', 'title' => t('Qualifier le prospect'), 'sources' => ['web'],
                     'instruction' => t('À partir de @{depart}, renseigne-toi sur l’entreprise sur Internet : secteur, taille, actualité. Évalue l’intérêt de la demande sur 10 (adéquation avec notre offre, maturité du besoin, taille) et dis pourquoi en une phrase.'),
                     'output' => ['key' => 'qualification', 'name' => t('Qualification'), 'form' => 'fiche', 'fields' => [
                         ['key' => 'secteur', 'label' => t('Secteur'), 'type' => 'text'],
                         ['key' => 'effectif', 'label' => t('Effectif'), 'type' => 'number'],
                         ['key' => 'besoin', 'label' => t('Besoin exprimé'), 'type' => 'text'],
                         ['key' => 'score', 'label' => t('Score sur 10'), 'type' => 'number'],
                         ['key' => 'raison', 'label' => t('Pourquoi ce score'), 'type' => 'text'],
                         ['key' => 'budget', 'label' => t('Budget évoqué'), 'type' => 'yesno'],
                     ]]],
                    ['key' => 'prometteur', 'type' => 'condition', 'title' => t('Prospect prometteur ?'), 'mode' => 'rule',
                     'rule' => ['join' => 'any', 'items' => [
                         ['piece' => 'qualification', 'field' => 'score', 'op' => 'gte', 'value' => '7'],
                         ['piece' => 'qualification', 'field' => 'budget', 'op' => 'yes', 'value' => ''],
                     ]],
                     'yes' => ['then' => 'continue', 'nodes' => [
                         ['key' => 'alerte', 'type' => 'notify', 'title' => t('Me prévenir tout de suite'),
                          'message' => t('Prospect prometteur : @{depart.nom} (@{depart.entreprise}), @{qualification.score}/10. @{qualification.raison}')],
                     ]],
                     'no' => ['then' => 'continue', 'nodes' => []]],
                    ['key' => 'reponse', 'type' => 'action', 'title' => t('Répondre au prospect'), 'target' => 'gmail',
                     'instruction' => t('Réponds à la personne de @{depart} : remercie-la et reprends son besoin en une phrase. Si @{qualification} donne un score de 7 ou plus, propose un appel cette semaine ; sinon, propose notre documentation et un rendez-vous plus tard.'),
                     'write' => 'approve', 'filters' => ['recipients' => '@{depart.email}']],
                ],
                'end' => ['title' => t('Livrable'), 'pieces' => ['qualification']],
            ],
        ];
    }

    /** Un fichier Excel de suivi → une relance par client en retard, relues ensemble avant de partir. */
    private static function relanceClients(): array
    {
        return [
            'name' => t('Relancer chaque client d’un fichier Excel'),
            'description' => t('Votre fichier de suivi parcouru ligne par ligne : une relance rédigée pour chaque client qui doit encore de l’argent, toutes relues ensemble avant de partir.'),
            'color' => '#16a34a',
            'definition' => [
                'objective' => t('Relancer chaque client qui doit encore de l’argent, avec un email personnalisé, sans oublier personne ni écrire deux fois au même.'),
                'start' => ['title' => t('Départ'), 'fiche_name' => t('Fichier de suivi'), 'fields' => [
                    ['key' => 'fichier', 'label' => t('Fichier des clients'), 'type' => 'file', 'required' => true,
                     'help' => t('Un classeur Excel ou CSV, une ligne par client, avec les colonnes Client, Email, Montant dû et Échéance.')],
                ]],
                'nodes' => [
                    ['key' => 'clients', 'type' => 'loop', 'title' => t('Relancer chaque client'), 'mode' => 'each',
                     'over' => 'fichier', 'max' => 100,
                     'source' => ['kind' => 'rows', 'from' => 'piece', 'label' => 'client', 'columns' => [
                         ['key' => 'client', 'label' => t('Client'), 'type' => 'text'],
                         ['key' => 'email', 'label' => t('Email'), 'type' => 'email'],
                         ['key' => 'montant', 'label' => t('Montant dû'), 'type' => 'number'],
                         ['key' => 'echeance', 'label' => t('Échéance'), 'type' => 'date'],
                     ]],
                     // Seulement ceux qui doivent encore quelque chose, une seule fois par adresse.
                     'filter' => ['rule' => ['join' => 'all', 'items' => [['piece' => 'client_en_cours', 'field' => 'montant', 'op' => 'gt', 'value' => '0']]],
                         'dedupe' => 'email', 'require_label' => true],
                     'item' => ['key' => 'client_en_cours', 'name' => t('Client en cours')],
                     'review_actions' => true,
                     'collect' => 'relance',
                     // Le fichier complété : ses colonnes, puis l'objet et le message de chaque relance.
                     'output' => ['key' => 'relances', 'name' => t('Suivi des relances')],
                     'body' => [
                         ['key' => 'rediger', 'type' => 'step', 'title' => t('Rédiger la relance'), 'sources' => ['gmail'],
                          'instruction' => t('Rédige un email de relance courtois pour @{client_en_cours} : un objet court, puis un message qui rappelle le montant dû et l’échéance et propose de régler ou de nous appeler en cas de difficulté. Regarde dans Gmail si nous avons échangé récemment avec ce client et tiens-en compte. Message de cinq à huit lignes, sans formule de fin.'),
                          'output' => ['key' => 'relance', 'name' => t('Email de relance'), 'form' => 'fiche', 'fields' => [
                              ['key' => 'objet', 'label' => t('Objet'), 'type' => 'text'],
                              ['key' => 'message', 'label' => t('Message'), 'type' => 'text'],
                          ]]],
                         ['key' => 'preparer', 'type' => 'action', 'title' => t('Préparer l’email'), 'target' => 'gmail',
                          'instruction' => t('Prépare un email à l’adresse de @{client_en_cours}, avec pour objet @{relance.objet} et pour message @{relance.message}.'),
                          'write' => 'approve', 'filters' => ['recipients' => '@{client_en_cours.email}']],
                     ]],
                ],
                'end' => ['title' => t('Relances du jour'), 'pieces' => ['relances'], 'deliveries' => []],
            ],
        ];
    }

    /** Un email avec des pièces jointes → chaque document lu, une tâche pour ceux qui demandent une action. */
    private static function piecesJointes(): array
    {
        return [
            'name' => t('Analyser chaque pièce jointe reçue'),
            'description' => t('Un email arrive avec des pièces jointes : MIA lit chacune, garde celles qui demandent une action, et une tâche est préparée pour chacune.'),
            'color' => '#0891b2',
            'definition' => [
                'objective' => t('Qu’aucun document reçu par email ne reste sans suite : chaque pièce jointe lue, résumée et, si besoin, transformée en tâche.'),
                'start' => ['title' => t('Départ'), 'chat' => false, 'fields' => [],
                    'event' => ['kind' => 'email', 'source' => 'gmail', 'query' => '', 'filters' => ['mail' => ['attachment' => true]],
                        'piece_name' => t('Email reçu'), 'timezone' => 'Europe/Paris']],
                'nodes' => [
                    ['key' => 'documents', 'type' => 'loop', 'title' => t('Traiter chaque pièce jointe'), 'mode' => 'each',
                     'over' => 'evenement', 'max' => 20,
                     'source' => ['kind' => 'attachments', 'from' => 'piece', 'types' => 'all', 'inline' => false],
                     'item' => ['key' => 'document', 'name' => t('Pièce jointe en cours')],
                     'review_actions' => true,
                     'collect' => 'analyse',
                     'output' => ['key' => 'analyses', 'name' => t('Pièces jointes analysées')],
                     'body' => [
                         ['key' => 'lire', 'type' => 'step', 'title' => t('Lire la pièce jointe'),
                          'instruction' => t('Lis @{document}, reçu avec @{evenement}. Dis ce que c’est, ce qu’il contient d’important, et s’il demande une action de notre part (payer, signer, répondre, transmettre) avec sa date limite.'),
                          'output' => ['key' => 'analyse', 'name' => t('Analyse du document'), 'form' => 'fiche', 'fields' => [
                              ['key' => 'nature', 'label' => t('Nature du document'), 'type' => 'text'],
                              ['key' => 'resume', 'label' => t('Résumé'), 'type' => 'text'],
                              ['key' => 'action', 'label' => t('Action à mener'), 'type' => 'text'],
                              ['key' => 'a_traiter', 'label' => t('À traiter'), 'type' => 'yesno'],
                              ['key' => 'date_limite', 'label' => t('Date limite'), 'type' => 'date'],
                          ]]],
                         // Une condition dans la boucle : un document sans action passe au suivant.
                         ['key' => 'garder', 'type' => 'condition', 'title' => t('Une action à mener ?'), 'mode' => 'rule',
                          'rule' => ['join' => 'all', 'items' => [['piece' => 'analyse', 'field' => 'a_traiter', 'op' => 'yes', 'value' => '']]],
                          'yes' => ['then' => 'continue', 'nodes' => []], 'no' => ['then' => 'skip', 'nodes' => []]],
                         ['key' => 'tache', 'type' => 'action', 'title' => t('Préparer la tâche'), 'target' => 'tasks',
                          'instruction' => t('Crée une tâche pour l’action décrite par @{analyse}, avec sa date limite. Dans les notes, indique le nom du document @{document} et l’expéditeur de @{evenement}.'),
                          'write' => 'approve'],
                     ]],
                ],
                'end' => ['title' => t('Pièces jointes traitées'), 'pieces' => ['analyses'], 'deliveries' => []],
            ],
        ];
    }
}
