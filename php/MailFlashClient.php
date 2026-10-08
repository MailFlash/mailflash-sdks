<?php

declare(strict_types=1);

namespace MailFlash\Client;

/**
 * Minimal MailFlash API client for any PHP app (no framework required).
 *
 * Copy into your project and autoload the namespace, or require this file and
 * register the class via Composer classmap.
 *
 * @see https://mailflash.es — POST /api/v1/email/send
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
     * @return array{status: int, body: array<string, mixed>|string}
     */
    public function send(array $payload, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: '.$idempotencyKey;
        }

        return $this->request('POST', '/email/send', [], $this->normalizePayload($payload), $headers);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|string}
     */
    public function getStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->request('GET', '/stats', ['date_from' => $dateFrom, 'date_to' => $dateTo]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|string}
     */
    public function listDomains(bool $verifiedOnly = false): array
    {
        return $this->request('GET', '/domains', ['verified_only' => $verifiedOnly ? '1' : null]);
    }

    /**
     * @param  array{q?: string, status?: 'active'|'suppressed', page?: int}  $filters
     * @return array{status: int, body: array<string, mixed>|string}
     */
    public function listContacts(array $filters = []): array
    {
        return $this->request('GET', '/contacts', $filters);
    }

    /**
     * @param  array{status?: string, tag?: string, to?: string, from?: string, date_from?: string, date_to?: string, page?: int}  $filters
     * @return array{status: int, body: array<string, mixed>|string}
     */
    public function listEmails(array $filters = []): array
    {
        return $this->request('GET', '/emails', $filters);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|string}
     */
    public function getEmail(string $id, bool $includeBody = false): array
    {
        return $this->request('GET', '/emails/'.rawurlencode($id), ['include' => $includeBody ? 'body' : null]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|string}
     */
    public function getEmailEvents(string $id): array
    {
        return $this->request('GET', '/emails/'.rawurlencode($id).'/events');
    }

    public function accepted(array $result): bool
    {
        return ($result['status'] ?? 0) === 202;
    }

    public function ok(array $result): bool
    {
        $status = $result['status'] ?? 0;

        return $status >= 200 && $status < 300;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     * @param  list<string>  $extraHeaders
     * @return array{status: int, body: array<string, mixed>|string}
     */
    private function request(string $method, string $path, array $query = [], ?array $json = null, array $extraHeaders = []): array
    {
        $url = rtrim($this->baseUrl, '/').'/api/v1'.$path;
        $query = array_filter($query, static fn (mixed $v): bool => $v !== null && $v !== '');
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = ['Accept: application/json', 'X-API-Key: '.$this->apiKey, ...$extraHeaders];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];

        if ($json !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                return ['status' => 0, 'body' => ['error' => 'encode', 'message' => $e->getMessage()]];
            }
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ['error' => 'transport', 'message' => 'Failed to initialize cURL.']];
        }
        curl_setopt_array($ch, $options);

        // No curl_close(): handles are freed automatically since PHP 8.0, and it is deprecated in 8.5.
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($raw === false) {
            return ['status' => 0, 'body' => ['error' => 'transport', 'message' => $error !== '' ? $error : 'Unknown cURL error.']];
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : (string) $raw,
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
