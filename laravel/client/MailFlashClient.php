<?php

declare(strict_types=1);

namespace App\Services\MailFlash;

use Illuminate\Http\Client\ConnectionException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * MailFlash API client for Laravel apps (uses Illuminate HTTP client).
 *
 * Setup:
 *   1. Copy this file to app/Services/MailFlash/MailFlashClient.php
 *      (the namespace matches Laravel's App\ PSR-4 autoload for that path)
 *
 *   2. config/services.php:
 *      'mailflash' => [
 *          'url' => env('MAILFLASH_URL', 'https://mailflash.es'),
 *          'key' => env('MAILFLASH_API_KEY'),
 *      ],
 *
 *   3. AppServiceProvider::register():
 *      $this->app->singleton(\App\Services\MailFlash\MailFlashClient::class, function () {
 *          return new \App\Services\MailFlash\MailFlashClient(
 *              config('services.mailflash.url'),
 *              config('services.mailflash.key'),
 *          );
 *      });
 *
 *   4. Usage:
 *      app(\App\Services\MailFlash\MailFlashClient::class)->send([...], 'order-1');
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
        $headers = [];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->request('POST', '/email/send', [], $this->normalizePayload($payload), $headers);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|string, response?: Response}
     */
    public function getStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->request('GET', '/stats', ['date_from' => $dateFrom, 'date_to' => $dateTo]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|string, response?: Response}
     */
    public function listDomains(bool $verifiedOnly = false): array
    {
        return $this->request('GET', '/domains', ['verified_only' => $verifiedOnly ? '1' : null]);
    }

    /**
     * @param  array{q?: string, status?: 'active'|'suppressed', page?: int}  $filters
     * @return array{status: int, body: array<string, mixed>|string, response?: Response}
     */
    public function listContacts(array $filters = []): array
    {
        return $this->request('GET', '/contacts', $filters);
    }

    /**
     * @param  array{status?: string, tag?: string, to?: string, from?: string, date_from?: string, date_to?: string, page?: int}  $filters
     * @return array{status: int, body: array<string, mixed>|string, response?: Response}
     */
    public function listEmails(array $filters = []): array
    {
        return $this->request('GET', '/emails', $filters);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|string, response?: Response}
     */
    public function getEmail(string $id, bool $includeBody = false): array
    {
        return $this->request('GET', '/emails/'.rawurlencode($id), ['include' => $includeBody ? 'body' : null]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|string, response?: Response}
     */
    public function getEmailEvents(string $id): array
    {
        return $this->request('GET', '/emails/'.rawurlencode($id).'/events');
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

        $message = is_array($result['body'] ?? null)
            ? json_encode($result['body'], JSON_THROW_ON_ERROR)
            : (string) ($result['body'] ?? 'MailFlash request failed');

        $status = (int) ($result['status'] ?? 0);

        if ($status < 100) {
            throw new ConnectionException($result['body']['message'] ?? $message);
        }

        // Throw directly rather than via Response::throw(), which ignores 2xx-but-not-202.
        $response = $result['response'] ?? null;

        throw new RequestException(
            $response instanceof Response
                ? $response
                : new Response(new Psr7Response($status, [], $message)),
        );
    }

    /**
     * @param  array{status: int, body: mixed, response?: Response}  $result
     */
    public function ok(array $result): bool
    {
        $status = $result['status'] ?? 0;

        return $status >= 200 && $status < 300;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $headers
     * @return array{status: int, body: array<string, mixed>|string, response?: Response}
     */
    private function request(string $method, string $path, array $query = [], ?array $json = null, array $headers = []): array
    {
        $url = rtrim($this->baseUrl, '/').'/api/v1'.$path;
        $query = array_filter($query, static fn (mixed $v): bool => $v !== null && $v !== '');

        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $this->apiKey, ...$headers])
                ->send($method, $url, $options);
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
