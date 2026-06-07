<?php

declare(strict_types=1);

namespace App\Mail\MailFlash;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * MailFlash mail transport for Laravel.
 *
 * Copy to app/Mail/MailFlash/MailFlashTransport.php and register via MailFlashServiceProvider.
 *
 * @see https://mailflash.es — POST /api/v1/email/send
 */
final class MailFlashTransport extends AbstractTransport
{
    private const API_URL = 'https://mailflash.es/api/v1/email/send';

    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly string $apiKey,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->apiKey === '') {
            throw new TransportException('MailFlash API key is not configured. Set MAILFLASH_API_KEY in .env.');
        }

        $original = $message->getOriginalMessage();

        if (! $original instanceof Email) {
            throw new TransportException('MailFlash transport only supports Symfony Email messages.');
        }

        $payload = $this->buildPayload($original);
        $result = $this->sendPayload($payload);

        if (($result['status'] ?? 0) === 202) {
            return;
        }

        throw new TransportException($this->formatError($result));
    }

    public function __toString(): string
    {
        return 'mailflash://mailflash.es';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Email $email): array
    {
        $from = $email->getFrom();

        if ($from === []) {
            throw new TransportException('MailFlash requires a From address.');
        }

        $fromAddress = $from[0];
        $to = $this->addressesToRecipients($email->getTo());

        if ($to === []) {
            throw new TransportException('MailFlash requires at least one recipient.');
        }

        $payload = [
            'from' => $fromAddress->getAddress(),
            'to' => $to,
            'subject' => $email->getSubject() ?? '',
        ];

        if ($fromAddress->getName() !== '') {
            $payload['from_name'] = $fromAddress->getName();
        }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        if (is_string($html) && $html !== '') {
            $payload['html'] = $html;
        }

        if (is_string($text) && $text !== '') {
            $payload['text'] = $text;
        }

        $cc = $this->addressesToRecipients($email->getCc());

        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        $bcc = $this->addressesToRecipients($email->getBcc());

        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        $replyTo = $email->getReplyTo();

        if ($replyTo !== []) {
            $payload['reply_to'] = $replyTo[0]->getAddress();
        }

        $attachments = $this->attachmentsToPayload($email->getAttachments());

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{email: string, name?: string}>
     */
    private function addressesToRecipients(array $addresses): array
    {
        $out = [];

        foreach ($addresses as $address) {
            if (! $address instanceof Address) {
                continue;
            }

            $entry = ['email' => $address->getAddress()];

            if ($address->getName() !== '') {
                $entry['name'] = $address->getName();
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param  list<DataPart>  $attachments
     * @return list<array{filename: string, content: string, content_type?: string}>
     */
    private function attachmentsToPayload(array $attachments): array
    {
        $out = [];

        foreach ($attachments as $attachment) {
            if (! $attachment instanceof DataPart) {
                continue;
            }

            $body = $attachment->getBody();
            $filename = $attachment->getFilename()
                ?? $attachment->getName()
                ?? 'attachment';

            $item = [
                'filename' => $filename,
                'content' => base64_encode($body),
            ];

            $contentType = $attachment->getContentType();

            if ($contentType !== '') {
                $item['content_type'] = $contentType;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>|string}
     */
    private function sendPayload(array $payload): array
    {
        if (! function_exists('curl_init')) {
            return [
                'status' => 0,
                'body' => [
                    'error' => 'transport',
                    'message' => 'PHP cURL extension is required.',
                ],
            ];
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: '.$this->apiKey,
        ];

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [
                'status' => 0,
                'body' => [
                    'error' => 'encode',
                    'message' => $e->getMessage(),
                ],
            ];
        }

        $ch = curl_init(self::API_URL);

        if ($ch === false) {
            return [
                'status' => 0,
                'body' => [
                    'error' => 'transport',
                    'message' => 'Failed to initialize cURL.',
                ],
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'status' => 0,
                'body' => [
                    'error' => 'transport',
                    'message' => $error !== '' ? $error : 'Unknown cURL error.',
                ],
            ];
        }

        $decoded = json_decode($raw, true);

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : $raw,
        ];
    }

    /**
     * @param  array{status?: int, body?: mixed}  $result
     */
    private function formatError(array $result): string
    {
        $status = (int) ($result['status'] ?? 0);
        $body = $result['body'] ?? '';

        if (is_array($body)) {
            if (! empty($body['message']) && is_string($body['message'])) {
                return sprintf('MailFlash error (%d): %s', $status, $body['message']);
            }

            if (! empty($body['error']) && is_string($body['error'])) {
                return sprintf('MailFlash error (%d): %s', $status, $body['error']);
            }

            return sprintf('MailFlash error (%d): %s', $status, json_encode($body, JSON_THROW_ON_ERROR));
        }

        if ($status === 0) {
            return is_string($body) && $body !== ''
                ? $body
                : 'Could not reach the MailFlash API.';
        }

        return sprintf(
            'MailFlash error (%d): %s',
            $status,
            is_string($body) ? $body : 'Unknown error.',
        );
    }
}
