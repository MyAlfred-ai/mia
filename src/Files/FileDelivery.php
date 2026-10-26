<?php

declare(strict_types=1);

/**
 * Convention de livraison d'un fichier par le modèle — SOURCE UNIQUE.
 *
 * Le contrat tient en une ligne : un FICHIER est un bloc de code dont la ligne
 * d'ouverture porte un nom.
 *
 *     ```csv fichier="budget-2026.xlsx"
 *     Poste;Montant
 *     Loyer;1200
 *     ```
 *
 * POURQUOI DU TEXTE, ET PAS UN APPEL D'OUTIL. Un outil `creer_fichier` aurait
 * réservé la fonctionnalité aux deux fournisseurs qui savent appeler des
 * outils, fait basculer chaque message dans la boucle d'outils, et caché le
 * contenu jusqu'à la fin de sa rédaction. Un bloc nommé marche avec les neuf
 * adaptateurs, en envoi comme en régénération, se lit pendant qu'il s'écrit,
 * et reste dans le message : au tour suivant le modèle relit son propre
 * fichier, donc « ajoute une colonne » fonctionne sans rien stocker.
 *
 * Corollaire assumé : RIEN n'est écrit sur le disque. Le fichier est fabriqué
 * à la demande, depuis le texte que l'utilisateur a sous les yeux — pas de
 * volume Docker, pas d'élagage, pas de sauvegarde à prévoir.
 *
 * Deux moitiés vivent ici parce qu'elles doivent rester d'accord : ce que l'on
 * DEMANDE au modèle (`promptFragment`) et ce que l'écran ACCEPTE de lui
 * (`ATTRIBUTES`, relu par le JavaScript via FileFormats::clientCatalog()). La
 * seconde est volontairement plus large que la première : les modèles écrivent
 * aussi `title="…"`, `filename=…` ou `csv:nom.csv`, habitudes prises ailleurs.
 */
final class FileDelivery
{
    /**
     * Noms d'attribut acceptés pour le nom du fichier, sur la ligne
     * d'ouverture du bloc. Le premier est celui que le prompt français
     * enseigne, le deuxième celui du prompt anglais.
     *
     * @var list<string>
     */
    public const ATTRIBUTES = ['fichier', 'file', 'filename', 'nom', 'name', 'title', 'titre'];

    /**
     * Fragment du prompt système : comment livrer un fichier dans CETTE
     * interface. Traduit — il cite le bouton « Télécharger » et la section
     * « Créer » du menu « + », et renverrait sinon un anglophone vers des libellés
     * qui n'existent pas à son écran (cf. I18n, lot 3).
     *
     * Prompt de CONTRAT, pas d'éditorial : il n'est pas réglable par
     * l'administrateur. Le rendre éditable, c'est laisser disparaître les
     * cartes d'un coup de touche Retour, sans message d'erreur.
     */
    public static function promptFragment(): string
    {
        return "\n\n" . t('Livraison de fichiers — règles de cette interface :')
            . "\n- " . t("Quand l'utilisateur demande un FICHIER (Excel, CSV, Word, RTF, PDF, PowerPoint, texte, JSON, script…), écris son contenu dans un bloc de code dont la ligne d'ouverture porte le nom du fichier, par exemple : ```csv fichier=\"budget-2026.xlsx\". L'interface affiche alors une carte avec un bouton « Télécharger » et fabrique elle-même le vrai fichier à partir de ce contenu. C'est la livraison normale et attendue : ne t'excuse pas, et ne dis jamais que tu ne peux pas créer de fichier.")
            . "\n- " . t("L'extension du nom choisit le format, et le contenu s'écrit ainsi. Tableur (.xlsx, .csv) : lignes CSV dans un bloc ```csv — séparateur point-virgule, première ligne d'en-têtes, guillemets autour d'une cellule qui contient un point-virgule, des guillemets ou un retour à la ligne ; les formules Excel sont admises (=SOMME(B2:B9)) ; un seul onglet par fichier. Document mis en forme (.docx, .rtf, .pdf) ou présentation PowerPoint (.pptx) : Markdown (titres, listes, gras, tableaux) dans un bloc ```markdown — c'est la seule exception à la règle ci-dessus sur les blocs markdown. Tout autre fichier texte (.txt, .md, .json, .xml, .sql, .html, code…) : le contenu tel quel, dans un bloc du langage correspondant (```text fichier=\"notes.txt\").")
            . "\n- " . t("Présentation PowerPoint (.pptx) : un titre `#` pour la couverture (le paragraphe qui le suit en devient le sous-titre), puis un titre `##` par slide. Une slide porte UNE idée : 3 à 5 puces d'une ligne (douze mots au plus), jamais de paragraphe — une slide trop chargée est découpée en plusieurs. Un `##` sans contenu fait un intercalaire de partie, une citation `>` seule (suivie de « — Auteur ») une slide citation, et un paragraphe « Notes : … » la note du présentateur de sa slide : c'est là que vont les détails. Pas d'image : la mise en page est automatique, au modèle de l'entreprise s'il en a un. Pour retoucher les slides à l'écran ou y ajouter des illustrations, la carte du fichier porte un bouton « Modifier les slides ».")
            . "\n- " . t("Mises en page reconnues dans une présentation, chacune seule sur sa slide : points clés = 2 à 6 lignes « - **Terme** — explication d'une phrase » (quelques mots en gras, puis un tiret) ; chiffres clés = les mêmes lignes avec une valeur courte en gras (« - **42 %** — légende ») ; chronologie = les mêmes lignes avec des dates (« - **2024** — Ouverture de Lyon ») ; tableau = un tableau Markdown de 6 colonnes au plus, avec au besoin une phrase d'introduction ; comparaison = deux sous-titres `###` suivis chacun de leurs puces ; étapes = une liste numérotée de 3 à 6 entrées courtes. Choisis-les quand le contenu s'y prête : une présentation qui varie se lit mieux qu'une suite de listes.")
            . "\n- " . t("Un bloc par fichier, avec son contenu COMPLET (jamais de « … » ni de « suite identique »), et un nom court, sans chemin. Si le contenu contient lui-même un bloc de code, délimite le fichier par quatre accents graves. Annonce le fichier en une phrase sans recopier son contenu hors du bloc ; pour le modifier, renvoie le fichier entier dans un nouveau bloc du même nom.")
            . "\n- " . t("Sans demande de fichier, réponds normalement : un tableau Markdown ou un texte mis en forme se lit mieux dans la conversation qu'un fichier.")
            . "\n- " . t("N'invente JAMAIS de lien de téléchargement, d'URL temporaire, de SAS Azure, de sandbox OpenAI, de chemin `files.oaiusercontent.com`, `blob.core.windows.net`, `openai-public`, etc. : ces URLs sont fausses, elles renvoient une erreur de signature et nuisent à l'utilisateur.")
            . "\n- " . t("N'invente pas non plus de format alternatif bizarre (XML Spreadsheet 2003, SpreadsheetML, blob base64 du XLSX à reconstruire, etc.) : le bloc nommé suffit, l'interface fabrique le classeur. Formats impossibles ici (archive zip, anciens .xls, .doc et .ppt, Keynote) : dis-le, et propose .xlsx, .docx, .pptx ou .pdf.")
            . "\n- " . t("Images, pages web et vidéos ne se livrent pas ainsi : elles passent par le menu « + » du chat, section « Créer », et ses entrées « Image », « Page web » et « Vidéo » (libellés exacts affichés à l'utilisateur : ne lui en invente pas d'autres). En dehors de ces outils et des blocs nommés, aucun fichier n'est créé — ne le promets pas.");
    }
}
