<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Régime de retraite cité par un profil mais absent du référentiel
 * `data/retirement_schemes.json`.
 */
final class UnknownRetirementSchemeException extends ImportException
{
}
