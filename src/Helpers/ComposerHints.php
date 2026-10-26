<?php

declare(strict_types=1);

/**
 * Idées d'utilisation affichées dans la zone de saisie vide — SOURCE UNIQUE.
 *
 * Le texte d'aide du champ (« Envoyer un message ou déposer vos fichiers… »)
 * s'affiche d'abord, puis laisse la place, en fondu, à des exemples de
 * demandes professionnelles (public/assets/js/composer-hints.js). Ces
 * exemples ne promettent que ce que la personne peut RÉELLEMENT faire :
 *
 *   - un connecteur n'est cité que s'il est proposé ET relié à son compte ;
 *   - un outil (image, page web, carte, vidéo) que s'il lui est ouvert et en
 *     service (ni arrêté par son fournisseur, ni en échec à son dernier test) ;
 *   - une tâche programmée, un workflow, un assistant que si son rôle permet
 *     d'en créer ;
 *   - une base de connaissances que si au moins une est interrogeable.
 *
 * Aucune requête de plus : le gabarit du chat a déjà tout calculé pour le
 * menu « + », le sélecteur de bases et la barre latérale ; il passe ici ce
 * qu'il sait (`forChat`, pure). Les phrases sont traduites côté serveur et
 * livrées toutes faites (window.COMPOSER_HINTS) : le script n'a aucun texte.
 *
 * Chaque phrase appartient à un GROUPE : le navigateur en prend une par
 * groupe à tour de rôle, pour que deux exemples voisins ne disent pas la
 * même chose.
 */
final class ComposerHints
{
    /** Longueur visée d'une phrase (caractères) : une ligne de la zone de saisie. */
    public const MAX_LEN = 60;

    /** Connecteurs du chat → groupe de phrases (Gmail et Outlook disent la même chose). */
    private const CONNECTOR_GROUPS = [
        'gmail'      => 'mail',
        'outlook'    => 'mail',
        'calendar'   => 'agenda',
        'mscalendar' => 'agenda',
        'tasks'      => 'taches',
        'mstodo'     => 'taches',
        'drive'      => 'drive',
        'slack'      => 'slack',
        'teams'      => 'teams',
    ];

    /** Types d'outil du chat → groupe (le site illustré est une page web). */
    private const TOOL_GROUPS = [
        'image'            => 'image',
        'html'             => 'page',
        'html_illustrated' => 'page',
        'map'              => 'carte',
        'video'            => 'video',
    ];

    /**
     * Types des outils RÉELLEMENT utilisables, depuis les listes du menu « + »
     * (`['image' => [outils…], 'html' => […], …]`) : même règle que la ligne
     * grisée du menu — arrêté par son fournisseur ou en échec à son dernier
     * test, l'outil ne compte pas.
     *
     * @param array<string, list<array<string, mixed>>> $toolsByType
     * @return list<string>
     */
    public static function usableToolTypes(array $toolsByType): array
    {
        $types = [];
        foreach ($toolsByType as $fallbackType => $tools) {
            foreach ((array)$tools as $tool) {
                if (!is_array($tool)) {
                    continue;
                }
                if (isset($tool['last_test_ok']) && (int)$tool['last_test_ok'] === 0) {
                    continue;
                }
                $arret = ChatTool::retirement($tool);
                if ($arret !== null && $arret['retired']) {
                    continue;
                }
                $types[] = (string)($tool['type'] ?? $fallbackType);
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Textes de la suggestion de suite (FollowUpSuggestion) livrés au script
     * (window.COMPOSER_SUGGEST) : le raccourci, le bouton au doigt, son
     * infobulle et la phrase lue par les lecteurs d'écran ({texte} = la
     * suggestion).
     *
     * @return array{key: string, accept: string, title: string, sr: string}
     */
    public static function suggestionTexts(): array
    {
        return [
            'key'    => 'Tab',
            'accept' => t('Utiliser'),
            'title'  => t('Reprendre cette suggestion (Tab)'),
            'sr'     => t('Suggestion : {texte}. Appuyez sur Tab pour la reprendre.'),
        ];
    }

    /**
     * Phrases à proposer, d'après ce que la page sait de la personne.
     *
     * @param array{tools?: list<string>, connectors?: list<string>, web?: bool, kb?: bool,
     *              schedule?: bool, workflow?: bool, assistant?: bool, company?: string} $caps
     * @return list<array{g: string, t: string}>
     */
    public static function forChat(array $caps): array
    {
        $groups = [];
        foreach ((array)($caps['connectors'] ?? []) as $key) {
            if (isset(self::CONNECTOR_GROUPS[$key])) {
                $groups[self::CONNECTOR_GROUPS[$key]] = true;
            }
        }
        foreach ((array)($caps['tools'] ?? []) as $type) {
            if (isset(self::TOOL_GROUPS[$type])) {
                $groups[self::TOOL_GROUPS[$type]] = true;
            }
        }
        $web  = !empty($caps['web']);
        $mail = isset($groups['mail']);

        $phrases = [
            // Ce que tout le monde peut faire : rédiger, analyser, livrer un fichier, retenir.
            'redaction' => [
                t('Rédige une réponse cordiale à un client mécontent'),
                t('Résume ce document en cinq points clés'),
                t('Prépare l\'ordre du jour de la réunion d\'équipe'),
                t('Reformule ce texte pour un public non spécialiste'),
                t('Traduis ce courrier en anglais, ton professionnel'),
            ],
            'analyse' => [
                t('Analyse l\'appel d\'offres joint : points de vigilance'),
                t('Compare ces deux devis et recommande le meilleur'),
            ],
            'fichiers' => [
                t('Fais un tableau Excel de suivi des commandes'),
                t('Prépare une présentation PowerPoint de nos résultats'),
                t('Transforme ces notes en compte rendu Word'),
            ],
            'memoire' => [
                t('Retiens que je préfère des réponses courtes'),
            ],
        ];

        $company = trim((string)($caps['company'] ?? ''));
        if ($company !== '' && mb_strlen($company) <= 30) {
            $phrases['entreprise'] = [t('Présente {entreprise} en une page pour un prospect', ['entreprise' => $company])];
        }
        if ($web) {
            $phrases['web'] = [
                t('Fais une veille sur les nouveautés de mon secteur'),
                t('Quelles sont les dernières tendances de notre marché ?'),
            ];
        }
        if ($mail) {
            $phrases['mail'] = [
                t('Résume les emails importants reçus cette semaine'),
                t('Prépare une réponse au dernier email de mon client'),
            ];
        }
        if (isset($groups['agenda'])) {
            $phrases['agenda'] = [
                t('Qu\'ai-je à l\'agenda demain ?'),
                t('Trouve un créneau libre d\'une heure jeudi matin'),
            ];
        }
        if (isset($groups['drive'])) {
            $phrases['drive'] = [t('Retrouve la dernière version du contrat dans mon Drive')];
        }
        if (isset($groups['taches'])) {
            $phrases['taches'] = [t('Ajoute à mes tâches : relancer le fournisseur vendredi')];
        }
        if (isset($groups['slack'])) {
            $phrases['slack'] = [t('Résume les échanges de cette semaine sur Slack')];
        }
        if (isset($groups['teams'])) {
            $phrases['teams'] = [t('Qu’a-t-on décidé cette semaine sur Teams ?')];
        }
        if (!empty($caps['kb'])) {
            $phrases['bases'] = [t('Que disent nos procédures sur les notes de frais ?')];
        }
        if (isset($groups['image'])) {
            $phrases['image'] = [t('Crée une illustration pour notre prochaine newsletter')];
        }
        if (isset($groups['page'])) {
            $phrases['page'] = [t('Crée une page web pour présenter notre nouvelle offre')];
        }
        if (isset($groups['carte'])) {
            $phrases['carte'] = [t('Trace l\'itinéraire de mes visites clients de jeudi')];
        }
        if (isset($groups['video'])) {
            $phrases['video'] = [t('Réalise une courte vidéo d\'annonce de notre événement')];
        }
        if (!empty($caps['schedule'])) {
            // Une tâche programmée relit ses sources à chaque exécution : l'exemple
            // n'en cite qu'une que la personne a.
            $phrases['programmee'] = [$mail
                ? t('Tous les matins, résume-moi les emails de la nuit')
                : ($web
                    ? t('Chaque lundi à 8 h, fais-moi une veille de notre marché')
                    : t('Chaque vendredi, rappelle-moi de préparer le point d\'équipe'))];
        }
        if (!empty($caps['workflow'])) {
            $phrases['workflow'] = [t('Crée un workflow pour répondre aux appels d\'offres')];
        }
        if (!empty($caps['assistant'])) {
            $phrases['assistant'] = [t('Crée un assistant qui répond aux questions RH')];
        }

        $out = [];
        foreach ($phrases as $group => $list) {
            foreach ($list as $text) {
                $out[] = ['g' => $group, 't' => $text];
            }
        }

        return $out;
    }
}
