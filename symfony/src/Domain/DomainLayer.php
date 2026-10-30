<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Marqueur de la couche de domaine.
 *
 * La couche App\Domain est du PHP pur : elle ne dépend ni de la persistance
 * ni de App\Infrastructure (frontière garantie par Deptrac, cf. deptrac.yaml).
 * Ce marqueur matérialise la couche tant que le moteur de calcul (#12, jalons
 * suivants) n'y a pas encore introduit ses interfaces et objets de domaine.
 */
final class DomainLayer
{
    private function __construct()
    {
    }
}
