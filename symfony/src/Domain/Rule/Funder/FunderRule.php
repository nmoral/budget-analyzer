<?php

declare(strict_types=1);

namespace App\Domain\Rule\Funder;

use App\Domain\Rule\Rule;

/**
 * Famille « financeur » : règle de prise en charge par un financeur (identité de
 * sélection uniquement en #12).
 */
interface FunderRule extends Rule
{
}
