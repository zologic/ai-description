<?php
defined('ABSPATH') || exit;

/**
 * APDG_Ajax_Handlers
 *
 * Handles all wp_ajax_* actions.
 * Contains NO generation logic — delegates to Generator, Queue, Audit_Log.
 * Contains NO UI rendering — delegates to Admin_UI.
 */
class APDG_Ajax_Handlers {

    public static function boot(): void {
        $actions = [
            'apdg_generate_preview' => 'preview',
            'apdg_save_generated'   => 'save',
            'apdg_get_products'     => 'get_products',
            'apdg_toggle_lock'      => 'toggle_lock',
            'apdg_save_settings'    => 'save_settings',
            'apdg_export_audit'     => 'export_audit',
        ];
        foreach ($actions as $action => $method) {
            add_action('wp_ajax_' . $action, [__CLASS__, $method]);
        }
    }

    // ── Preview — generate but do NOT save ───────────────────────────────────

    public static function preview(): void {
        self::verify('edit_products');

        $pid     = absint($_POST['product_id'] ?? 0);
        $product = wc_get_product($pid);
        if (!$product) wp_send_json_error('Product niet gevonden');
        if (get_post_meta($pid, '_apdg_locked', true)) wp_send_json_error('Product is vergrendeld');

        $tier = sanitize_text_field($_POST['tier'] ?? 'mid');
        $mode = sanitize_text_field($_POST['mode'] ?? 'full');

        // Override brand if provided from product tab
        $brand = sanitize_text_field($_POST['brand'] ?? '');
        if ($brand) update_post_meta($pid, '_apdg_brand', $brand);

        $start  = microtime(true);
        $result = APDG_Generator::run($product, $tier, $mode);
        $ms     = (int) round((microtime(true) - $start) * 1000);

        if (is_wp_error($result)) {
            APDG_Audit_Log::write([
                'product_id'   => $pid, 'product_name' => $product->get_name(),
                'action' => 'rejected', 'tier' => $tier, 'mode' => $mode,
                'safety_passed' => 0, 'rejection_reason' => $result->get_error_message(),
                'response_time_ms' => $ms,
            ]);
            wp_send_json_error($result->get_error_message());
        }

        $original = [
            'short_description' => $product->get_short_description(),
            'long_description'  => $product->get_description(),
            'meta_description'  => get_post_meta($pid, '_yoast_wpseo_metadesc', true)
                                   ?: get_post_meta($pid, 'rank_math_description', true),
        ];

        // Similarity check
        $noise = array_merge(
            explode(' ', $product->get_name()),
            explode(' ', get_post_meta($pid, '_apdg_brand', true) ?: '')
        );
        $sim = APDG_Similarity_Checker::compare(
            $original['long_description'] ?? '',
            $result['long_description']   ?? '',
            $noise
        );

        APDG_Audit_Log::write([
            'product_id'      => $pid, 'product_name' => $product->get_name(),
            'action'          => 'previewed', 'tier' => $tier, 'mode' => $mode,
            'similarity_score'=> $sim['score'], 'similarity_zone' => $sim['zone'],
            'word_count_long' => str_word_count(strip_tags($result['long_description'] ?? '')),
            'word_count_short'=> str_word_count(strip_tags($result['short_description'] ?? '')),
            'safety_passed'   => 1, 'response_time_ms' => $ms,
        ]);

        wp_send_json_success([
            'generated'  => $result,
            'original'   => $original,
            'product_id' => $pid,
            'similarity' => $sim,
            'model_used' => APDG_Model_Manager::get_last_used($pid),
            'ms'         => $ms,
        ]);
    }

    // ── Save — human-approved, from preview diff ──────────────────────────────

    public static function save(): void {
        self::verify('edit_products');

        $pid     = absint($_POST['product_id'] ?? 0);
        $product = wc_get_product($pid);
        if (!$product) wp_send_json_error('Niet gevonden');
        if (get_post_meta($pid, '_apdg_locked', true)) wp_send_json_error('Vergrendeld');

        $content = [
            'short_description' => wp_kses_post(stripslashes($_POST['short_description'] ?? '')),
            'long_description'  => wp_kses_post(stripslashes($_POST['long_description']  ?? '')),
            'meta_description'  => sanitize_text_field(stripslashes($_POST['meta_description'] ?? '')),
        ];

        APDG_Generator::save($product, $content, sanitize_text_field(stripslashes($_POST['brand'] ?? '')));

        $sim_score = floatval($_POST['similarity_score'] ?? 0);
        $sim_zone  = sanitize_text_field($_POST['similarity_zone'] ?? 'allow');

        APDG_Audit_Log::write([
            'product_id'      => $pid, 'product_name' => $product->get_name(),
            'action'          => 'saved',
            'tier'            => sanitize_text_field($_POST['tier'] ?? 'mid'),
            'mode'            => sanitize_text_field($_POST['mode'] ?? 'full'),
            'similarity_score'=> $sim_score, 'similarity_zone' => $sim_zone,
            'word_count_long' => str_word_count(strip_tags($content['long_description'])),
            'word_count_short'=> str_word_count(strip_tags($content['short_description'])),
            'safety_passed'   => 1,
        ]);

        wp_send_json_success('Opgeslagen.');
    }

    // ── Product list for bulk UI ──────────────────────────────────────────────

    public static function get_products(): void {
        self::verify('manage_woocommerce');

        $filter   = sanitize_text_field($_POST['filter'] ?? 'all');
        $category = absint($_POST['category'] ?? 0);
        $args     = ['status' => 'publish', 'limit' => 200, 'return' => 'ids'];

        if ($category) {
            $term = get_term($category, 'product_cat');
            if ($term) $args['category'] = [$term->slug];
        }

        $ids = wc_get_products($args);
        $out = [];

        foreach ($ids as $id) {
            $p = wc_get_product($id);
            if (!$p) continue;

            $locked = (bool) get_post_meta($id, '_apdg_locked', true);
            $has    = !empty(trim(strip_tags($p->get_description())));
            $tier   = get_post_meta($id, '_apdg_tier', true) ?: 'mid';
            $last   = get_post_meta($id, '_apdg_last_generated', true);
            $cats   = wp_get_post_terms($id, 'product_cat', ['fields' => 'names']);
            $status = get_post_meta($id, '_apdg_ai_status', true) ?: '';
            $model  = APDG_Model_Manager::get_last_used($id);

            if ($filter === 'no_description' && $has)   continue;
            if ($filter === 'locked'   && !$locked)     continue;
            if ($filter === 'unlocked' && $locked)      continue;

            $out[] = [
                'id'       => $id,
                'name'     => $p->get_name(),
                'category' => $cats[0] ?? '-',
                'has_desc' => $has,
                'locked'   => $locked,
                'tier'     => $tier,
                'status'   => $status,
                'model'    => $model,
                'last_gen' => $last ? human_time_diff(strtotime($last)) . ' ago' : 'Never',
                'edit_url' => get_edit_post_link($id),
            ];
        }

        wp_send_json_success(['products' => $out]);
    }

    // ── Lock toggle ───────────────────────────────────────────────────────────

    public static function toggle_lock(): void {
        self::verify('edit_products');
        $pid = absint($_POST['product_id'] ?? 0);
        $new = !(bool) get_post_meta($pid, '_apdg_locked', true);
        update_post_meta($pid, '_apdg_locked', $new ? '1' : '');
        wp_send_json_success(['locked' => $new]);
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public static function save_settings(): void {
        self::verify('manage_woocommerce');
        update_option('apdg_groq_api_key', sanitize_text_field($_POST['api_key']    ?? ''));
        update_option('apdg_model',        sanitize_text_field($_POST['model']       ?? 'auto'));
        update_option('apdg_daily_limit',  absint($_POST['daily_limit']              ?? 300));
        update_option('apdg_overwrite',    (int)($_POST['overwrite']                 ?? 0));
        wp_send_json_success('Instellingen opgeslagen.');
    }

    // ── Audit CSV export ──────────────────────────────────────────────────────

    public static function export_audit(): void {
        check_ajax_referer('apdg_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_die('Permission denied');
        APDG_Audit_Log::export_csv();
        exit;
    }

    // ── Shared auth helper ────────────────────────────────────────────────────

    private static function verify(string $cap): void {
        check_ajax_referer('apdg_nonce', 'nonce');
        if (!current_user_can($cap)) wp_send_json_error('Permission denied');
    }
}
