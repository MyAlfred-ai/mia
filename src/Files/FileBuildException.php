<?php

declare(strict_types=1);

/**
 * Échec PRÉSENTABLE de la fabrication d'un fichier.
 *
 * Un fichier qui ne se fabrique pas ne doit jamais laisser l'utilisateur
 * devant un bouton muet : chaque échec porte un message lisible ET les formats
 * de rechange à proposer (« le classeur n'a pas pu être créé — le même
 * contenu en CSV ? »). Le navigateur garde de toute façon sa propre issue de
 * secours : le texte brut, qu'il sait enregistrer sans le serveur.
 *
 * Les messages sont TRADUITS : ils s'affichent dans la carte du fichier. Le
 * détail technique (exception d'une bibliothèque, chemin, pile) ne quitte
 * jamais le journal.
 */
final class FileBuildException extends RuntimeException
{
    public const EMPTY       = 'empty';
    public const TOO_LARGE   = 'too_large';
    public const UNSUPPORTED = 'unsupported';
    public const UNAVAILABLE = 'unavailable';
    public const INVALID     = 'invalid';
    public const FAILED      = 'failed';

    /** @var list<string> */
    private array $alternatives;

    /**
     * @param list<string> $alternatives Extensions à proposer à la place.
     */
    private function __construct(
        private string $kind,
        string $message,
        array $alternatives = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->alternatives = array_values(array_unique(array_map('strval', $alternatives)));
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** @return list<string> */
    public function alternatives(): array
    {
        return $this->alternatives;
    }

    /**
     * Même échec, avec des formats de rechange : celui qui lève ne sait pas
     * toujours quoi proposer (TabularData ignore qu'on fabriquait un classeur),
     * celui qui appelle, si.
     *
     * @param list<string> $alternatives
     */
    public function withAlternatives(array $alternatives): self
    {
        return new self($this->kind, $this->getMessage(), $alternatives, $this->getPrevious());
    }

    /** Statut HTTP de la réponse d'erreur. */
    public function httpStatus(): int
    {
        return match ($this->kind) {
            self::TOO_LARGE   => 413,
            self::UNSUPPORTED => 415,
            self::UNAVAILABLE => 503,
            self::FAILED      => 500,
            default           => 422,
        };
    }

    /** Charge utile JSON rendue au navigateur. */
    public function toPayload(): array
    {
        return [
            'error'        => $this->getMessage(),
            'code'         => $this->kind,
            'alternatives' => $this->alternatives,
        ];
    }

    // ------------------------------------------------------------------
    // Fabriques : un seul endroit rédige les messages.
    // ------------------------------------------------------------------

    public static function emptySource(): self
    {
        return new self(self::EMPTY, t('Ce fichier est vide : il n’y a rien à télécharger.'));
    }

    public static function tooLarge(int $maxBytes, array $alternatives = []): self
    {
        return new self(
            self::TOO_LARGE,
            t('Ce fichier est trop volumineux pour être fabriqué ici (plus de {taille}).', [
                'taille' => self::humanSize($maxBytes),
            ]),
            $alternatives
        );
    }

    /** Tableau au-delà de ce qu'un classeur fabriqué à la volée peut porter. */
    public static function tableTooLarge(int $rows, int $cols, array $alternatives = []): self
    {
        return new self(
            self::TOO_LARGE,
            t('Ce tableau est trop grand pour un classeur ({lignes} lignes × {colonnes} colonnes).', [
                'lignes'   => $rows,
                'colonnes' => $cols,
            ]),
            $alternatives
        );
    }

    /**
     * Présentation au-delà de ce qu'un fichier fabriqué ici porte : la couper
     * livrerait un fichier faux sans le dire.
     */
    public static function tooManySlides(int $count, int $max, array $alternatives = []): self
    {
        return new self(
            self::TOO_LARGE,
            t('Cette présentation compterait {n} slides : une présentation créée ici en compte au plus {max}.', [
                'n'   => $count,
                'max' => $max,
            ]),
            $alternatives
        );
    }

    /**
     * Format qu'on ne sait pas fabriquer depuis du texte (ancien format,
     * archive, image…). `$why` vient du catalogue, déjà traduit.
     */
    public static function unsupported(string $why, array $alternatives = []): self
    {
        return new self(self::UNSUPPORTED, $why, $alternatives);
    }

    /** Conversion demandée sans objet : un classeur depuis un courrier, par exemple. */
    public static function incompatible(string $targetLabel, array $alternatives = []): self
    {
        return new self(
            self::UNSUPPORTED,
            t('Ce contenu ne peut pas être converti en {format}.', ['format' => $targetLabel]),
            $alternatives
        );
    }

    /** Bibliothèque absente du serveur (installation incomplète). */
    public static function unavailable(string $targetLabel, array $alternatives = []): self
    {
        return new self(
            self::UNAVAILABLE,
            t('La création de ce format ({format}) n’est pas disponible sur ce serveur.', ['format' => $targetLabel]),
            $alternatives
        );
    }

    /** Source illisible pour le format visé (tableau sans aucune cellule…). */
    public static function invalid(string $message, array $alternatives = []): self
    {
        return new self(self::INVALID, $message, $alternatives);
    }

    /** Tableau attendu, rien d'exploitable trouvé. */
    public static function noTable(array $alternatives = []): self
    {
        return new self(
            self::INVALID,
            t('Aucun tableau exploitable dans ce contenu : impossible d’en faire un classeur.'),
            $alternatives
        );
    }

    /** Échec inattendu : le détail part au journal, jamais à l'écran. */
    public static function failed(string $targetLabel, array $alternatives = [], ?Throwable $previous = null): self
    {
        return new self(
            self::FAILED,
            t('Le fichier {format} n’a pas pu être créé.', ['format' => $targetLabel]),
            $alternatives,
            $previous
        );
    }

    /** Taille lisible, sans dépendre de Format (absent des scripts CLI minimaux). */
    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1_000_000) {
            return t('{n} Mo', ['n' => rtrim(rtrim(number_format($bytes / 1_000_000, 1, ',', ''), '0'), ',')]);
        }

        return t('{n} Ko', ['n' => (string)max(1, (int)round($bytes / 1000))]);
    }
}
