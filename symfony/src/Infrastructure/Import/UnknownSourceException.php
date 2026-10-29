<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Slug `source` référencé par un CSV mais absent du référentiel `data/sources.json`.
 */
final class UnknownSourceException extends ImportException
{
}
