<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Échelon — progression de carrière.
 *
 * Champs : grille, rang, indice brut, indice majoré, durée en mois.
 * Valeur datée via sa grille (pas de FK directe vers le jeu de paramètres).
 * Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'echelon')]
class Echelon
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Grille::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Grille $grille;

    #[ORM\Column(type: Types::INTEGER)]
    public int $rank;

    #[ORM\Column(type: Types::INTEGER)]
    public int $grossIndex;

    #[ORM\Column(type: Types::INTEGER)]
    public int $increasedIndex;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $durationMonths = null;
}
