<?php
/**
 * Newsletter: sends the weekly preview as e-mail campaign through the API of Brevo or Emailit,
 * at the issue time. Services with RSS-to-email (Brevo, Mailchimp, MailerLite …) can use the
 * newsletter feed instead (ETCF_Digest_Feed); the admin page explains both ways.
 *
 * Brevo:   POST /v3/emailCampaigns, then POST /v3/emailCampaigns/{id}/sendNow (header api-key).
 *          The HTML must contain an unsubscribe link with {{ unsubscribe }}.
 * Emailit: POST /v2/campaigns, POST /v2/campaigns/{id} (recipients), POST /v2/campaigns/{id}/send
 *          (Bearer key with scope "full"). Unsubscribe link: {{unsubscribe_url}}.
 */

defined('ABSPATH') || exit;

class ETCF_Newsletter
{
    const OPTION = 'etcf_nl_settings';
    const STATE  = 'etcf_nl_state';
    const HOOK   = 'etcf_nl_send';

    const BREVO   = 'https://api.brevo.com/v3';
    const EMAILIT = 'https://api.emailit.com/v2';

    /* ------------------------------------------------------------ settings */

    public static function defaults(): array
    {
        return [
            'enabled'    => 0,
            'provider'   => '', // brevo, emailit
            'api_key'    => '',
            'list_id'    => '', // Brevo list ID (number) or Emailit audience ID (aud_…).
            'from_name'  => get_bloginfo('name'),
            'from_email' => '',
            'subject'    => '', // Empty = title of the issue.
            'footer'     => '',
            'test_email' => get_option('admin_email'),
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_intersect_key(wp_parse_args(is_array($saved) ? $saved : [], self::defaults()), self::defaults());
    }

    /** @return mixed */
    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function sanitize($input): array
    {
        $old   = self::all();
        $input = is_array($input) ? $input : [];
        $out   = $old;

        $out['enabled']    = empty($input['enabled']) ? 0 : 1;
        $out['provider']   = in_array($input['provider'] ?? '', ['brevo', 'emailit'], true) ? $input['provider'] : '';
        $out['list_id']    = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($input['list_id'] ?? ''));
        $out['from_name']  = sanitize_text_field((string) ($input['from_name'] ?? ''));
        $out['from_email'] = sanitize_email((string) ($input['from_email'] ?? ''));
        $out['subject']    = sanitize_text_field((string) ($input['subject'] ?? ''));
        $out['footer']     = sanitize_textarea_field((string) ($input['footer'] ?? ''));
        $out['test_email'] = sanitize_email((string) ($input['test_email'] ?? ''));

        $key = trim((string) ($input['api_key'] ?? ''));
        if (!empty($input['delete_api_key'])) {
            $out['api_key'] = '';
        } elseif ($key !== '') {
            $out['api_key'] = sanitize_text_field($key);
        }
        return $out;
    }

    public static function is_ready(): bool
    {
        $s = self::all();
        return $s['provider'] !== '' && $s['api_key'] !== '' && $s['list_id'] !== '' && is_email($s['from_email']);
    }

    public static function active(): bool
    {
        return ETCF_Settings::is_configured() && self::get('enabled') && self::is_ready();
    }

    public static function provider_name(): string
    {
        return ['brevo' => 'Brevo', 'emailit' => 'Emailit'][self::get('provider')] ?? '';
    }

    public static function state(): array
    {
        $state = get_option(self::STATE, []);
        return wp_parse_args(is_array($state) ? $state : [], ['log' => [], 'sent_issue' => '']);
    }

    public static function update_state(array $changes): void
    {
        update_option(self::STATE, array_merge(self::state(), $changes), false);
    }

    public static function log(string $text, bool $ok = true): void
    {
        $log = self::state()['log'];
        array_unshift($log, [time(), $text, $ok]);
        self::update_state(['log' => array_slice($log, 0, 30)]);
    }

    /* ------------------------------------------------------------ schedule */

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'ensure_scheduled']);
        foreach ([self::OPTION, ETCF_Settings::OPTION] as $option) {
            add_action('update_option_' . $option, [self::class, 'reschedule']);
            add_action('add_option_' . $option, [self::class, 'reschedule']);
        }
    }

    public static function ensure_scheduled(): void
    {
        $next = wp_next_scheduled(self::HOOK);
        if (self::active() && !$next) {
            wp_schedule_single_event(ETCF_Digest::next_issue()->getTimestamp(), self::HOOK);
        } elseif (!self::active() && $next) {
            self::unschedule();
        }
    }

    public static function reschedule(): void
    {
        self::unschedule();
        self::ensure_scheduled();
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run(): void
    {
        self::unschedule();
        $now   = new DateTimeImmutable('now', wp_timezone());
        $issue = ETCF_Digest::current_issue($now->modify('+1 minute'));
        if (self::active() && self::state()['sent_issue'] !== $issue->format('Y-m-d')) {
            self::update_state(['sent_issue' => $issue->format('Y-m-d')]);
            self::send_issue($issue, $now);
        }
        self::ensure_scheduled();
    }

    /**
     * Sends the issue to the list, unless it is empty or has nothing new (option of the feed).
     *
     * @return true|WP_Error|null null = nothing to send.
     */
    public static function send_issue(DateTimeImmutable $issue, DateTimeImmutable $now)
    {
        $selection = ETCF_Digest::select($issue, $now);
        if (ETCF_Digest::is_empty($selection) || (ETCF_Settings::get('digest_feed_only_new') && !ETCF_Digest::has_new($selection, $issue))) {
            self::log(__('Nothing sent: no events in the coming days, or nothing new.', 'event-toolkit-for-custom-fields'));
            return null;
        }
        $subject = self::subject($issue);
        $result  = self::get('provider') === 'emailit'
            ? self::emailit_campaign($subject, self::html($selection, $subject, 'emailit'), self::text($selection, 'emailit'))
            : self::brevo_campaign($subject, self::html($selection, $subject, 'brevo'));
        if (is_wp_error($result)) {
            /* translators: 1: service name, 2: error message */
            self::log(sprintf(__('%1$s – not sent: %2$s', 'event-toolkit-for-custom-fields'), self::provider_name(), $result->get_error_message()), false);
            return $result;
        }
        /* translators: 1: service name, 2: subject */
        self::log(sprintf(__('%1$s – sent: %2$s', 'event-toolkit-for-custom-fields'), self::provider_name(), $subject));
        return true;
    }

    /* ------------------------------------------------------------ content */

    public static function subject(DateTimeImmutable $issue): string
    {
        $subject = trim((string) self::get('subject'));
        return $subject !== '' ? $subject : ETCF_Digest::issue_title($issue);
    }

    /** Unsubscribe placeholder of the service. */
    private static function unsubscribe(string $provider): string
    {
        return $provider === 'emailit' ? '{{unsubscribe_url}}' : '{{ unsubscribe }}';
    }

    /**
     * Colours of the mail, from the look of the story images: the card in the background colour
     * with the text colour, around it the second background colour. Links and button in the accent
     * colour if it is readable on the card (3 : 1), else in the text colour.
     *
     * @return array{outer: string, card: string, ink: string, muted: string, line: string, link: string, button: string, button_text: string}
     */
    public static function palette(): array
    {
        $style = ETCF_Sharepic::style();
        $card  = self::hex($style['paper'], '#ffffff');
        $ink   = self::hex($style['ink'], '#1e1e1e');
        if (self::contrast($card, $ink) < 4.5) {
            $ink = self::contrast($card, '#111111') >= self::contrast($card, '#ffffff') ? '#111111' : '#ffffff';
        }
        $outer  = self::hex($style['paper2'], $card);
        $accent = self::hex($style['accent'], $ink);
        $link   = self::contrast($card, $accent) >= 3 ? $accent : $ink;
        $button = self::contrast($card, $accent) >= 1.5 ? $accent : $ink;
        $label  = self::contrast($button, $card) >= self::contrast($button, $ink) ? $card : $ink;
        if (self::contrast($button, $label) < 4.5) {
            $label = self::contrast($button, '#111111') >= self::contrast($button, '#ffffff') ? '#111111' : '#ffffff';
        }
        $palette = [
            'outer'       => $outer,
            'card'        => $card,
            'ink'         => $ink,
            'muted'       => self::mix($ink, $card, 0.3),
            'line'        => self::mix($ink, $card, 0.85),
            'link'        => $link,
            'button'      => $button,
            'button_text' => $label,
        ];
        return (array) apply_filters('etcf_newsletter_palette', $palette, $style);
    }

    /**
     * Fonts and heading style of the mail: the fonts of the story images (website or chosen),
     * with web-safe fallbacks; capitals and letter spacing as on the story images.
     *
     * @return array{heading: string, text: string, heading_family: string, text_family: string, weight: int, caps: bool, tracking: float}
     */
    public static function type(): array
    {
        // "Neutral standard font" on the story images: plain mail fonts, nothing from Google Fonts.
        $fonts = ETCF_Settings::get('sharepic_fonts') === 'site' ? ETCF_Fonts::families() : ['heading' => '', 'text' => ''];
        $style = ETCF_Sharepic::style();
        $stack = function (string $family): string {
            $family = str_replace(['"', "'", ';', '<', '>'], '', $family);
            return ($family !== '' ? "'" . $family . "', " : '') . 'Arial, Helvetica, sans-serif';
        };
        return [
            'heading'        => $stack($fonts['heading']),
            'text'           => $stack($fonts['text']),
            'heading_family' => $fonts['heading'],
            'text_family'    => $fonts['text'],
            'weight'         => ETCF_Fonts::heading_weight(),
            'caps'           => (bool) $style['caps'],
            'tracking'       => round((float) $style['track'], 3),
        ];
    }

    /** CSS for headings: font, colour, weight, capitals, letter spacing. */
    public static function heading_css(string $size = ''): string
    {
        $type = self::type();
        $css  = 'font-family:' . $type['heading'] . ';color:' . self::palette()['ink'] . ';font-weight:' . $type['weight'] . ';';
        if ($size !== '') {
            $css .= 'font-size:' . $size . ';';
        }
        if ($type['caps']) {
            $css .= 'text-transform:uppercase;';
        }
        if (abs($type['tracking']) >= 0.001) {
            $css .= 'letter-spacing:' . $type['tracking'] . 'em;';
        }
        return $css;
    }

    /**
     * The weekly preview with inline styles in the colours and fonts of the website.
     * Used inside the full mail, in the newsletter feed and in the previews.
     */
    public static function content(array $selection): string
    {
        $p       = self::palette();
        $type    = self::type();
        $content = ETCF_Digest::email_html($selection);
        $text    = 'font-family:' . $type['text'] . ';color:' . $p['ink'] . ';font-size:16px;line-height:1.55;';
        $rules   = [
            'h2' => 'margin:28px 0 12px;' . self::heading_css('22px') . 'line-height:1.25;',
            'h3' => 'margin:0 0 4px;' . self::heading_css('19px') . 'line-height:1.3;',
            'p'  => 'margin:0 0 16px;' . $text,
            'ul' => 'margin:0 0 16px;padding-left:20px;' . $text,
            'li' => 'margin:0 0 6px;',
        ];
        foreach ($rules as $tag => $css) {
            // Keep styles that are already there (they come after ours and win).
            $content = (string) preg_replace_callback('/<' . $tag . '(\s+style="([^"]*)")?>/', function ($m) use ($tag, $css) {
                return '<' . $tag . ' style="' . esc_attr($css . ($m[2] ?? '')) . '">';
            }, $content);
        }
        $content = (string) preg_replace('/<a (?![^>]*style=)/', '<a style="color:' . esc_attr($p['link']) . ';text-decoration:underline;" ', $content);
        // Links in headings take the heading colour, so titles do not look like body links.
        $content = (string) preg_replace_callback('/(<h[23][^>]*>)<a style="[^"]*"/', function ($m) use ($p) {
            return $m[1] . '<a style="color:' . esc_attr($p['ink']) . ';text-decoration:none;"';
        }, $content);
        return (string) apply_filters('etcf_newsletter_content', $content, $selection);
    }

    /**
     * The styled preview on its own background, for the newsletter feed: readable in any
     * template of the newsletter service, also when the look is dark and the template white.
     */
    public static function feed_content(array $selection): string
    {
        $p = self::palette();
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background:' . esc_attr($p['card']) . ';">'
            . '<tr><td style="padding:4px 16px;background:' . esc_attr($p['card']) . ';color:' . esc_attr($p['ink']) . ';">'
            . self::content($selection)
            . '</td></tr></table>';
    }

    /**
     * The whole newsletter as HTML document, in the colours and fonts of the website.
     * Simple tables and inline styles, because mail programs ignore most CSS.
     *
     * @param string $provider 'brevo', 'emailit', or '' for a preview (unsubscribe link "#").
     */
    public static function html(array $selection, string $subject, string $provider): string
    {
        $p    = self::palette();
        $type = self::type();
        $body = esc_attr($type['text']);

        $unsubscribe = $provider !== '' ? self::unsubscribe($provider) : '#';
        $footer      = trim((string) self::get('footer'));
        $footer      = $footer !== '' ? nl2br(esc_html($footer)) . '<br><br>' : '';
        $fonts       = ETCF_Fonts::google_css_url([$type['heading_family'], $type['text_family']], $type['weight']);
        // A mail is no WordPress page: the font stylesheet has to be part of the document.
        $fonts       = $fonts !== '' ? '<link rel="stylesheet" href="' . esc_url($fonts) . '">' : ''; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- e-mail document, not a page.

        return '<!DOCTYPE html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html($subject) . '</title>' . $fonts . '</head>'
            . '<body style="margin:0;padding:0;background:' . esc_attr($p['outer']) . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . esc_attr($p['outer']) . ';"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:' . esc_attr($p['card']) . ';">'
            . '<tr><td style="padding:28px 32px 8px;font-family:' . $body . ';font-size:16px;line-height:1.55;color:' . esc_attr($p['ink']) . ';">'
            . '<p style="margin:0 0 4px;font-size:13px;color:' . esc_attr($p['muted']) . ';">' . esc_html(get_bloginfo('name')) . '</p>'
            . '<h1 style="margin:0 0 16px;' . esc_attr(self::heading_css('28px')) . 'line-height:1.2;">' . esc_html($subject) . '</h1>'
            . self::content($selection)
            . '</td></tr>'
            . '<tr><td style="padding:16px 32px 28px;font-family:' . $body . ';font-size:12px;line-height:1.5;color:' . esc_attr($p['muted']) . ';border-top:1px solid ' . esc_attr($p['line']) . ';">'
            . $footer
            /* translators: link text in the newsletter footer */
            . '<a href="' . $unsubscribe . '" style="color:' . esc_attr($p['muted']) . ';">' . esc_html__('Unsubscribe', 'event-toolkit-for-custom-fields') . '</a>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** "#abc" or "#aabbcc" in lower case, else the fallback. */
    private static function hex(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
    }

    /** WCAG contrast ratio of two colours. */
    public static function contrast(string $a, string $b): float
    {
        $lum = function (string $hex): float {
            $c = array_map(function ($v) {
                $v /= 255;
                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, sscanf($hex, '#%02x%02x%02x'));
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $la = $lum($a);
        $lb = $lum($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Mixes colour $a with $b; $t = share of $b. */
    private static function mix(string $a, string $b, float $t): string
    {
        $ca = sscanf($a, '#%02x%02x%02x');
        $cb = sscanf($b, '#%02x%02x%02x');
        $out = '#';
        foreach ([0, 1, 2] as $i) {
            $out .= sprintf('%02x', (int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $t));
        }
        return $out;
    }

    /** Plain text version (Emailit sends both). */
    public static function text(array $selection, string $provider): string
    {
        $footer = trim((string) self::get('footer'));
        return ETCF_Share::week_text($selection, true)
            . ($footer !== '' ? "\n\n--\n" . $footer : '')
            /* translators: %s: unsubscribe link */
            . "\n\n" . sprintf(__('Unsubscribe: %s', 'event-toolkit-for-custom-fields'), $provider !== '' ? self::unsubscribe($provider) : '');
    }

    /* ------------------------------------------------------------ API */

    /** @return array|WP_Error */
    private static function request(string $method, string $url, array $headers, ?array $body = null)
    {
        $args = ['method' => $method, 'timeout' => 30, 'headers' => $headers + ['Accept' => 'application/json']];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = $body === [] ? '{}' : wp_json_encode($body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code >= 300) {
            $message = is_array($data) ? (string) ($data['message'] ?? $data['error']['message'] ?? $data['error'] ?? '') : '';
            /* translators: 1: HTTP status code, 2: error message */
            return new WP_Error('etcf_nl_http', trim(sprintf(__('HTTP %1$d %2$s', 'event-toolkit-for-custom-fields'), $code, $message)));
        }
        return is_array($data) ? $data : [];
    }

    private static function brevo_headers(): array
    {
        return ['api-key' => (string) self::get('api_key')];
    }

    private static function emailit_headers(): array
    {
        return ['Authorization' => 'Bearer ' . self::get('api_key')];
    }

    /** @return true|WP_Error */
    private static function brevo_campaign(string $subject, string $html)
    {
        $campaign = self::request('POST', self::BREVO . '/emailCampaigns', self::brevo_headers(), [
            'name'        => $subject,
            'subject'     => $subject,
            'sender'      => ['name' => (string) self::get('from_name'), 'email' => (string) self::get('from_email')],
            'htmlContent' => $html,
            'recipients'  => ['listIds' => [(int) self::get('list_id')]],
        ]);
        if (is_wp_error($campaign)) {
            return $campaign;
        }
        if (empty($campaign['id'])) {
            return new WP_Error('etcf_nl_brevo', __('Brevo did not create the campaign.', 'event-toolkit-for-custom-fields'));
        }
        $sent = self::request('POST', self::BREVO . '/emailCampaigns/' . (int) $campaign['id'] . '/sendNow', self::brevo_headers());
        return is_wp_error($sent) ? $sent : true;
    }

    /** @return true|WP_Error */
    private static function emailit_campaign(string $subject, string $html, string $text)
    {
        $campaign = self::request('POST', self::EMAILIT . '/campaigns', self::emailit_headers(), [
            'name'       => $subject,
            'subject'    => $subject,
            'from_email' => (string) self::get('from_email'),
            'from_name'  => (string) self::get('from_name'),
            'html'       => $html,
            'text'       => $text,
        ]);
        if (is_wp_error($campaign)) {
            return $campaign;
        }
        $id = (string) ($campaign['id'] ?? $campaign['data']['id'] ?? '');
        if ($id === '') {
            return new WP_Error('etcf_nl_emailit', __('Emailit did not create the campaign.', 'event-toolkit-for-custom-fields'));
        }
        $recipients = self::request('POST', self::EMAILIT . '/campaigns/' . rawurlencode($id), self::emailit_headers(), [
            'recipients' => [['audience_id' => (string) self::get('list_id'), 'exclude' => false]], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- field of the Emailit API, not a WordPress query.
        ]);
        if (is_wp_error($recipients)) {
            return $recipients;
        }
        $sent = self::request('POST', self::EMAILIT . '/campaigns/' . rawurlencode($id) . '/send', self::emailit_headers(), []);
        return is_wp_error($sent) ? $sent : true;
    }

    /**
     * Checks key and list. Returns a short description of the list.
     *
     * @return string|WP_Error
     */
    public static function check()
    {
        if (self::get('provider') === 'emailit') {
            $audience = self::request('GET', self::EMAILIT . '/audiences/' . rawurlencode((string) self::get('list_id')), self::emailit_headers());
            if (is_wp_error($audience)) {
                return $audience;
            }
            $name = (string) ($audience['name'] ?? $audience['data']['name'] ?? self::get('list_id'));
            /* translators: %s: name of the audience */
            return sprintf(__('Emailit: audience "%s" found.', 'event-toolkit-for-custom-fields'), $name);
        }
        $list = self::request('GET', self::BREVO . '/contacts/lists/' . (int) self::get('list_id'), self::brevo_headers());
        if (is_wp_error($list)) {
            return $list;
        }
        /* translators: 1: list name, 2: number of subscribers */
        return sprintf(__('Brevo: list "%1$s" with %2$d contacts found.', 'event-toolkit-for-custom-fields'), (string) ($list['name'] ?? ''), (int) ($list['uniqueSubscribers'] ?? $list['totalSubscribers'] ?? 0));
    }

    /**
     * Sends the current preview once to the test address (as single mail, not to the list).
     *
     * @return true|WP_Error|null
     */
    public static function send_test(array $selection, DateTimeImmutable $issue)
    {
        $to = (string) self::get('test_email');
        if (!is_email($to)) {
            return new WP_Error('etcf_nl_test', __('Enter a valid test address first.', 'event-toolkit-for-custom-fields'));
        }
        if (ETCF_Digest::is_empty($selection)) {
            return null;
        }
        /* translators: %s: subject */
        $subject = sprintf(__('[Test] %s', 'event-toolkit-for-custom-fields'), self::subject($issue));
        $html    = self::html($selection, $subject, '');
        $from    = (string) self::get('from_email');
        $name    = (string) self::get('from_name');
        if (self::get('provider') === 'emailit') {
            $result = self::request('POST', self::EMAILIT . '/emails', self::emailit_headers(), [
                'from'    => $name !== '' ? $name . ' <' . $from . '>' : $from,
                'to'      => $to,
                'subject' => $subject,
                'html'    => $html,
                'text'    => self::text($selection, ''),
            ]);
        } else {
            $result = self::request('POST', self::BREVO . '/smtp/email', self::brevo_headers(), [
                'sender'      => ['name' => $name, 'email' => $from],
                'to'          => [['email' => $to]],
                'subject'     => $subject,
                'htmlContent' => $html,
            ]);
        }
        return is_wp_error($result) ? $result : true;
    }
}
