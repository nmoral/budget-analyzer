<?php

declare(strict_types=1);

namespace App\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Régime de retraite — code (`CAS_CIVIL`, `CAS_MILITAIRE`, `CNRACL`, `IRCANTEC`)
 * et libellé. Les paramètres/règles enfichables du régime relèvent de #12.
 * Modèle de persistance, sans logique métier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'retirement_scheme')]
class RetirementScheme
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public string $code;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $label;
}
