<?php

declare(strict_types=1);

namespace App\Infrastructure\ParameterSet;

use App\Infrastructure\Entity\ParameterSet;

/**
 * Portée « jeu de paramètres courant » d'une requête de calcul (#19).
 *
 * Les adapters datés (taux, montant, primes) consultent la portée pour scoper leurs
 * lectures au jeu épinglé du scénario ; hors d'une portée active (`current() === null`),
 * ils retombent sur le dernier jeu importé, comportement historique inchangé.
 *
 * Service d'infrastructure partagé pour la durée de la requête : le domaine reste pur
 * (il ne connaît ni la portée ni Doctrine) ; seule l'infrastructure la manipule.
 */
final class ParameterSetScope
{
    private ?ParameterSet $current = null;

    public function current(): ?ParameterSet
    {
        return $this->current;
    }

    /**
     * Active `$set` pendant l'exécution de `$callback`, puis restaure l'état précédent.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function within(ParameterSet $set, callable $callback): mixed
    {
        $previous = $this->current;
        $this->current = $set;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }
}
