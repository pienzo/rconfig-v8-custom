<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * A server-configured Cloud API adapter for approved WhatsApp templates.
 *
 * No arbitrary destination/template comes from HTTP and no job invokes this
 * until enterprise onboarding, recipient consent and policy approval.
 * Meta acceptance is NOT delivery confirmation.
 */
final class WhatsAppCloudTemplateTransport
{
    /**
     * @param  list<string>  $bodyParameters
     */
    public function sendApprovedTemplate(string $event, array $bodyParameters = []): string
    {
        if (config('rconfig-whatsapp.enabled') !== true) {
            throw new RuntimeException('WhatsApp delivery is disabled.');
        }

        if (! in_array($event, ['backup.success', 'backup.failed'], true)) {
            throw new InvalidArgumentException('Unsupported WhatsApp backup event.');
        }

        $token = config('rconfig-whatsapp.access_token');
        $numberId = config('rconfig-whatsapp.phone_number_id');
        $recipient = config('rconfig-whatsapp.recipient');
        $version = config('rconfig-whatsapp.graph_version');
        $language = config('rconfig-whatsapp.template_language');
        $templateName = config($event === 'backup.success'
            ? 'rconfig-whatsapp.template_success'
            : 'rconfig-whatsapp.template_failed');

        if (! is_string($token) || preg_match('/\A[A-Za-z0-9._-]{20,4096}\z/D', $token) !== 1
            || ! is_string($numberId) || preg_match('/\A[0-9]{5,30}\z/D', $numberId) !== 1
            || ! is_string($recipient) || preg_match('/\A[1-9][0-9]{7,14}\z/D', $recipient) !== 1
            || ! is_string($version) || preg_match('/\Av[0-9]{2,3}\.[0-9]\z/D', $version) !== 1
            || ! is_string($language) || preg_match('/\A[a-z]{2}(?:_[A-Z]{2})?\z/D', $language) !== 1
            || ! is_string($templateName) || preg_match('/\A[a-z0-9_]{1,512}\z/D', $templateName) !== 1) {
            throw new RuntimeException('WhatsApp Cloud API is not configured.');
        }

        if (! array_is_list($bodyParameters) || count($bodyParameters) > 8) {
            throw new InvalidArgumentException('Unsupported WhatsApp template parameters.');
        }

        $values = [];
        foreach ($bodyParameters as $parameter) {
            if (! is_string($parameter) || $parameter === ''
                || strlen($parameter) > 512 || preg_match('//u', $parameter) !== 1) {
                throw new InvalidArgumentException('Invalid WhatsApp template parameter.');
            }

            $values[] = ['type' => 'text', 'text' => $parameter];
        }

        $template = [
            'name' => $templateName,
            'language' => ['code' => $language],
        ];
        if ($values !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => $values,
            ]];
        }

        try {
            $response = Http::withToken($token)
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)
                ->timeout(10)
                ->post('https://graph.facebook.com/' . $version . '/' . $numberId . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'template',
                    'template' => $template,
                ]);
        } catch (ConnectionException) {
            // Do not echo headers or provider details to logs or the caller.
            throw new RuntimeException('WhatsApp Cloud API connection failed.');
        }

        if ($response->status() !== 200 || ! is_string($response->json('messages.0.id'))
            || $response->json('messages.0.id') === '') {
            throw new RuntimeException('WhatsApp provider rejected the template submission.');
        }

        // The ID is private delivery correlation metadata, not an actual
        // read/delivery receipt; do not return it in HTTP responses or logs.
        return $response->json('messages.0.id');
    }
}
