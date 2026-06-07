<?php
/**
 * Plugin Name: MailFlash
 * Plugin URI:  https://mailflash.es
 * Description: Send all WordPress email through the MailFlash transactional API.
 * Version:     1.0.0
 * Requires at least: 5.7
 * Requires PHP: 8.0
 * Author:      MailFlash
 * Author URI:  https://mailflash.es
 * License:     MIT
 * Text Domain: mailflash
 */

if (! defined('ABSPATH')) {
    exit;
}

define('MAILFLASH_API_URL', 'https://mailflash.es');
define('MAILFLASH_API_TIMEOUT', 30);
define('MAILFLASH_VERSION', '1.0.0');

define('MAILFLASH_OPT_API_KEY', 'mailflash_api_key');
define('MAILFLASH_OPT_FROM_EMAIL', 'mailflash_from_email');
define('MAILFLASH_OPT_FROM_NAME', 'mailflash_from_name');
define('MAILFLASH_OPT_TRACK_OPENS', 'mailflash_track_opens');
define('MAILFLASH_OPT_TRACK_CLICKS', 'mailflash_track_clicks');

register_activation_hook(__FILE__, 'mailflash_activate');
register_deactivation_hook(__FILE__, 'mailflash_deactivate');

add_action('admin_menu', 'mailflash_register_settings_page');
add_action('admin_init', 'mailflash_register_settings');
add_action('admin_init', 'mailflash_handle_test_send');
add_action('admin_notices', 'mailflash_admin_notices');
add_filter('pre_wp_mail', 'mailflash_pre_wp_mail', 10, 2);

/**
 * Set default options on activation.
 */
function mailflash_activate(): void
{
    add_option(MAILFLASH_OPT_API_KEY, '');
    add_option(MAILFLASH_OPT_FROM_EMAIL, get_option('admin_email', ''));
    add_option(MAILFLASH_OPT_FROM_NAME, get_bloginfo('name', 'display'));
    add_option(MAILFLASH_OPT_TRACK_OPENS, '0');
    add_option(MAILFLASH_OPT_TRACK_CLICKS, '0');
}

/**
 * Deactivation hook (options are kept so settings survive re-activation).
 */
function mailflash_deactivate(): void
{
    // Intentionally empty — settings persist across deactivate/reactivate.
}

/**
 * Register Settings > MailFlash.
 */
function mailflash_register_settings_page(): void
{
    add_options_page(
        __('MailFlash', 'mailflash'),
        __('MailFlash', 'mailflash'),
        'manage_options',
        'mailflash',
        'mailflash_render_settings_page'
    );
}

/**
 * Register plugin settings with the WordPress Settings API.
 */
function mailflash_register_settings(): void
{
    register_setting(
        'mailflash_settings',
        MAILFLASH_OPT_API_KEY,
        [
            'type' => 'string',
            'sanitize_callback' => 'mailflash_sanitize_api_key',
            'default' => '',
        ]
    );

    register_setting(
        'mailflash_settings',
        MAILFLASH_OPT_FROM_EMAIL,
        [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => '',
        ]
    );

    register_setting(
        'mailflash_settings',
        MAILFLASH_OPT_FROM_NAME,
        [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]
    );

    register_setting(
        'mailflash_settings',
        MAILFLASH_OPT_TRACK_OPENS,
        [
            'type' => 'string',
            'sanitize_callback' => 'mailflash_sanitize_checkbox',
            'default' => '0',
        ]
    );

    register_setting(
        'mailflash_settings',
        MAILFLASH_OPT_TRACK_CLICKS,
        [
            'type' => 'string',
            'sanitize_callback' => 'mailflash_sanitize_checkbox',
            'default' => '0',
        ]
    );

    add_settings_section(
        'mailflash_main',
        __('API credentials', 'mailflash'),
        'mailflash_render_section_intro',
        'mailflash'
    );

    add_settings_field(
        MAILFLASH_OPT_API_KEY,
        __('API key', 'mailflash'),
        'mailflash_render_api_key_field',
        'mailflash',
        'mailflash_main'
    );

    add_settings_field(
        MAILFLASH_OPT_FROM_EMAIL,
        __('From email', 'mailflash'),
        'mailflash_render_from_email_field',
        'mailflash',
        'mailflash_main'
    );

    add_settings_field(
        MAILFLASH_OPT_FROM_NAME,
        __('From name', 'mailflash'),
        'mailflash_render_from_name_field',
        'mailflash',
        'mailflash_main'
    );

    add_settings_field(
        MAILFLASH_OPT_TRACK_OPENS,
        __('Track opens', 'mailflash'),
        'mailflash_render_track_opens_field',
        'mailflash',
        'mailflash_main'
    );

    add_settings_field(
        MAILFLASH_OPT_TRACK_CLICKS,
        __('Track clicks', 'mailflash'),
        'mailflash_render_track_clicks_field',
        'mailflash',
        'mailflash_main'
    );
}

function mailflash_sanitize_api_key(string $value): string
{
    return sanitize_text_field(trim($value));
}

function mailflash_sanitize_checkbox(mixed $value): string
{
    return $value === '1' || $value === 1 || $value === true ? '1' : '0';
}

function mailflash_render_section_intro(): void
{
    echo '<p>';
    echo esc_html__(
        'Connect WordPress to MailFlash. All wp_mail() calls are sent through the MailFlash API at mailflash.es.',
        'mailflash'
    );
    echo '</p>';
}

function mailflash_render_api_key_field(): void
{
    $value = get_option(MAILFLASH_OPT_API_KEY, '');
    printf(
        '<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="off" />',
        esc_attr(MAILFLASH_OPT_API_KEY),
        esc_attr($value)
    );
    echo '<p class="description">';
    printf(
        wp_kses(
            /* translators: %s: MailFlash dashboard URL */
            __('Your project API key from the <a href="%s" target="_blank" rel="noopener noreferrer">MailFlash dashboard</a> (Projects → API key).', 'mailflash'),
            [
                'a' => [
                    'href' => [],
                    'target' => [],
                    'rel' => [],
                ],
            ]
        ),
        esc_url(MAILFLASH_API_URL)
    );
    echo '</p>';
}

function mailflash_render_from_email_field(): void
{
    $value = get_option(MAILFLASH_OPT_FROM_EMAIL, '');
    printf(
        '<input type="email" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
        esc_attr(MAILFLASH_OPT_FROM_EMAIL),
        esc_attr($value)
    );
    echo '<p class="description">';
    esc_html_e('Must be an address on a domain verified in your MailFlash project.', 'mailflash');
    echo '</p>';
}

function mailflash_render_from_name_field(): void
{
    $value = get_option(MAILFLASH_OPT_FROM_NAME, '');
    printf(
        '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
        esc_attr(MAILFLASH_OPT_FROM_NAME),
        esc_attr($value)
    );
}

function mailflash_render_track_opens_field(): void
{
    $checked = get_option(MAILFLASH_OPT_TRACK_OPENS, '0') === '1';
    printf('<input type="hidden" name="%1$s" value="0" />', esc_attr(MAILFLASH_OPT_TRACK_OPENS));
    printf(
        '<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
        esc_attr(MAILFLASH_OPT_TRACK_OPENS),
        checked($checked, true, false),
        esc_html__('Enable open tracking for WordPress emails.', 'mailflash')
    );
}

function mailflash_render_track_clicks_field(): void
{
    $checked = get_option(MAILFLASH_OPT_TRACK_CLICKS, '0') === '1';
    printf('<input type="hidden" name="%1$s" value="0" />', esc_attr(MAILFLASH_OPT_TRACK_CLICKS));
    printf(
        '<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
        esc_attr(MAILFLASH_OPT_TRACK_CLICKS),
        checked($checked, true, false),
        esc_html__('Enable click tracking for WordPress emails.', 'mailflash')
    );
}

/**
 * Render the settings page and test-send form.
 */
function mailflash_render_settings_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $test_result = get_transient('mailflash_test_result');
    if (is_array($test_result)) {
        delete_transient('mailflash_test_result');
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <?php if (is_array($test_result)) : ?>
            <div class="notice notice-<?php echo $test_result['success'] ? 'success' : 'error'; ?> is-dismissible">
                <p><?php echo esc_html($test_result['message']); ?></p>
            </div>
        <?php endif; ?>

        <form action="options.php" method="post">
            <?php
            settings_fields('mailflash_settings');
            do_settings_sections('mailflash');
            submit_button(__('Save settings', 'mailflash'));
            ?>
        </form>

        <hr />

        <h2><?php esc_html_e('Send test email', 'mailflash'); ?></h2>
        <p><?php esc_html_e('Send a test message through MailFlash to confirm your configuration.', 'mailflash'); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('mailflash_test_send', 'mailflash_test_nonce'); ?>
            <input type="hidden" name="mailflash_action" value="test_send" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="mailflash_test_to"><?php esc_html_e('Recipient', 'mailflash'); ?></label>
                    </th>
                    <td>
                        <input
                            type="email"
                            id="mailflash_test_to"
                            name="mailflash_test_to"
                            value="<?php echo esc_attr(get_option('admin_email', '')); ?>"
                            class="regular-text"
                            required
                        />
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Send test email', 'mailflash'), 'secondary'); ?>
        </form>
    </div>
    <?php
}

/**
 * Handle the test-send form submission.
 */
function mailflash_handle_test_send(): void
{
    if (! isset($_POST['mailflash_action']) || $_POST['mailflash_action'] !== 'test_send') {
        return;
    }

    if (! current_user_can('manage_options')) {
        return;
    }

    if (
        ! isset($_POST['mailflash_test_nonce'])
        || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mailflash_test_nonce'])), 'mailflash_test_send')
    ) {
        return;
    }

    $to = isset($_POST['mailflash_test_to'])
        ? sanitize_email(wp_unslash($_POST['mailflash_test_to']))
        : '';

    if ($to === '') {
        set_transient('mailflash_test_result', [
            'success' => false,
            'message' => __('Please enter a valid recipient email address.', 'mailflash'),
        ], 30);

        return;
    }

    $payload = mailflash_build_payload([
        'to' => $to,
        'subject' => sprintf(
            /* translators: %s: site name */
            __('MailFlash test email from %s', 'mailflash'),
            get_bloginfo('name')
        ),
        'message' => __(
            'This is a test email sent from the MailFlash WordPress plugin. If you received this, your configuration is working.',
            'mailflash'
        ),
        'headers' => ['Content-Type: text/plain; charset=UTF-8'],
        'attachments' => [],
    ]);

    if ($payload === null) {
        set_transient('mailflash_test_result', [
            'success' => false,
            'message' => __('Configure your API key and from email before sending a test.', 'mailflash'),
        ], 30);

        return;
    }

    $result = mailflash_api_send($payload);

    if (mailflash_api_accepted($result)) {
        set_transient('mailflash_test_result', [
            'success' => true,
            'message' => __('Test email accepted by MailFlash and queued for delivery.', 'mailflash'),
        ], 30);

        return;
    }

    set_transient('mailflash_test_result', [
        'success' => false,
        'message' => mailflash_format_error($result),
    ], 30);
}

/**
 * Show a notice when the plugin is active but not configured.
 */
function mailflash_admin_notices(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    if (mailflash_is_configured()) {
        return;
    }

    $settings_url = admin_url('options-general.php?page=mailflash');
    ?>
    <div class="notice notice-warning">
        <p>
            <?php
            printf(
                wp_kses(
                    /* translators: %s: settings page URL */
                    __('MailFlash is active but not configured. Add your API key in <a href="%s">Settings → MailFlash</a>.', 'mailflash'),
                    ['a' => ['href' => []]]
                ),
                esc_url($settings_url)
            );
            ?>
        </p>
    </div>
    <?php
}

/**
 * Intercept wp_mail() and send through MailFlash.
 *
 * @param null|bool $short_circuit Short-circuit return value.
 * @param array<string, mixed> $atts wp_mail() arguments.
 * @return null|bool
 */
function mailflash_pre_wp_mail($short_circuit, array $atts)
{
    if (! mailflash_is_configured()) {
        return null;
    }

    if (! empty($atts['attachments'])) {
        error_log('[MailFlash] Attachments are not supported in v1.0 — email sent without attachments.');
    }

    $payload = mailflash_build_payload($atts);

    if ($payload === null) {
        error_log('[MailFlash] Could not build payload — missing from address or recipients.');

        return false;
    }

    $result = mailflash_api_send($payload);

    if (mailflash_api_accepted($result)) {
        return true;
    }

    error_log('[MailFlash] Send failed: ' . mailflash_format_error($result));

    return false;
}

/**
 * Whether required settings are present.
 */
function mailflash_is_configured(): bool
{
    $api_key = get_option(MAILFLASH_OPT_API_KEY, '');
    $from_email = get_option(MAILFLASH_OPT_FROM_EMAIL, '');

    return $api_key !== '' && is_email($from_email);
}

/**
 * Build a MailFlash API payload from wp_mail() arguments.
 *
 * @param array<string, mixed> $atts
 * @return array<string, mixed>|null
 */
function mailflash_build_payload(array $atts): ?array
{
    $from_email = get_option(MAILFLASH_OPT_FROM_EMAIL, '');
    $from_name = get_option(MAILFLASH_OPT_FROM_NAME, '');

    if (! is_email($from_email)) {
        return null;
    }

    $parsed_headers = mailflash_parse_headers($atts['headers'] ?? []);
    $to = mailflash_normalize_recipients($atts['to'] ?? '');

    if ($to === []) {
        return null;
    }

    $content_type = $parsed_headers['content_type'] ?? 'text/plain';
    $is_html = stripos($content_type, 'text/html') !== false;
    $message = (string) ($atts['message'] ?? '');

    $payload = [
        'from' => $from_email,
        'to' => $to,
        'subject' => (string) ($atts['subject'] ?? ''),
    ];

    if ($from_name !== '') {
        $payload['from_name'] = $from_name;
    }

    if ($is_html) {
        $payload['html'] = $message;
    } else {
        $payload['text'] = $message;
    }

    if (! empty($parsed_headers['cc'])) {
        $payload['cc'] = mailflash_normalize_recipients($parsed_headers['cc']);
    }

    if (! empty($parsed_headers['bcc'])) {
        $payload['bcc'] = mailflash_normalize_recipients($parsed_headers['bcc']);
    }

    if (! empty($parsed_headers['reply_to'])) {
        $payload['reply_to'] = $parsed_headers['reply_to'];
    }

    if (get_option(MAILFLASH_OPT_TRACK_OPENS, '0') === '1') {
        $payload['track_opens'] = true;
    }

    if (get_option(MAILFLASH_OPT_TRACK_CLICKS, '0') === '1') {
        $payload['track_clicks'] = true;
    }

    return mailflash_filter_payload($payload);
}

/**
 * Parse wp_mail headers into structured values.
 *
 * @param string|array<int, string> $headers
 * @return array{content_type?: string, cc?: array<int, string>, bcc?: array<int, string>, reply_to?: string}
 */
function mailflash_parse_headers($headers): array
{
    $lines = [];

    if (is_string($headers)) {
        $lines = preg_split('/\r\n|\r|\n/', $headers) ?: [];
    } elseif (is_array($headers)) {
        $lines = $headers;
    }

    $parsed = [
        'cc' => [],
        'bcc' => [],
    ];

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ($line === '' || ! str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value);

        switch ($name) {
            case 'content-type':
                $parsed['content_type'] = $value;
                break;

            case 'cc':
                $parsed['cc'] = array_merge($parsed['cc'], mailflash_split_address_list($value));
                break;

            case 'bcc':
                $parsed['bcc'] = array_merge($parsed['bcc'], mailflash_split_address_list($value));
                break;

            case 'reply-to':
                $parsed['reply_to'] = mailflash_extract_email($value);
                break;
        }
    }

    return $parsed;
}

/**
 * Split a comma-separated address list.
 *
 * @return array<int, string>
 */
function mailflash_split_address_list(string $value): array
{
    $parts = array_map('trim', explode(',', $value));
    $emails = [];

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $email = mailflash_extract_email($part);

        if ($email !== '') {
            $emails[] = $email;
        }
    }

    return $emails;
}

/**
 * Extract an email address from "Name <email@example.com>" or plain email.
 */
function mailflash_extract_email(string $value): string
{
    if (preg_match('/<([^>]+)>/', $value, $matches)) {
        $email = sanitize_email(trim($matches[1]));

        return is_email($email) ? $email : '';
    }

    $email = sanitize_email(trim($value));

    return is_email($email) ? $email : '';
}

/**
 * Normalize wp_mail recipients to MailFlash format.
 *
 * @param string|array<int, mixed> $recipients
 * @return list<array{email: string, name?: string}>
 */
function mailflash_normalize_recipients($recipients): array
{
    if (is_string($recipients)) {
        $recipients = mailflash_split_address_list($recipients);
    }

    if (! is_array($recipients)) {
        return [];
    }

    $out = [];

    foreach ($recipients as $recipient) {
        if (is_string($recipient)) {
            $email = mailflash_extract_email($recipient);

            if ($email !== '') {
                $out[] = ['email' => $email];
            }

            continue;
        }

        if (is_array($recipient)) {
            $email = isset($recipient['email'])
                ? sanitize_email((string) $recipient['email'])
                : '';

            if (! is_email($email)) {
                continue;
            }

            $entry = ['email' => $email];

            if (! empty($recipient['name'])) {
                $entry['name'] = sanitize_text_field((string) $recipient['name']);
            }

            $out[] = $entry;
        }
    }

    return $out;
}

/**
 * Remove empty values from the payload.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function mailflash_filter_payload(array $payload): array
{
    return array_filter(
        $payload,
        static function (mixed $value): bool {
            return $value !== null && $value !== [];
        }
    );
}

/**
 * Send a payload to the MailFlash API.
 *
 * @param array<string, mixed> $payload
 * @return array{status: int, body: array<string, mixed>|string}
 */
function mailflash_api_send(array $payload): array
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

    $api_key = get_option(MAILFLASH_OPT_API_KEY, '');
    $url = rtrim(MAILFLASH_API_URL, '/') . '/api/v1/email/send';

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-Key: ' . $api_key,
    ];

    $body = wp_json_encode($payload);

    if ($body === false) {
        return [
            'status' => 0,
            'body' => [
                'error' => 'encode',
                'message' => 'Failed to encode JSON payload.',
            ],
        ];
    }

    $ch = curl_init($url);

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
        CURLOPT_TIMEOUT => MAILFLASH_API_TIMEOUT,
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
 * @param array{status?: int, body?: mixed} $result
 */
function mailflash_api_accepted(array $result): bool
{
    return ($result['status'] ?? 0) === 202;
}

/**
 * @param array{status?: int, body?: mixed} $result
 */
function mailflash_format_error(array $result): string
{
    $status = (int) ($result['status'] ?? 0);
    $body = $result['body'] ?? '';

    if (is_array($body)) {
        if (! empty($body['message']) && is_string($body['message'])) {
            return sprintf(
                /* translators: 1: HTTP status code, 2: error message */
                __('MailFlash error (%1$d): %2$s', 'mailflash'),
                $status,
                $body['message']
            );
        }

        if (! empty($body['error']) && is_string($body['error'])) {
            return sprintf(
                /* translators: 1: HTTP status code, 2: error code */
                __('MailFlash error (%1$d): %2$s', 'mailflash'),
                $status,
                $body['error']
            );
        }

        return sprintf(
            /* translators: 1: HTTP status code, 2: JSON response body */
            __('MailFlash error (%1$d): %2$s', 'mailflash'),
            $status,
            wp_json_encode($body)
        );
    }

    if ($status === 0) {
        return is_string($body) && $body !== ''
            ? $body
            : __('Could not reach the MailFlash API.', 'mailflash');
    }

    return sprintf(
        /* translators: 1: HTTP status code, 2: response body */
        __('MailFlash error (%1$d): %2$s', 'mailflash'),
        $status,
        is_string($body) ? $body : __('Unknown error.', 'mailflash')
    );
}
