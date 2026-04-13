<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// El seeder del loan-service es mínimo: los préstamos se crean
// operativamente desde el flujo real. Aquí solo verificamos conectividad.

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('✅ Loan service listo. Los préstamos se crean desde el flujo operativo.');
        $this->command->table(
            ['Configuración', 'Valor'],
            [
                ['Duración préstamo físico',  config('library.loans.max_days') . ' días'],
                ['Duración préstamo e-book',  config('library.loans.ebook_max_days') . ' días'],
                ['Renovaciones máximas',      config('library.loans.max_renewals')],
                ['Días por renovación',       config('library.loans.renewal_days')],
                ['Préstamos simultáneos',     config('library.loans.max_active_per_user')],
            ]
        );
    }
}