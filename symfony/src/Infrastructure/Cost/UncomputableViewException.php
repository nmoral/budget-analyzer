<?php

declare(strict_types=1);

namespace App\Infrastructure\Cost;

use App\Domain\Shared\MissingDatedAmountException;
use App\Domain\Shared\MissingDatedRateException;
use App\Domain\Shared\MissingPrimeRateException;

/**
 * Une vue de coût valide (code connu) ne peut pas être valorisée avec le jeu de
 * paramètres épinglé : un paramètre daté requis (taux, montant ou prime) manque pour
 * la date demandée. Enveloppe l'exception de domaine en nommant la vue fautive, pour
 * un mapping HTTP 422 maîtrisé par le contrôleur (jamais 500).
 *
 * Distincte d'`InvalidSelectionException` (vue inconnue) : ici la vue existe, c'est le
 * jeu de paramètres qui est incomplet.
 */
final class UncomputableViewException extends \RuntimeException
{
    public static function forView(
        string $view,
        MissingDatedRateException|MissingDatedAmountException|MissingPrimeRateException $cause,
    ): self {
        return new self(sprintf(
            'La vue « %s » ne peut pas être calculée avec le jeu de paramètres épinglé : %s',
            $view,
            $cause->getMessage(),
        ), 0, $cause);
    }
}
