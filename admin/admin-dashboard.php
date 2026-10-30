<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_Dashboard')) {
    /**
     * WooCommerce → Delivery refusals: the plugin's own page, with a Dashboard tab
     * (KPIs, trends and breakdowns of refused deliveries) and a Settings tab.
     */
    class DRPM_Dashboard
    {
        const PAGE = 'cod-guard';

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
            return isset($_GET['tab']) && 'settings' === $_GET['tab'] ? 'settings' : 'dashboard';
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
                'settings'  => __('Settings', 'cod-guard'),
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
                } else {
                    $this->renderDashboard();
                }
                ?>
            </div>
            <?php
        }

        private function renderDashboard()
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '12m';
            if (!in_array($range, DRPM_Stats::RANGES, true)) {
                $range = '12m';
            }
            $window = DRPM_Stats::window($range);

            $current = DRPM_Stats::totals($window['from'], $window['to']);
            $previous = $window['prev_from'] ? DRPM_Stats::totals($window['prev_from'], $window['from']) : null;
            $timeline = DRPM_Stats::timeline($window['from'], $window['to'], $window['granularity']);
            $methods = DRPM_Stats::byPaymentMethod($window['from'], $window['to']);
            $regions = DRPM_Stats::byRegion($window['from'], $window['to']);
            $products = DRPM_Stats::topProducts($window['from'], $window['to']);
            $refusers = DRPM_Stats::repeatRefusers($window['from'], $window['to']);

            $rangeLabels = array(
                '30d' => __('Last 30 days', 'cod-guard'),
                '90d' => __('Last 90 days', 'cod-guard'),
                '12m' => __('Last 12 months', 'cod-guard'),
                'all' => __('All time', 'cod-guard'),
            );
            $chartTitles = array(
                'day'   => array(__('Refused deliveries per day', 'cod-guard'), __('Refusal rate per day', 'cod-guard')),
                'week'  => array(__('Refused deliveries per week', 'cod-guard'), __('Refusal rate per week', 'cod-guard')),
                'month' => array(__('Refused deliveries per month', 'cod-guard'), __('Refusal rate per month', 'cod-guard')),
            )[$window['granularity']];
            $backfill = get_option('drpm_backfill_state', array());
            ?>
                <div class="drpm-dash__head">
                    <nav class="drpm-range" aria-label="<?php esc_attr_e('Date range', 'cod-guard'); ?>">
                        <?php foreach ($rangeLabels as $key => $label) : ?>
                            <a href="<?php echo esc_url(self::url(array('range' => $key))); ?>" class="drpm-range__item<?php echo $key === $range ? ' is-active' : ''; ?>" <?php echo $key === $range ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                        <?php endforeach; ?>
                    </nav>
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

                <section class="drpm-grid">
                    <div class="drpm-card">
                        <h2 class="drpm-card__title"><?php esc_html_e('Most refused products', 'cod-guard'); ?></h2>
                        <?php if (null === $products) : ?>
                            <p class="drpm-empty"><?php esc_html_e('Product data needs WooCommerce Analytics to be enabled.', 'cod-guard'); ?></p>
                        <?php elseif (!$products) : ?>
                            <p class="drpm-empty"><?php esc_html_e('No refused products in this period.', 'cod-guard'); ?></p>
                        <?php else : ?>
                            <table class="drpm-table">
                                <thead><tr>
                                    <th scope="col"><?php esc_html_e('Product', 'cod-guard'); ?></th>
                                    <th scope="col" class="num"><?php esc_html_e('Refused orders', 'cod-guard'); ?></th>
                                    <th scope="col" class="num"><?php esc_html_e('Refusal rate', 'cod-guard'); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($products as $product) : ?>
                                    <tr>
                                        <td><?php echo $product['url'] ? '<a href="' . esc_url($product['url']) . '">' . esc_html($product['label']) . '</a>' : esc_html($product['label']); ?></td>
                                        <td class="num"><?php echo esc_html(number_format_i18n($product['refused'])); ?></td>
                                        <td class="num"><?php echo esc_html(number_format_i18n($product['rate'], 1)); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="drpm-card">
                        <h2 class="drpm-card__title"><?php esc_html_e('Repeat refusers', 'cod-guard'); ?></h2>
                        <?php if (!$refusers) : ?>
                            <p class="drpm-empty"><?php esc_html_e('No customer refused more than once in this period.', 'cod-guard'); ?></p>
                        <?php else : ?>
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
                                        <td><?php echo esc_html($refuser['last_refusal']); ?></td>
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
