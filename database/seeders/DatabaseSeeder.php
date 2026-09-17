<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Üretim kurulumu. Demo veri BURADA YOK: `php artisan db:seed --class=DemoSeeder` ayrıca çalıştırılır.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CoreSeeder::class,
        ]);
    }
}
