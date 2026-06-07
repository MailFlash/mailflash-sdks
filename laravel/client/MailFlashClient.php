<?php

declare(strict_types=1);

namespace MailFlash\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * MailFlash API client for Laravel apps (uses Illuminate HTTP client).
 *
 * Setup:
 *   1. Copy this file to app/Services/MailFlash/MailFlashClient.php
 *
 *   2. config/services.php:
 *      'mailflash' => [
 *          'url' => env('MAILFLASH_URL', 'https://mailflash.es'),
 *          'key' => env('MAILFLASH_API_KEY'),
 *      ],
 *
 *   3. AppServiceProvider::register():
 *      $this->app->singleton(\MailFlash\Client\MailFlashClient::class, function () {
 *          return new \MailFlash\Client\MailFlashClient(
 *              config('services.mailflash.url'),
 *              config('services.mailflash.key'),
 *          );
 *      });
 *
 *   4. Usage:
 *      app(\MailFlash\Client\MailFlashClient::class)->send([...], 'order-1');
 *
 * For apps without Laravel, use ../../php/MailFlashClient.php instead.
 */
final class MailFlashClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 30,
    ) {}

    /**
     * @param  array{
     *     from: string,
     *     to: list<array{email: string, name?: string}|string>,
     *     subject: string,
     *     from_name?: string,
     *     cc?: list<array{email: string, name?: string}|string>,
     *     bcc?: list<array{email: string, name?: string}|string>,
     *     reply_to?: string,
     *     html?: string,
     *     text?: string,
     *     headers?: array<string, string>,
     *     attachments?: list<array{filename: string, content: string, content_type?: string}>,
     *     tags?: list<string>,
     *     track_opens?: bool,
     *     track_clicks?: bool,
     * }  $payload
     * @return array{status: int, body: array<string, mixed>|string, response?: Response}
     */
    public function send(array $payload, ?string $idempotencyKey = null): array
    {
        $body = $this->normalizePayload($payload);
        $url = rtrim($this->baseUrl, '/').'/api/v1/email/send';

        $request = Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->withHeaders(['X-API-Key' => $this->apiKey]);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        try {
            /** @var Response $response */
            $response = $request->post($url, $body);
        } catch (ConnectionException $e) {
            return [
                'status' => 0,
                'body' => ['error' => 'transport', 'message' => $e->getMessage()],
            ];
        }

        $decoded = $response->json();

        return [
            'status' => $response->status(),
            'body' => is_array($decoded) ? $decoded : $response->body(),
            'response' => $response,
        ];
    }

    /**
     * @param  array{status: int, body: mixed, response?: Response}  $result
     */
    public function accepted(array $result): bool
    {
        return ($result['status'] ?? 0) === 202;
    }

    /**
     * @param  array{status: int, body: mixed, response?: Response}  $result
     * @return array{status: int, body: array<string, mixed>|string, response?: Response}
     */
    public function throwIfFailed(array $result): array
    {
        if ($this->accepted($result)) {
            return $result;
        }

        if (isset($result['response']) && $result['response'] instanceof Response) {
            $result['response']->throw();
        }

        $message = is_array($result['body'] ?? null)
            ? json_encode($result['body'], JSON_THROW_ON_ERROR)
            : (string) ($result['body'] ?? 'MailFlash request failed');

        throw new RequestException(
            Http::response($message, $result['status'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        foreach (['to', 'cc', 'bcc'] as $field) {
            if (! isset($payload[$field])) {
                continue;
            }
            $payload[$field] = self::normalizeRecipients($payload[$field]);
        }

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
    }

    /**
     * @param  list<array{email: string, name?: string}|string>  $recipients
     * @return list<array{email: string, name?: string}>
     */
    public static function normalizeRecipients(array $recipients): array
    {
        $out = [];
        foreach ($recipients as $recipient) {
            if (is_string($recipient)) {
                $out[] = ['email' => $recipient];

                continue;
            }
            $out[] = array_filter(
                $recipient,
                static fn (mixed $v): bool => $v !== null && $v !== '',
            );
        }

        return $out;
    }
}
