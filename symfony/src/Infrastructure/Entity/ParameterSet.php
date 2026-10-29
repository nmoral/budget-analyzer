<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeu de paramètres — reproductibilité des résultats.
 *
 * Champs : identifiant de version (commit du dépôt de données), date d'import.
 * Les valeurs datées (paramètre, grille, échelon via sa grille, taux de primes)
 * portent une FK vers ce jeu. Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'parameter_set')]
class ParameterSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public string $versionId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $importedAt;
}
