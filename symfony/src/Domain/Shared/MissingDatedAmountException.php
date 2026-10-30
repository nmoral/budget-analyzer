<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Exception de domaine levée lorsqu'aucun paramètre daté ne couvre la date
 * demandée pour une clé de montant. Le message et l'API nomment explicitement la
 * clé et la date (pas de valeur par défaut silencieuse).
 */
final class MissingDatedAmountException extends \DomainException
{
    private function __construct(
        private readonly string $key,
        private readonly \DateTimeImmutable $date,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forKeyAndDate(string $key, \DateTimeImmutable $date): self
    {
        return new self($key, $date, sprintf(
            'Aucun montant daté "%s" ne couvre la date %s.',
            $key,
            $date->format('Y-m-d'),
        ));
    }

    public function key(): string
    {
        return $this->key;
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }
}
