<?php

declare(strict_types=1);

/**
 * Outils Google Agenda du chat (agenda personnel, OAuth) : agendas, recherche et lecture
 * d'évènements, création soumise à approbation.
 *
 * Extrait de ChatController::runSend (registre d'outils, src/Agent/) : les
 * définitions, la consigne et les handlers sont ceux du contrôleur, déplacés
 * tels quels. Toute erreur revient AU MODÈLE en ['error' => message FR] —
 * jamais une exception qui casserait le flux ; toute écriture passe par la
 * file d'approbation ($this->turn->queueWrite), rien n'est exécuté pendant
 * le flux.
 */
final class GoogleCalendarTools extends AbstractToolProvider
{
    public function __construct(AgentTurn $turn, private readonly UserCalendarService $calendar)
    {
        parent::__construct($turn);
        // Heures lues et affichées dans le fuseau du navigateur (cf. AgentTurn).
        $calendar->setTimeZone($turn->timeZone);
    }

    public function key(): string
    {
        return 'calendar';
    }

    public function connector(): ?string
    {
        return 'calendar';
    }

    public function definitions(): array
    {
        return $this->calendarToolDefinitions($this->calendar);
    }

    public function guidance(): string
    {
        return $this->calendarToolGuidance($this->calendar);
    }

    public function externalContentTools(): array
    {
        return [
            'rechercher_agenda',
            'lire_evenement_agenda',
        ];
    }

    public function handlers(): array
    {
        $calendarService     = $this->calendar;
        $calendarTextBudget  = $this->turn->textBudget;
        $queueConnectorWrite = $this->turn->queueWrite;
        $toolHandlers = [];

        $toolHandlers['lister_agendas'] = function (array $args) use ($calendarService) {
            $this->sseEmit(['phase' => 'calendar_search']);
            try {
                return ['agendas' => $calendarService->listCalendars()];
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['rechercher_agenda'] = function (array $args) use ($calendarService) {
            $this->sseEmit(['phase' => 'calendar_search']);
            try {
                return self::hintIfEmpty(['evenements' => $calendarService->searchEvents(
                    (string)($args['agenda'] ?? ''),
                    (string)($args['requete'] ?? ''),
                    (string)($args['debut'] ?? ''),
                    (string)($args['fin'] ?? ''),
                    (int)($args['max_resultats'] ?? 10)
                )], 'evenements', "Aucun évènement trouvé. Élargis la fenêtre (debut/fin), retire le filtre texte, ou vérifie l'agenda visé avec lister_agendas.");
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['lire_evenement_agenda'] = function (array $args) use ($calendarService, $calendarTextBudget) {
            $this->sseEmit(['phase' => 'calendar_read']);
            try {
                return $calendarService->readEvent(
                    (string)($args['agenda'] ?? ''),
                    (string)($args['evenement'] ?? ''),
                    $calendarTextBudget
                );
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $toolHandlers['trouver_creneaux_agenda'] = function (array $args) use ($calendarService) {
            $this->sseEmit(['phase' => 'calendar_slots']);
            try {
                return self::hintIfEmpty(
                    FreeSlots::find($calendarService, $args, $this->turn->timeZone, new \DateTimeImmutable()),
                    'creneaux',
                    "Aucun créneau libre sur cette période. Élargis la fenêtre (fin), réduis la durée, ou assouplis les heures de travail (heure_debut, heure_fin, jours_ouvres)."
                );
            } catch (\RuntimeException $e) {
                return ['error' => $e->getMessage()];
            }
        };
        if ($calendarService->canWrite()) {
            $toolHandlers['creer_evenement_agenda'] = function (array $args) use ($queueConnectorWrite) {
                // Le fuseau de l'utilisateur VOYAGE avec l'action : elle sera
                // exécutée au clic « Approuver », dans une requête qui ne
                // connaît plus le navigateur (cf. UserCalendarService::argTimeZone).
                if ($this->turn->timeZone !== '' && trim((string)($args['fuseau'] ?? '')) === '') {
                    $args['fuseau'] = $this->turn->timeZone;
                }
                // Validation amont (titre, dates, participants) : une erreur
                // revient au modèle, jamais dans une carte cassée.
                try {
                    UserCalendarService::buildEventPayload($args);
                    UserCalendarService::normalizeCalendarId((string)($args['agenda'] ?? ''));
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite('creer_evenement_agenda', $args, null);
            };
        }

        return $toolHandlers;
    }

    /** Définitions function-calling des outils Google Agenda (selon les scopes accordés). */
    private function calendarToolDefinitions(UserCalendarService $calendar): array
    {
        $defs = [
            [
                'name'        => 'lister_agendas',
                'description' => "Liste les agendas Google de l'utilisateur (id, nom, principal, rôle). "
                    . "Utile UNIQUEMENT quand l'utilisateur mentionne un agenda spécifique (« mon agenda "
                    . "perso », « l'agenda équipe ») : par défaut, travaille sur l'agenda principal sans "
                    . "appeler cet outil.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => (object)[],
                ],
            ],
            [
                'name'        => 'rechercher_agenda',
                'description' => "Recherche des évènements dans l'agenda Google PERSONNEL de l'utilisateur "
                    . "sur une plage de dates (défaut : d'aujourd'hui à +90 jours), avec filtre texte "
                    . "optionnel (titre, description, lieu, participants). Renvoie pour chaque évènement : "
                    . "id, agenda, titre, début, fin, journée entière, lieu. À utiliser AVANT "
                    . "lire_evenement_agenda pour obtenir l'id d'un évènement.",
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
                            'description' => "Id de l'agenda (via lister_agendas). Défaut : primary (agenda principal).",
                        ],
                        'max_resultats' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum d'évènements (1 à 25). Défaut : 10.",
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'lire_evenement_agenda',
                'description' => "Lit un évènement complet de l'agenda Google de l'utilisateur : titre, "
                    . "dates, lieu, description, participants et lien Google Agenda. Lecture seule.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'evenement' => [
                            'type'        => 'string',
                            'description' => "ID de l'évènement (obtenu via rechercher_agenda).",
                        ],
                        'agenda' => [
                            'type'        => 'string',
                            'description' => "Id de l'agenda d'où vient l'évènement. Défaut : primary.",
                        ],
                    ],
                    'required'   => ['evenement'],
                ],
            ],
        ];

        // Créneaux libres : définition commune aux deux agendas (FreeSlots).
        $defs[] = FreeSlots::toolDefinition('trouver_creneaux_agenda', "l'agenda Google", "visibles si leur agenda est partagé avec l'utilisateur ou dans la même organisation Google Workspace");

        if ($calendar->canWrite()) {
            $defs[] = [
                'name'        => 'creer_evenement_agenda',
                'description' => "Crée un évènement dans l'agenda Google de l'utilisateur. Chaque création "
                    . "est soumise à l'approbation explicite de l'utilisateur avant exécution (carte de "
                    . "confirmation). Aucune invitation n'est envoyée automatiquement aux participants.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'titre'  => ['type' => 'string', 'description' => "Titre de l'évènement."],
                        'debut'  => ['type' => 'string', 'description' => "Début : AAAA-MM-JJ HH:MM (ou AAAA-MM-JJ pour une journée entière)."],
                        'fin'    => ['type' => 'string', 'description' => "Fin : AAAA-MM-JJ HH:MM (défaut : début + 1 h). Journée entière : AAAA-MM-JJ du dernier jour."],
                        'journee_entiere' => ['type' => 'boolean', 'description' => "true pour un évènement sur la ou les journées entières."],
                        'description' => ['type' => 'string', 'description' => "Description de l'évènement (optionnel)."],
                        'lieu'    => ['type' => 'string', 'description' => "Lieu (optionnel)."],
                        'invites' => ['type' => 'string', 'description' => "Adresses email des participants, séparées par des virgules (optionnel — ajoutés SANS envoi d'invitation)."],
                        'agenda'  => ['type' => 'string', 'description' => "Id de l'agenda cible. Défaut : primary."],
                    ],
                    'required'   => ['titre', 'debut'],
                ],
            ];
        }
        return $defs;
    }

    /** Consigne ajoutée au system prompt quand les outils Google Agenda sont disponibles. */
    private function calendarToolGuidance(UserCalendarService $calendar): string
    {
        // Date courante indispensable : « demain », « la semaine prochaine »…
        // se traduisent en dates absolues pour rechercher_agenda.
        $daysFr = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
        // Date du jour dans le fuseau de l'UTILISATEUR (le serveur tourne en UTC).
        $now    = new \DateTimeImmutable('now', FreeSlots::zone($this->turn->timeZone));

        $txt = "\n\nTu disposes des outils `rechercher_agenda` et `lire_evenement_agenda` pour consulter "
            . "l'agenda Google personnel de l'utilisateur. Nous sommes le "
            . $daysFr[(int)$now->format('N')] . ' ' . $now->format('d/m/Y')
            . " : convertis les expressions relatives (« demain », « la semaine prochaine ») en dates "
            . "AAAA-MM-JJ avant d'appeler `rechercher_agenda`. Pour des évènements passés, fournis "
            . "explicitement `debut` dans le passé. Cite toujours la date et l'heure des évènements que "
            . "tu rapportes. Pour trouver un moment LIBRE (« quand suis-je dispo ? », « trouve une heure avec "
            . "Paul la semaine prochaine »), appelle `trouver_creneaux_agenda` : il calcule les créneaux pour toi, "
            . "participants compris quand leur agenda est visible ; propose ensuite le créneau et, si "
            . "l'utilisateur le veut, crée l'évènement avec son `debut`. Si un outil renvoie un champ "
            . "`error`, transmets ce message tel quel à l'utilisateur sans l'inventer ni le reformuler.";

        if ($calendar->canWrite()) {
            $txt .= " Tu peux créer des évènements avec `creer_evenement_agenda`, uniquement sur demande "
                . "claire de l'utilisateur. IMPORTANT : chaque création est soumise à son approbation — "
                . "l'outil renvoie `en_attente_approbation` et une carte s'affiche sous ta réponse. "
                . "N'affirme JAMAIS que l'évènement a été créé : annonce qu'il attend son approbation "
                . "dans la carte ci-dessous, en résumant titre et date. Aucune invitation n'est envoyée "
                . "aux participants ; ne modifie ni ne supprime jamais d'évènement existant (aucun outil "
                . "n'existe pour cela).";
        } else {
            $txt .= " Tu ne peux PAS créer d'évènements : la connexion Agenda actuelle est en lecture "
                . "seule. Si l'utilisateur le demande, invite-le à reconnecter son agenda dans "
                . "Réglages → Comptes connectés.";
        }
        return $txt;
    }
}
