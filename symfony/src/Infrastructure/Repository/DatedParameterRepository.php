<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Infrastructure\Entity\DatedParameter;
use App\Infrastructure\Entity\ParameterSet;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès « valeur en vigueur à la date D » pour les paramètres datés.
 */
final class DatedParameterRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParameterSetRepository $parameterSets,
    ) {
    }

    /**
     * Unique paramètre de clé `$key` dont la période `[startDate, endDate]`
     * (bornes incluses, endDate NULL = période ouverte) couvre `$date`, scopé au
     * jeu `$set` (défaut : dernier jeu importé). Null si aucune période ne couvre D.
     */
    public function valueInEffectAt(string $key, \DateTimeImmutable $date, ?ParameterSet $set = null): ?DatedParameter
    {
        $set ??= $this->parameterSets->latest();
        if ($set === null) {
            return null;
        }

        $result = $this->em->createQueryBuilder()
            ->select('p')
            ->from(DatedParameter::class, 'p')
            ->where('p.key = :key')
            ->andWhere('p.parameterSet = :set')
            ->andWhere('p.startDate <= :date')
            ->andWhere('(p.endDate IS NULL OR p.endDate >= :date)')
            ->setParameter('key', $key)
            ->setParameter('set', $set)
            ->setParameter('date', $date)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($result === null || $result instanceof DatedParameter);

        return $result;
    }
}
