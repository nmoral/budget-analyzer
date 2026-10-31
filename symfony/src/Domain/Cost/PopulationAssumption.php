<?php

declare(strict_types=1);

namespace App\Domain\Cost;

/**
 * Hypothèse de population retenue pour un horizon de coût, sans logique de calcul.
 *
 * `STATIONARY` : population stationnaire (régime permanent), où chaque poste est
 * en permanence occupé par la carrière moyenne d'un agent du corps.
 */
enum PopulationAssumption
{
    case STATIONARY;
}
