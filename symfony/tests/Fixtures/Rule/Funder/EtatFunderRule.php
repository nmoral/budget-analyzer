<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Rule\Funder;

use App\Domain\Rule\Funder\FunderRule;

/**
 * Implémentation de test de la famille financeur (code ETAT_P140).
 */
final class EtatFunderRule implements FunderRule
{
    public function code(): string
    {
        return 'ETAT_P140';
    }
}
