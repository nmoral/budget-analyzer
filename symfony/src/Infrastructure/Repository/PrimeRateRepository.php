<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\PrimeRate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès « taux de primes pour l'année Y ».
 */
final class PrimeRateRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Taux de primes retenu pour le corps `$bodyCode` et l'année `$year` : le
     * dernier `observationYear <= $year` ; à année égale, la ligne non-fallback
     * l'emporte sur la ligne fallback. Null si aucune observation <= Y. Le drapeau
     * `fallback` est porté par l'entité renvoyée.
     */
    public function forYear(string $bodyCode, int $year): ?PrimeRate
    {
        $result = $this->em->createQueryBuilder()
            ->select('r')
            ->from(PrimeRate::class, 'r')
            ->join('r.body', 'b')
            ->where('b.code = :bodyCode')
            ->andWhere('r.observationYear <= :year')
            ->orderBy('r.observationYear', 'DESC')
            ->addOrderBy('r.fallback', 'ASC')
            ->setParameter('bodyCode', $bodyCode)
            ->setParameter('year', $year)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($result === null || $result instanceof PrimeRate);

        return $result;
    }
}
