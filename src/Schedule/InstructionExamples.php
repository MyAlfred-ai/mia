<?php

declare(strict_types=1);

/**
 * Exemples de consigne proposés sous le champ « Consigne » du formulaire
 * d'une tâche programmée (templates/settings/schedule-form.php).
 *
 * La consigne est le cœur de la tâche : c'est le texte que l'assistant reçoit,
 * tel quel, à chaque exécution. Quatre exemples montrent le TON attendu — une
 * demande autoportante, écrite comme dans le chat, un rythme typique chacune
 * (la semaine, les jours ouvrés, le mois, le vendredi) — sans dresser un
 * catalogue : une puce par exemple, un clic la pose dans le champ.
 *
 * SOURCE UNIQUE, partagée avec le guide utilisateur : la section « Exemples »
 * de docs/user/fonctionnalites/conversations/programmer-une-tache.md cite ces
 * mêmes consignes, mot pour mot — ScheduledTaskFormUxTest le garde. Deux
 * jeux d'exemples finiraient par se contredire sur ce qu'est une « bonne »
 * consigne.
 *
 * Pure : aucune lecture de base, aucun état. Libellés traduits ici (t()),
 * jamais dans le script.
 */
final class InstructionExamples
{
    /**
     * Les exemples, dans l'ordre d'affichage. `label` = la puce, et le nom
     * proposé pour la tâche quand le champ « Nom » est encore vide ;
     * `instruction` = la consigne entière, posée dans le champ au clic.
     *
     * @return list<array{key: string, label: string, instruction: string}>
     */
    public static function all(): array
    {
        return [
            [
                'key'         => 'mails',
                'label'       => t('Point du lundi'),
                'instruction' => t('Fais le point sur les mails reçus depuis lundi dernier : clients en attente de réponse, engagements pris, urgences.'),
            ],
            [
                'key'         => 'veille',
                'label'       => t('Veille quotidienne'),
                'instruction' => t('Les annonces de la semaine dans notre secteur, en cinq points, avec les liens.'),
            ],
            [
                'key'         => 'bilan',
                'label'       => t('Bilan du mois'),
                'instruction' => t('Liste mes rendez-vous du mois écoulé et le temps passé par client.'),
            ],
            [
                'key'         => 'relances',
                'label'       => t('Relances du vendredi'),
                'instruction' => t('Pour chaque devis envoyé cette semaine et resté sans réponse, crée pour lundi une tâche de relance au nom du client, dans ma liste Commercial.'),
            ],
        ];
    }
}
