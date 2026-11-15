<?php
/**
 * Settings: which fields provide the event data, and how the outputs behave.
 * All fields are read as regular post meta, which works with ACF, ACPT and plain custom fields.
 *
 * The settings page has tabs; each tab only submits its own fields. sanitize() therefore
 * starts from the saved values and replaces only the fields of the submitted tab.
 */

defined('ABSPATH') || exit;

class ETCF_Settings
{
    const OPTION = 'etcf_settings';

    /** Fields per settings tab, with their type (key, keys, text, int, check, url, time, weekday, textarea, color, email, choice). */
    public static function tabs(): array
    {
        return [
            'events' => [
                'post_type'        => 'key',
                'date_key'         => 'keys',
                'end_date_key'     => 'keys',
                'start_keys'       => 'keys',
                'end_time_key'     => 'keys',
                'doors_key'        => 'keys',
                'default_duration' => 'int',
                'location_keys'    => 'keys',
                'description_key'  => 'keys',
                'ticket_url_key'   => 'keys',
                'price_key'        => 'keys',
                'status_key'       => 'keys',
                'status_cancelled' => 'text',
                'status_postponed' => 'text',
            ],
            'calendar' => [
                'base_path'      => 'slug',
                'feed_slug'      => 'slug',
                'feed_title'     => 'text',
                'feed_past_days' => 'int',
            ],
            'search' => [
                'schema_enabled' => 'check',
                'currency'       => 'text',
                'country'        => 'text',
                'venue_name'     => 'text',
                'venue_address'  => 'text',
                'organizer_name' => 'text',
                'organizer_url'  => 'url',
            ],
            'digest' => [
                'digest_weekday'      => 'weekday',
                'digest_time'         => 'time',
                'digest_days_ahead'   => 'int',
                'digest_include_new'  => 'check',
                'digest_intro'        => 'textarea',
                'digest_heading_week' => 'text',
                'digest_heading_new'  => 'text',
                'digest_program_url'  => 'url',
                'digest_feed_enabled' => 'check',
                'digest_feed_slug'    => 'slug',
                'digest_feed_only_new' => 'check',
            ],
            'share' => [
                'sharepic_paper'    => 'color',
                'sharepic_bg'       => 'choice',
                'sharepic_paper2'   => 'color',
                'sharepic_bg_image' => 'int',
                'sharepic_veil'     => 'int',
                'sharepic_ink'      => 'color',
                'sharepic_accent'   => 'color',
                'sharepic_footer'   => 'text',
                'sharepic_fonts'    => 'choice',
                'sharepic_heading_font'   => 'text',
                'sharepic_heading_weight' => 'choice',
                'sharepic_heading_caps'   => 'choice',
                'sharepic_heading_tracking' => 'choice',
                'sharepic_text_font'      => 'text',
                'share_reminder'    => 'check',
                'share_reminder_to' => 'email',
            ],
        ];
    }

    public static function defaults(): array
    {
        return [
            'post_type'        => '',
            'date_key'         => '',
            'end_date_key'     => '',
            'start_keys'       => '',
            'end_time_key'     => '',
            'doors_key'        => '',
            'default_duration' => 180,
            'location_keys'    => '',
            'description_key'  => '',
            'ticket_url_key'   => '',
            'price_key'        => '',
            'status_key'       => '',
            'status_cancelled' => 'cancelled, canceled, abgesagt, 1',
            'status_postponed' => 'postponed, verschoben',

            'base_path'        => 'calendar',
            'feed_slug'        => 'events',
            'feed_title'       => get_bloginfo('name'),
            'feed_past_days'   => 30,

            'schema_enabled'   => 1,
            'currency'         => 'EUR',
            'country'          => self::locale_country(),
            'venue_name'       => '',
            'venue_address'    => '',
            'organizer_name'   => get_bloginfo('name'),
            'organizer_url'    => home_url('/'),

            'digest_weekday'      => 1, // 1 = Monday … 7 = Sunday (ISO-8601).
            'digest_time'         => '10:00',
            'digest_days_ahead'   => 7,
            'digest_include_new'  => 1,
            'digest_intro'        => '',
            'digest_heading_week' => '', // Empty = translated default.
            'digest_heading_new'  => '',
            'digest_program_url'  => home_url('/'),
            'digest_feed_enabled' => 1,
            'digest_feed_slug'    => 'weekly',
            'digest_feed_only_new' => 0,

            'sharepic_paper'    => '#ffffff',
            'sharepic_bg'       => 'solid', // 'solid', 'gradient' (paper → paper2) or 'image'.
            'sharepic_paper2'   => '#e9e4dc',
            'sharepic_bg_image' => 0, // Attachment ID.
            'sharepic_veil'     => 70, // Percent of the background colour over the image.
            'sharepic_ink'      => '#1e1e1e',
            'sharepic_accent'   => '#3858e9',
            'sharepic_footer'   => '', // Empty = address of the programme page.
            'sharepic_fonts'    => 'site', // 'site' = fonts of the website, 'standard' = a neutral font already on the server.
            'sharepic_heading_font'   => '', // Empty = detected.
            'sharepic_heading_weight' => 0, // 0 = as on the website.
            'sharepic_heading_caps'   => 'site', // 'site' = as on the website, 1 = capitals, 0 = upper and lower case.
            'sharepic_heading_tracking' => 'site', // 'site', 'tight', 'wide' (relative to the website) or 'none'; see ETCF_Sharepic::TRACKING.
            'sharepic_text_font'      => '',
            'share_reminder'    => 0,
            'share_reminder_to' => get_option('admin_email'),
        ];
    }

    /** Two-letter country code from the site language, e.g. "DE" for de_DE. */
    private static function locale_country(): string
    {
        $parts = explode('_', get_locale());
        return (isset($parts[1]) && strlen($parts[1]) === 2) ? strtoupper($parts[1]) : '';
    }

    /** Unsaved values for a preview (see override()). */
    private static $override = [];

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_merge(wp_parse_args(is_array($saved) ? $saved : [], self::defaults()), self::$override);
    }

    /**
     * Uses unsaved values of one tab for the rest of the request, e.g. to preview story images
     * while the form is being edited. The values are sanitized like on saving; nothing is stored.
     */
    public static function override(string $tab, array $input): void
    {
        $types = self::tabs()[$tab] ?? [];
        $out   = [];
        foreach ($types as $key => $type) {
            if ($type === 'check') {
                $out[$key] = empty($input[$key]) ? 0 : 1;
            } elseif (array_key_exists($key, $input)) {
                $out[$key] = self::clean($key, $type, $input[$key]);
            }
        }
        self::$override = $out;
    }

    /** @return mixed */
    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /** Comma-separated list of field names as an array. */
    public static function key_list(string $key): array
    {
        $raw = (string) self::get($key);
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Sanitizes the fields of the submitted tab and keeps all other saved values.
     * Without a "_tab" marker (e.g. programmatic updates) all known fields are taken from the input.
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $out   = self::all();
        $tabs  = self::tabs();
        $tab   = isset($input['_tab']) ? sanitize_key($input['_tab']) : '';
        $types = isset($tabs[$tab]) ? $tabs[$tab] : array_merge(...array_values($tabs));

        foreach ($types as $key => $type) {
            if ($type === 'check') {
                // Unchecked checkboxes are not submitted, so a missing key means "off".
                $out[$key] = empty($input[$key]) ? 0 : 1;
                continue;
            }
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $out[$key] = self::clean($key, $type, $input[$key]);
        }
        unset($out['_tab']);
        return $out;
    }

    /** @return mixed */
    private static function clean(string $key, string $type, $value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        switch ($type) {
            case 'key':
                return sanitize_key($value);
            case 'keys':
                $parts = array_filter(array_map(function ($k) {
                    return preg_replace('/[^A-Za-z0-9_\-]/', '', trim($k));
                }, explode(',', $value)));
                return implode(', ', $parts);
            case 'int':
                $min = ['default_duration' => 15, 'digest_days_ahead' => 1][$key] ?? 0;
                $max = ['digest_days_ahead' => 31, 'sharepic_veil' => 95][$key] ?? PHP_INT_MAX;
                return min($max, max($min, absint($value)));
            case 'slug':
                return sanitize_title($value) ?: self::defaults()[$key];
            case 'url':
                return esc_url_raw($value);
            case 'textarea':
                return sanitize_textarea_field($value);
            case 'weekday':
                return min(7, max(1, absint($value)));
            case 'choice':
                $choices = self::choices()[$key] ?? [];
                return array_key_exists($value, $choices) ? (is_int(array_key_first($choices)) ? (int) $value : $value) : self::defaults()[$key];
            case 'color':
                return sanitize_hex_color($value) ?: self::defaults()[$key];
            case 'email':
                return sanitize_email($value);
            case 'time':
                return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : '10:00';
        }
        // text
        if ($key === 'currency') {
            return strtoupper(preg_replace('/[^A-Za-z]/', '', $value)) ?: 'EUR';
        }
        if ($key === 'country') {
            return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $value), 0, 2));
        }
        return sanitize_text_field($value);
    }

    /** Allowed values of the 'choice' settings (value => label). */
    public static function choices(): array
    {
        $site = ETCF_Sharepic::site_heading();
        $base = $site['tracking'];
        return [
            'sharepic_bg'             => [
                'solid'    => __('One colour', 'event-toolkit-for-custom-fields'),
                'gradient' => __('Gradient', 'event-toolkit-for-custom-fields'),
                'image'    => __('Picture', 'event-toolkit-for-custom-fields'),
            ],
            'sharepic_fonts'          => [
                'site'    => __('Fonts of the website', 'event-toolkit-for-custom-fields'),
                'standard' => __('Neutral standard font (from WordPress or the server)', 'event-toolkit-for-custom-fields'),
            ],
            'sharepic_heading_weight' => [
                0   => __('As on the website', 'event-toolkit-for-custom-fields'),
                400 => __('Regular (400)', 'event-toolkit-for-custom-fields'),
                500 => __('Medium (500)', 'event-toolkit-for-custom-fields'),
                600 => __('Semibold (600)', 'event-toolkit-for-custom-fields'),
                700 => __('Bold (700)', 'event-toolkit-for-custom-fields'),
                800 => __('Extra bold (800)', 'event-toolkit-for-custom-fields'),
                900 => __('Black (900)', 'event-toolkit-for-custom-fields'),
            ],
            'sharepic_heading_caps' => [
                'site' => sprintf(
                    /* translators: %s: what was found on the website, e.g. "capitals" */
                    __('As on the website (%s)', 'event-toolkit-for-custom-fields'),
                    $site['caps'] ? __('capitals', 'event-toolkit-for-custom-fields') : __('upper and lower case', 'event-toolkit-for-custom-fields')
                ),
                1 => __('Capitals', 'event-toolkit-for-custom-fields'),
                0 => __('Upper and lower case', 'event-toolkit-for-custom-fields'),
            ],
            'sharepic_heading_tracking' => [
                'site'  => sprintf(
                    /* translators: %s: letter spacing found on the website, e.g. "0 %" */
                    __('As on the website (%s)', 'event-toolkit-for-custom-fields'),
                    self::percent($base)
                ),
                'tight' => sprintf(
                    /* translators: %s: resulting letter spacing, e.g. "−1.5 %" */
                    __('Tighter than the website (%s)', 'event-toolkit-for-custom-fields'),
                    self::percent($base + ETCF_Sharepic::TRACKING['tight'])
                ),
                'wide'  => sprintf(
                    /* translators: %s: resulting letter spacing, e.g. "+4 %" */
                    __('Wider than the website (%s)', 'event-toolkit-for-custom-fields'),
                    self::percent($base + ETCF_Sharepic::TRACKING['wide'])
                ),
                'none'  => __('No letter spacing (0 %)', 'event-toolkit-for-custom-fields'),
            ],
        ];
    }

    /** Letter spacing in em as signed percent, e.g. "−1.5 %". */
    private static function percent(float $em): string
    {
        $v = round($em * 100, 1);
        if (abs($v) < 0.05) {
            return '0 %';
        }
        return ($v < 0 ? '−' : '+') . number_format_i18n(abs($v), floor(abs($v)) == abs($v) ? 0 : 1) . ' %';
    }

    /**
     * One-time changes of saved settings after an update. 2.7.0: capitals and letter spacing
     * of the story headings follow the website unless chosen otherwise. "No capitals" was the
     * only possible default before, so it becomes "as on the website"; "normal" spacing from
     * 2.6.0 too.
     */
    public static function migrate(): void
    {
        $rev = (int) get_option('etcf_settings_rev', 0);
        if ($rev >= 1) {
            return;
        }
        $saved = get_option(self::OPTION, null);
        if (is_array($saved)) {
            if (isset($saved['sharepic_heading_caps']) && (string) $saved['sharepic_heading_caps'] === '0') {
                $saved['sharepic_heading_caps'] = 'site';
            }
            if (($saved['sharepic_heading_tracking'] ?? '') === 'normal') {
                $saved['sharepic_heading_tracking'] = 'site';
            }
            update_option(self::OPTION, $saved);
        }
        update_option('etcf_settings_rev', 1);
    }

    public static function is_configured(): bool
    {
        return self::get('post_type') !== '' && self::get('date_key') !== '';
    }
}
