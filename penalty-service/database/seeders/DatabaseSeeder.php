<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $rates = config('penalties.damage_rates');

        $this->command->info('✅ Penalty service listo.');
        $this->command->newLine();
        $this->command->info('Tarifas configuradas:');

        $this->command->table(
            ['Concepto', 'Tarifa'],
            array_merge(
                [
                    ['Tarifa diaria por retraso', 'S/ ' . number_format(config('penalties.daily_rate_overdue'), 2)],
                    ['Multiplicador por pérdida',  config('penalties.loss_multiplier') . 'x costo adquisición'],
                    ['Reposición por defecto',     'S/ ' . number_format(config('penalties.default_replacement_cost'), 2)],
                ],
                array_map(
                    fn($key, $rate) => [
                        'Daño: ' . str_replace('_', ' → ', $key),
                        'S/ ' . number_format($rate, 2)
                    ],
                    array_keys($rates),
                    $rates
                )
            )
        );
    }
}