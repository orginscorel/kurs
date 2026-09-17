<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CommunicationSuppression;
use App\Services\Campaigns\ConsentService;
use App\Services\Campaigns\UnsubscribeToken;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Herkese açık abonelikten çıkma. GET yalnız onay sayfası gösterir (e-posta tarayıcıları bağlantıyı
 * önceden açtığında kişi yanlışlıkla çıkarılmasın); POST (sayfadaki düğme ya da RFC 8058 tek tık) kaydeder.
 * Kimlik: imzalı jeton (UnsubscribeToken). Yanıt adresi maskeli gösterir.
 */
class UnsubscribeController extends Controller
{
    public function show(string $token): Response
    {
        $data = UnsubscribeToken::parse($token);
        if (! $data) {
            return $this->page('invalid', null, null, $token, 404);
        }

        $done = CommunicationSuppression::query()->withoutGlobalScope('branch')
            ->where('branch_id', $data['branch_id'])->where('channel', $data['channel'])
            ->where('address', CommunicationSuppression::normalize($data['channel'], $data['address']))->exists();

        return $this->page($done ? 'done' : 'confirm', $data, $this->institution($data['branch_id']), $token);
    }

    public function store(Request $request, string $token, ConsentService $consents): Response
    {
        $data = UnsubscribeToken::parse($token);
        if (! $data) {
            return $this->page('invalid', null, null, $token, 404);
        }

        $consents->optOut($data['branch_id'], $data['channel'], $data['recipient_type'], $data['recipient_id'], $data['address'],
            'unsubscribe', $request->has('List-Unsubscribe') ? 'E-posta tek tık abonelik iptali' : 'Abonelikten çıkma bağlantısı');
        Log::info('Abonelikten çıkma', ['branch_id' => $data['branch_id'], 'channel' => $data['channel'], 'recipient_type' => $data['recipient_type'], 'recipient_id' => $data['recipient_id']]);

        return $this->page('done', $data, $this->institution($data['branch_id']), $token);
    }

    private function institution(int $branchId): string
    {
        return (string) (Settings::group('institution', $branchId)['name'] ?? 'Erbaa Bilgi Eğitim');
    }

    private function page(string $state, ?array $data, ?string $institution, string $token, int $status = 200): Response
    {
        $masked = null;
        if ($data) {
            $a = $data['address'];
            $masked = $data['channel'] === 'email'
                ? preg_replace('/^(.).*(@.*)$/u', '$1•••$2', $a)
                : str_repeat('•', max(0, strlen($a) - 4)).substr($a, -4);
        }

        return response()->view('public.unsubscribe', [
            'state' => $state,
            'institution' => $institution ?? 'Erbaa Bilgi Eğitim',
            'address' => $masked,
            'channel' => $data['channel'] ?? null,
            'action' => UnsubscribeToken::url($token),
        ], $status)->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }
}
