<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\ParameterSet;
use App\Infrastructure\Entity\PrimeRate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès « taux de primes pour l'année Y ».
 */
final class PrimeRateRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParameterSetRepository $parameterSets,
    ) {
    }

    /**
     * Taux de primes retenu pour le corps `$bodyCode` et l'année `$year`, scopé au
     * jeu de paramètres `$set` (défaut : dernier jeu importé) : le dernier
     * `observationYear <= $year` de ce jeu ; à année égale, la ligne non-fallback
     * l'emporte sur la ligne fallback. Null si aucune observation <= Y dans le jeu
     * (pas de repli silencieux sur un autre jeu). Le drapeau `fallback` est porté
     * par l'entité renvoyée.
     */
    public function forYear(string $bodyCode, int $year, ?ParameterSet $set = null): ?PrimeRate
    {
        $set ??= $this->parameterSets->latest();
        if ($set === null) {
            return null;
        }

        $result = $this->em->createQueryBuilder()
            ->select('r')
            ->from(PrimeRate::class, 'r')
            ->join('r.body', 'b')
            ->where('b.code = :bodyCode')
            ->andWhere('r.parameterSet = :set')
            ->andWhere('r.observationYear <= :year')
            ->orderBy('r.observationYear', 'DESC')
            ->addOrderBy('r.fallback', 'ASC')
            ->setParameter('bodyCode', $bodyCode)
            ->setParameter('set', $set)
            ->setParameter('year', $year)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($result === null || $result instanceof PrimeRate);

        return $result;
    }
}
