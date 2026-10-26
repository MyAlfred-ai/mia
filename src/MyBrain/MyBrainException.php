<?php

declare(strict_types=1);

/**
 * Un appel à MyBrain qui n'a pas abouti. Le message est en français et peut
 * être montré à un administrateur tel quel ; `kind` dit ce qu'il faut en faire,
 * parce que les pannes ne se valent pas :
 *
 *   REFUSED      la clé est refusée (supprimée, droit retiré) — l'état est
 *                durable : la base se grise dans le chat jusqu'à un nouveau test ;
 *   INVALID      l'adresse ne mène pas à un MyBrain (404, redirection, réponse
 *                illisible) — durable aussi, c'est la configuration qui est fausse ;
 *   UNREACHABLE  serveur injoignable, en erreur ou qui limite les appels —
 *                passager : la base reste cochable, le message part sans elle ;
 *   REQUEST      MyBrain refuse CETTE demande (400, 413) — rien à dire de la
 *                connexion elle-même, l'état enregistré ne bouge pas.
 */
final class MyBrainException extends \RuntimeException
{
    public const REFUSED     = 'refused';
    public const INVALID     = 'invalid';
    public const UNREACHABLE = 'unreachable';
    public const REQUEST     = 'request';

    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly int $httpStatus = 0
    ) {
        parent::__construct($message);
    }

    /** La panne tient-elle à la configuration (et non au moment) ? */
    public function isDurable(): bool
    {
        return $this->kind === self::REFUSED || $this->kind === self::INVALID;
    }
}
