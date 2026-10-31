<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\Scenario;
use App\Infrastructure\Entity\ScenarioLine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès aux lignes d'un scénario, dans l'ordre de leur identifiant.
 */
final class ScenarioLineRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<ScenarioLine>
     */
    public function forScenario(Scenario $scenario): array
    {
        $result = $this->em->createQueryBuilder()
            ->select('l')
            ->from(ScenarioLine::class, 'l')
            ->where('l.scenario = :scenario')
            ->setParameter('scenario', $scenario)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        \assert(\is_array($result));

        $lines = [];
        foreach ($result as $line) {
            \assert($line instanceof ScenarioLine);
            $lines[] = $line;
        }

        return $lines;
    }
}
