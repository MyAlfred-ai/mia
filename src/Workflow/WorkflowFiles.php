<?php

declare(strict_types=1);

/**
 * Une pièce du dossier en FICHIER : le document dans son format (Word, PDF,
 * PowerPoint, Excel, Markdown), un tableau en classeur, un texte en document
 * Word, un fichier reçu tel quel, une création (image, page web, itinéraire —
 * WorkflowCreations::file). Source unique du téléchargement d'une pièce
 * (page de l'exécution) et des pièces jointes d'une livraison par email —
 * les deux donnent le même fichier.
 */
final class WorkflowFiles
{
    /** Format « pour un email » : la forme qui convient à une pièce jointe (image, page allégées). */
    public const FOR_EMAIL = 'email';

    /**
     * @param string $format extension voulue ('' = celle de la pièce ; FOR_EMAIL = pour une pièce jointe)
     * @return array{name: string, mime: string, bytes: string}
     * @throws \RuntimeException pièce absente ou fichier illisible (message affichable)
     * @throws FileBuildException conversion impossible (message affichable)
     */
    public static function build(array $run, string $key, string $format): array
    {
        $pieces = WorkflowSpec::pieces($run['definition']);
        $art = WorkflowRun::artifacts($run['id'])[$key] ?? null;
        // « memoire#3 » : la pièce d'un tour de boucle, de la forme de « memoire ».
        $piece = $pieces[WorkflowLoop::splitIter($key)[0]] ?? null;
        if ($art === null || $piece === null) {
            throw new \RuntimeException(t('Pièce introuvable.'));
        }
        $content = $art['content'];
        $format = (string)preg_replace('/[^a-z]/', '', strtolower($format));
        // Image, page web, carte : le fichier de ce qui a été créé.
        if (isset(WorkflowSpec::CREATE_KINDS[$piece['form']])) {
            return WorkflowCreations::file($piece, $content, $format);
        }
        if ($format === self::FOR_EMAIL) {
            $format = '';
        }

        if ($piece['form'] === 'file') {
            $path = (string)($content['path'] ?? '');
            if ($path !== '' && WorkflowPrompt::readableUpload($path)) {
                return [
                    'name'  => (string)($content['name'] ?? 'fichier'),
                    'mime'  => (string)($content['type'] ?? '') ?: 'application/octet-stream',
                    'bytes' => (string)file_get_contents($path),
                ];
            }
            // Fichier lu ailleurs (Drive) : seul son texte est au dossier.
            $text = (string)($content['text'] ?? '');
            if (trim($text) === '') {
                throw new \RuntimeException(t('Fichier introuvable.'));
            }
            $base = pathinfo((string)($content['name'] ?? $piece['name']), PATHINFO_FILENAME) ?: $piece['name'];
            $file = FileFactory::build($base . '.' . ($format !== '' ? $format : 'md'), $text, 'markdown', $base . '.md', self::options());
            return ['name' => $file->name, 'mime' => $file->mime, 'bytes' => $file->bytes];
        }

        [$source, $language, $origin] = match ($piece['form']) {
            'document' => [(string)$content['source'], (string)($content['language'] ?? 'markdown'), ArtifactContract::filename(['name' => $piece['name'], 'format' => (string)($content['format'] ?? $piece['format'] ?? 'docx')])],
            'table'    => [ArtifactContract::tableMarkdown((array)($content['rows'] ?? [])), 'csv', $piece['name'] . '.xlsx'],
            default    => [ArtifactContract::render($piece, $content, 400000), 'markdown', $piece['name'] . '.docx'],
        };
        $ext = $format !== '' ? $format : FileFormats::extensionOf($origin);
        $file = FileFactory::build(pathinfo($origin, PATHINFO_FILENAME) . '.' . $ext, $source, $language, $origin, self::options());
        return ['name' => $file->name, 'mime' => $file->mime, 'bytes' => $file->bytes];
    }

    /** Réglages des fichiers fabriqués : langue, auteur (le nom de l'application). */
    public static function options(): array
    {
        return ['locale' => I18n::locale(), 'author' => (string)Settings::get('app_name', 'MIA')];
    }
}
