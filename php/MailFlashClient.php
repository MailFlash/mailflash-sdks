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
        $body = $this->normalizePayload($payload);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: '.$this->apiKey,
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: '.$idempotencyKey;
        }

        $url = rtrim($this->baseUrl, '/').'/api/v1/email/send';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => 0, 'body' => ['error' => 'transport', 'message' => $error]];
        }

        $decoded = json_decode($raw, true);

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : $raw,
        ];
    }

    public function accepted(array $result): bool
    {
        return ($result['status'] ?? 0) === 202;
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
