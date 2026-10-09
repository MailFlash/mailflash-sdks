<?php

declare(strict_types=1);

namespace App\Mail\MailFlash;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\TagHeader;
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
    private const TIMEOUT_SECONDS = 30;

    /** Headers already mapped to payload fields, or set by MailFlash itself. */
    private const RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'sender', 'return-path',
        'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://mailflash.es',
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
        return 'mailflash://'.(parse_url($this->baseUrl, PHP_URL_HOST) ?: 'mailflash.es');
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

        [$attachments, $cidRewrites] = $this->attachmentsToPayload($email->getAttachments());

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        if (is_string($html) && $html !== '') {
            $payload['html'] = $this->rewriteCids($html, $cidRewrites);
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

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        [$headers, $tags] = $this->headersToPayload($email);

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        if ($tags !== []) {
            $payload['tags'] = $tags;
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
     * Mailable tags (Envelope tags / ->tag()) become MailFlash tags; other custom
     * headers (e.g. X-*, List-Unsubscribe, Mailable metadata) are passed through.
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function headersToPayload(Email $email): array
    {
        $headers = [];
        $tags = [];

        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof TagHeader) {
                $tags[] = $header->getValue();

                continue;
            }

            if (in_array(strtolower($header->getName()), self::RESERVED_HEADERS, true)) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return [$headers, array_values(array_unique($tags))];
    }

    /**
     * Inline parts (`$message->embed()`, `embedData()`) are sent with a Content-ID.
     * Laravel references them in HTML as `cid:<name>`; like Symfony does when it
     * renders MIME, those references are rewritten to the part's real Content-ID.
     *
     * @param  list<DataPart>  $attachments
     * @return array{0: list<array{filename: string, content: string, content_type?: string, content_id?: string, disposition?: string}>, 1: array<string, string>}
     */
    private function attachmentsToPayload(array $attachments): array
    {
        $out = [];
        $cidRewrites = [];

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

            if ($attachment->getDisposition() === 'inline') {
                // getContentId() generates an RFC-valid id (with "@") when none was set.
                $contentId = $attachment->getContentId();
                $name = $attachment->getName() ?? $filename;

                if ($name !== $contentId) {
                    $cidRewrites[$name] = $contentId;
                }

                $item['content_id'] = $contentId;
                $item['disposition'] = 'inline';
            }

            $out[] = $item;
        }

        return [$out, $cidRewrites];
    }

    /**
     * @param  array<string, string>  $cidRewrites  name => Content-ID
     */
    private function rewriteCids(string $html, array $cidRewrites): string
    {
        foreach ($cidRewrites as $name => $contentId) {
            $html = (string) preg_replace_callback(
                '/cid:'.preg_quote($name, '/').'(?=["\'\s>)])/',
                static fn (): string => 'cid:'.$contentId,
                $html,
            );
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>|string}
     */
    private function sendPayload(array $payload): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $this->apiKey])
                ->post(rtrim($this->baseUrl, '/').'/api/v1/email/send', $payload);
        } catch (ConnectionException $e) {
            return [
                'status' => 0,
                'body' => [
                    'error' => 'transport',
                    'message' => $e->getMessage(),
                ],
            ];
        } catch (\InvalidArgumentException $e) {
            // Guzzle throws this when the payload cannot be JSON-encoded (e.g. invalid UTF-8).
            return [
                'status' => 0,
                'body' => [
                    'error' => 'encode',
                    'message' => $e->getMessage(),
                ],
            ];
        }

        $decoded = $response->json();

        return [
            'status' => $response->status(),
            'body' => is_array($decoded) ? $decoded : $response->body(),
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
