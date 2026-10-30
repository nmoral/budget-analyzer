<?php

declare(strict_types=1);

namespace App\Domain\Rule;

/**
 * Contrat commun aux règles enfichables du moteur : chaque règle porte son
 * identité de sélection, le code métier par lequel un profil la référence.
 *
 * C'est le seul contrat posé en #12 (ossature) : les signatures de calcul
 * (coût annuel, horizons…) sont ajoutées par famille en #13→#18.
 */
interface Rule
{
    /**
     * Code métier identifiant cette règle au sein de sa famille (ex. le code du
     * régime de retraite). Sert de clé de sélection dans le résolveur.
     */
    public function code(): string;
}
