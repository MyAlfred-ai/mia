<?php

declare(strict_types=1);

/**
 * Fabrique de fichiers — POINT D'ENTRÉE UNIQUE du module.
 *
 *     $fichier = FileFactory::build('budget.xlsx', $csv, 'csv');
 *
 * Un nom, un contenu, le langage du bloc : la fabrique choisit le format
 * (FileFormats), vérifie que la conversion a un sens, délègue au fabricant du
 * format et rend un GeneratedFile. Elle ne lit ni `$_POST` ni la session :
 * appelable depuis un contrôleur, une tâche de fond ou un test.
 *
 * DEUX NOMS, et la distinction compte. `$filename` est le fichier DEMANDÉ
 * (« budget.csv » quand l'utilisateur choisit CSV dans le menu) ; `$origin`
 * est le fichier tel que le modèle l'a DÉCLARÉ (« budget.xlsx »). C'est
 * l'origine qui dit comment lire le contenu : un courrier déclaré en `.docx`
 * reste du Markdown, qu'on le télécharge en Word, en PDF ou en texte.
 *
 * Aucune exception brute ne sort d'ici : tout échec devient une
 * FileBuildException présentable (message traduit + formats de rechange), le
 * détail technique partant au journal.
 */
final class FileFactory
{
    /**
     * @param string $filename Fichier à produire (son extension choisit le format).
     * @param string $source   Contenu écrit par le modèle.
     * @param string $language Langage du bloc de code (csv, markdown, text…).
     * @param string $origin   Fichier tel que déclaré par le modèle ('' = le même que $filename).
     * @param array{locale?: string, author?: string} $options
     *
     * @throws FileBuildException
     */
    public static function build(
        string $filename,
        string $source,
        string $language = '',
        string $origin = '',
        array $options = []
    ): GeneratedFile {
        $language = strtolower(trim($language));

        $targetExt = self::resolveExtension($filename, $language);
        $originExt = $origin !== '' ? self::resolveExtension($origin, $language) : $targetExt;

        // Format binaire qu'on ne sait pas écrire depuis du texte : refus
        // AVANT toute lecture, avec ce qu'il faut proposer à la place.
        $refusal = FileFormats::unsupported($targetExt);
        if ($refusal !== null) {
            throw FileBuildException::unsupported($refusal['why'], $refusal['use']);
        }

        $format = FileFormats::get($targetExt);
        // L'origine peut être un format refusé (« plan.key ») dont on
        // télécharge une issue de secours : son contenu se lit alors comme le
        // format de rechange choisi (un document, ou une présentation .pptx).
        $syntax = FileFormats::unsupported($originExt) !== null
            ? FileFormats::sourceSyntax($targetExt, $language)
            : FileFormats::sourceSyntax($originExt, $language);

        if (!FileFormats::canBuild($syntax, $targetExt, $originExt)) {
            throw FileBuildException::incompatible(
                $format['short'],
                array_values(array_diff(FileFormats::alternatives($originExt), [$targetExt]))
            );
        }

        $content = self::cleanSource($source);
        if (trim($content) === '') {
            throw FileBuildException::emptySource();
        }

        $alternatives = self::fallbacks($targetExt, $syntax, $originExt);
        if (strlen($content) > $format['max']) {
            throw FileBuildException::tooLarge($format['max'], $alternatives);
        }

        $name = FileNaming::build($filename, $format['ext'], $format['stem']);

        try {
            $bytes = self::builderFor($format['builder'])->build(new FileSource(
                $content,
                $syntax,
                $format['ext'],
                FileNaming::stem($name),
                (string)($options['locale'] ?? I18n::locale()),
                (string)($options['author'] ?? 'MIA'),
                $originExt
            ));
        } catch (FileBuildException $e) {
            // Le fabricant sait POURQUOI, la fabrique sait QUOI proposer.
            throw $e->alternatives() === [] ? $e->withAlternatives($alternatives) : $e;
        } catch (Throwable $e) {
            error_log('[Fichiers] Fabrication de « ' . $name . ' » impossible : '
                . get_class($e) . ' — ' . $e->getMessage());
            throw FileBuildException::failed($format['short'], $alternatives, $e);
        }

        if ($bytes === '') {
            throw FileBuildException::failed($format['short'], $alternatives);
        }

        return new GeneratedFile($name, $format['mime'], $bytes);
    }

    /**
     * Extension visée par un nom de fichier ; à défaut celle du langage du
     * bloc ; à défaut `txt` — du texte s'enregistre toujours.
     */
    public static function resolveExtension(string $filename, string $language = ''): string
    {
        $ext = FileFormats::extensionOf($filename);
        if ($ext !== '') {
            return $ext;
        }
        $ext = FileFormats::extensionForLanguage($language);

        return $ext !== '' ? $ext : 'txt';
    }

    /**
     * Contenu normalisé : sans BOM ni octet nul, fins de ligne LF, UTF-8
     * valide. Une séquence invalide (collage depuis un vieil outil) ferait
     * échouer json_encode, l'écriture XML d'un classeur, et l'en-tête HTTP.
     */
    public static function cleanSource(string $source): string
    {
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $source = substr($source, 3);
        }
        $source = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $source);

        if (!mb_check_encoding($source, 'UTF-8')) {
            $source = mb_convert_encoding($source, 'UTF-8', 'UTF-8');
        }

        return $source;
    }

    /**
     * Formats à proposer si la fabrication échoue : ceux qui se fabriquent
     * depuis la même source sans dépendre de la même bibliothèque, le plus
     * simple en premier.
     *
     * D'après la SYNTAXE de la source, sauf pour une présentation : ses issues
     * sont celles de son menu (un PowerPoint en échec se propose en PDF de
     * slides ou en Word, jamais en RTF).
     *
     * @return list<string>
     */
    private static function fallbacks(string $targetExt, string $syntax, string $originExt = ''): array
    {
        if (FileFormats::isPresentation($originExt) && $syntax !== FileFormats::SYNTAX_RAW) {
            return array_values(array_diff(['pptx', 'pdf', 'docx', 'md'], [$targetExt]));
        }

        $order = match ($syntax) {
            FileFormats::SYNTAX_TABLE    => ['csv', 'xlsx'],
            FileFormats::SYNTAX_MARKDOWN,
            FileFormats::SYNTAX_TEXT,
            FileFormats::SYNTAX_HTML     => ['rtf', 'docx', 'pdf', 'txt', 'md'],
            default                      => [],
        };

        return array_values(array_diff($order, [$targetExt]));
    }

    private static function builderFor(string $class): FileBuilder
    {
        if (!class_exists($class) || !is_subclass_of($class, FileBuilder::class)) {
            throw new RuntimeException('Fabricant de fichier introuvable : ' . $class);
        }

        return new $class();
    }
}
