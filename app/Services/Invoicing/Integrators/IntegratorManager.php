<?php

namespace App\Services\Invoicing\Integrators;

use App\Services\Accounting\FinanceSettings;

final class IntegratorManager
{
    /** @var array<string, class-string<InvoiceIntegrator>> */
    public const DRIVERS = ['none' => NullIntegrator::class, 'simulation' => SimulationIntegrator::class];

    public function current(?int $branchId = null): InvoiceIntegrator
    {
        $key = (string) FinanceSettings::get('integrator', $branchId);

        return app(self::DRIVERS[$key] ?? NullIntegrator::class);
    }

    /** @return list<array{key:string, label:string, connected:bool}> */
    public function options(): array
    {
        return collect(self::DRIVERS)->map(fn ($c) => app($c))->map(fn (InvoiceIntegrator $d) => ['key' => $d->key(), 'label' => $d->label(), 'connected' => $d->connected()])->values()->all();
    }
}
