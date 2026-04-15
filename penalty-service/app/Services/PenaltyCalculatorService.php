<?php

namespace App\Services;

// =============================================================================
// PenaltyCalculatorService — Calcula el monto de la multa según el tipo
// y las tarifas configuradas en config/penalties.php.
// Separado del PenaltyService para facilitar unit testing aislado.
// =============================================================================

class PenaltyCalculatorService
{
    /**
     * Calcula los montos desglosados de una multa a partir de los datos
     * recibidos desde el loan-service.
     *
     * @return array{
     *   type: string,
     *   days_overdue: int,
     *   overdue_amount: float,
     *   damage_amount: float,
     *   loss_amount: float,
     *   total_amount: float
     * }
     */
    public function calculate(array $loanData): array
    {
        $type          = $loanData['type']           ?? 'overdue';
        $daysOverdue   = (int) ($loanData['days_overdue']  ?? 0);
        $conditionOut  = $loanData['condition_out']  ?? null;
        $conditionIn   = $loanData['condition_in']   ?? null;
        $hasDamage     = (bool) ($loanData['has_damage'] ?? false);
        $acquisitionCost = (float) ($loanData['acquisition_cost']
            ?? config('penalties.default_replacement_cost'));

        $overdueAmount = 0.00;
        $damageAmount  = 0.00;
        $lossAmount    = 0.00;

        // ── Cálculo por tipo ──────────────────────────────────────────────────

        if ($type === 'loss') {
            $lossAmount = round(
                $acquisitionCost * config('penalties.loss_multiplier'),
                2
            );
        } else {
            // Componente por retraso
            if ($daysOverdue > 0) {
                $overdueAmount = round(
                    $daysOverdue * config('penalties.daily_rate_overdue'),
                    2
                );
            }

            // Componente por daño físico
            if ($hasDamage && $conditionOut && $conditionIn) {
                $damageAmount = $this->calculateDamageAmount($conditionOut, $conditionIn);
            }
        }

        // Derivar el tipo correcto si fue enviado genéricamente
        $resolvedType = $this->resolveType($type, $overdueAmount, $damageAmount, $lossAmount);
        $total        = round($overdueAmount + $damageAmount + $lossAmount, 2);

        return [
            'type'           => $resolvedType,
            'days_overdue'   => $daysOverdue,
            'overdue_amount' => $overdueAmount,
            'damage_amount'  => $damageAmount,
            'loss_amount'    => $lossAmount,
            'total_amount'   => $total,
        ];
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function calculateDamageAmount(string $conditionOut, string $conditionIn): float
    {
        $rates = config('penalties.damage_rates');

        $key = "{$conditionOut}_to_{$conditionIn}";

        return (float) ($rates[$key] ?? 0.00);
    }

    private function resolveType(
        string $requested,
        float  $overdueAmount,
        float  $damageAmount,
        float  $lossAmount
    ): string {
        if ($requested === 'loss') return 'loss';

        $hasOverdue = $overdueAmount > 0;
        $hasDamage  = $damageAmount > 0;

        return match(true) {
            $hasOverdue && $hasDamage => 'combined',
            $hasOverdue               => 'overdue',
            $hasDamage                => 'damage',
            default                   => 'overdue',
        };
    }
}