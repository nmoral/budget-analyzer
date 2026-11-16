<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Budget de référence daté par financeur.
 *
 * Montant total d'un financeur (`ETAT_P140` = programme 140 LFI, `ONDAM` = ONDAM
 * global LFSS…) sur une période de validité. Bornes de validité incluses
 * `[startDate, endDate]`, `endDate` NULL = période ouverte. Montant exposé en
 * string (DECIMAL, jamais float). FK vers le financeur, la source et le jeu de
 * paramètres (valeur datée). Modèle de persistance, sans logique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reference_budget')]
class ReferenceBudget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 16, scale: 2)]
    public string $value;

    #[ORM\Column(type: Types::STRING, length: 64)]
    public string $unit;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $endDate = null;

    #[ORM\ManyToOne(targetEntity: Funder::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Funder $funder;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Source $source;

    #[ORM\ManyToOne(targetEntity: ParameterSet::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ParameterSet $parameterSet;
}
