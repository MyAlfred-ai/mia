<?php

declare(strict_types=1);

/**
 * Un fournisseur d'outils du chat : un connecteur (Drive, Gmail…) ou un outil
 * confié à l'assistant (image, n8n, Gamma, cerveau MyBrain).
 *
 * Il porte, pour son périmètre, tout ce que le modèle reçoit et tout ce qui
 * s'exécute : définitions (schémas envoyés au LLM), consigne ajoutée au prompt
 * système, handlers dispatchés par nom — et les métadonnées dont le registre a
 * besoin pour les traiter sans rien savoir de leur métier.
 */
interface ToolProvider
{
    /** Clé stable du fournisseur (drive, gmail, image, n8n…). */
    public function key(): string;

    /**
     * Clé de CONNECTEUR engagé, ou null pour un outil d'assistant.
     *
     * Un connecteur engagé reçoit la méthode de travail « agent » et le rappel
     * des objets déjà consultés dans la conversation (ConnectorContext) : un
     * outil image seul n'a rien à enchaîner, il n'en a pas l'usage.
     */
    public function connector(): ?string;

    /** @return list<array{name: string, description: string, parameters: array}> */
    public function definitions(): array;

    /** Consigne ajoutée au prompt système quand les outils sont exposés. */
    public function guidance(): string;

    /** @return array<string, callable(array): array> nom d'outil → handler */
    public function handlers(): array;

    /**
     * Outils dont le résultat rapporte un contenu écrit par un TIERS (email,
     * document, message, évènement) : le registre le marque comme donnée —
     * jamais une consigne pour le modèle.
     *
     * @return list<string>
     */
    public function externalContentTools(): array;
}
