<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Code `academie` référencé par un CSV mais absent du référentiel `data/academies.json`.
 */
final class UnknownAcademieException extends ImportException
{
}
