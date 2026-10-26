<?php

declare(strict_types=1);

/**
 * Ce qu'un fabricant reçoit : le contenu écrit par le modèle, la syntaxe dans
 * laquelle il faut le lire, et le format à produire.
 *
 * Objet-valeur : les fabricants ne lisent ni `$_POST` ni la session, ce qui
 * les rend appelables de n'importe où (contrôleur, test, tâche de fond).
 */
final class FileSource
{
    /**
     * @param string $content Contenu, déjà normalisé (UTF-8 valide, fins de ligne LF, sans BOM).
     * @param string $syntax  Une des constantes FileFormats::SYNTAX_*.
     * @param string $ext     Extension du fichier à produire.
     * @param string $title   Nom du fichier sans extension (titre du document, nom de l'onglet).
     * @param string $locale  Langue de l'utilisateur : tranche « 1,234 » (décimale en français,
     *                        milliers en anglais) et l'ordre jour/mois d'une date.
     * @param string $author  Auteur inscrit dans les propriétés du fichier (nom de l'application).
     * @param string $origin  Extension du fichier tel que le modèle l'a DÉCLARÉ ('' = la même
     *                        que $ext). Elle seule distingue un PDF de document d'un PDF de
     *                        slides : les deux se lisent en Markdown.
     */
    public function __construct(
        public readonly string $content,
        public readonly string $syntax,
        public readonly string $ext,
        public readonly string $title,
        public readonly string $locale = 'fr',
        public readonly string $author = 'MIA',
        public readonly string $origin = ''
    ) {
    }

    /** La source est-elle une présentation, quel que soit le format demandé ? */
    public function isPresentation(): bool
    {
        return FileFormats::isPresentation($this->origin !== '' ? $this->origin : $this->ext);
    }

    /** Même source, autre contenu (nettoyé pour un format qui ne dessine pas tout). */
    public function withContent(string $content): self
    {
        return new self($content, $this->syntax, $this->ext, $this->title, $this->locale, $this->author, $this->origin);
    }

    /**
     * Blocs de document de la source, selon sa syntaxe. Point d'entrée commun
     * des fabricants Word, RTF, PDF et PowerPoint : des lectures séparées
     * donneraient des fichiers différents pour le même texte.
     *
     * @return list<array<string, mixed>>
     */
    public function documentBlocks(): array
    {
        return match ($this->syntax) {
            FileFormats::SYNTAX_MARKDOWN => MarkdownDocument::parse($this->content),
            // HTML écrit par le modèle : ramené à son texte. Le mettre en page
            // fidèlement demanderait un moteur HTML — seul Word en a un ici
            // (cf. DocxFileBuilder).
            FileFormats::SYNTAX_HTML     => MarkdownDocument::fromPlainText(MailHtml::htmlVersTexte($this->content)),
            default                      => MarkdownDocument::fromPlainText($this->content),
        };
    }

    /** Titre du document : son premier titre, sinon le nom du fichier. */
    public function documentTitle(array $blocks): string
    {
        $title = MarkdownDocument::title($blocks);

        return $title !== '' ? $title : $this->title;
    }
}
