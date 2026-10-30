<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Guide')) {
    /**
     * The "How to use" tab: the shop manager's guide, in English and Greek.
     *
     * The guide lives in one template per language (admin/guide/) rather than in the
     * translation files, because each version also has its own screenshots.
     */
    class DRPM_Guide
    {
        const LANGUAGES = array(
            'en' => 'English',
            'el' => 'Ελληνικά',
        );

        /**
         * The requested language, or else the one matching the user's admin language.
         */
        private static function currentLanguage()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $language = isset($_GET['guide_lang']) ? sanitize_key(wp_unslash($_GET['guide_lang'])) : '';
            if (isset(self::LANGUAGES[$language])) {
                return $language;
            }

            return 0 === strpos(get_user_locale(), 'el') ? 'el' : 'en';
        }

        /**
         * Outputs a screenshot from the current language's folder.
         */
        private static function image($language, $name, $alt)
        {
            printf(
                '<img class="drpm-guide__image" src="%s" alt="%s" loading="lazy" />',
                esc_url(DRPM_PLUGIN_URL . 'assets/images/guide/' . $language . '/' . $name . '.webp'),
                esc_attr($alt)
            );
        }

        public static function render()
        {
            $language = self::currentLanguage();
            $image = function ($name, $alt) use ($language) {
                self::image($language, $name, $alt);
            };
            ?>
            <div class="drpm-dash__head">
                <nav class="drpm-range" aria-label="<?php esc_attr_e('Guide language', 'cod-guard'); ?>">
                    <?php foreach (self::LANGUAGES as $key => $label) : ?>
                        <a href="<?php echo esc_url(DRPM_Dashboard::url(array('tab' => 'guide', 'guide_lang' => $key))); ?>" lang="<?php echo esc_attr($key); ?>" class="drpm-range__item<?php echo $key === $language ? ' is-active' : ''; ?>" <?php echo $key === $language ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <article class="drpm-card drpm-guide" lang="<?php echo esc_attr($language); ?>">
                <?php include DRPM_PLUGIN_DIR . 'admin/guide/' . $language . '.php'; ?>
            </article>
            <?php
        }
    }
}
