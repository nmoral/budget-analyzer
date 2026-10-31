<?php

declare(strict_types=1);

namespace App\Domain\Rule\CostView;

use App\Domain\Shared\Rate;

/**
 * Value object de domaine PUR : hypothèses de la vue actuarielle (#18), à défauts
 * sourcés, surchargeables, avec connaissance des champs modifiés (isModified).
 *
 * Pour la formule V1 — retraite = (TIB × taux d'annuité) × facteur actuariel — deux
 * hypothèses sont effectivement consommées :
 * - `taux_annuité` : droits de pension acquis par année de service (Rate) ;
 * - `facteur_actuariel` : multiplicateur sans unité (ä) résumant actualisation,
 *   survie et différé, fourni en hypothèse (aucune table de mortalité dans le
 *   domaine), porté comme chaîne décimale pour rester décimal-safe.
 *
 * Les défauts sont sourcés indicativement (taux d'annuité 1,75 % ; facteur 12,5) ;
 * toute surcharge via `with*` marque le champ correspondant comme modifié, les
 * autres restant « défaut ». Aucune valeur par défaut silencieuse : `isModified`
 * rejette un champ inconnu.
 */
final class AssumptionsActuarielles
{
    public const TAUX_ANNUITE = 'taux_annuité';
    public const FACTEUR_ACTUARIEL = 'facteur_actuariel';

    /**
     * @param array<string, bool> $modified état « surchargé » par champ
     */
    private function __construct(
        private readonly Rate $tauxAnnuite,
        private readonly string $facteurActuariel,
        private readonly array $modified,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            Rate::fromPercentage('1.75'),
            '12.5',
            [self::TAUX_ANNUITE => false, self::FACTEUR_ACTUARIEL => false],
        );
    }

    public function withTauxAnnuite(Rate $tauxAnnuite): self
    {
        return new self(
            $tauxAnnuite,
            $this->facteurActuariel,
            [...$this->modified, self::TAUX_ANNUITE => true],
        );
    }

    public function withFacteurActuariel(string $facteurActuariel): self
    {
        return new self(
            $this->tauxAnnuite,
            $facteurActuariel,
            [...$this->modified, self::FACTEUR_ACTUARIEL => true],
        );
    }

    public function tauxAnnuite(): Rate
    {
        return $this->tauxAnnuite;
    }

    public function facteurActuariel(): string
    {
        return $this->facteurActuariel;
    }

    public function isModified(string $field): bool
    {
        if (!array_key_exists($field, $this->modified)) {
            throw new \InvalidArgumentException(sprintf('Hypothèse actuarielle inconnue : "%s".', $field));
        }

        return $this->modified[$field];
    }
}
