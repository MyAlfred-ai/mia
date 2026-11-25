<?php

declare(strict_types=1);

/**
 * Outils Calendrier Outlook du chat (OAuth délégué) : agendas, recherche et lecture
 * d'évènements, salles de réunion, création et modification soumises à
 * approbation — invités, salle, réunion Teams, récurrence, rappel, disponibilité.
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
        // Salles de l'organisation (annuaire), et leur disponibilité sur un créneau.
        $toolHandlers[UserOutlookCalendarService::TOOL_ROOMS] = function (array $args) use ($msCalendarService) {
            $this->sseEmit(['phase' => 'mscalendar_rooms']);
            try {
                return self::hintIfEmpty(
                    $msCalendarService->searchRooms(
                        (string)($args['requete'] ?? ''),
                        (string)($args['debut'] ?? ''),
                        (string)($args['fin'] ?? '')
                    ),
                    'salles',
                    "Aucune salle trouvée. Retire ou raccourcis `requete` ; si la salle a une adresse email, demande-la à l'utilisateur."
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
            $toolHandlers['creer_evenement_agenda_outlook'] = function (array $args) use ($queueConnectorWrite, $msCalendarService) {
                // Le fuseau de l'utilisateur VOYAGE avec l'action : elle sera
                // exécutée au clic « Approuver », dans une requête qui ne
                // connaît plus le navigateur (cf. UserCalendarService::argTimeZone).
                if ($this->turn->timeZone !== '' && trim((string)($args['fuseau'] ?? '')) === '') {
                    $args['fuseau'] = $this->turn->timeZone;
                }
                // Validation amont (titre, dates, adresses des invités, salle,
                // récurrence) : une erreur revient au modèle, jamais dans une
                // carte cassée ni après l'approbation. La salle est RÉSOLUE ici
                // (nom → « Nom <adresse> ») : la carte la nomme et l'exécution,
                // au clic, ne dépend plus de l'annuaire.
                try {
                    $args = self::withResolvedRoom($args, $msCalendarService);
                    $payload = UserOutlookCalendarService::buildEventPayload($args, $msCalendarService->accountEmail());
                    UserOutlookCalendarService::normalizeCalendarId((string)($args['agenda'] ?? ''));
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                // Salle libre sur ce créneau ? Vu AVANT la carte : elle le dit,
                // et le modèle propose une autre salle plutôt qu'un refus plus tard.
                $ctx = $msCalendarService->roomCheck(
                    UserOutlookCalendarService::parseRoom($args['salle'] ?? null),
                    $payload,
                    isset($payload['recurrence'])
                );
                return self::withRoomWarning(
                    $queueConnectorWrite(UserOutlookCalendarService::TOOL_CREATE, $args, $ctx !== [] ? $ctx : null),
                    $ctx
                );
            };
            $toolHandlers['modifier_evenement_agenda_outlook'] = function (array $args) use ($queueConnectorWrite, $msCalendarService) {
                $this->sseEmit(['phase' => 'mscalendar_read']);
                if ($this->turn->timeZone !== '' && trim((string)($args['fuseau'] ?? '')) === '') {
                    $args['fuseau'] = $this->turn->timeZone;
                }
                // L'évènement est LU avant la carte : elle le nomme, dit ce qui
                // change et qui sera prévenu ; une demande impossible (pas
                // organisateur, invité absent, horaire incohérent) revient au
                // modèle pendant qu'il rédige.
                try {
                    $args = self::withResolvedRoom($args, $msCalendarService);
                    $ctx  = $msCalendarService->inspectUpdate($args);
                } catch (\RuntimeException $e) {
                    return ['error' => $e->getMessage()];
                }
                // Posé par le SERVEUR, jamais lu du modèle : une tâche sans
                // validation s'en sert pour ne pas écrire seule à des invités.
                $args['previent_invites'] = $ctx['previent'];
                return self::withRoomWarning($queueConnectorWrite(UserOutlookCalendarService::TOOL_UPDATE, $args, $ctx), $ctx);
            };
        }

        return $toolHandlers;
    }

    /**
     * La salle demandée (nom ou adresse) remplacée par sa forme résolue
     * « Nom <adresse> » — ce que portent la carte, la file d'approbation et
     * le filtre de destinataires d'un workflow. Throw RuntimeException à
     * message FR (salle introuvable, ambiguë, annuaire injoignable).
     */
    private static function withResolvedRoom(array $args, UserOutlookCalendarService $calendar): array
    {
        $raw = $args['salle'] ?? null;
        if (is_scalar($raw) && !is_bool($raw) && trim((string)$raw) !== '') {
            $args['salle'] = UserOutlookCalendarService::roomLabel($calendar->resolveRoom((string)$raw));
        } else {
            unset($args['salle']);
        }
        return $args;
    }

    /** Résultat de la file d'approbation, complété quand la salle semble prise. */
    private static function withRoomWarning(array $result, array $ctx): array
    {
        if (!isset($result['error']) && ($ctx['salle_dispo'] ?? '') === 'occupee') {
            $result['avertissement'] = UserOutlookCalendarService::ROOM_BUSY_NOTE;
        }
        return $result;
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

        $defs[] = [
            'name'        => UserOutlookCalendarService::TOOL_ROOMS,
            'description' => "Liste les salles de réunion de l'organisation (annuaire Microsoft : nom et adresse "
                . "email), filtrées par nom ; avec `debut` ET `fin`, dit pour chacune si elle est libre, "
                . "occupée ou non vérifiable sur ce créneau (les libres d'abord). À utiliser pour trouver la "
                . "salle à réserver ou une salle libre. Lecture seule : rien n'est réservé.",
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'requete' => ['type' => 'string', 'description' => "Nom ou partie du nom de la salle (ex. « Everest », « 3e étage »). Vide = toutes."],
                    'debut'   => ['type' => 'string', 'description' => "Début du créneau à vérifier, AAAA-MM-JJ HH:MM (optionnel, avec `fin`)."],
                    'fin'     => ['type' => 'string', 'description' => "Fin du créneau à vérifier, AAAA-MM-JJ HH:MM (optionnel, avec `debut`)."],
                ],
            ],
        ];

        // Modalités communes à la création et à la modification.
        $options = [
            'visio' => ['type' => 'boolean', 'description' => "true pour en faire une réunion Teams (lien de connexion créé par Outlook). Ne se retire pas ensuite."],
            'rappel_minutes' => ['type' => 'integer', 'description' => "Rappel : minutes avant le début (ex. 15). -1 pour le désactiver. Omets pour garder le réglage par défaut."],
            'disponibilite' => ['type' => 'string', 'enum' => array_keys(UserOutlookCalendarService::SHOW_AS), 'description' => "Disponibilité affichée dans l'agenda pendant l'évènement (défaut : occupe)."],
            'importance' => ['type' => 'string', 'enum' => array_keys(UserOutlookCalendarService::IMPORTANCE), 'description' => "Importance (défaut : normale)."],
            'confidentialite' => ['type' => 'string', 'enum' => array_keys(UserOutlookCalendarService::SENSITIVITY), 'description' => "privee = les autres ne voient qu'un créneau occupé."],
        ];
        if ($calendar->canWrite()) {
            $defs[] = [
                'name'        => UserOutlookCalendarService::TOOL_CREATE,
                'description' => "Crée un évènement ou une réunion dans le calendrier Outlook de l'utilisateur : "
                    . "invités obligatoires et facultatifs, salle de réunion réservée, réunion Teams, récurrence, "
                    . "rappel, disponibilité. "
                    . "Chaque création est soumise à son approbation explicite avant exécution (carte de "
                    . "confirmation). ATTENTION : Outlook envoie l'invitation par email à chaque invité dès "
                    . "la création, sans réglage possible — n'ajoute que les invités que l'utilisateur a "
                    . "nommés, avec leur adresse email exacte.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'titre'  => ['type' => 'string', 'description' => "Titre de l'évènement."],
                        'debut'  => ['type' => 'string', 'description' => "Début : AAAA-MM-JJ HH:MM (ou AAAA-MM-JJ pour une journée entière)."],
                        'fin'    => ['type' => 'string', 'description' => "Fin : AAAA-MM-JJ HH:MM (défaut : début + 1 h). Journée entière : AAAA-MM-JJ du dernier jour."],
                        'journee_entiere' => ['type' => 'boolean', 'description' => "true pour un évènement sur la ou les journées entières."],
                        'description' => ['type' => 'string', 'description' => "Description / ordre du jour, en texte simple (optionnel). Les invités la reçoivent."],
                        'lieu'    => ['type' => 'string', 'description' => "Lieu en texte libre (optionnel). Pour une salle de réunion de l'organisation, utilise plutôt `salle`."],
                        'salle'   => ['type' => 'string', 'description' => "Salle de réunion à RÉSERVER : son nom tel que l'annuaire le connaît (via lister_salles_outlook) ou son adresse email. Elle devient le lieu et reçoit la demande de réservation ; elle accepte ou refuse d'elle-même selon son agenda. Une seule salle."],
                        'invites' => ['type' => 'string', 'description' => "Adresses email des invités OBLIGATOIRES (des personnes), séparées par des virgules. Vide = évènement personnel, aucune invitation."],
                        'invites_optionnels' => ['type' => 'string', 'description' => "Adresses email des invités FACULTATIFS, séparées par des virgules."],
                    ] + $options + [
                        'recurrence' => ['type' => 'string', 'enum' => UserOutlookCalendarService::RECURRENCES, 'description' => "Répétition. Omets pour un évènement unique. Le `debut` est la première occurrence."],
                        'recurrence_intervalle' => ['type' => 'integer', 'description' => "Tous les N jours / semaines / mois / ans (défaut : 1 ; 2 = une semaine sur deux)."],
                        'recurrence_jours' => ['type' => 'string', 'description' => "Récurrence hebdomadaire : jours, séparés par des virgules (ex. « lundi, jeudi »). Défaut : le jour du début."],
                        'recurrence_fin' => ['type' => 'string', 'description' => "Dernier jour de la série, AAAA-MM-JJ (optionnel)."],
                        'recurrence_occurrences' => ['type' => 'integer', 'description' => "Nombre d'occurrences, si aucune date de fin (optionnel). Ni l'un ni l'autre = sans fin."],
                        'agenda'  => ['type' => 'string', 'description' => "Id de l'agenda cible. Vide = agenda principal."],
                    ],
                    'required'   => ['titre', 'debut'],
                ],
            ];
            $defs[] = [
                'name'        => UserOutlookCalendarService::TOOL_UPDATE,
                'description' => "Modifie un évènement EXISTANT du calendrier Outlook de l'utilisateur : titre, "
                    . "horaire, lieu, salle (réserver, changer, libérer), description, invités (ajout, retrait), "
                    . "réunion Teams, rappel, disponibilité. Ne passe QUE ce qui change : tout paramètre omis reste tel quel. "
                    . "Soumis à approbation (carte). ATTENTION : dès qu'une réunion change, Outlook en "
                    . "informe ses invités par email. Seul l'organisateur peut modifier une réunion ; "
                    . "sur une réunion organisée par quelqu'un d'autre, seuls le rappel et la "
                    . "disponibilité se règlent. Aucune suppression ni annulation.",
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'evenement' => ['type' => 'string', 'description' => "ID de l'évènement (via rechercher_agenda_outlook ou lire_evenement_agenda_outlook). L'id d'une occurrence ne modifie que cette occurrence."],
                        'titre'  => ['type' => 'string', 'description' => "Nouveau titre."],
                        'debut'  => ['type' => 'string', 'description' => "Nouveau début : AAAA-MM-JJ HH:MM. Seul, il garde la durée. Une date sans heure (AAAA-MM-JJ) déplace le jour en gardant l'heure."],
                        'fin'    => ['type' => 'string', 'description' => "Nouvelle fin : AAAA-MM-JJ HH:MM (journée entière : AAAA-MM-JJ du dernier jour). Seule, elle garde le début."],
                        'journee_entiere' => ['type' => 'boolean', 'description' => "true pour le passer en journée entière."],
                        'lieu'    => ['type' => 'string', 'description' => "Nouveau lieu en texte libre. Sur une réunion dans une salle, il complète le nom de la salle (pour quitter la salle : `retirer_salle`)."],
                        'salle'   => ['type' => 'string', 'description' => "Salle à réserver pour cette réunion (nom via lister_salles_outlook, ou adresse email). REMPLACE la salle actuelle, qui est libérée."],
                        'retirer_salle' => ['type' => 'boolean', 'description' => "true pour libérer la salle réservée (elle quitte la réunion et le lieu)."],
                        'description' => ['type' => 'string', 'description' => "Nouvelle description (REMPLACE l'actuelle). Impossible sur une réunion Teams."],
                        'ajouter_invites' => ['type' => 'string', 'description' => "Adresses email à inviter (obligatoires), séparées par des virgules. Les invités actuels restent."],
                        'ajouter_invites_optionnels' => ['type' => 'string', 'description' => "Adresses email à inviter en facultatif."],
                        'retirer_invites' => ['type' => 'string', 'description' => "Adresses email à retirer des invités."],
                    ] + $options,
                    'required'   => ['evenement'],
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
            . "avec son `debut`. SALLES DE RÉUNION : `lister_salles_outlook` donne les salles de l'organisation "
            . "(nom, adresse) et, avec `debut` et `fin`, celles qui sont libres sur ce créneau ; pour qu'un créneau "
            . "convienne AUSSI à une salle, ajoute son adresse aux `participants` de `trouver_creneaux_outlook`. "
            . "Si un outil renvoie un champ "
            . "`error`, transmets ce message tel quel à l'utilisateur sans l'inventer ni le reformuler.";

        if ($calendar->canWrite()) {
            $txt .= " Tu peux créer des évènements et des réunions avec `creer_evenement_agenda_outlook`, "
                . "et modifier un évènement existant avec `modifier_evenement_agenda_outlook` (cherche-le "
                . "ou lis-le d'abord pour avoir son id), uniquement sur demande claire de l'utilisateur. "
                . "IMPORTANT : chaque création ou modification est soumise à son approbation — l'outil "
                . "renvoie `en_attente_approbation` et une carte s'affiche sous ta réponse. N'affirme JAMAIS "
                . "que c'est fait : annonce que cela attend son approbation dans la carte ci-dessous, en "
                . "résumant titre, date et invités. INVITÉS : Outlook leur écrit par email dès l'approbation "
                . "(invitation à la création, mise à jour à chaque changement), sans retour possible. "
                . "N'invite donc que les personnes que l'utilisateur a désignées, avec leur adresse email "
                . "exacte : si tu n'as qu'un nom, cherche l'adresse (ses emails, son agenda) ou demande-la, "
                . "ne la devine jamais. Pour RÉSERVER une salle, passe-la dans `salle` (nom exact rendu par "
                . "`lister_salles_outlook`, ou son adresse) — jamais dans `invites` ni seulement dans `lieu`, qui "
                . "n'est que du texte ; ne la réserve que si l'utilisateur l'a demandée. La salle accepte ou refuse "
                . "d'elle-même selon son agenda : ne dis jamais qu'elle est réservée, dis qu'elle recevra la "
                . "demande ; si l'outil renvoie un `avertissement` (salle déjà prise), dis-le et propose une salle "
                . "libre. Pour une réunion à distance, passe `visio: true` (réunion Teams) ; "
                . "pour trouver un horaire commun, appelle d'abord `trouver_creneaux_outlook`. Pour une "
                . "modification, ne passe que ce qui change. Tu ne peux ni supprimer ni annuler un "
                . "évènement : aucun outil n'existe pour cela, l'utilisateur le fait dans Outlook.";
        } else {
            $txt .= " Tu ne peux PAS créer ni modifier d'évènements : la connexion actuelle au calendrier Outlook est "
                . "en lecture seule. Si l'utilisateur le demande, invite-le à reconnecter son calendrier "
                . "dans Réglages → Comptes connectés.";
        }
        return $txt;
    }
}
