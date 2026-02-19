<?php
defined('ABSPATH') || exit;

/**
 * APDG_Queue
 *
 * Background bulk processing via Action Scheduler (ships with WooCommerce).
 * This is the correct approach for 47k SKUs — not a JS loop hitting AJAX.
 *
 * Flow:
 *   1. User clicks "Queue Batch" in admin UI
 *   2. AJAX handler calls APDG_Queue::enqueue_batch($product_ids)
 *   3. Each product gets an async Action Scheduler job
 *   4. WooCommerce runs them in background (3s apart, no timeout risk)
 *   5. Results tracked in apdg_queue table
 *   6. Dashboard shows live progress
 */
class APDG_Queue {

    const ACTION_SINGLE  = 'apdg_process_product';
    const ACTION_STATUS  = 'apdg_ajax_queue_status';
    const BATCH_DELAY_S  = 3; // Seconds between jobs — safe for Groq free ~30 RPM

    public static function boot(): void {
        add_action(self::ACTION_SINGLE, [__CLASS__, 'process_product'], 10, 1);
        add_action('wp_ajax_apdg_queue_batch',  [__CLASS__, 'ajax_enqueue_batch']);
        add_action('wp_ajax_apdg_queue_status', [__CLASS__, 'ajax_queue_status']);
        add_action('wp_ajax_apdg_queue_cancel', [__CLASS__, 'ajax_cancel_queue']);
    }

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'apdg_queue';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id  BIGINT UNSIGNED NOT NULL,
            tier        VARCHAR(8)  NOT NULL DEFAULT 'mid',
            status      VARCHAR(16) NOT NULL DEFAULT 'pending',
            queued_at   DATETIME    NOT NULL,
            started_at  DATETIME    DEFAULT NULL,
            finished_at DATETIME    DEFAULT NULL,
            result      VARCHAR(32) DEFAULT NULL,
            error_msg   VARCHAR(512) DEFAULT NULL,
            model_used  VARCHAR(64)  DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY product_id (product_id),
            KEY status (status),
            KEY queued_at (queued_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ── Enqueue batch ─────────────────────────────────────────────────────────

    public static function ajax_enqueue_batch(): void {
        check_ajax_referer('apdg_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

        $product_ids = array_map('absint', (array)($_POST['product_ids'] ?? []));
        if (empty($product_ids)) wp_send_json_error('No product IDs provided');

        // Respect daily limit — count jobs already queued today
        $daily_limit  = (int) get_option('apdg_daily_limit', 300);
        $queued_today = self::count_queued_today();
        $available    = max(0, $daily_limit - $queued_today);

        if ($available === 0) {
            wp_send_json_error("Daglimiet bereikt ({$daily_limit}). Probeer morgen opnieuw.");
        }

        $product_ids = array_slice($product_ids, 0, $available);
        $enqueued    = 0;
        $skipped     = 0;

        foreach ($product_ids as $i => $pid) {
            // Check lock and existing queue entry
            if (get_post_meta($pid, '_apdg_locked', true)) { $skipped++; continue; }
            if (!get_option('apdg_overwrite', 0)) {
                $p = wc_get_product($pid);
                if ($p && !empty(trim(strip_tags($p->get_description())))) { $skipped++; continue; }
            }

            // Add to queue table
            self::upsert_queue_row($pid, 'pending');

            // Schedule Action Scheduler job — staggered by 3s each
            if (function_exists('as_enqueue_async_action')) {
                as_schedule_single_action(
                    time() + ($i * self::BATCH_DELAY_S),
                    self::ACTION_SINGLE,
                    [['product_id' => $pid]],
                    'apdg'
                );
            } else {
                // Fallback: WP Cron (less reliable but works without AS)
                wp_schedule_single_event(
                    time() + ($i * self::BATCH_DELAY_S),
                    self::ACTION_SINGLE,
                    [['product_id' => $pid]]
                );
            }

            $enqueued++;
        }

        wp_send_json_success([
            'enqueued' => $enqueued,
            'skipped'  => $skipped,
            'message'  => "{$enqueued} producten in wachtrij geplaatst. Verwerking op achtergrond gestart.",
        ]);
    }

    // ── Process single product (called by AS/cron) ────────────────────────────

    public static function process_product(array $args): void {
        $pid     = absint($args['product_id'] ?? 0);
        $product = wc_get_product($pid);

        if (!$product) {
            self::update_queue_row($pid, 'failed', 'Product niet gevonden');
            return;
        }

        // Processing lock — prevent double-run if cron overlaps
        if (get_post_meta($pid, '_apdg_generating', true)) {
            return; // Another job is already running this product
        }
        update_post_meta($pid, '_apdg_generating', 1);
        self::update_queue_row($pid, 'generating', null, ['started_at' => current_time('mysql')]);

        try {
            $tier   = APDG_Model_Manager::get_tier($pid);
            $result = APDG_Generator::run($product, $tier, 'full');

            if (is_wp_error($result)) {
                self::update_queue_row($pid, 'failed', $result->get_error_message());
                APDG_Audit_Log::write([
                    'product_id'   => $pid,
                    'product_name' => $product->get_name(),
                    'action'       => 'rejected',
                    'tier'         => $tier,
                    'mode'         => 'full',
                    'safety_passed'=> 0,
                    'rejection_reason' => $result->get_error_message(),
                ]);
                return;
            }

            // Similarity gate
            $existing = $product->get_description();
            if (!empty($existing)) {
                $noise = array_merge(
                    explode(' ', $product->get_name()),
                    explode(' ', get_post_meta($pid, '_apdg_brand', true) ?: '')
                );
                $sim = APDG_Similarity_Checker::compare($existing, $result['long_description'] ?? '', $noise);

                if ($sim['zone'] === 'block') {
                    $reason = "Similariteit te hoog ({$sim['pct']}%) — bestaande tekst behouden";
                    self::update_queue_row($pid, 'skipped_similarity', $reason);
                    APDG_Audit_Log::write([
                        'product_id'      => $pid,
                        'product_name'    => $product->get_name(),
                        'action'          => 'blocked_similarity',
                        'tier'            => $tier,
                        'similarity_score'=> $sim['score'],
                        'similarity_zone' => $sim['zone'],
                        'rejection_reason'=> $reason,
                    ]);
                    return;
                }
            } else {
                $sim = ['score' => 0.0, 'zone' => 'allow', 'pct' => 0];
            }

            // Save
            APDG_Generator::save($product, $result);
            $model_used = APDG_Model_Manager::get_last_used($pid);
            self::update_queue_row($pid, 'complete', null, [
                'finished_at' => current_time('mysql'),
                'result'      => 'saved',
                'model_used'  => $model_used,
            ]);

            APDG_Audit_Log::write([
                'product_id'      => $pid,
                'product_name'    => $product->get_name(),
                'action'          => 'saved',
                'tier'            => $tier,
                'mode'            => 'full',
                'similarity_score'=> $sim['score'],
                'similarity_zone' => $sim['zone'],
                'word_count_long' => str_word_count(strip_tags($result['long_description'] ?? '')),
                'word_count_short'=> str_word_count(strip_tags($result['short_description'] ?? '')),
                'safety_passed'   => 1,
            ]);

        } finally {
            delete_post_meta($pid, '_apdg_generating'); // Always release lock
        }
    }

    // ── Status / cancellation ─────────────────────────────────────────────────

    public static function ajax_queue_status(): void {
        check_ajax_referer('apdg_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

        global $wpdb;
        $table = $wpdb->prefix . 'apdg_queue';
        $stats = $wpdb->get_results("
            SELECT status, COUNT(*) as count
            FROM {$table}
            WHERE queued_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY status
        ", ARRAY_A);

        $totals = ['pending' => 0, 'generating' => 0, 'complete' => 0, 'failed' => 0, 'skipped_similarity' => 0];
        foreach ($stats as $row) {
            $totals[$row['status']] = (int) $row['count'];
        }

        $recent = $wpdb->get_results(
            "SELECT product_id, status, result, error_msg, model_used, finished_at
             FROM {$table}
             ORDER BY queued_at DESC LIMIT 20",
            ARRAY_A
        );

        wp_send_json_success(['totals' => $totals, 'recent' => $recent]);
    }

    public static function ajax_cancel_queue(): void {
        check_ajax_referer('apdg_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION_SINGLE, [], 'apdg');
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'apdg_queue',
            ['status' => 'cancelled'],
            ['status' => 'pending']
        );

        wp_send_json_success('Wachtrij geannuleerd.');
    }

    // ── DB helpers ────────────────────────────────────────────────────────────

    private static function upsert_queue_row(int $pid, string $status): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}apdg_queue (product_id, tier, status, queued_at)
             VALUES (%d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE status = %s, queued_at = %s",
            $pid,
            APDG_Model_Manager::get_tier($pid),
            $status,
            current_time('mysql'),
            $status,
            current_time('mysql')
        ));
    }

    private static function update_queue_row(int $pid, string $status, ?string $error = null, array $extra = []): void {
        global $wpdb;
        $data = array_merge(['status' => $status], $extra);
        if ($error) $data['error_msg'] = $error;
        $wpdb->update($wpdb->prefix . 'apdg_queue', $data, ['product_id' => $pid]);
    }

    private static function count_queued_today(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}apdg_queue WHERE queued_at >= CURDATE()"
        );
    }
}
