<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\Grille;
use App\Infrastructure\Entity\ParameterSet;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès « valeur en vigueur à la date D » pour les grilles indiciaires.
 */
final class GrilleRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParameterSetRepository $parameterSets,
    ) {
    }

    /**
     * Unique grille (corps `$bodyCode` + grade `$grade`) dont la période
     * `[startDate, endDate]` (bornes incluses, endDate NULL = période ouverte)
     * couvre `$date`, scopée au jeu `$set` (défaut : dernier jeu importé).
     * Null si aucune période ne couvre D.
     */
    public function valueInEffectAt(
        string $bodyCode,
        string $grade,
        \DateTimeImmutable $date,
        ?ParameterSet $set = null,
    ): ?Grille {
        $set ??= $this->parameterSets->latest();
        if ($set === null) {
            return null;
        }

        $result = $this->em->createQueryBuilder()
            ->select('g')
            ->from(Grille::class, 'g')
            ->join('g.body', 'b')
            ->where('b.code = :bodyCode')
            ->andWhere('g.grade = :grade')
            ->andWhere('g.parameterSet = :set')
            ->andWhere('g.startDate <= :date')
            ->andWhere('(g.endDate IS NULL OR g.endDate >= :date)')
            ->setParameter('bodyCode', $bodyCode)
            ->setParameter('grade', $grade)
            ->setParameter('set', $set)
            ->setParameter('date', $date)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($result === null || $result instanceof Grille);

        return $result;
    }
}
