<?php

namespace App\Services\System;

use App\Models\Device;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sistem sağlığı: her kalem status = healthy | warning | critical.
 */
class SystemHealthService
{
    public function check(): array
    {
        return [
            'api' => $this->api(),
            'database' => $this->database(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'storage' => $this->storage(),
            'integrations' => $this->integrations(),
            'devices' => $this->devices(),
            'versions' => $this->versions(),
        ];
    }

    private function api(): array
    {
        $start = microtime(true);
        DB::select('select 1');
        $ms = round((microtime(true) - $start) * 1000, 1);

        return ['status' => $ms < 300 ? 'healthy' : ($ms < 1000 ? 'warning' : 'critical'), 'response_ms' => $ms];
    }

    private function database(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1');
            $ms = round((microtime(true) - $start) * 1000, 1);
            $db = config('database.connections.mysql.database');
            $size = DB::selectOne('select ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) as mb from information_schema.tables where table_schema = ?', [$db]);
            $tables = DB::selectOne('select COUNT(*) as c from information_schema.tables where table_schema = ?', [$db]);

            return [
                'status' => $ms < 300 ? 'healthy' : 'warning', 'connected' => true, 'response_ms' => $ms,
                'size_mb' => (float) ($size->mb ?? 0), 'table_count' => (int) ($tables->c ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'critical', 'connected' => false, 'error' => 'Veritabanına bağlanılamadı.'];
        }
    }

    private function queue(): array
    {
        $pending = (int) DB::table('jobs')->count();
        $failed = (int) DB::table('failed_jobs')->count();
        $oldestPendingAge = DB::table('jobs')->min('available_at');
        $stuck = $oldestPendingAge && $oldestPendingAge < now()->subMinutes(10)->timestamp;

        return [
            'status' => $failed > 20 || $stuck ? 'critical' : ($failed > 0 || $pending > 50 ? 'warning' : 'healthy'),
            'pending' => $pending, 'failed' => $failed,
        ];
    }

    private function scheduler(): array
    {
        $raw = Cache::get('system:heartbeat');
        $lastRun = $raw ? CarbonImmutable::parse($raw) : null;
        $age = $lastRun ? abs(now()->diffInMinutes($lastRun)) : null;

        return [
            'status' => $age === null ? 'critical' : ($age <= 3 ? 'healthy' : ($age <= 15 ? 'warning' : 'critical')),
            'last_run_at' => $lastRun?->toIso8601String(), 'minutes_ago' => $age,
        ];
    }

    private function storage(): array
    {
        $writable = is_writable(storage_path('app'));
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $freeGb = $free ? round($free / 1024 / 1024 / 1024, 1) : null;
        $freePercent = $free && $total ? round($free / $total * 100) : null;

        return [
            'status' => ! $writable ? 'critical' : ($freePercent !== null && $freePercent < 10 ? 'critical' : ($freePercent !== null && $freePercent < 20 ? 'warning' : 'healthy')),
            'writable' => $writable, 'free_gb' => $freeGb, 'free_percent' => $freePercent,
        ];
    }

    private function integrations(): array
    {
        $rows = Integration::query()->get(['kind', 'provider', 'status', 'is_enabled', 'last_checked_at', 'last_error']);
        $kinds = ['whatsapp', 'sms', 'email'];
        $result = [];
        foreach ($kinds as $kind) {
            $row = $rows->firstWhere('kind', $kind);
            $result[$kind] = $row
                ? ['status' => $row->status === 'connected' ? 'healthy' : ($row->status === 'error' ? 'critical' : 'warning'), 'provider' => $row->provider, 'is_enabled' => $row->is_enabled, 'last_checked_at' => $row->last_checked_at?->toIso8601String(), 'last_error' => $row->last_error]
                : ['status' => 'warning', 'provider' => null, 'is_enabled' => false, 'label' => 'Yapılandırılmadı'];
        }

        return $result;
    }

    private function devices(): array
    {
        $devices = Device::query()->where('is_active', true)->get(['id', 'name', 'last_seen_at']);
        $offline = $devices->filter(fn ($d) => ! $d->isOnline())->count();

        return [
            'status' => $devices->isEmpty() ? 'warning' : ($offline > 0 ? 'warning' : 'healthy'),
            'total' => $devices->count(), 'offline' => $offline,
            'devices' => $devices->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'last_seen_at' => $d->last_seen_at?->toIso8601String(), 'online' => $d->isOnline()]),
        ];
    }

    private function versions(): array
    {
        return [
            'status' => 'healthy', 'php' => PHP_VERSION, 'laravel' => app()->version(),
        ];
    }
}
