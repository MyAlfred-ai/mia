<?php

declare(strict_types=1);

/**
 * Fichier fabriqué, prêt à être envoyé : un nom, un type, des octets.
 * Objet-valeur — il ne sait rien de la façon dont il a été produit.
 */
final class GeneratedFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $mime,
        public readonly string $bytes
    ) {
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }
}
