<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('DRPM_CLI')) {
    /**
     * Manage COD Guard.
     */
    class DRPM_CLI
    {
        /**
         * Rebuilds the order index synchronously.
         *
         * ## EXAMPLES
         *
         *     wp cod-guard reindex
         */
        public function reindex()
        {
            DRPM_Index::install();
            $total = 0;
            $page = 1;
            do {
                $processed = DRPM_Index::indexPage($page++);
                $total += $processed;
                WP_CLI::log(sprintf('Indexed %d orders...', $total));
            } while ($processed >= DRPM_Index::BATCH_SIZE);

            update_option('drpm_backfill_state', array(
                'status'    => 'complete',
                'processed' => $total,
                'started'   => time(),
                'finished'  => time(),
            ), false);
            delete_option('drpm_needs_backfill');

            WP_CLI::success(sprintf('Indexed %d orders.', $total));
        }

        /**
         * Shows the refusal risk for an order's customer.
         *
         * ## OPTIONS
         *
         * <order_id>
         * : The order ID.
         *
         * [--format=<format>]
         * : table or json.
         * ---
         * default: table
         * ---
         *
         * ## EXAMPLES
         *
         *     wp cod-guard check 123
         */
        public function check($args, $assocArgs)
        {
            $order = wc_get_order((int) $args[0]);
            if (!$order) {
                WP_CLI::error('Order not found.');
            }

            $risk = DRPM_Risk::evaluateOrder($order);
            $risk['refused_ids'] = implode(',', $risk['refused_ids']);
            $risk['checked_fields'] = implode(',', $risk['checked_fields']);
            $risk['at_risk'] = $risk['at_risk'] ? 'yes' : 'no';

            WP_CLI\Utils\format_items($assocArgs['format'], array($risk), array_keys($risk));
        }
    }

    WP_CLI::add_command('cod-guard', 'DRPM_CLI');
}
