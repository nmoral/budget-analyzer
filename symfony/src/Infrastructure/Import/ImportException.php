<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

/**
 * Échec d'import — interrompt la transaction et provoque un rollback total (C2).
 */
class ImportException extends \RuntimeException
{
}
