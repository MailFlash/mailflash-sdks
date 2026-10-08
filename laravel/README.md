# MailFlash Laravel SDKs

Two drop-in integrations for Laravel 10+ apps using the [MailFlash](https://mailflash.es) transactional email API.

| Integration | Directory | Use when |
|---|---|---|
| **Mail driver** | [`driver/`](./driver/) | You want `MAIL_MAILER=mailflash` and standard `Mail::to()->send()` |
| **API client** | [`client/`](./client/) | You call `$mailflash->send([...])` directly with full payload control |

Most Laravel apps should start with the **mail driver** (it maps Mailable tags and custom headers too). Use the **API client** when you need idempotency keys, per-message tracking overrides, or the read endpoints (stats, email events, contacts, domains).

---

## Quick start — mail driver

```env
MAIL_MAILER=mailflash
MAIL_FROM_ADDRESS=hello@yourdomain.com
MAILFLASH_API_KEY=your_project_key_here
```

Copy [`driver/MailFlashTransport.php`](./driver/MailFlashTransport.php) and [`driver/MailFlashServiceProvider.php`](./driver/MailFlashServiceProvider.php) to `app/Mail/MailFlash/`. See [`driver/README.md`](./driver/README.md).

## Quick start — API client

Copy [`client/MailFlashClient.php`](./client/MailFlashClient.php) to `app/Services/MailFlash/MailFlashClient.php` (namespace `App\Services\MailFlash`). See [`client/README.md`](./client/README.md).

---

## Resources

- [MailFlash dashboard](https://mailflash.es)
- [All MailFlash SDKs](../README.md)
