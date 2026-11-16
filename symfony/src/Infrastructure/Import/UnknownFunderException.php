<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Code `funder_code` référencé par un CSV mais absent du référentiel `data/funders.json`.
 */
final class UnknownFunderException extends ImportException
{
}
