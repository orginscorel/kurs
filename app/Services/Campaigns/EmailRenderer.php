<?php

namespace App\Services\Campaigns;

use App\Models\OutboundMessage;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;

/**
 * Kurum logolu, sade, mobil uyumlu HTML e-posta. Gövde düz metindir: satır sonları paragraf,
 * bağlantılar tıklanabilir olur; HTML girişi kaçışlanır (şablona kod enjekte edilemez).
 */
final class EmailRenderer
{
    /** @return array{html: string, text: string, unsubscribe_url: ?string} */
    public static function render(OutboundMessage $message): array
    {
        $institution = Settings::group('institution', $message->branch_id);
        $logo = null;
        if (! empty($institution['logo_path'])) {
            try {
                $logo = Storage::disk('public')->url($institution['logo_path']);
            } catch (\Throwable) {
                $logo = null;
            }
        }

        $unsubscribeUrl = null;
        if ($message->campaign_id) {
            $unsubscribeUrl = UnsubscribeToken::url(UnsubscribeToken::make(
                (int) $message->branch_id, 'email', $message->recipient_type, $message->recipient_id ? (int) $message->recipient_id : null, (string) $message->to,
            ));
        }

        $html = view('emails.campaign', [
            'subject' => $message->subject ?: ($institution['name'] ?? 'Erbaa Bilgi Eğitim'),
            'paragraphs' => self::paragraphs((string) $message->body),
            'institution' => [
                'name' => $institution['name'] ?? 'Erbaa Bilgi Eğitim',
                'phone' => $institution['phone'] ?? null,
                'email' => $institution['email'] ?? null,
                'address' => $institution['address'] ?? null,
                'website' => $institution['website'] ?? null,
                'logo' => $logo,
            ],
            'unsubscribeUrl' => $unsubscribeUrl,
            'commercial' => (bool) $message->is_commercial,
        ])->render();

        $text = (string) $message->body;
        if ($unsubscribeUrl) {
            $text .= "\n\n—\nBu e-postaları artık almak istemiyorsanız: {$unsubscribeUrl}";
        }

        return ['html' => $html, 'text' => $text, 'unsubscribe_url' => $unsubscribeUrl];
    }

    /** @return list<string> kaçışlanmış, bağlantıları işlenmiş paragraf HTML'leri */
    public static function paragraphs(string $body): array
    {
        $blocks = preg_split("/\n\s*\n/", str_replace("\r\n", "\n", trim($body))) ?: [];

        return array_values(array_map(function (string $block) {
            $escaped = e($block);
            $linked = preg_replace_callback('~https?://[^\s<]+~u', function ($m) {
                $url = rtrim($m[0], '.,);');
                $tail = substr($m[0], strlen($url));

                return '<a href="'.$url.'" style="color:#1f3b63;text-decoration:underline">'.$url.'</a>'.$tail;
            }, $escaped);

            return nl2br($linked, false);
        }, array_filter($blocks, fn ($b) => trim($b) !== '')));
    }
}
