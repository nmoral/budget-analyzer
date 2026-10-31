<?php

declare(strict_types=1);

namespace App\Domain\Rule\CostView;

use App\Domain\Shared\Amount;
use App\Domain\Shared\DatedRateProvider;
use App\Domain\Shared\RetirementValuation;

/**
 * Vue équilibre technique (#18) : valorise la retraite au taux employeur qui
 * équilibrerait les charges de pension du régime, retraite = TIB × taux d'équilibre
 * du régime, lu via le port de domaine. La clé est datée et propre au régime, dérivée
 * du code de régime (« taux_equilibre_cas_civil », « taux_equilibre_cnracl »…) : une
 * clé par régime. Un régime sans paramètre d'équilibre fait lever le port
 * (MissingDatedRateException), sans valeur par défaut silencieuse.
 */
final class TechnicalBalanceViewRule implements CostViewRule
{
    private const KEY_PREFIX = 'taux_equilibre_';

    public function __construct(
        private readonly DatedRateProvider $rates,
    ) {
    }

    public function code(): string
    {
        return 'EQUILIBRE_TECHNIQUE';
    }

    public function retirementCost(Amount $tib, \DateTimeImmutable $date, string $retirementRegimeCode): RetirementValuation
    {
        $key = self::KEY_PREFIX . strtolower($retirementRegimeCode);
        $rate = $this->rates->rateAt($key, $date);

        return new RetirementValuation(
            $tib->multipliedByRate($rate->rate()),
            'TIB·' . $key,
            [$rate->input()],
        );
    }

    public function definition(): string
    {
        return 'Coût retraite valorisé au taux d\'équilibre technique du régime de l\'agent, appliqué au TIB.';
    }

    public function question(): string
    {
        return 'Quel taux employeur équilibrerait les charges de pension du régime de cet agent ?';
    }

    public function assumptions(): array
    {
        return ['Taux d\'équilibre technique par régime (clés datées « taux_equilibre_cas_civil » / « taux_equilibre_cnracl »).'];
    }

    public function sources(): array
    {
        return ['Jaune budgétaire « Pensions » et rapports du Conseil d\'orientation des retraites (COR) sur l\'équilibre des régimes de la fonction publique.'];
    }
}
