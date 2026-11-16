<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Session de concours d'un corps, par voie et par académie (territoire).
 *
 * Effectifs d'une académie : postes offerts, candidats inscrits, présents aux
 * épreuves écrites et admis (entiers). FK vers le corps, l'académie, la source et le
 * jeu de paramètres (donnée scopée). Modèle de persistance, sans logique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'concours_academie')]
class ConcoursAcademie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    public int $session;

    #[ORM\Column(type: Types::STRING, length: 64)]
    public string $voie;

    #[ORM\Column(type: Types::INTEGER)]
    public int $postes;

    #[ORM\Column(type: Types::INTEGER)]
    public int $inscrits;

    #[ORM\Column(type: Types::INTEGER)]
    public int $presents;

    #[ORM\Column(type: Types::INTEGER)]
    public int $admis;

    #[ORM\ManyToOne(targetEntity: Corps::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Corps $body;

    #[ORM\ManyToOne(targetEntity: Academie::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Academie $academie;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Source $source;

    #[ORM\ManyToOne(targetEntity: ParameterSet::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ParameterSet $parameterSet;
}
