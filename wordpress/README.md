# MailFlash WordPress plugin

Send all WordPress email (`wp_mail()`) through the [MailFlash](https://mailflash.es) transactional API — password resets, WooCommerce orders, contact forms, and everything else.

The plugin is a **single PHP file** with a built-in settings page. No Composer, no dependencies beyond PHP cURL.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.7+ |
| PHP | 8.0+ |
| PHP extension | `curl` |
| MailFlash account | Project with verified sending domain |

---

## Installation

### Option A — Upload zip (recommended)

1. Download [`mailflash-wp.zip`](./mailflash-wp.zip) from this folder.
2. In WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose `mailflash-wp.zip` and click **Install Now**, then **Activate**.

### Option B — Manual copy

1. Copy `mailflash-wp.php` into your WordPress plugins directory:

   ```
   wp-content/plugins/mailflash-wp/mailflash-wp.php
   ```

2. In WordPress admin, go to **Plugins** and activate **MailFlash**.

---

## Configuration

1. Open **Settings → MailFlash** in the WordPress admin.
2. Enter your **API key** — found in the MailFlash dashboard under your project’s **API key** tab.
3. Set **From email** — must be an address on a domain you have verified in MailFlash (SPF/DKIM/DMARC).
4. Optionally set **From name** and enable **Track opens** / **Track clicks**.
5. Click **Save settings**.
6. Use **Send test email** at the bottom of the page to confirm delivery.

The plugin always connects to `https://mailflash.es`. There is no API URL setting — MailFlash is a hosted service.

---

## What gets sent through MailFlash

Every call to WordPress `wp_mail()` is intercepted when the plugin is configured:

| WordPress source | Examples |
|---|---|
| Core | Password resets, new user notifications, comment moderation |
| Plugins | Contact Form 7, Gravity Forms, WooCommerce order emails |
| Themes | Any theme calling `wp_mail()` |

If the API key is not configured, WordPress falls back to its default mail transport (PHP `mail()`).

---

## Field mapping

| WordPress `wp_mail()` | MailFlash API |
|---|---|
| `to` | `to` |
| `subject` | `subject` |
| `message` | `html` or `text` (based on `Content-Type` header) |
| `Cc` / `Bcc` / `Reply-To` headers | `cc` / `bcc` / `reply_to` |
| Settings: From email / name | `from` / `from_name` |
| Settings: tracking checkboxes | `track_opens` / `track_clicks` |

**Attachments** are not supported in v1.0. Emails with attachments are still sent, but without the attachment files (a notice is written to the PHP error log).

---

## Troubleshooting

### Admin notice: "MailFlash is active but not configured"

Add your API key and from email under **Settings → MailFlash**.

### Test email fails with 401

- API key is missing or incorrect.
- Copy the key again from the MailFlash dashboard (Projects → API key).

### Test email fails with 403

- The **From email** domain is not verified in MailFlash.
- Open your project in the MailFlash dashboard and complete SPF/DKIM/DMARC setup for that domain.

### Test email fails with 422

- Validation error, or the recipient is on your project’s suppression list (hard bounce, complaint, unsubscribe).
- Check the suppression list in the MailFlash dashboard.

### Email accepted (202) but never arrives

- Check spam/junk folders.
- Confirm DNS records are verified (green) in MailFlash.
- Check the email detail view in the MailFlash dashboard for delivery status.

### PHP error log

Failed sends log messages prefixed with `[MailFlash]`. On typical Linux hosting:

```bash
tail -f /var/log/php-fpm/error.log
# or
tail -f /var/log/apache2/error.log
```

### cURL not available

The plugin requires PHP’s cURL extension. Contact your host or enable `extension=curl` in `php.ini`.

---

## Uninstall

Deactivate the plugin under **Plugins**. Settings are kept in the WordPress options table so you can reactivate without re-entering credentials. To remove settings entirely, delete the `mailflash_*` options from `wp_options` or use a cleanup plugin.

---

## Resources

- [MailFlash dashboard](https://mailflash.es)
- [API reference](https://mailflash.es/docs/api)
- [Other MailFlash SDKs](../README.md)
