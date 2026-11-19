<?php
/**
 * Template for an RSS campaign in Brevo, as YAML for the developer mode of Brevo's
 * drag-and-drop editor: header with logo or site name, the weekly preview from the
 * newsletter feed ({{ item.CONTENT_ENCODED }}), a button to the programme and a footer
 * with legal notice, browser link and unsubscribe link. Colours and fonts as in the
 * mails of the plugin (ETCF_Newsletter::palette(), ::type()).
 *
 * The structure follows the YAML Brevo shows for its RSS default template (version 3.0.1):
 * body → wrapper (rows) → grid-row → grid-column → text, image, text-button. The repeated
 * block is a grid-row with `loop: 'item in params.items | slice:":1"'` – the feed has at most
 * one item per issue.
 */

defined('ABSPATH') || exit;

class ETCF_Brevo_Template
{
    /** Number of the template the structure was taken from. */
    const VERSION = '3.0.1';

    public static function yaml(): string
    {
        return self::dump(self::document()) . "\n";
    }

    /** File name for the download, e.g. "brevo-weekly-preview.yaml". */
    public static function filename(): string
    {
        return 'brevo-' . sanitize_file_name(sanitize_title(ETCF_Settings::get('digest_feed_slug') ?: 'weekly')) . '.yaml';
    }

    /* ------------------------------------------------------------ document */

    public static function document(): array
    {
        $p     = ETCF_Newsletter::palette();
        $type  = ETCF_Newsletter::type();
        $fonts = ETCF_Fonts::google_css_url([$type['heading_family'], $type['text_family']], $type['weight']);
        $site  = (string) ETCF_Settings::get('feed_title') ?: get_bloginfo('name');

        $doc = [
            'author'               => '',
            'campaignType'         => 'template',
            'fields'               => [],
            'fieldsHistory'        => [],
            'firstLoadSocialIcons' => false,
            'fonts'                => $fonts !== '' ? [$fonts] : [],
            'id'                   => self::id('document'),
            'name'                 => '',
            /* translators: %s: name of the site or calendar */
            'subject'              => sprintf(__('%s: weekly preview', 'event-toolkit-for-custom-fields'), $site),
            'version'              => self::VERSION,
            'body'                 => [
                'id'      => self::id('body'),
                'type'    => 'body',
                'layout'  => [
                    '320px'   => ['width' => '320px'],
                    'default' => ['wrapper-background-color' => $p['outer'], 'wrapper-width' => '600px'],
                ],
                'content' => [[
                    'content-type' => 'wrapper',
                    'id'           => self::id('wrapper'),
                    'stylable'     => false,
                    'title'        => 'Wrapper',
                    'type'         => 'rows',
                    'layout'       => [
                        '320px'   => ['width' => '320px'],
                        'default' => ['align' => 'center', 'valign' => 'top'],
                    ],
                    'content'      => [self::header(), self::preview(), self::footer()],
                ]],
            ],
            'media'  => ['320px' => ['max-width' => 600, 'min-width' => 0]],
            'styles' => self::styles(),
        ];
        return (array) apply_filters('etcf_brevo_template', $doc);
    }

    private static function header(): array
    {
        $p    = ETCF_Newsletter::palette();
        $logo = self::logo();
        if ($logo) {
            $element = [
                'id'        => self::id('header-logo'),
                'stylable'  => false,
                'thumbnail' => '/assets/img/editor/element_image.svg',
                'title'     => 'Image',
                'type'      => 'image',
                'layout'    => [
                    '320px'   => ['max-width' => '320px', 'width' => $logo['width'] . 'px'],
                    'default' => [
                        'align'         => 'left',
                        'canvas-height' => $logo['height'] . 'px',
                        'padding-bottom' => '0px',
                        'padding-top'   => '0px',
                        'valign'        => 'top',
                        'width'         => $logo['width'] . 'px',
                    ],
                ],
                'content'   => ['src' => $logo['url']],
            ];
        } else {
            $element = self::text('header-name', 'Site name', '<span style="' . ETCF_Newsletter::heading_css('20px') . '">' . esc_html(get_bloginfo('name')) . '</span>', ['line-height' => '1.3']);
        }
        return self::row('header', 'header', 'Header', ['background-color' => $p['card'], 'padding-top' => '28px', 'padding-bottom' => '4px', 'padding-left' => '32px', 'padding-right' => '32px'], [$element], '/assets/img/editor/element-header.svg');
    }

    /** The repeated block: title, the whole preview and a button. */
    private static function preview(): array
    {
        $p       = ETCF_Newsletter::palette();
        $label   = __('Full programme', 'event-toolkit-for-custom-fields');
        $button  = '<span style="color:' . $p['button_text'] . ';">' . esc_html($label) . '</span>';
        $content = [
            self::text('title', 'Main Headline', '<span style="' . ETCF_Newsletter::heading_css('28px') . 'line-height:1.2;">{{ item.TITLE }}</span>', ['line-height' => '1.2', 'padding-top' => '8px', 'padding-bottom' => '8px', 'padding-left' => '16px', 'padding-right' => '16px']),
            self::text('content', 'Weekly preview', '{{ item.CONTENT_ENCODED | safe }}', ['line-height' => '1.55']),
            [
                'id'        => self::id('button'),
                'stylable'  => false,
                'text'      => $button,
                'thumbnail' => '/assets/img/editor/element_button.svg',
                'title'     => 'Button',
                'type'      => 'text-button',
                'layout'    => [
                    '320px'   => ['width' => '100%'],
                    'default' => [
                        'align'                 => 'left',
                        'data-placeholder-href' => '{{ item.LINK }}',
                        'height'                => '44px',
                        'item-height'           => '44px',
                        'margin-left'           => '16px',
                        'margin-top'            => '8px',
                        'padding-bottom'        => '12px',
                        'padding-left'          => '20px',
                        'padding-right'         => '20px',
                        'padding-top'           => '12px',
                        'text-align'            => 'center',
                        'text-valign'           => 'top',
                        'type'                  => 'absoluteLink',
                        'valign'                => 'top',
                    ],
                ],
                'content'   => ['href' => '{{ item.LINK }}', 'text' => $button],
            ],
        ];

        // 16 px here and 16 px inside the feed content (ETCF_Newsletter::feed_content()) = 32 px like header and footer.
        $row = self::row('preview', 'dynamicList', 'Dynamic content', ['background-color' => $p['card'], 'padding-top' => '8px', 'padding-bottom' => '32px', 'padding-left' => '16px', 'padding-right' => '16px'], $content, 'https://img.mailinblue.com/2670624/images/modules/module_thumb_a78ed9dc4dd9dbca8164335198094c78_1599743054.png');
        // Keys of Brevo's repeat block, as in its RSS default template.
        $row = [
            'align'         => 'top',
            'customBlockId' => '5f5a244c5b80624620552602',
            'dist'          => '12',
            'eventPath'     => 'Custom data source',
            'gutter'        => '0px',
            'id'            => $row['id'],
            'isCustomBlock' => true,
            'loop'          => 'item in params.items | slice:":1"',
            'moduleName'    => 'dynamicList',
            'name'          => 'Dynamic content',
            'templateName'  => 'dynamicList',
            'thumbnail'     => $row['thumbnail'],
            'title'         => '65675',
            'type'          => 'grid-row',
            'layout'        => $row['layout'],
            'content'       => $row['content'],
        ];
        return $row;
    }

    private static function footer(): array
    {
        $p      = ETCF_Newsletter::palette();
        $muted  = ETCF_Newsletter::contrast($p['outer'], $p['muted']) >= 4.5 ? $p['muted'] : $p['ink'];
        $muted  = ETCF_Newsletter::contrast($p['outer'], $muted) >= 4.5 ? $muted : (ETCF_Newsletter::contrast($p['outer'], '#111111') >= ETCF_Newsletter::contrast($p['outer'], '#ffffff') ? '#111111' : '#ffffff');
        $span   = 'color:' . $muted . ';font-size:13px;';
        $notice = trim((string) ETCF_Newsletter::get('footer'));
        $notice = $notice !== '' ? $notice : get_bloginfo('name');
        $lines  = implode('<br>', array_map('esc_html', array_filter(array_map('trim', explode("\n", $notice)))));
        $links  = '<a href="{{ mirror }}" style="color:' . $muted . ';">' . esc_html__('View in browser', 'event-toolkit-for-custom-fields') . '</a>'
            . ' | <a href="{{ unsubscribe }}" style="color:' . $muted . ';">' . esc_html__('Unsubscribe', 'event-toolkit-for-custom-fields') . '</a>';

        return self::row('footer', 'footer', 'Footer', ['background-color' => $p['outer'], 'padding-top' => '20px', 'padding-bottom' => '24px', 'padding-left' => '32px', 'padding-right' => '32px'], [
            self::text('footer-notice', 'Legal text', '<span style="' . $span . '">' . $lines . '</span>', ['line-height' => '1.5', 'text-align' => 'center']),
            self::text('footer-links', 'Legal text', '<span style="' . $span . '">' . $links . '</span>', ['line-height' => '1.5', 'text-align' => 'center', 'padding-top' => '12px']),
        ], '/assets/img/editor/element-footer.svg');
    }

    private static function styles(): array
    {
        $p    = ETCF_Newsletter::palette();
        $type = ETCF_Newsletter::type();
        $head = ['color' => $p['ink'], 'font-family' => $type['heading'], 'font-weight' => (string) $type['weight']];
        return [
            'default'             => ['properties' => ['color' => $p['ink'], 'font-family' => $type['text'], 'font-size' => '16px', 'line-height' => '1.55'], 'title' => 'Paragraph'],
            'default-button'      => ['properties' => [
                'background-color' => $p['button'],
                'border-color'     => $p['button'],
                'border-radius'    => '4px',
                'border-width'     => '1px',
                'color'            => $p['button_text'],
                'font-family'      => $type['text'],
                'font-size'        => '16px',
                'font-weight'      => 'bold',
                'width'            => '200px',
            ], 'title' => 'css.default.fontFamily'],
            'default-link'        => ['properties' => ['color' => $p['link'], 'font-weight' => 'normal', 'text-decoration' => 'underline'], 'title' => 'Link'],
            'sib_class_headline1' => ['properties' => $head + ['font-size' => '28px'], 'title' => 'Heading 1'],
            'sib_class_headline2' => ['properties' => $head + ['font-size' => '22px'], 'title' => 'Heading 2'],
            'sib_class_headline3' => ['properties' => $head + ['font-size' => '19px'], 'title' => 'Heading 3'],
            'sib_class_impressum' => ['properties' => ['color' => $p['muted'], 'font-size' => '12px'], 'title' => 'Imprint'],
        ];
    }

    /* ------------------------------------------------------------ building blocks */

    private static function row(string $name, string $module, string $title, array $layout, array $content, string $thumbnail): array
    {
        return [
            'align'      => 'top',
            'dist'       => '12',
            'gutter'     => '0px',
            'id'         => self::id($name),
            'moduleName' => $module,
            'stylable'   => false,
            'thumbnail'  => $thumbnail,
            'title'      => $title,
            'type'       => 'grid-row',
            'layout'     => [
                '320px'   => ['display' => 'rows', 'padding-left' => '16px', 'padding-right' => '16px', 'width' => '100%'],
                'default' => ['align' => 'center', 'valign' => 'top', 'width' => '100%'] + $layout,
            ],
            'content'    => [[
                'id'        => self::id($name . '-column'),
                'isEmpty'   => false,
                'label'     => '',
                'read-only' => true,
                'stylable'  => true,
                'title'     => 'grid-column',
                'type'      => 'grid-column',
                'layout'    => [
                    '320px'   => [],
                    'default' => ['padding-left' => '0px', 'padding-right' => '0px', 'valign' => 'top'],
                ],
                'content'   => $content,
            ]],
        ];
    }

    private static function text(string $name, string $title, string $html, array $layout = []): array
    {
        return [
            'id'        => self::id($name),
            'stylable'  => false,
            'thumbnail' => '/assets/img/editor/element_text.svg',
            'title'     => $title,
            'type'      => 'text',
            'layout'    => [
                '320px'   => ['width' => '100%'],
                'default' => array_merge(['align' => 'left', 'text-align' => 'left', 'text-valign' => 'top', 'valign' => 'top', 'width' => '100%'], $layout),
            ],
            'content'   => $html,
        ];
    }

    /**
     * Logo of the website (custom logo, else site icon), at most 200 × 80 px.
     *
     * @return array{url: string, width: int, height: int}|null
     */
    private static function logo(): ?array
    {
        $id = (int) get_theme_mod('custom_logo') ?: (int) get_option('site_icon');
        $id = (int) apply_filters('etcf_newsletter_logo', $id);
        if (!$id) {
            return null;
        }
        $image = wp_get_attachment_image_src($id, 'medium');
        if (!$image || empty($image[1]) || empty($image[2])) {
            return null;
        }
        $scale = min(1, 200 / $image[1], 80 / $image[2]);
        return ['url' => (string) $image[0], 'width' => (int) round($image[1] * $scale), 'height' => (int) round($image[2] * $scale)];
    }

    /** Stable UUID per element, so the same settings give the same file. */
    private static function id(string $name): string
    {
        $h = md5(home_url('/') . '|etcf-brevo|' . $name);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    /* ------------------------------------------------------------ YAML */

    /** Minimal YAML writer for maps, lists and scalars, in the layout Brevo uses itself. */
    public static function dump($value, int $indent = 0): string
    {
        $pad   = str_repeat('  ', $indent);
        $lines = [];
        $list  = is_array($value) && array_keys($value) === range(0, count($value) - 1);
        foreach ($value as $key => $item) {
            $head = $list ? $pad . '-' : $pad . self::key((string) $key) . ':';
            if (is_array($item) && $item !== []) {
                $lines[] = $head;
                $lines[] = self::dump($item, $indent + 1);
            } else {
                $lines[] = $head . ' ' . self::scalar($item);
            }
        }
        return implode("\n", $lines);
    }

    private static function key(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $key) ? $key : self::quote($key);
    }

    private static function scalar($value): string
    {
        if (is_array($value)) {
            return '[]';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'null';
        }
        $value = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_\/. -]*$/', $value) && substr($value, -1) !== ' '
            && !in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no', 'on', 'off', 'y', 'n'], true)) {
            return $value;
        }
        return self::quote($value);
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
