/**
 * MailFlash API client for Node.js 18+ (native fetch).
 *
 * One client instance = one MailFlash project (via that project's API key).
 * For multiple projects, create multiple clients with different keys.
 *
 * Usage:
 *   Copy this file into your project as mailflash_client.ts, then:
 *
 *   import { MailFlashClient } from './mailflash_client.js';
 *
 *   const client = new MailFlashClient(
 *     'https://mailflash.es',
 *     process.env.MAILFLASH_API_KEY!,
 *   );
 *   await client.send({
 *     from: 'hello@yourdomain.com',
 *     to: ['you@example.com'],
 *     subject: 'Order confirmed',
 *     html: '<p>Thanks!</p>',
 *   });
 */

export type Recipient = string | { email: string; name?: string };

export type Attachment = {
    filename: string;
    content: string;
    content_type?: string;
};

export type SendEmailPayload = {
    from: string;
    subject: string;
    to: Recipient[];
    from_name?: string;
    cc?: Recipient[];
    bcc?: Recipient[];
    reply_to?: string;
    html?: string;
    text?: string;
    headers?: Record<string, string>;
    attachments?: Attachment[];
    tags?: string[];
    track_opens?: boolean;
    track_clicks?: boolean;
};

export type SendOptions = {
    idempotencyKey?: string;
    signal?: AbortSignal;
};

export type ApiResult = {
    status: number;
    body: Record<string, unknown> | string;
};

export type StatsParams = {
    dateFrom?: string;
    dateTo?: string;
    signal?: AbortSignal;
};

export type ListEmailsParams = {
    status?: string;
    tag?: string;
    to?: string;
    from?: string;
    dateFrom?: string;
    dateTo?: string;
    page?: number;
    signal?: AbortSignal;
};

export type ListContactsParams = {
    q?: string;
    status?: 'active' | 'suppressed';
    page?: number;
    signal?: AbortSignal;
};

export type ListDomainsParams = {
    verifiedOnly?: boolean;
    signal?: AbortSignal;
};

export class MailFlashClient {
    private readonly apiRoot: string;

    constructor(
        baseUrl: string,
        private readonly apiKey: string,
        private readonly timeoutMs = 30_000,
    ) {
        this.apiRoot = `${baseUrl.replace(/\/$/, '')}/api/v1`;
    }

    async send(payload: SendEmailPayload, options: SendOptions = {}): Promise<ApiResult> {
        const headers: Record<string, string> = { 'Content-Type': 'application/json' };
        if (options.idempotencyKey) {
            headers['Idempotency-Key'] = options.idempotencyKey;
        }

        return this.request('POST', '/email/send', {
            body: normalizePayload(payload),
            headers,
            signal: options.signal,
        });
    }

    async getStats(params: StatsParams = {}): Promise<ApiResult> {
        const query = new URLSearchParams();
        if (params.dateFrom) query.set('date_from', params.dateFrom);
        if (params.dateTo) query.set('date_to', params.dateTo);
        const qs = query.toString();

        return this.request('GET', `/stats${qs ? `?${qs}` : ''}`, { signal: params.signal });
    }

    async listDomains(params: ListDomainsParams = {}): Promise<ApiResult> {
        const query = new URLSearchParams();
        if (params.verifiedOnly) query.set('verified_only', '1');
        const qs = query.toString();

        return this.request('GET', `/domains${qs ? `?${qs}` : ''}`, { signal: params.signal });
    }

    async listContacts(params: ListContactsParams = {}): Promise<ApiResult> {
        const query = new URLSearchParams();
        if (params.q) query.set('q', params.q);
        if (params.status) query.set('status', params.status);
        if (params.page) query.set('page', String(params.page));
        const qs = query.toString();

        return this.request('GET', `/contacts${qs ? `?${qs}` : ''}`, { signal: params.signal });
    }

    async listEmails(params: ListEmailsParams = {}): Promise<ApiResult> {
        const query = new URLSearchParams();
        if (params.status) query.set('status', params.status);
        if (params.tag) query.set('tag', params.tag);
        if (params.to) query.set('to', params.to);
        if (params.from) query.set('from', params.from);
        if (params.dateFrom) query.set('date_from', params.dateFrom);
        if (params.dateTo) query.set('date_to', params.dateTo);
        if (params.page) query.set('page', String(params.page));
        const qs = query.toString();

        return this.request('GET', `/emails${qs ? `?${qs}` : ''}`, { signal: params.signal });
    }

    async getEmail(id: string, options: { includeBody?: boolean; signal?: AbortSignal } = {}): Promise<ApiResult> {
        const query = options.includeBody ? '?include=body' : '';

        return this.request('GET', `/emails/${encodeURIComponent(id)}${query}`, { signal: options.signal });
    }

    async getEmailEvents(id: string, signal?: AbortSignal): Promise<ApiResult> {
        return this.request('GET', `/emails/${encodeURIComponent(id)}/events`, { signal });
    }

    accepted(result: ApiResult): boolean {
        return result.status === 202;
    }

    ok(result: ApiResult): boolean {
        return result.status >= 200 && result.status < 300;
    }

    private async request(
        method: string,
        path: string,
        options: { body?: Record<string, unknown>; headers?: Record<string, string>; signal?: AbortSignal } = {},
    ): Promise<ApiResult> {
        // The timeout always applies; a caller's signal can abort earlier.
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(new Error(`Request timed out after ${this.timeoutMs} ms`)), this.timeoutMs);
        const onCallerAbort = () => controller.abort(options.signal?.reason);
        if (options.signal?.aborted) {
            onCallerAbort();
        } else {
            options.signal?.addEventListener('abort', onCallerAbort, { once: true });
        }

        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-API-Key': this.apiKey,
            ...options.headers,
        };

        try {
            const response = await fetch(`${this.apiRoot}${path}`, {
                method,
                headers,
                body: options.body ? JSON.stringify(options.body) : undefined,
                signal: controller.signal,
            });

            const text = await response.text();
            let parsed: Record<string, unknown> | string = text;
            try {
                parsed = JSON.parse(text) as Record<string, unknown>;
            } catch {
                /* keep raw text */
            }

            return { status: response.status, body: parsed };
        } catch (err) {
            const reason = controller.signal.aborted ? controller.signal.reason : err;
            const message = reason instanceof Error ? reason.message : String(reason ?? err);
            return { status: 0, body: { error: 'transport', message } };
        } finally {
            clearTimeout(timeout);
            options.signal?.removeEventListener('abort', onCallerAbort);
        }
    }
}

/** @deprecated Use ApiResult */
export type SendResult = ApiResult;

export function normalizeRecipients(recipients: Recipient[]): { email: string; name?: string }[] {
    return recipients.map((r) => {
        if (typeof r === 'string') return { email: r };
        return r.name ? { email: r.email, name: r.name } : { email: r.email };
    });
}

function normalizePayload(payload: SendEmailPayload): Record<string, unknown> {
    const out: Record<string, unknown> = { ...payload };
    for (const field of ['to', 'cc', 'bcc'] as const) {
        const list = payload[field];
        if (list) {
            out[field] = normalizeRecipients(list);
        }
    }
    return Object.fromEntries(
        Object.entries(out).filter(([, v]) => v !== null && v !== undefined && !(Array.isArray(v) && v.length === 0)),
    );
}
