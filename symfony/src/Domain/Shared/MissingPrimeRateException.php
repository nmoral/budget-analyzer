<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Exception de domaine levée lorsqu'aucune observation de taux de primes du
 * corps n'est disponible au plus tard l'année de la date demandée. Le message et
 * l'API nomment explicitement le corps et la date (pas de valeur par défaut
 * silencieuse).
 */
final class MissingPrimeRateException extends \DomainException
{
    private function __construct(
        private readonly string $bodyCode,
        private readonly \DateTimeImmutable $date,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forBodyAndDate(string $bodyCode, \DateTimeImmutable $date): self
    {
        return new self($bodyCode, $date, sprintf(
            'Aucun taux de primes du corps "%s" observé au plus tard l\'année de la date %s.',
            $bodyCode,
            $date->format('Y-m-d'),
        ));
    }

    public function bodyCode(): string
    {
        return $this->bodyCode;
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }
}
