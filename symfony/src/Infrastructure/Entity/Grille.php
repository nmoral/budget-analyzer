<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Grille — version d'une grille indiciaire.
 *
 * Champs : corps, grade, version, début, fin, source.
 * Bornes de validité incluses `[startDate, endDate]`, `endDate` NULL = période ouverte.
 * FK vers le jeu de paramètres (valeur datée). Modèle de persistance, sans logique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'grille')]
class Grille
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Corps::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Corps $body;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $grade;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $version;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $endDate = null;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Source $source;

    #[ORM\ManyToOne(targetEntity: ParameterSet::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ParameterSet $parameterSet;
}
