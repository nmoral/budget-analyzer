<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\ScenarioLine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès aux scénarios : persistance d'un scénario avec ses lignes (une transaction)
 * et relecture par identifiant.
 */
final class ScenarioRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Persiste le scénario et ses lignes en un seul `flush` ; les lignes référencent
     * le scénario, qui reçoit son identifiant généré.
     */
    public function save(Scenario $scenario, ScenarioLine ...$lines): void
    {
        $this->em->persist($scenario);
        foreach ($lines as $line) {
            $this->em->persist($line);
        }
        $this->em->flush();
    }

    public function find(int $id): ?Scenario
    {
        return $this->em->find(Scenario::class, $id);
    }
}
