<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Updater')) {
    /**
     * Offers new GitHub releases on the Plugins screen, as manual updates.
     *
     * Reads the update.json that .github/workflows/release.yml attaches to each release.
     * That file is served by github.com rather than api.github.com, so sites on shared
     * IP addresses don't run into the API's rate limit.
     */
    class DRPM_Updater
    {
        const MANIFEST_URL = 'https://github.com/ventouris/cod_woo_plugin/releases/latest/download/update.json';
        const PACKAGE_PREFIX = 'https://github.com/ventouris/cod_woo_plugin/releases/download/';
        const CACHE_KEY = 'drpm_update_manifest';

        public static function register()
        {
            add_filter('update_plugins_github.com', array(__CLASS__, 'checkForUpdate'), 10, 3);
            add_filter('plugins_api', array(__CLASS__, 'pluginInformation'), 10, 3);
            add_filter('upgrader_source_selection', array(__CLASS__, 'keepPluginFolder'), 10, 4);
        }

        /**
         * Tells WordPress about the latest release. WordPress compares the versions itself.
         *
         * @param array|false $update     Update data so far.
         * @param array       $pluginData Plugin headers.
         * @param string      $pluginFile Plugin path relative to the plugins directory.
         * @return array|false
         */
        public static function checkForUpdate($update, $pluginData, $pluginFile)
        {
            if (plugin_basename(DRPM_PLUGIN_FILE) !== $pluginFile) {
                return $update;
            }

            $manifest = self::getManifest();
            if (!$manifest) {
                return $update;
            }

            return array(
                'slug'         => self::slug(),
                'version'      => $manifest['version'],
                'url'          => $manifest['url'],
                'package'      => $manifest['package'],
                'requires'     => $manifest['requires'],
                'requires_php' => $manifest['requires_php'],
            );
        }

        /**
         * Fills the "View details" popup on the Plugins screen.
         */
        public static function pluginInformation($result, $action, $args)
        {
            if ('plugin_information' !== $action || !isset($args->slug) || self::slug() !== $args->slug) {
                return $result;
            }

            $manifest = self::getManifest();
            if (!$manifest) {
                return $result;
            }

            return (object) array(
                'name'          => DRPM_PLUGIN_NAME,
                'slug'          => self::slug(),
                'version'       => $manifest['version'],
                'author'        => '<a href="https://github.com/ventouris">Tasos Ventouris</a>',
                'homepage'      => $manifest['url'],
                'requires'      => $manifest['requires'],
                'requires_php'  => $manifest['requires_php'],
                'last_updated'  => $manifest['last_updated'],
                'download_link' => $manifest['package'],
                'sections'      => array(
                    'changelog' => wpautop(esc_html($manifest['changelog'])),
                ),
            );
        }

        /**
         * Installs the update into the current folder, whatever the site named it, so
         * that WordPress keeps the plugin active.
         *
         * @param string|WP_Error $source       Extracted update folder.
         * @param string          $remoteSource Parent of the extracted folder.
         * @param WP_Upgrader     $upgrader     The upgrader.
         * @param array           $hookExtra    Details of the upgrade.
         * @return string|WP_Error
         */
        public static function keepPluginFolder($source, $remoteSource, $upgrader, $hookExtra = array())
        {
            global $wp_filesystem;

            if (is_wp_error($source) || !isset($hookExtra['plugin']) || plugin_basename(DRPM_PLUGIN_FILE) !== $hookExtra['plugin']) {
                return $source;
            }

            $target = trailingslashit($remoteSource) . self::slug() . '/';
            if (trailingslashit($source) === $target) {
                return $source;
            }

            if (!$wp_filesystem->move($source, $target, true)) {
                return new WP_Error('drpm_update_folder', __('Could not prepare the COD Guard update folder.', 'cod-guard'));
            }

            return $target;
        }

        /**
         * Fetches the manifest, cached between checks. "Check again" on the Updates
         * screen skips the cache.
         *
         * @return array|null Null when no valid manifest is available.
         */
        private static function getManifest()
        {
            $cached = isset($_GET['force-check']) ? false : get_site_transient(self::CACHE_KEY); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (false !== $cached) {
                return is_array($cached) ? $cached : null;
            }

            $response = wp_remote_get(self::MANIFEST_URL, array('timeout' => 10));
            $manifest = null;
            if (200 === wp_remote_retrieve_response_code($response)) {
                $manifest = self::parseManifest(json_decode(wp_remote_retrieve_body($response), true));
            }

            // Failures are cached too (as an empty string), but retried sooner.
            set_site_transient(self::CACHE_KEY, $manifest ? $manifest : '', $manifest ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);

            return $manifest;
        }

        /**
         * Validates a decoded manifest and fills in its optional fields.
         *
         * @param mixed $data Decoded update.json.
         * @return array|null
         */
        private static function parseManifest($data)
        {
            if (!is_array($data) || empty($data['version']) || empty($data['package']) || !is_string($data['version']) || !is_string($data['package'])) {
                return null;
            }

            if (!preg_match('/^\d+\.\d+\.\d+$/', $data['version']) || 0 !== strpos($data['package'], self::PACKAGE_PREFIX)) {
                return null;
            }

            return array_map('strval', wp_parse_args($data, array(
                'url'          => 'https://github.com/ventouris/cod_woo_plugin/releases',
                'requires'     => '',
                'requires_php' => '',
                'changelog'    => '',
                'last_updated' => '',
            )));
        }

        /**
         * The plugin's folder name, which WordPress uses as its slug.
         */
        private static function slug()
        {
            return dirname(plugin_basename(DRPM_PLUGIN_FILE));
        }
    }

    DRPM_Updater::register();
}
