<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Taux de primes — primes en V1.
 *
 * Champs : corps, valeur, année d'observation, source, fallback.
 * FK vers le jeu de paramètres (valeur datée). Modèle de persistance, sans logique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'prime_rate')]
class PrimeRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Corps::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Corps $body;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 6)]
    public string $value;

    #[ORM\Column(type: Types::INTEGER)]
    public int $observationYear;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Source $source;

    #[ORM\Column(type: Types::BOOLEAN)]
    public bool $fallback;

    #[ORM\ManyToOne(targetEntity: ParameterSet::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ParameterSet $parameterSet;
}
