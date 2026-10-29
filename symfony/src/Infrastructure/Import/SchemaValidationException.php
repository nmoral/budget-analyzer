<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Violation du schéma Frictionless d'un CSV (colonne requise absente ou type invalide, C3).
 */
final class SchemaValidationException extends ImportException
{
}
