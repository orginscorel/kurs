<?php

namespace App\Console\Commands;

use App\Models\Guardian;
use App\Services\Guardians\GuardianAccountService;
use App\Support\BranchContext;
use Illuminate\Console\Command;

/**
 * Veli portal hesaplarını açar (kullanıcı adı = cep telefonu 05xxxxxxxxx) ve aktifliği bağlı
 * öğrencilerin durumuyla eşitler. İdempotent; var olan hesapların şifresine dokunmaz.
 * Telefonu eksik/geçersiz ya da başka hesapla çakışan veliler atlanır ve listelenir.
 *   php artisan kurs:guardian-accounts [--dry-run]
 */
class CreateGuardianAccounts extends Command
{
    protected $signature = 'kurs:guardian-accounts {--dry-run : Yalnız yapılacakları göster, değişiklik yapma}';

    protected $description = 'Veli portal hesaplarını açar (kullanıcı adı = cep telefonu) ve aktiflik durumunu eşitler';

    public function handle(GuardianAccountService $accounts, BranchContext $branches): int
    {
        $dry = (bool) $this->option('dry-run');
        $stats = ['create' => 0, 'created' => 0, 'phone_missing' => 0, 'phone_conflict' => 0, 'activate' => 0, 'deactivate' => 0, 'synced' => 0];
        $problems = [];
        $plannedUsernames = [];

        Guardian::query()->withoutGlobalScopes()->orderBy('id')->chunkById(100, function ($guardians) use ($accounts, $branches, $dry, &$stats, &$problems, &$plannedUsernames) {
            foreach ($guardians as $guardian) {
                $branches->run($guardian->branch_id, function () use ($accounts, $guardian, $dry, &$stats, &$problems, &$plannedUsernames) {
                    $user = $accounts->user($guardian);

                    if (! $user && ! $guardian->trashed()) {
                        $username = GuardianAccountService::desiredUsername($guardian);
                        $problem = ! $username ? GuardianAccountService::PROBLEM_PHONE_MISSING
                            : (($accounts->conflictFor($username, $guardian) || ($dry && isset($plannedUsernames[$username]))) ? GuardianAccountService::PROBLEM_PHONE_CONFLICT : null);

                        if ($problem) {
                            $stats[$problem]++;
                            $problems[] = [$guardian->id, $guardian->full_name, $problem === GuardianAccountService::PROBLEM_PHONE_MISSING ? 'Geçerli cep telefonu yok' : "Telefon başka hesapta ({$username})"];

                            return;
                        }

                        $stats['create']++;
                        $plannedUsernames[$username] = true;
                        if (! $dry) {
                            $result = $accounts->ensure($guardian, audit: false);
                            $stats['created'] += $result['created'] ? 1 : 0;
                            $user = $result['user'];
                        }
                    }

                    if ($user) {
                        $should = $accounts->shouldBeActive($guardian);
                        if ((bool) $user->is_active !== $should) {
                            $stats[$should ? 'activate' : 'deactivate']++;
                            if (! $dry && $accounts->syncActive($guardian)) {
                                $stats['synced']++;
                            }
                        }
                    }
                });
            }
        });

        $this->info(($dry ? '[deneme] ' : '')."Açılacak hesap: {$stats['create']}".($dry ? '' : " (açılan: {$stats['created']})"));
        $this->info("Açılacak/kapanacak hesap: {$stats['activate']} / {$stats['deactivate']}".($dry ? '' : " (eşitlenen: {$stats['synced']})"));
        $this->info("Atlanan — telefon yok: {$stats['phone_missing']} · telefon çakışıyor: {$stats['phone_conflict']}");
        if ($problems) {
            $this->table(['Veli id', 'Veli', 'Sorun'], array_slice($problems, 0, 200));
        }

        return self::SUCCESS;
    }
}
