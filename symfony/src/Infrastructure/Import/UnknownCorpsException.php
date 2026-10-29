<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Code `body_code` référencé par un CSV mais absent du référentiel `data/corps.json`.
 */
final class UnknownCorpsException extends ImportException
{
}
