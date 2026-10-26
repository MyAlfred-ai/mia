<?php

declare(strict_types=1);

/**
 * Fabrication d'un fichier à télécharger — `POST /files/build`.
 *
 * Le navigateur envoie ce que l'utilisateur a sous les yeux (le contenu d'un
 * bloc de code nommé, cf. FileDelivery) et le format voulu ; le serveur rend
 * le fichier. RIEN n'est stocké : ni fichier sur disque, ni ligne en base.
 *
 * POURQUOI LE CONTENU VIENT DU NAVIGATEUR, quand la règle du dépôt est de
 * relire en base (export d'un deck, lecture à voix haute) : ici la conversion
 * ne donne accès à RIEN. Elle transforme le texte qu'on lui confie et le rend
 * à celui qui l'a envoyé — aucun contenu d'autrui n'est lu, aucun droit n'est
 * à vérifier au-delà de la session. En échange, le bouton marche partout où
 * le texte est affiché : pendant que la réponse s'écrit, sur une réponse
 * interrompue qui n'a pas été enregistrée, sur un bloc de code d'une
 * conversation d'il y a six mois. Même précédent que les exports PDF et Word
 * d'une réponse, qui reçoivent le HTML rendu par la page.
 *
 * Contrôleur mince : tout le métier est dans FileFactory.
 */
class FileController
{
    /**
     * Au-delà, la requête est refusée sans même être lue : trois fois le
     * plafond du plus gros format (l'encodage du formulaire gonfle le corps).
     */
    private const MAX_REQUEST_BYTES = FileFormats::DEFAULT_MAX_BYTES * 3;

    public function build(): void
    {
        AuthMiddleware::requireAuth();

        // Langue et nom de l'application lus AVANT de rendre la session : la
        // fabrication d'un classeur ou d'un PDF prend parfois plusieurs
        // secondes, et les autres requêtes de l'onglet (envoi d'un message)
        // ne doivent pas attendre derrière son verrou.
        $locale = I18n::locale();
        $author = (string)Settings::get('app_name', 'MIA');
        session_write_close();

        // Corps plus gros que `post_max_size` : PHP vide alors `$_POST` sans
        // rien dire, et la requête passerait pour « fichier vide ».
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_REQUEST_BYTES || ($length > 0 && $_POST === [])) {
            $this->fail(FileBuildException::tooLarge(FileFormats::DEFAULT_MAX_BYTES));

            return;
        }

        try {
            $file = FileFactory::build(
                self::field('name', 300),
                (string)($_POST['source'] ?? ''),
                self::field('language', 40),
                self::field('origin', 300),
                ['locale' => $locale, 'author' => $author !== '' ? $author : 'MIA']
            );
        } catch (FileBuildException $e) {
            $this->fail($e);

            return;
        }

        header('Content-Type: ' . $file->mime);
        header('Content-Disposition: ' . FileNaming::contentDisposition($file->name));
        header('Content-Length: ' . $file->size());
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        // Un `.html` ou un `.svg` fabriqué ne doit jamais être interprété par
        // le navigateur depuis notre origine : pièce jointe, type non deviné.
        header('X-Content-Type-Options: nosniff');
        echo $file->bytes;
    }

    /** Champ court du formulaire : borné, sans octet de contrôle. */
    private static function field(string $name, int $max): string
    {
        $value = (string)($_POST[$name] ?? '');
        $value = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);

        return mb_substr(trim($value), 0, $max, 'UTF-8');
    }

    private function fail(FileBuildException $e): void
    {
        http_response_code($e->httpStatus());
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($e->toPayload(), JSON_UNESCAPED_UNICODE);
    }
}
