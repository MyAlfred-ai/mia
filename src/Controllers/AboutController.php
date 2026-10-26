<?php

declare(strict_types=1);

class AboutController
{
    /** URL par défaut de la documentation ; chaque installation peut la régler. */
    public const DOCS_URL = 'https://docs.myalfred.ai/mia/';

    public function index(): void
    {
        require ROOT . '/templates/about/index.php';
    }
}
