<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Dashboard')) {
    /**
     * WooCommerce → Delivery refusals: the plugin's own page, with a Dashboard tab
     * (KPIs, trends and breakdowns of refused deliveries), a Settings tab and a How to use tab.
     */
    class DRPM_Dashboard
    {
        const PAGE = 'cod-guard';
        const PRODUCTS_ON_DASHBOARD = 10;
        const PRODUCTS_PER_PAGE = 20;
        const BLOCKED_PER_PAGE = 20;

        public function __construct()
        {
            add_action('admin_menu', array($this, 'addMenu'), 60);
            add_action('admin_enqueue_scripts', array($this, 'enqueue'));
            // Let WooCommerce load its admin styles/scripts (settings fields, tooltips) here.
            add_filter('woocommerce_screen_ids', array($this, 'addScreenId'));
        }

        public function addScreenId($ids)
        {
            $ids[] = 'woocommerce_page_' . self::PAGE;
            return $ids;
        }

        private static function currentTab()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return isset($_GET['tab']) && in_array($_GET['tab'], array('products', 'blocked', 'settings', 'guide'), true) ? $_GET['tab'] : 'dashboard';
        }

        public function onLoad()
        {
            if ('settings' === self::currentTab()) {
                DRPM_Settings::instance()->handleSave();
            }
        }

        public function addMenu()
        {
            $hook = add_submenu_page(
                'woocommerce',
                __('COD Guard', 'cod-guard'),
                __('COD Guard', 'cod-guard'),
                'manage_woocommerce',
                self::PAGE,
                array($this, 'render')
            );
            if ($hook) {
                add_action('load-' . $hook, array($this, 'onLoad'));
            }
        }

        public static function url($args = array())
        {
            return add_query_arg(array_merge(array('page' => self::PAGE), $args), admin_url('admin.php'));
        }

        public function enqueue($hook)
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!isset($_GET['page']) || self::PAGE !== $_GET['page'] || 'dashboard' !== self::currentTab()) {
                return;
            }
            wp_enqueue_script('drpm-dashboard', DRPM_PLUGIN_URL . 'assets/js/dashboard.js', array(), DRPM_VERSION . '.' . filemtime(DRPM_PLUGIN_DIR . 'assets/js/dashboard.js'), true);
            wp_localize_script('drpm-dashboard', 'drpmDashboard', array(
                'refused'   => __('Refused', 'cod-guard'),
                'completed' => __('Completed', 'cod-guard'),
                'rate'      => __('Refusal rate', 'cod-guard'),
            ));
        }

        public function render()
        {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            $tab = self::currentTab();
            $tabs = array(
                'dashboard' => __('Dashboard', 'cod-guard'),
                'products'  => __('Refused products', 'cod-guard'),
                'blocked'   => __('Blocked customers', 'cod-guard'),
                'settings'  => __('Settings', 'cod-guard'),
                'guide'     => __('How to use', 'cod-guard'),
            );
            ?>
            <div class="wrap drpm-dash">
                <h1 class="drpm-dash__title"><?php esc_html_e('COD Guard', 'cod-guard'); ?></h1>
                <nav class="nav-tab-wrapper woo-nav-tab-wrapper drpm-tabs" aria-label="<?php esc_attr_e('Sections', 'cod-guard'); ?>">
                    <?php foreach ($tabs as $key => $label) : ?>
                        <a href="<?php echo esc_url(self::url('dashboard' === $key ? array() : array('tab' => $key))); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </nav>
                <?php
                if ('settings' === $tab) {
                    DRPM_Settings::instance()->render();
                } elseif ('guide' === $tab) {
                    DRPM_Guide::render();
                } elseif ('blocked' === $tab) {
                    $this->renderBlocked();
                } elseif ('products' === $tab) {
                    $this->renderProducts();
                } else {
                    $this->renderDashboard();
                }
                ?>
            </div>
            <?php
        }

        private static function currentRange()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '12m';
            return in_array($range, DRPM_Stats::RANGES, true) ? $range : '12m';
        }

        /**
         * Date range pills; $args are kept on every link (e.g. the tab).
         */
        private function rangeNav($range, array $args = array())
        {
            $labels = array(
                '30d' => __('Last 30 days', 'cod-guard'),
                '90d' => __('Last 90 days', 'cod-guard'),
                '12m' => __('Last 12 months', 'cod-guard'),
                'all' => __('All time', 'cod-guard'),
            );
            ?>
            <nav class="drpm-range" aria-label="<?php esc_attr_e('Date range', 'cod-guard'); ?>">
                <?php foreach ($labels as $key => $label) : ?>
                    <a href="<?php echo esc_url(self::url(array_merge($args, array('range' => $key)))); ?>" class="drpm-range__item<?php echo $key === $range ? ' is-active' : ''; ?>" <?php echo $key === $range ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php
        }

        private function renderDashboard()
        {
            $range = self::currentRange();
            $window = DRPM_Stats::window($range);

            $current = DRPM_Stats::totals($window['from'], $window['to']);
            $previous = $window['prev_from'] ? DRPM_Stats::totals($window['prev_from'], $window['from']) : null;
            $timeline = DRPM_Stats::timeline($window['from'], $window['to'], $window['granularity']);
            $methods = DRPM_Stats::byPaymentMethod($window['from'], $window['to']);
            $regions = DRPM_Stats::byRegion($window['from'], $window['to']);
            $products = DRPM_Stats::topProducts($window['from'], $window['to'], self::PRODUCTS_ON_DASHBOARD);
            $refusers = DRPM_Stats::repeatRefusers($window['from'], $window['to']);

            $chartTitles = array(
                'day'   => array(__('Refused deliveries per day', 'cod-guard'), __('Refusal rate per day', 'cod-guard')),
                'week'  => array(__('Refused deliveries per week', 'cod-guard'), __('Refusal rate per week', 'cod-guard')),
                'month' => array(__('Refused deliveries per month', 'cod-guard'), __('Refusal rate per month', 'cod-guard')),
            )[$window['granularity']];
            $backfill = get_option('drpm_backfill_state', array());
            ?>
                <div class="drpm-dash__head">
                    <?php $this->rangeNav($range); ?>
                </div>

                <?php if ('running' === ($backfill['status'] ?? '') || 'yes' === get_option('drpm_needs_backfill')) : ?>
                    <div class="notice notice-info inline"><p><?php esc_html_e('Existing orders are still being indexed in the background, so these numbers may be incomplete.', 'cod-guard'); ?></p></div>
                <?php endif; ?>

                <section class="drpm-kpis" aria-label="<?php esc_attr_e('Key figures', 'cod-guard'); ?>">
                    <div class="drpm-card drpm-hero">
                        <span class="drpm-kpi__label"><?php esc_html_e('Refusal rate', 'cod-guard'); ?></span>
                        <span class="drpm-hero__value"><?php echo esc_html(number_format_i18n($current['rate'], 1)); ?>%</span>
                        <?php $this->delta($current['rate'], $previous['rate'] ?? null, 'pp'); ?>
                        <span class="drpm-kpi__sub">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: 1: refused, 2: refused + completed */
                                __('%1$s of %2$s delivery attempts refused', 'cod-guard'),
                                number_format_i18n($current['refused']),
                                number_format_i18n($current['refused'] + $current['delivered'])
                            ));
                            ?>
                        </span>
                    </div>

                    <div class="drpm-card drpm-kpi">
                        <span class="drpm-kpi__label"><?php esc_html_e('Refused deliveries', 'cod-guard'); ?></span>
                        <span class="drpm-kpi__value"><?php echo esc_html(number_format_i18n($current['refused'])); ?></span>
                        <?php $this->delta($current['refused'], $previous['refused'] ?? null, 'count'); ?>
                    </div>

                    <div class="drpm-card drpm-kpi">
                        <span class="drpm-kpi__label"><?php esc_html_e('Cost of refusals', 'cod-guard'); ?></span>
                        <?php if (DRPM_Stats::costPerRefusal() > 0) : ?>
                            <span class="drpm-kpi__value"><?php echo wp_kses_post(wc_price($current['cost'])); ?></span>
                            <?php $this->delta($current['cost'], $previous['cost'] ?? null, 'count'); ?>
                            <span class="drpm-kpi__sub"><?php echo wp_kses_post(sprintf(
                                /* translators: %s: cost per refused parcel */
                                __('at %s per refused parcel', 'cod-guard'),
                                wc_price(DRPM_Stats::costPerRefusal())
                            )); ?></span>
                        <?php else : ?>
                            <span class="drpm-kpi__value drpm-kpi__value--empty">—</span>
                            <a class="drpm-kpi__sub" href="<?php echo esc_url(DRPM_Settings::url('drpm_refusal_cost')); ?>"><?php esc_html_e('Set your cost per refused parcel', 'cod-guard'); ?></a>
                        <?php endif; ?>
                    </div>

                    <div class="drpm-card drpm-kpi">
                        <span class="drpm-kpi__label"><?php esc_html_e('Order value refused', 'cod-guard'); ?></span>
                        <span class="drpm-kpi__value"><?php echo wp_kses_post(wc_price($current['refused_value'])); ?></span>
                        <span class="drpm-kpi__sub"><?php esc_html_e('Sales that didn\'t go through', 'cod-guard'); ?></span>
                    </div>

                    <div class="drpm-card drpm-kpi">
                        <span class="drpm-kpi__label"><?php esc_html_e('Checkouts with methods hidden', 'cod-guard'); ?></span>
                        <span class="drpm-kpi__value"><?php echo esc_html(number_format_i18n($current['restricted'])); ?></span>
                        <span class="drpm-kpi__sub">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: number of orders */
                                __('%s completed with another payment method', 'cod-guard'),
                                number_format_i18n($current['restricted_delivered'])
                            ));
                            ?>
                        </span>
                    </div>
                </section>

                <section class="drpm-grid">
                    <?php
                    $this->chartCard(
                        $chartTitles[0],
                        'columns',
                        $timeline,
                        array(
                            __('Period', 'cod-guard')    => 'label',
                            __('Refused', 'cod-guard')   => 'refused',
                            __('Completed', 'cod-guard') => 'delivered',
                        )
                    );
                    $this->chartCard(
                        $chartTitles[1],
                        'line',
                        $timeline,
                        array(
                            __('Period', 'cod-guard')       => 'label',
                            __('Refusal rate', 'cod-guard') => 'rate',
                        )
                    );
                    ?>
                </section>

                <section class="drpm-grid">
                    <div class="drpm-card">
                        <h2 class="drpm-card__title"><?php esc_html_e('Refusal rate by payment method', 'cod-guard'); ?></h2>
                        <?php $this->bars($methods, 'rate'); ?>
                    </div>
                    <div class="drpm-card">
                        <h2 class="drpm-card__title"><?php esc_html_e('Regions with the most refusals', 'cod-guard'); ?></h2>
                        <?php $this->bars($regions, 'refused'); ?>
                    </div>
                </section>

                <section class="drpm-grid drpm-grid--tables">
                    <div class="drpm-card">
                        <div class="drpm-card__head">
                            <h2 class="drpm-card__title"><?php esc_html_e('Most refused products', 'cod-guard'); ?></h2>
                            <?php if ($products) : ?>
                                <a class="drpm-card__link" href="<?php echo esc_url(self::url(array_filter(array('tab' => 'products', 'range' => '12m' === $range ? null : $range)))); ?>"><?php esc_html_e('All refused products', 'cod-guard'); ?> →</a>
                            <?php endif; ?>
                        </div>
                        <?php if (null === $products) : ?>
                            <p class="drpm-empty"><?php esc_html_e('Product data needs WooCommerce Analytics to be enabled.', 'cod-guard'); ?></p>
                        <?php elseif (!$products) : ?>
                            <p class="drpm-empty"><?php esc_html_e('No refused products in this period.', 'cod-guard'); ?></p>
                        <?php else : ?>
                            <div class="drpm-table-scroll">
                            <table class="drpm-table">
                                <thead><tr>
                                    <th scope="col"><?php esc_html_e('Product', 'cod-guard'); ?></th>
                                    <th scope="col" class="num"><?php esc_html_e('Refused orders', 'cod-guard'); ?></th>
                                    <th scope="col" class="num"><?php esc_html_e('Refusal rate', 'cod-guard'); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($products as $i => $product) : ?>
                                    <tr>
                                        <td><span class="drpm-table__rank"><?php echo esc_html(number_format_i18n($i + 1)); ?></span><?php echo $product['url'] ? '<a href="' . esc_url($product['url']) . '">' . esc_html($product['label']) . '</a>' : esc_html($product['label']); ?></td>
                                        <td class="num"><?php echo esc_html(number_format_i18n($product['refused'])); ?></td>
                                        <td class="num"><?php echo esc_html(number_format_i18n($product['rate'], 1)); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="drpm-card">
                        <div class="drpm-card__head">
                            <h2 class="drpm-card__title"><?php esc_html_e('Repeat refusers', 'cod-guard'); ?></h2>
                            <a class="drpm-card__link" href="<?php echo esc_url(self::url(array('tab' => 'blocked'))); ?>"><?php esc_html_e('All blocked customers', 'cod-guard'); ?> →</a>
                        </div>
                        <?php if (!$refusers) : ?>
                            <p class="drpm-empty"><?php esc_html_e('No customer refused more than once in this period.', 'cod-guard'); ?></p>
                        <?php else : ?>
                            <div class="drpm-table-scroll">
                            <table class="drpm-table">
                                <thead><tr>
                                    <th scope="col"><?php esc_html_e('Customer', 'cod-guard'); ?></th>
                                    <th scope="col" class="num"><?php esc_html_e('Refused', 'cod-guard'); ?></th>
                                    <th scope="col" class="num"><?php esc_html_e('Completed', 'cod-guard'); ?></th>
                                    <th scope="col"><?php esc_html_e('Last refusal', 'cod-guard'); ?></th>
                                    <th scope="col"><?php esc_html_e('Status', 'cod-guard'); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($refusers as $refuser) : ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url($refuser['url']); ?>"><?php echo esc_html($refuser['label']); ?></a>
                                            <span class="drpm-table__meta"><?php echo esc_html($refuser['email']); ?></span>
                                        </td>
                                        <td class="num"><?php echo esc_html(number_format_i18n($refuser['refused'])); ?></td>
                                        <td class="num"><?php echo esc_html(number_format_i18n($refuser['delivered'])); ?></td>
                                        <td class="drpm-table__date"><?php echo esc_html($refuser['last_refusal']); ?></td>
                                        <td>
                                            <?php if ($refuser['restricted']) : ?>
                                                <span class="drpm-status drpm-status--critical"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Methods hidden', 'cod-guard'); ?></span>
                                            <?php else : ?>
                                                <span class="drpm-status"><span class="dashicons dashicons-unlock" aria-hidden="true"></span><?php esc_html_e('Allowed', 'cod-guard'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <p class="drpm-footnote">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: list of statuses */
                        __('Refusal rate = refused ÷ (refused + %s) orders, by order date. Checkouts with methods hidden are orders placed after COD Guard hid payment methods.', 'cod-guard'),
                        implode(', ', array_map(function ($status) {
                            return function_exists('mb_strtolower') ? mb_strtolower(wc_get_order_status_name($status)) : strtolower(wc_get_order_status_name($status));
                        }, DRPM_Risk::deliveredStatuses()))
                    ));
                    ?>
                </p>
            <?php
        }

        /**
         * Refused products tab: every product with a refused order in the range, paged.
         */
        private function renderProducts()
        {
            $range = self::currentRange();
            $window = DRPM_Stats::window($range);
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $search = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';

            $all = DRPM_Stats::topProducts($window['from'], $window['to'], 100000);
            $rows = array();
            foreach ((array) $all as $i => $row) {
                $row['rank'] = $i + 1; // Rank in the full list, so it stays meaningful when searching.
                if ('' === $search || false !== (function_exists('mb_stripos') ? mb_stripos($row['label'], $search) : stripos($row['label'], $search))) {
                    $rows[] = $row;
                }
            }
            $matched = count($rows);
            $pages = max(1, (int) ceil($matched / self::PRODUCTS_PER_PAGE));
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $paged = isset($_GET['paged']) ? min($pages, max(1, absint($_GET['paged']))) : 1;
            $rows = array_slice($rows, ($paged - 1) * self::PRODUCTS_PER_PAGE, self::PRODUCTS_PER_PAGE);
            $keep = array('tab' => 'products', 'range' => '12m' === $range ? null : $range, 's' => $search ?: null);
            ?>
                <div class="drpm-dash__head">
                    <?php $this->rangeNav($range, array('tab' => 'products', 's' => $search ?: null)); ?>
                    <form method="get" class="drpm-search" role="search">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                        <input type="hidden" name="tab" value="products">
                        <?php if ('12m' !== $range) : ?>
                            <input type="hidden" name="range" value="<?php echo esc_attr($range); ?>">
                        <?php endif; ?>
                        <label class="screen-reader-text" for="drpm-products-search"><?php esc_html_e('Search refused products', 'cod-guard'); ?></label>
                        <input type="search" id="drpm-products-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Product name', 'cod-guard'); ?>">
                        <button type="submit" class="button"><?php esc_html_e('Search', 'cod-guard'); ?></button>
                    </form>
                </div>

                <div class="drpm-card">
                    <?php if (null === $all) : ?>
                        <p class="drpm-empty"><?php esc_html_e('Product data needs WooCommerce Analytics to be enabled.', 'cod-guard'); ?></p>
                    <?php elseif (!$rows) : ?>
                        <p class="drpm-empty"><?php '' !== $search ? esc_html_e('No refused product matches your search.', 'cod-guard') : esc_html_e('No refused products in this period.', 'cod-guard'); ?></p>
                    <?php else : ?>
                        <div class="drpm-table-scroll">
                        <table class="drpm-table drpm-products">
                            <thead><tr>
                                <th scope="col"><?php esc_html_e('Product', 'cod-guard'); ?></th>
                                <th scope="col" class="num"><?php esc_html_e('Refused orders', 'cod-guard'); ?></th>
                                <th scope="col" class="num"><?php esc_html_e('Completed', 'cod-guard'); ?></th>
                                <th scope="col" class="num"><?php esc_html_e('Refusal rate', 'cod-guard'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td><span class="drpm-table__rank"><?php echo esc_html(number_format_i18n($row['rank'])); ?></span><?php echo $row['url'] ? '<a href="' . esc_url($row['url']) . '">' . esc_html($row['label']) . '</a>' : esc_html($row['label']); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n($row['refused'])); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n($row['delivered'])); ?></td>
                                    <td class="num"><?php echo esc_html(number_format_i18n($row['rate'], 1)); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php $this->pager($paged, self::PRODUCTS_PER_PAGE, $matched, 'paged', $keep); ?>
                    <?php endif; ?>
                </div>

                <p class="drpm-footnote"><?php esc_html_e('Refused orders = orders containing the product that were refused, by order date. Refusal rate = refused ÷ (refused + completed) of those orders.', 'cod-guard'); ?></p>
            <?php
        }

        /**
         * Blocked customers tab: everyone payment methods are hidden from, filterable by
         * how they got there (automatic rule or a manual exception).
         */
        private function renderBlocked()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : 'all';
            if (!in_array($type, DRPM_Blocked::TYPES, true)) {
                $type = 'all';
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $search = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';

            $all = DRPM_Blocked::all();
            $counts = DRPM_Blocked::counts($all);
            $rows = array_filter($all, function ($row) use ($type, $search) {
                if ('all' !== $type && $row['type'] !== $type) {
                    return false;
                }
                if ('' === $search) {
                    return true;
                }
                $haystack = $row['label'] . ' ' . $row['email'] . ' ' . $row['phone'];
                return false !== (function_exists('mb_stripos') ? mb_stripos($haystack, $search) : stripos($haystack, $search));
            });
            $matched = count($rows);
            $pages = max(1, (int) ceil($matched / self::BLOCKED_PER_PAGE));
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $paged = isset($_GET['paged']) ? min($pages, max(1, absint($_GET['paged']))) : 1;
            $rows = array_slice(array_values($rows), ($paged - 1) * self::BLOCKED_PER_PAGE, self::BLOCKED_PER_PAGE);

            $typeLabels = array(
                'all'       => __('All', 'cod-guard'),
                'automatic' => __('Automatic', 'cod-guard'),
                'manual'    => __('Manual', 'cod-guard'),
            );
            $sourceLabels = array(
                'threshold' => __('Refusal history', 'cod-guard'),
                'profile'   => __('Customer profile', 'cod-guard'),
                'list'      => __('"Always block" list', 'cod-guard'),
            );
            $months = DRPM_Risk::months();
            ?>
                <div class="drpm-dash__head">
                    <nav class="drpm-range" aria-label="<?php esc_attr_e('How they were blocked', 'cod-guard'); ?>">
                        <?php foreach ($typeLabels as $key => $label) : ?>
                            <a href="<?php echo esc_url(self::url(array_filter(array('tab' => 'blocked', 'type' => 'all' === $key ? null : $key, 's' => $search ?: null)))); ?>" class="drpm-range__item<?php echo $key === $type ? ' is-active' : ''; ?>" <?php echo $key === $type ? 'aria-current="page"' : ''; ?>>
                                <?php echo esc_html($label); ?> <span class="drpm-range__count"><?php echo esc_html(number_format_i18n($counts[$key])); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <form method="get" class="drpm-search" role="search">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                        <input type="hidden" name="tab" value="blocked">
                        <?php if ('all' !== $type) : ?>
                            <input type="hidden" name="type" value="<?php echo esc_attr($type); ?>">
                        <?php endif; ?>
                        <label class="screen-reader-text" for="drpm-blocked-search"><?php esc_html_e('Search blocked customers', 'cod-guard'); ?></label>
                        <input type="search" id="drpm-blocked-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Name, email or phone', 'cod-guard'); ?>">
                        <button type="submit" class="button"><?php esc_html_e('Search', 'cod-guard'); ?></button>
                    </form>
                </div>

                <div class="drpm-card">
                    <?php if (!$rows) : ?>
                        <p class="drpm-empty">
                            <?php
                            if ('' !== $search) {
                                esc_html_e('No blocked customer matches your search.', 'cod-guard');
                            } elseif ('manual' === $type) {
                                esc_html_e('No customer is blocked manually. Use "Always block" on a customer profile or in Settings → Exceptions.', 'cod-guard');
                            } elseif ('automatic' === $type) {
                                esc_html_e('No customer currently crosses your refusal threshold.', 'cod-guard');
                            } else {
                                esc_html_e('No customer is blocked right now.', 'cod-guard');
                            }
                            ?>
                        </p>
                    <?php else : ?>
                        <div class="drpm-table-scroll">
                        <table class="drpm-table drpm-blocked">
                            <thead><tr>
                                <th scope="col"><?php esc_html_e('Customer', 'cod-guard'); ?></th>
                                <th scope="col"><?php esc_html_e('Blocked', 'cod-guard'); ?></th>
                                <th scope="col" class="num"><?php esc_html_e('Refused', 'cod-guard'); ?></th>
                                <th scope="col" class="num"><?php esc_html_e('Completed', 'cod-guard'); ?></th>
                                <th scope="col" class="num"><?php esc_html_e('Refusal rate', 'cod-guard'); ?></th>
                                <th scope="col"><?php esc_html_e('Last refusal', 'cod-guard'); ?></th>
                                <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'cod-guard'); ?></span></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td class="drpm-blocked__who">
                                        <a href="<?php echo esc_url($row['url']); ?>"><?php echo esc_html($row['label'] ?: ($row['email'] ?: $row['phone'])); ?></a>
                                        <?php if ($row['guest']) : ?>
                                            <span class="drpm-tag" title="<?php esc_attr_e('No account: the link opens their orders. Exceptions for guests are set in Settings → Exceptions.', 'cod-guard'); ?>"><?php esc_html_e('Guest', 'cod-guard'); ?></span>
                                        <?php endif; ?>
                                        <span class="drpm-table__meta"><?php echo esc_html(implode(' · ', array_filter(array($row['label'] ? $row['email'] : '', $row['phone'])))); ?></span>
                                    </td>
                                    <td class="drpm-blocked__type">
                                        <span class="drpm-badge drpm-badge--<?php echo 'manual' === $row['type'] ? 'risk' : 'warn'; ?>"><?php echo esc_html($typeLabels[$row['type']]); ?></span>
                                        <span class="drpm-table__meta"><?php echo esc_html($sourceLabels[$row['source']]); ?></span>
                                    </td>
                                    <td class="num" data-label="<?php esc_attr_e('Refused', 'cod-guard'); ?>"><?php echo esc_html(number_format_i18n($row['refused'])); ?></td>
                                    <td class="num" data-label="<?php esc_attr_e('Completed', 'cod-guard'); ?>"><?php echo esc_html(number_format_i18n($row['delivered'])); ?></td>
                                    <td class="num" data-label="<?php esc_attr_e('Refusal rate', 'cod-guard'); ?>"><?php echo esc_html(number_format_i18n($row['rate'], 1)); ?>%</td>
                                    <td class="drpm-table__date" data-label="<?php esc_attr_e('Last refusal', 'cod-guard'); ?>"><?php echo esc_html($row['last_refusal'] ?: '—'); ?></td>
                                    <td class="drpm-table__actions">
                                        <?php if ('list' === $row['source']) : ?>
                                            <a href="<?php echo esc_url(DRPM_Settings::url('drpm_blocked_list')); ?>"><?php esc_html_e('Edit list', 'cod-guard'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php $this->pager($paged, self::BLOCKED_PER_PAGE, $matched, 'paged', array('tab' => 'blocked', 'type' => 'all' === $type ? null : $type, 's' => $search ?: null)); ?>
                    <?php endif; ?>
                </div>

                <p class="drpm-footnote">
                    <?php
                    echo esc_html(
                        ($months > 0
                            ? sprintf(
                                /* translators: %d: months */
                                __('Automatic = their refusals in the last %d months cross the rule in Settings. Manual = "Always block" on their profile or in Settings → Exceptions. Counts use the same matching as checkout.', 'cod-guard'),
                                $months
                            )
                            : __('Automatic = their refusals cross the rule in Settings. Manual = "Always block" on their profile or in Settings → Exceptions. Counts use the same matching as checkout.', 'cod-guard'))
                    );
                    ?>
                </p>
            <?php
        }

        /**
         * "Showing 11–20 of 34" plus previous / numbered / next links. Hidden when there's one page.
         *
         * @param array  $args   Query args to keep (null values are dropped).
         * @param string $anchor Element id to jump back to after the page loads.
         */
        private function pager($page, $perPage, $total, $param, array $args, $anchor = '')
        {
            $pages = (int) ceil($total / max(1, $perPage));
            if ($pages <= 1) {
                return;
            }
            $link = function ($n) use ($param, $args, $anchor) {
                $query = array_filter(array_merge($args, array($param => $n > 1 ? $n : null)), function ($v) {
                    return null !== $v && '' !== $v;
                });
                return esc_url(self::url($query) . ($anchor ? '#' . $anchor : ''));
            };

            // First, last, and two either side of the current page; gaps become "…".
            $numbers = array();
            for ($n = 1; $n <= $pages; $n++) {
                if (1 === $n || $pages === $n || abs($n - $page) <= 2) {
                    $numbers[] = $n;
                } elseif (end($numbers) !== '…') {
                    $numbers[] = '…';
                }
            }
            ?>
            <nav class="drpm-pager" aria-label="<?php esc_attr_e('Pages', 'cod-guard'); ?>">
                <span class="drpm-pager__summary">
                    <?php
                    $first = ($page - 1) * $perPage + 1;
                    echo esc_html(sprintf(
                        /* translators: 1: first row, 2: last row, 3: total rows */
                        __('%1$s–%2$s of %3$s', 'cod-guard'),
                        number_format_i18n($first),
                        number_format_i18n(min($total, $page * $perPage)),
                        number_format_i18n($total)
                    ));
                    ?>
                </span>
                <span class="drpm-pager__links">
                    <?php if ($page > 1) : ?>
                        <a class="drpm-pager__item" href="<?php echo $link($page - 1); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $link. ?>" rel="prev"><span aria-hidden="true">‹</span><span class="screen-reader-text"><?php esc_html_e('Previous page', 'cod-guard'); ?></span></a>
                    <?php endif; ?>
                    <?php foreach ($numbers as $n) : ?>
                        <?php if ('…' === $n) : ?>
                            <span class="drpm-pager__gap">…</span>
                        <?php elseif ($n === $page) : ?>
                            <span class="drpm-pager__item is-active" aria-current="page"><?php echo esc_html(number_format_i18n($n)); ?></span>
                        <?php else : ?>
                            <a class="drpm-pager__item" href="<?php echo $link($n); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $link. ?>"><?php echo esc_html(number_format_i18n($n)); ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($page < $pages) : ?>
                        <a class="drpm-pager__item" href="<?php echo $link($page + 1); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $link. ?>" rel="next"><span aria-hidden="true">›</span><span class="screen-reader-text"><?php esc_html_e('Next page', 'cod-guard'); ?></span></a>
                    <?php endif; ?>
                </span>
            </nav>
            <?php
        }

        /**
         * Change vs the previous period of the same length. More refusals is bad, so "up" is red.
         *
         * @param string $unit 'pp' (percentage points) or 'count'.
         */
        private function delta($current, $previous, $unit)
        {
            if (null === $previous) {
                return;
            }

            $diff = $current - $previous;
            if (abs($diff) < 0.05) {
                echo '<span class="drpm-delta drpm-delta--flat">' . esc_html__('No change vs previous period', 'cod-guard') . '</span>';
                return;
            }

            if ('pp' === $unit) {
                $text = sprintf('%s%s pp', $diff > 0 ? '+' : '−', number_format_i18n(abs($diff), 1));
            } elseif ($previous > 0) {
                $text = sprintf('%s%s%%', $diff > 0 ? '+' : '−', number_format_i18n(abs($diff) / $previous * 100, 0));
            } else {
                $text = sprintf('+%s', number_format_i18n($diff));
            }

            printf(
                '<span class="drpm-delta drpm-delta--%1$s"><span aria-hidden="true">%2$s</span> %3$s <span class="drpm-delta__vs">%4$s</span></span>',
                $diff > 0 ? 'bad' : 'good',
                $diff > 0 ? '▲' : '▼',
                esc_html($text),
                esc_html__('vs previous period', 'cod-guard')
            );
        }

        /**
         * Card with a JS-drawn chart and a data-table fallback.
         *
         * @param array<string,string> $columns Table heading => data key.
         */
        private function chartCard($title, $type, array $data, array $columns)
        {
            ?>
            <div class="drpm-card">
                <h2 class="drpm-card__title"><?php echo esc_html($title); ?></h2>
                <?php if (!$data) : ?>
                    <p class="drpm-empty"><?php esc_html_e('No orders in this period.', 'cod-guard'); ?></p>
                <?php else : ?>
                    <div class="drpm-chart" data-chart="<?php echo esc_attr($type); ?>" data-points="<?php echo esc_attr(wp_json_encode($data)); ?>" role="img" aria-label="<?php echo esc_attr($title); ?>"></div>
                    <details class="drpm-datatable">
                        <summary><?php esc_html_e('Show data table', 'cod-guard'); ?></summary>
                        <table class="drpm-table">
                            <thead><tr>
                                <?php foreach (array_keys($columns) as $heading) : ?>
                                    <th scope="col"><?php echo esc_html($heading); ?></th>
                                <?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($data as $row) : ?>
                                <tr>
                                    <?php foreach ($columns as $key) : ?>
                                        <td class="<?php echo 'label' === $key ? '' : 'num'; ?>"><?php echo esc_html('rate' === $key ? (null === $row[$key] ? '—' : number_format_i18n($row[$key], 1) . '%') : $row[$key]); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Horizontal bars, every value directly labelled (so no tooltip is needed).
         *
         * @param string $measure 'rate' or 'refused'.
         */
        private function bars(array $rows, $measure)
        {
            if (!$rows) {
                echo '<p class="drpm-empty">' . esc_html__('No refusals in this period.', 'cod-guard') . '</p>';
                return;
            }

            $max = max(array_column($rows, $measure)) ?: 1;
            echo '<ul class="drpm-bars">';
            foreach ($rows as $row) {
                $value = 'rate' === $measure
                    ? number_format_i18n($row['rate'], 1) . '%'
                    : number_format_i18n($row['refused']);
                $detail = 'rate' === $measure
                    ? sprintf(
                        /* translators: 1: refused, 2: attempts */
                        __('%1$s of %2$s', 'cod-guard'),
                        number_format_i18n($row['refused']),
                        number_format_i18n($row['refused'] + $row['delivered'])
                    )
                    : sprintf(
                        /* translators: %s: rate */
                        __('%s%% rate', 'cod-guard'),
                        number_format_i18n($row['rate'], 1)
                    );
                printf(
                    '<li class="drpm-bars__row"><span class="drpm-bars__label">%1$s</span><span class="drpm-bars__track"><span class="drpm-bars__bar" style="width:%2$s%%"></span></span><span class="drpm-bars__value">%3$s <span class="drpm-bars__detail">%4$s</span></span></li>',
                    esc_html($row['label']),
                    esc_attr(max(0.5, round($row[$measure] / $max * 100, 2))),
                    esc_html($value),
                    esc_html($detail)
                );
            }
            echo '</ul>';
        }
    }

    new DRPM_Dashboard();
}
