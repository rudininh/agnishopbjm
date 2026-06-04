<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketplaceFailureNotifier
{
    private const ORDER_SOURCES = ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'];

    public function notifySyncLog(array $log): void
    {
        if (! $this->shouldNotify($log)) {
            return;
        }

        $message = $this->buildMessage($log);
        $dedupKey = 'marketplace-failure-notify:'.md5(implode('|', [
            $log['source_marketplace'] ?? '',
            $log['target_marketplace'] ?? '',
            $log['sku'] ?? '',
            $log['status'] ?? '',
            $log['message'] ?? '',
        ]));
        $ttl = max(1, (int) config('marketplace_notifications.failure.dedup_minutes', 10)) * 60;
        if (! Cache::add($dedupKey, true, $ttl)) {
            return;
        }

        if ((bool) config('marketplace_notifications.telegram.enabled')) {
            $this->sendTelegram($message);
        }

        if ((bool) config('marketplace_notifications.whatsapp.enabled')) {
            $this->sendWhatsapp($message, $log);
        }
    }

    private function shouldNotify(array $log): bool
    {
        if (! (bool) config('marketplace_notifications.failure.enabled')) {
            return false;
        }

        $source = (string) ($log['source_marketplace'] ?? '');
        $status = (string) ($log['status'] ?? '');
        $statuses = config('marketplace_notifications.failure.statuses', ['error', 'skipped']);

        return in_array($source, self::ORDER_SOURCES, true)
            && in_array($status, $statuses, true);
    }

    private function buildMessage(array $log): string
    {
        $source = $this->label((string) ($log['source_marketplace'] ?? '-'));
        $target = $this->label((string) ($log['target_marketplace'] ?? '-'));
        $status = strtoupper((string) ($log['status'] ?? '-'));
        $sku = trim((string) ($log['sku'] ?? '-')) ?: '-';
        $rawMessage = trim((string) ($log['message'] ?? '-')) ?: '-';
        $orderRef = $this->extractOrderReference($log);
        $time = now()->format('d M Y H:i').' WITA';

        return implode("\n", [
            'Auto Sync gagal / dilewati',
            'Waktu: '.$time,
            'Order: '.$orderRef,
            'Marketplace: '.$source.' -> '.$target,
            'SKU: '.$sku,
            'Status: '.$status,
            'Alasan: '.$rawMessage,
        ]);
    }

    private function extractOrderReference(array $log): string
    {
        $message = (string) ($log['message'] ?? '');
        if (preg_match('/(?:Order|order|Shopee order|TikTok order)\s+([A-Z0-9]+)/', $message, $matches)) {
            return $matches[1];
        }

        $sku = trim((string) ($log['sku'] ?? ''));
        return $sku !== '' ? $sku : '-';
    }

    private function sendTelegram(string $message): void
    {
        $token = trim((string) config('marketplace_notifications.telegram.bot_token'));
        $chatId = trim((string) config('marketplace_notifications.telegram.chat_id'));
        if ($token === '' || $chatId === '') {
            Log::warning('Marketplace Telegram notification skipped: bot token/chat id belum lengkap.');
            return;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'disable_web_page_preview' => true,
            ]);

            if (! $response->successful()) {
                Log::warning('Marketplace Telegram notification failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Marketplace Telegram notification exception: '.$exception->getMessage());
        }
    }

    private function sendWhatsapp(string $message, array $log): void
    {
        $url = trim((string) config('marketplace_notifications.whatsapp.webhook_url'));
        if ($url === '') {
            Log::warning('Marketplace WhatsApp notification skipped: webhook URL belum diisi.');
            return;
        }

        $request = Http::timeout(10);
        $token = trim((string) config('marketplace_notifications.whatsapp.token'));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post($url, [
                'phone' => config('marketplace_notifications.whatsapp.phone'),
                'message' => $message,
                'text' => $message,
                'source_marketplace' => $log['source_marketplace'] ?? null,
                'target_marketplace' => $log['target_marketplace'] ?? null,
                'sku' => $log['sku'] ?? null,
                'status' => $log['status'] ?? null,
            ]);

            if (! $response->successful()) {
                Log::warning('Marketplace WhatsApp notification failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Marketplace WhatsApp notification exception: '.$exception->getMessage());
        }
    }

    private function label(string $value): string
    {
        return match ($value) {
            'shopee' => 'Shopee',
            'tiktok' => 'TikTok',
            'shopee_order' => 'Shopee Order',
            'shopee_stock_refresh' => 'Shopee Stock Refresh',
            'tiktok_order' => 'TikTok Order',
            default => $value !== '' ? $value : '-',
        };
    }
}
