<?php

declare(strict_types=1);

/**
 * Outils Google Agenda du chat (agenda personnel, OAuth) : agendas, recherche et lecture
 * d'évènements, créneaux libres, création et modification soumises à approbation —
 * invités, récurrence, visibilité, disponibilité.
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
            $toolHandlers['creer_evenement_agenda'] = function (array $args) use ($queueConnectorWrite, $calendarService) {
                // Le fuseau de l'utilisateur VOYAGE avec l'action : elle sera
                // exécutée au clic « Approuver », dans une requête qui ne
                // connaît plus le navigateur (cf. UserCalendarService::argTimeZone).
                if ($this->turn->timeZone !== '' && trim((string)($args['fuseau'] ?? '')) === '') {
                    $args['fuseau'] = $this->turn->timeZone;
                }
                // Validation amont (titre, dates, adresses des invités,
                // récurrence, visibilité, disponibilité) : une erreur revient
                // au modèle, jamais dans une carte cassée ni après l'approbation.
                try {
                    UserCalendarService::buildEventPayload($args, $calendarService->accountEmail());
                    UserCalendarService::normalizeCalendarId((string)($args['agenda'] ?? ''));
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                return $queueConnectorWrite(UserCalendarService::TOOL_CREATE, $args, null);
            };
            $toolHandlers['modifier_evenement_agenda'] = function (array $args) use ($queueConnectorWrite, $calendarService) {
                $this->sseEmit(['phase' => 'calendar_read']);
                if ($this->turn->timeZone !== '' && trim((string)($args['fuseau'] ?? '')) === '') {
                    $args['fuseau'] = $this->turn->timeZone;
                }
                // L'évènement est LU avant la carte : elle le nomme, dit ce qui
                // change et qui sera prévenu ; une demande impossible (pas
                // organisateur, invité absent, horaire incohérent) revient au
                // modèle pendant qu'il rédige.
                try {
                    $ctx = $calendarService->inspectUpdate($args);
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                // Posé par le SERVEUR, jamais lu du modèle : une tâche sans
                // validation s'en sert pour ne pas écrire seule à des invités.
                $args['previent_invites'] = $ctx['touche_invites'];
                return $queueConnectorWrite(UserCalendarService::TOOL_UPDATE, $args, $ctx);
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
                    . "id, agenda, titre, début, fin, journée entière, lieu, et `serie` (id de la série pour une "
                    . "occurrence de réunion récurrente). À utiliser AVANT "
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
                    . "dates, lieu, description, participants (obligatoires, facultatifs, réponses), organisateur, "
                    . "répétition, visibilité, disponibilité et lien Google Agenda. Lecture seule.",
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

        // Modalités communes à la création et à la modification.
        $options = [
            'visibilite'    => ['type' => 'string', 'enum' => array_keys(UserCalendarService::VISIBILITY), 'description' => "Visibilité : privee = qui voit l'agenda de l'utilisateur n'y voit qu'un créneau occupé, sans le détail ; publique = détail visible de toute personne qui a accès à son agenda ; par_defaut = réglage de son agenda. Omets pour ne rien changer."],
            'disponibilite' => ['type' => 'string', 'enum' => array_keys(UserCalendarService::AVAILABILITY), 'description' => "Disponibilité affichée pendant l'évènement : occupe (le créneau est bloqué, défaut de Google) ou disponible (le créneau reste libre). Omets pour ne rien changer."],
            'envoyer_invitations' => ['type' => 'boolean', 'description' => "true (défaut) : Google Agenda envoie l'invitation ou la mise à jour par email aux invités. false : ils sont ajoutés (l'évènement apparaît dans leur agenda Google) sans email — une adresse hors Google n'est alors prévenue de rien. Ne passe false que si l'utilisateur le demande."],
        ];
        if ($calendar->canWrite()) {
            $defs[] = [
                'name'        => UserCalendarService::TOOL_CREATE,
                'description' => "Crée un évènement ou une réunion dans l'agenda Google de l'utilisateur : invités "
                    . "obligatoires et facultatifs, récurrence (chaque jour, jours ouvrés, chaque semaine sur un ou "
                    . "plusieurs jours, chaque mois à la même date ou le même jour de la semaine — « le 2e mardi », "
                    . "« le dernier vendredi » —, chaque année ; tous les N ; jusqu'à une date ou N fois ; dates "
                    . "sautées), visibilité (privée, publique), disponibilité (occupé, disponible). Chaque création "
                    . "est soumise à l'approbation explicite de l'utilisateur avant exécution (carte de "
                    . "confirmation). Par défaut, Google Agenda envoie l'invitation par email à chaque invité dès "
                    . "la création — n'ajoute que les invités que l'utilisateur a nommés, avec leur adresse email exacte.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'titre'  => ['type' => 'string', 'description' => "Titre de l'évènement."],
                        'debut'  => ['type' => 'string', 'description' => "Début : AAAA-MM-JJ HH:MM (ou AAAA-MM-JJ pour une journée entière). Pour une réunion récurrente, c'est la PREMIÈRE occurrence : elle doit tomber un jour de la règle."],
                        'fin'    => ['type' => 'string', 'description' => "Fin de la (première) occurrence : AAAA-MM-JJ HH:MM (défaut : début + 1 h). Journée entière : AAAA-MM-JJ du dernier jour. Ce n'est PAS la fin de la série (cf. recurrence_fin)."],
                        'journee_entiere' => ['type' => 'boolean', 'description' => "true pour un évènement sur la ou les journées entières."],
                        'description' => ['type' => 'string', 'description' => "Description / ordre du jour (optionnel). Les invités la voient."],
                        'lieu'    => ['type' => 'string', 'description' => "Lieu (optionnel)."],
                        'invites' => ['type' => 'string', 'description' => "Adresses email des invités OBLIGATOIRES, séparées par des virgules. Vide = évènement personnel, sans invité."],
                        'invites_optionnels' => ['type' => 'string', 'description' => "Adresses email des invités FACULTATIFS, séparées par des virgules."],
                    ] + $options + [
                        'recurrence' => ['type' => 'string', 'enum' => UserCalendarService::RECURRENCES, 'description' => "Répétition. Omets pour un évènement unique."],
                        'recurrence_intervalle' => ['type' => 'integer', 'description' => "Tous les N jours / semaines / mois / ans (défaut : 1 ; 2 = une semaine sur deux). Sans effet sur jours_ouvres."],
                        'recurrence_jours' => ['type' => 'string', 'description' => "Récurrence hebdomadaire : jours, séparés par des virgules (ex. « lundi, jeudi »). Défaut : le jour du début."],
                        'recurrence_mensuelle' => ['type' => 'string', 'enum' => UserCalendarService::MONTHLY_MODES, 'description' => "Récurrence mensuelle : meme_date (défaut : le même quantième, ex. le 15), meme_jour_semaine (le même rang du jour, ex. le 2e mardi — déduit du début), dernier_jour_semaine (le dernier mardi du mois — le début doit en être un)."],
                        'recurrence_fin' => ['type' => 'string', 'description' => "Dernier jour de la série, AAAA-MM-JJ (optionnel)."],
                        'recurrence_occurrences' => ['type' => 'integer', 'description' => "Nombre d'occurrences, si aucune date de fin (optionnel). Ni l'un ni l'autre = sans fin."],
                        'recurrence_exceptions' => ['type' => 'string', 'description' => "Dates de la série à sauter (ex. jours fériés), AAAA-MM-JJ séparées par des virgules (optionnel). Chacune doit être un jour où la réunion aurait lieu."],
                        'agenda'  => ['type' => 'string', 'description' => "Id de l'agenda cible. Défaut : primary."],
                    ],
                    'required'   => ['titre', 'debut'],
                ],
            ];
            $defs[] = [
                'name'        => UserCalendarService::TOOL_UPDATE,
                'description' => "Modifie un évènement EXISTANT de l'agenda Google de l'utilisateur : titre, horaire, "
                    . "lieu, description, invités (ajout, retrait, obligatoire ou facultatif), visibilité (privée, "
                    . "publique), disponibilité (occupé, disponible). Ne passe QUE ce qui change : tout paramètre "
                    . "omis reste tel quel. Soumis à approbation (carte). Par défaut, Google Agenda informe les "
                    . "invités du changement par email. Seul l'organisateur peut modifier titre, horaire, lieu, "
                    . "description et invités ; sur une réunion organisée par quelqu'un d'autre, seules la visibilité "
                    . "et la disponibilité se règlent. La répétition d'une série ne se change pas ici. Aucune "
                    . "suppression ni annulation.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'evenement' => ['type' => 'string', 'description' => "ID de l'évènement (via rechercher_agenda ou lire_evenement_agenda). Pour une réunion récurrente, l'id d'une occurrence ; cf. toute_la_serie."],
                        'toute_la_serie' => ['type' => 'boolean', 'description' => "Réunion récurrente : true = modifier TOUTE la série (toutes les occurrences) ; false/omis = seulement cette occurrence. Obligatoire pour la visibilité d'une réunion récurrente."],
                        'titre'  => ['type' => 'string', 'description' => "Nouveau titre."],
                        'debut'  => ['type' => 'string', 'description' => "Nouveau début : AAAA-MM-JJ HH:MM, ou HH:MM pour changer seulement l'heure (seule forme permise sur toute une série). Seul, il garde la durée. Une date sans heure (AAAA-MM-JJ) déplace le jour en gardant l'heure."],
                        'fin'    => ['type' => 'string', 'description' => "Nouvelle fin : AAAA-MM-JJ HH:MM ou HH:MM (journée entière : AAAA-MM-JJ du dernier jour). Seule, elle garde le début."],
                        'journee_entiere' => ['type' => 'boolean', 'description' => "true pour le passer en journée entière."],
                        'lieu'    => ['type' => 'string', 'description' => "Nouveau lieu."],
                        'description' => ['type' => 'string', 'description' => "Nouvelle description (REMPLACE l'actuelle)."],
                        'ajouter_invites' => ['type' => 'string', 'description' => "Adresses email à inviter (obligatoires), séparées par des virgules. Les invités actuels restent."],
                        'ajouter_invites_optionnels' => ['type' => 'string', 'description' => "Adresses email à inviter en facultatif."],
                        'retirer_invites' => ['type' => 'string', 'description' => "Adresses email à retirer des invités."],
                        'agenda'  => ['type' => 'string', 'description' => "Id de l'agenda de l'évènement. Défaut : primary."],
                    ] + $options,
                    'required'   => ['evenement'],
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
            $txt .= " Tu peux créer des évènements et des réunions avec `creer_evenement_agenda`, et modifier un "
                . "évènement existant avec `modifier_evenement_agenda` (cherche-le ou lis-le d'abord pour avoir son "
                . "id), uniquement sur demande claire de l'utilisateur. IMPORTANT : chaque création ou modification "
                . "est soumise à son approbation — l'outil renvoie `en_attente_approbation` et une carte s'affiche "
                . "sous ta réponse. N'affirme JAMAIS que c'est fait : annonce que cela attend son approbation dans "
                . "la carte ci-dessous, en résumant titre, date, répétition et invités. INVITÉS : par défaut, Google "
                . "Agenda leur écrit par email dès l'approbation (invitation à la création, mise à jour à chaque "
                . "changement) — `envoyer_invitations: false` les ajoute sans email, seulement si l'utilisateur le "
                . "demande. N'invite que les personnes que l'utilisateur a désignées, avec leur adresse email exacte : "
                . "si tu n'as qu'un nom, cherche l'adresse (ses emails, son agenda) ou demande-la, ne la devine "
                . "jamais. RÉCURRENCE : traduis les modalités de l'utilisateur (« tous les lundis et jeudis », "
                . "« un mardi sur deux », « le premier lundi du mois », « chaque jour ouvré jusqu'à fin juin », "
                . "« dix fois », « sauf le 25 décembre ») dans les paramètres `recurrence_*` ; `debut` est la "
                . "première occurrence et doit tomber un jour de la règle. Si une modalité ne s'exprime pas avec "
                . "ces paramètres, dis-le plutôt que de l'approcher en silence. Pour une réunion PRIVÉE, passe "
                . "`visibilite: privee` ; pour ne pas bloquer le créneau, `disponibilite: disponible`. Pour une "
                . "réunion récurrente existante, précise `toute_la_serie: true` si l'utilisateur parle de la série. "
                . "Pour trouver un horaire commun, appelle d'abord `trouver_creneaux_agenda`. Pour une modification, "
                . "ne passe que ce qui change. Tu ne peux ni supprimer ni annuler un évènement : aucun outil "
                . "n'existe pour cela, l'utilisateur le fait dans Google Agenda.";
        } else {
            $txt .= " Tu ne peux PAS créer ni modifier d'évènements : la connexion Agenda actuelle est en lecture "
                . "seule. Si l'utilisateur le demande, invite-le à reconnecter son agenda dans "
                . "Réglages → Comptes connectés.";
        }
        return $txt;
    }
}
