<?php

declare(strict_types=1);

/**
 * Choix proposés en boutons dans une conversation — SOURCE UNIQUE du contrat.
 *
 * Quand la suite dépend d'une décision de l'utilisateur entre quelques options
 * nettes, le modèle termine sa réponse par un bloc de code dont le langage est
 * `choix` :
 *
 *     ```choix
 *     Quel ton pour l'email ?
 *     - Formel — pour un client
 *     - Cordial
 *     - Très bref
 *     ```
 *
 * L'écran (public/assets/js/choice-buttons.js) en fait des BOUTONS ; un clic
 * envoie le libellé comme un message ordinaire de l'utilisateur, qui peut
 * toujours écrire autre chose à la place.
 *
 * POURQUOI DU TEXTE, ET PAS UN APPEL D'OUTIL — même raisonnement que les
 * fichiers livrés (FileDelivery) : un outil `proposer_choix` réserverait la
 * fonction aux modèles qui appellent des outils, ferait passer chaque message
 * par la boucle d'outils et cacherait les options jusqu'à la fin. Le bloc
 * marche avec les neuf fournisseurs, s'affiche pendant l'écriture et reste
 * dans le message : au tour suivant, le modèle relit les options qu'il avait
 * proposées et comprend la réponse qu'on lui envoie.
 *
 * RIEN de nouveau côté serveur : ni route, ni table, ni colonne. Le clic
 * passe par l'envoi normal (quota, assistant, connecteurs, trace, facturation)
 * — exactement comme si la personne avait tapé le libellé.
 *
 * Deux moitiés vivent ici parce qu'elles doivent rester d'accord : ce que l'on
 * DEMANDE au modèle (`promptFragment`, bornes enseignées) et ce que l'écran
 * ACCEPTE de lui (`clientConfig`, relu par le JavaScript). La seconde est plus
 * large que la première : `choices` et `choice` passent aussi, sept ou huit
 * options ne sont pas perdues, une liste sans tirets se lit encore.
 */
final class ChoiceButtons
{
    /**
     * Langages de bloc reconnus. Le premier est celui du prompt français, le
     * deuxième celui du prompt anglais.
     *
     * @var list<string>
     */
    public const LANGUAGES = ['choix', 'choices', 'choice'];

    /**
     * Mots qui, sur la ligne d'ouverture, rendent le choix MULTIPLE (plusieurs
     * options retenues ensemble, puis « Envoyer »).
     *
     * @var list<string>
     */
    public const MULTIPLE_FLAGS = ['multiple', 'multi', 'plusieurs'];

    /** Options demandées au modèle : de MIN_OPTIONS à MAX_OPTIONS. */
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 6;

    /** Options acceptées à l'écran : au-delà, ignorées (le prompt en demande six au plus). */
    public const OPTIONS_LIMIT = 8;

    /** Longueur maximale, en caractères, d'un libellé (c'est lui qui part comme message). */
    public const LABEL_MAX = 120;

    /** Longueur maximale d'une précision affichée sous le libellé. */
    public const DESCRIPTION_MAX = 200;

    /** Longueur maximale de la question posée au-dessus des options. */
    public const QUESTION_MAX = 300;

    /**
     * Options courtes et sans précision : rendues en pastilles sur une ligne
     * (« Oui » / « Non ») plutôt qu'en lignes empilées.
     */
    public const COMPACT_LABEL_MAX = 28;

    /**
     * Ce que le navigateur doit connaître du contrat (window.CHOICE_BUTTONS) :
     * aucune liste ni borne n'est recopiée en JavaScript.
     *
     * @return array{languages: list<string>, multiple: list<string>, min: int, limit: int, labelMax: int, descriptionMax: int, questionMax: int, compactMax: int}
     */
    public static function clientConfig(): array
    {
        return [
            'languages'      => self::LANGUAGES,
            'multiple'       => self::MULTIPLE_FLAGS,
            'min'            => self::MIN_OPTIONS,
            'limit'          => self::OPTIONS_LIMIT,
            'labelMax'       => self::LABEL_MAX,
            'descriptionMax' => self::DESCRIPTION_MAX,
            'questionMax'    => self::QUESTION_MAX,
            'compactMax'     => self::COMPACT_LABEL_MAX,
        ];
    }

    /**
     * Fragment du prompt système : quand et comment proposer des choix.
     *
     * Traduit, comme celui des fichiers livrés : l'exemple d'options se lit
     * dans la langue de l'écran, et un modèle s'y aligne volontiers.
     *
     * Prompt de CONTRAT, pas d'éditorial : il n'est pas réglable. Il n'est
     * PAS ajouté aux tours sans personne devant l'écran (tâche programmée,
     * étape de workflow) ni à la conversation vocale — un bouton n'y aurait
     * personne pour le toucher (cf. ChatController::runSend).
     */
    public static function promptFragment(): string
    {
        $bornes = ['min' => self::MIN_OPTIONS, 'max' => self::MAX_OPTIONS];

        return "\n\n" . t('Choix en boutons — capacité de cette interface :')
            . "\n- " . t("Quand la suite dépend d'une décision de l'utilisateur entre quelques options nettes (une piste parmi plusieurs, un ton, un format, la prochaine étape, une précision indispensable dont la réponse tient en quelques mots), termine ta réponse par un bloc ```choix : l'interface affiche chaque option en bouton, et un clic l'envoie comme réponse de l'utilisateur. Il peut toujours écrire autre chose à la place.")
            . "\n- " . t("Forme : sur la première ligne, la question (facultative si ta dernière phrase la pose déjà) ; puis une option par ligne, « - Libellé » ou « - Libellé — précision courte ». De {min} à {max} options. Seul le libellé est envoyé, tel quel, comme message de l'utilisateur : court (quelques mots) et compréhensible seul comme réponse. Quand plusieurs options peuvent être retenues ensemble, ouvre le bloc par ```choix multiple.", $bornes)
            . "\n- " . t('Exemple :')
            . "\n" . t("```choix\nQuel ton pour l'email ?\n- Formel — pour un client\n- Cordial\n- Très bref\n```")
            . "\n- " . t("Seulement quand c'est vraiment utile : jamais pour une question ouverte (un nom, une date, un texte à fournir), jamais par réflexe à la fin d'une réponse complète, jamais pour approuver une action préparée (sa carte porte déjà ses boutons). Un seul bloc par réponse, toujours en dernier. Ne recopie pas les options dans le texte au-dessus et n'ajoute pas d'option « Autre » : l'interface permet déjà d'écrire une autre réponse.");
    }
}
