<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Scénario — simulation ou programme.
 *
 * Champs : titre, provenance, montant annoncé optionnel, version des paramètres
 * (FK vers le jeu de paramètres). Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scenario')]
class Scenario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $title;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $provenance;

    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 2, nullable: true)]
    public ?string $announcedAmount = null;

    #[ORM\ManyToOne(targetEntity: ParameterSet::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ParameterSet $parameterSet;
}
