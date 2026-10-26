<?php

declare(strict_types=1);

/**
 * Consigne transversale « agent » : la MÉTHODE de travail avec les outils.
 *
 * Injectée quand au moins un connecteur expose des outils (Drive, Gmail,
 * Outlook, agendas, Slack, Tâches, n8n). Elle complète les consignes par
 * connecteur sans les contredire : elle donne la méthode (plan annoncé,
 * enchaînement, adaptation, budget), les consignes par connecteur gardent les
 * règles (écritures sous approbation, lecture avant modification…).
 *
 * Le budget annoncé lit LLMInterface::TOOL_MAX_ROUNDS — la source des boucles
 * des adapters — pour ne jamais mentir au modèle.
 */
final class AgentGuidance
{
    public static function method(): string
    {
        $rounds = LLMInterface::TOOL_MAX_ROUNDS;
        return "\n\nMéthode de travail avec ces outils :\n"
            . "- Demande en plusieurs étapes → affiche ton plan avec l'outil `" . PlanTools::TOOL . "` (dans le "
            . "même tour que tes premiers appels), puis exécute-le en enchaînant les outils sans redemander de "
            . "permission pour des lectures, et coche les étapes au fil de l'eau. Les écritures, elles, passent "
            . "toujours par la carte d'approbation.\n"
            . "- Le résultat d'un outil peut nourrir le suivant, y compris entre connecteurs différents : un "
            . "email trouvé peut devenir un évènement d'agenda ou une tâche, un fichier lu peut nourrir un "
            . "brouillon. Enchaîne jusqu'au bout de la demande, ne t'arrête pas en chemin pour demander si tu dois continuer.\n"
            . "- Plusieurs recherches indépendantes (autre connecteur, autre requête) → lance ces appels "
            . "d'outils dans le même tour plutôt qu'un par un.\n"
            . "- Une question que tes sources connectées peuvent trancher (documents, emails, agenda, "
            . "messages) → vérifie-la dans ces sources plutôt que de répondre de mémoire, même si tu crois "
            . "connaître la réponse : c'est pour cela que l'utilisateur les a activées.\n"
            . "- Recherche vide ou hors sujet → reformule ou élargis UNE fois (autres mots-clés, filtre "
            . "retiré, fenêtre plus large). Toujours rien → dis clairement ce que tu n'as pas trouvé et avec quelles requêtes.\n"
            . "- Budget : au plus {$rounds} allers-retours d'outils pour ce message, et un appel strictement "
            . "identique n'est jamais rejoué. Conclus dès que tu as assez d'éléments, en citant ce que tu as consulté.";
    }
}
