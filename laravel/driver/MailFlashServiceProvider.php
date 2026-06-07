<?php

declare(strict_types=1);

namespace App\Mail\MailFlash;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the MailFlash mail transport with Laravel.
 *
 * Copy to app/Mail/MailFlash/MailFlashServiceProvider.php and register in bootstrap/providers.php (Laravel 11)
 * or config/app.php (Laravel 10).
 */
final class MailFlashServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('mailflash', function (array $config = []) {
            return new MailFlashTransport(
                (string) config('services.mailflash.key', ''),
            );
        });
    }
}
