<?php
/**
 * Translations that ship with the plugin.
 *
 * WordPress loads language packs from translate.wordpress.org on its own. Such a pack can be
 * missing (plugin installed from GitHub) or older than the plugin; then every string added since
 * would show up in English, next to the translated ones. So the files in languages/ are loaded
 * as well, after the language pack: a string comes from the pack if it has it, else from the
 * bundled file, and the admin pages stay in one language.
 */

defined('ABSPATH') || exit;

class ETCF_I18n
{
    const DOMAIN = 'event-toolkit-for-custom-fields';

    public static function init(): void
    {
        add_action('init', [self::class, 'load'], 0);
        // switch_to_locale() (e-mails, story images in the language of the site) reloads only the pack.
        add_action('change_locale', [self::class, 'load']);
    }

    /** @param string $locale Given by change_locale; on init the locale of the request. */
    public static function load($locale = ''): void
    {
        $locale = is_string($locale) && $locale !== '' ? $locale : determine_locale();
        if ($locale === 'en_US') {
            return;
        }
        // The language pack first, so that it wins where both have a string. load_textdomain()
        // prefers the .l10n.php next to the .mo and skips files that are already loaded.
        load_textdomain(self::DOMAIN, WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . $locale . '.mo', $locale);
        load_textdomain(self::DOMAIN, ETCF_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo', $locale);
    }
}
