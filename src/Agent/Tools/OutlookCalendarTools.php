<?php

declare(strict_types=1);

/**
 * Outils Calendrier Outlook du chat (OAuth délégué) : agendas, recherche et lecture
 * d'évènements, création soumise à approbation (jamais de participant).
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : les
 * définitions, la consigne et les handlers sont ceux du contrôleur, déplacés
 * tels quels. Toute erreur revient AU MODÈLE en ['error' => message FR] —
 * jamais une exception qui casserait le flux ; toute écriture passe par la
 * file d'approbation ($this->turn->queueWrite), rien n'est exécuté pendant
 * le flux.
 */
final class OutlookCalendarTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly UserOutlookCalendarService $calendar)
    {
        parent::__construct($turn);
        // Heures lues et affichées dans le fuseau du navigateur (cf. AgentTurn).
        $calendar->setTimeZone($turn->timeZone);
    }

    public function key(): string
    {
        return 'mscalendar';
    }

    public function connector(): ?string
    {
        return 'mscalendar';
    }

    public function definitions(): array
    {
        return $this->msCalendarToolDefinitions($this->calendar);
    }

    public function guidance(): string
    {
        return $this->msCalendarToolGuidance($this->calendar);
    }

    public function externalContentTools(): array
    {
        return [
            'rechercher_agenda_outlook',
            'lire_evenement_agenda_outlook',
        ];
    }

    public function handlers(): array
    {
        $msCalendarService    = $this->calendar;
        $msCalendarTextBudget = $this->turn->textBudget;
        $queueConnectorWrite  = $this->turn->queueWrite;
        $toolHandlers = [];

        $toolHandlers['lister_agendas_outlook'] = function (array $args) use ($msCalendarService) {
            $this->sseEmit(['phase' => 'mscalendar_search']);
            try {
                return ['agendas' => $msCalendarService->listCalendars()];
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['rechercher_agenda_outlook'] = function (array $args) use ($msCalendarService) {
            $this->sseEmit(['phase' => 'mscalendar_search']);
            try {
                return self::hintIfEmpty(['evenements' => $msCalendarService->searchEvents(
                    (string)($args['agenda'] ?? ''),
                    (string)($args['requete'] ?? ''),
                    (string)($args['debut'] ?? ''),
                    (string)($args['fin'] ?? ''),
                    (int)($args['max_resultats'] ?? 10)
                )], 'evenements', "Aucun évènement trouvé. Élargis la fenêtre (debut/fin), retire le filtre texte, ou vérifie l'agenda visé avec lister_agendas_outlook.");
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lire_evenement_agenda_outlook'] = function (array $args) use ($msCalendarService, $msCalendarTextBudget) {
            $this->sseEmit(['phase' => 'mscalendar_read']);
            try {
                return $msCalendarService->readEvent(
                    (string)($args['evenement'] ?? ''),
                    $msCalendarTextBudget
                );
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['trouver_creneaux_outlook'] = function (array $args) use ($msCalendarService) {
            $this->sseEmit(['phase' => 'mscalendar_slots']);
            try {
                return self::hintIfEmpty(
                    FreeSlots::find($msCalendarService, $args, $this->turn->timeZone, new \DateTimeImmutable()),
                    'creneaux',
                    "Aucun créneau libre sur cette période. Élargis la fenêtre (fin), réduis la durée, ou assouplis les heures de travail (heure_debut, heure_fin, jours_ouvres)."
                );
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        if ($msCalendarService->canWrite()) {
            $toolHandlers['creer_evenement_agenda_outlook'] = function (array $args) use ($queueConnectorWrite) {
                // Le fuseau de l'utilisateur VOYAGE avec l'action : elle sera
                // exécutée au clic « Approuver », dans une requête qui ne
                // connaît plus le navigateur (cf. UserCalendarService::argTimeZone).
                if ($this->turn->timeZone !== '' && trim((string)($args['fuseau'] ?? '')) === '') {
                    $args['fuseau'] = $this->turn->timeZone;
                }
                // Validation amont (titre, dates, refus des participants) :
                // une erreur revient au modèle, jamais dans une carte cassée.
                try {
                    UserOutlookCalendarService::buildEventPayload($args);
                    UserOutlookCalendarService::normalizeCalendarId((string)($args['agenda'] ?? ''));
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('creer_evenement_agenda_outlook', $args, null);
            };
        }

        return $toolHandlers;
    }

    /** Définitions function-calling des outils Calendrier Outlook (selon les scopes accordés). */
    private function msCalendarToolDefinitions(UserOutlookCalendarService $calendar): array
    {
        $defs = [
            [
                'name'        => 'lister_agendas_outlook',
                'description' => "Liste les agendas du calendrier Outlook de l'utilisateur (id, nom, "
                    . "principal, modifiable). Utile UNIQUEMENT quand l'utilisateur mentionne un agenda "
                    . "spécifique (« mon agenda perso », « l'agenda équipe ») : par défaut, travaille sur "
                    . "l'agenda principal sans appeler cet outil.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'rechercher_agenda_outlook',
                'description' => "Recherche des évènements dans le calendrier Outlook PERSONNEL de "
                    . "l'utilisateur sur une plage de dates (défaut : d'aujourd'hui à +90 jours), avec "
                    . "filtre texte optionnel (titre, lieu, aperçu, organisateur, participants). Les "
                    . "occurrences des évènements récurrents sont développées. Renvoie pour chaque "
                    . "évènement : id, agenda, titre, début, fin, journée entière, lieu, annulé. À "
                    . "utiliser AVANT lire_evenement_agenda_outlook pour obtenir l'id d'un évènement.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'requete' => [
                            'type'        => 'string',
                            'description' => "Filtre texte optionnel (ex. « réunion budget », un nom de participant).",
                        ],
                        'debut' => [
                            'type'        => 'string',
                            'description' => "Début de la fenêtre, format AAAA-MM-JJ (défaut : aujourd'hui). Pour le passé, mets une date antérieure.",
                        ],
                        'fin' => [
                            'type'        => 'string',
                            'description' => "Fin de la fenêtre (incluse), format AAAA-MM-JJ (défaut : début + 90 jours).",
                        ],
                        'agenda' => [
                            'type'        => 'string',
                            'description' => "Id de l'agenda (via lister_agendas_outlook). Vide = agenda principal.",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum d'évènements (1 à 25). Défaut : 10.",
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'lire_evenement_agenda_outlook',
                'description' => "Lit un évènement complet du calendrier Outlook de l'utilisateur : titre, "
                    . "bornes, lieu, description, organisateur, participants, lien de visioconférence et "
                    . "lien Outlook. Lecture seule.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'evenement' => [
                            'type'        => 'string',
                            'description' => "ID de l'évènement (obtenu via rechercher_agenda_outlook).",
                        ],
                    ],
                    'required'   => ['evenement'],
                ],
            ],
        ];

        // Créneaux libres : définition commune aux deux agendas (FreeSlots).
        $defs[] = FreeSlots::toolDefinition('trouver_creneaux_outlook', "le calendrier Outlook", "visibles s'ils appartiennent au même annuaire Microsoft");

        if ($calendar->canWrite()) {
            $defs[] = [
                'name'        => 'creer_evenement_agenda_outlook',
                'description' => "Crée un évènement dans le calendrier Outlook de l'utilisateur. Chaque "
                    . "création est soumise à son approbation explicite avant exécution (carte de "
                    . "confirmation). AUCUN participant ne peut être ajouté : Microsoft enverrait "
                    . "l'invitation aussitôt, et MIA n'envoie jamais de message au nom de l'utilisateur.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'titre'  => ['type' => 'string', 'description' => "Titre de l'évènement."],
                        'debut'  => ['type' => 'string', 'description' => "Début : AAAA-MM-JJ HH:MM (ou AAAA-MM-JJ pour une journée entière)."],
                        'fin'    => ['type' => 'string', 'description' => "Fin : AAAA-MM-JJ HH:MM (défaut : début + 1 h). Journée entière : AAAA-MM-JJ du dernier jour."],
                        'journee_entiere' => ['type' => 'boolean', 'description' => "true pour un évènement sur la ou les journées entières."],
                        'description' => ['type' => 'string', 'description' => "Description de l'évènement (optionnel)."],
                        'lieu'    => ['type' => 'string', 'description' => "Lieu (optionnel)."],
                        'agenda'  => ['type' => 'string', 'description' => "Id de l'agenda cible. Vide = agenda principal."],
                    ],
                    'required'   => ['titre', 'debut'],
                ],
            ];
        }
        return $defs;
    }

    /** Consigne ajoutée au system prompt quand les outils Calendrier Outlook sont disponibles. */
    private function msCalendarToolGuidance(UserOutlookCalendarService $calendar): string
    {
        // Date courante indispensable : « demain », « la semaine prochaine »…
        // se traduisent en dates absolues pour rechercher_agenda_outlook.
        $daysFr = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
        // Date du jour dans le fuseau de l'UTILISATEUR (le serveur tourne en UTC).
        $now    = new \DateTimeImmutable('now', FreeSlots::zone($this->turn->timeZone));

        $txt = "\n\nTu disposes des outils `rechercher_agenda_outlook` et `lire_evenement_agenda_outlook` "
            . "pour consulter le calendrier Outlook personnel de l'utilisateur. Nous sommes le "
            . $daysFr[(int)$now->format('N')] . ' ' . $now->format('d/m/Y')
            . " : convertis les expressions relatives (« demain », « la semaine prochaine ») en dates "
            . "AAAA-MM-JJ avant d'appeler `rechercher_agenda_outlook`. Pour des évènements passés, "
            . "fournis explicitement `debut` dans le passé. Cite toujours la date et l'heure des "
            . "évènements que tu rapportes, et signale ceux marqués annulés. Pour trouver un moment LIBRE "
            . "(« quand suis-je dispo ? », « trouve une heure avec Paul la semaine prochaine »), appelle "
            . "`trouver_creneaux_outlook` : il calcule les créneaux pour toi, participants compris quand ils sont dans "
            . "le même annuaire ; propose ensuite le créneau et, si l'utilisateur le veut, crée l'évènement "
            . "avec son `debut`. Si un outil renvoie un champ "
            . "`error`, transmets ce message tel quel à l'utilisateur sans l'inventer ni le reformuler.";

        if ($calendar->canWrite()) {
            $txt .= " Tu peux créer des évènements avec `creer_evenement_agenda_outlook`, uniquement sur "
                . "demande claire de l'utilisateur. IMPORTANT : chaque création est soumise à son "
                . "approbation — l'outil renvoie `en_attente_approbation` et une carte s'affiche sous ta "
                . "réponse. N'affirme JAMAIS que l'évènement a été créé : annonce qu'il attend son "
                . "approbation dans la carte ci-dessous, en résumant titre et date. Tu ne peux PAS ajouter "
                . "de participants (Microsoft enverrait l'invitation) : crée l'évènement sans invité et "
                . "invite l'utilisateur à les ajouter lui-même dans Outlook. Ne modifie ni ne supprime "
                . "jamais d'évènement existant : aucun outil n'existe pour cela.";
        } else {
            $txt .= " Tu ne peux PAS créer d'évènements : la connexion actuelle au calendrier Outlook est "
                . "en lecture seule. Si l'utilisateur le demande, invite-le à reconnecter son calendrier "
                . "dans Réglages → Comptes connectés.";
        }
        return $txt;
    }
}
