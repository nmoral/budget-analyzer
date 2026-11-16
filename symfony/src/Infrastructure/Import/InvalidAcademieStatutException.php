<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Entrée de `data/academies.json` dont le `statut` est absent ou n'est pas exactement
 * `metropole`, `drom` ou `com`.
 */
final class InvalidAcademieStatutException extends ImportException
{
}
