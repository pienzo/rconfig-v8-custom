<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Disabled-by-default Telegram Bot API transport.
 *
 * No controller, queue worker, or Core event invokes this class yet.
 * Never log token-bearing URLs or API response bodies.
 */
final class TelegramBotTransport
{
    public function send(string $htmlText): void
    {
        if (config('rconfig-telegram.enabled') !== true) {
            throw new RuntimeException('Telegram delivery is disabled.');
        }

        $token = config('rconfig-telegram.bot_token');
        $chatId = config('rconfig-telegram.chat_id');

        if (! is_string($token) || preg_match('/\A[0-9]{5,15}:[A-Za-z0-9_-]{30,100}\z/D', $token) !== 1
            || ! is_string($chatId) || preg_match('/\A(?:-?[0-9]{1,20}|@[A-Za-z0-9_]{5,32})\z/D', $chatId) !== 1) {
            throw new RuntimeException('Telegram delivery is not configured.');
        }

        // Conservative UTF-8 byte cap protects the Bot API 4096-char limit.
        // Rendered HTML must already have escaped its variable values.
        if ($htmlText === '' || strlen($htmlText) > 4096 || preg_match('//u', $htmlText) !== 1) {
            throw new InvalidArgumentException('Telegram message is empty, invalid, or too long.');
        }

        try {
            $response = Http::asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)
                ->timeout(8)
                ->post('https://api.telegram.org/bot' . $token . '/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $htmlText,
                    'parse_mode' => 'HTML',
                    'link_preview_options' => ['is_disabled' => true],
                    'protect_content' => true,
                ]);
        } catch (ConnectionException) {
            // Never include the URL: Telegram places the bot secret in the path.
            throw new RuntimeException('Telegram transport connection failed.');
        }

        if ($response->status() !== 200 || $response->json('ok') !== true) {
            // Do not include provider error text; it may echo chat identifiers.
            throw new RuntimeException('Telegram provider rejected the message.');
        }
    }
}
