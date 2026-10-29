<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paramètre daté — valeur scalaire réglementaire.
 *
 * Champs : clé (`point_indice`, `taux_cas_civil`…), valeur, unité, début, fin, source.
 * Bornes de validité incluses `[startDate, endDate]`, `endDate` NULL = période ouverte.
 * FK vers le jeu de paramètres (valeur datée). Modèle de persistance, sans logique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dated_parameter')]
class DatedParameter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(name: '`key`', type: Types::STRING, length: 255)]
    public string $key;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 6)]
    public string $value;

    #[ORM\Column(type: Types::STRING, length: 64)]
    public string $unit;

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
