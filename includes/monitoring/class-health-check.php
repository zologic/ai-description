<?php
defined('ABSPATH') || exit;

/**
 * APDG_Health_Check
 *
 * Weekly cron that pings each configured model with a minimal test request.
 * On failure: stores result, shows dismissible admin notice, logs to error_log.
 *
 * This prevents silent breakage when Groq deprecates a model between
 * your check of the docs and your next production run.
 */
class APDG_Health_Check {

    const CRON_HOOK       = 'apdg_health_check';
    const OPTION_RESULTS  = 'apdg_health_results';
    const OPTION_VERSION  = 'apdg_model_catalog_version';
    const CATALOG_VERSION = '2026.02'; // Bump when updating Provider Registry

    // Tiny prompt — just enough to get a valid JSON response
    const TEST_PROMPT = 'Geef alleen dit JSON terug zonder uitleg: {"ok": true}';

    public static function boot(): void {
        add_action(self::CRON_HOOK,           [__CLASS__, 'run']);
        add_action('admin_notices',           [__CLASS__, 'show_notices']);
        add_action('wp_ajax_apdg_dismiss_notice', [__CLASS__, 'ajax_dismiss']);
        add_action('wp_ajax_apdg_run_health_check', [__CLASS__, 'ajax_run_now']);
    }

    // ── Cron execution ────────────────────────────────────────────────────────

    public static function run(): void {
        $api_key  = get_option('apdg_groq_api_key', '');
        $provider = APDG_Provider_Registry::get('groq');
        $models   = APDG_Provider_Registry::all_current_models('groq');
        $results  = [];

        foreach ($models as $speed => $model_id) {
            $start  = microtime(true);
            $status = self::ping_model($api_key, $model_id, $provider);
            $ms     = (int) round((microtime(true) - $start) * 1000);

            $results[$model_id] = [
                'speed'    => $speed,
                'status'   => $status['ok'] ? 'ok' : 'fail',
                'reason'   => $status['reason'] ?? '',
                'ms'       => $ms,
                'checked'  => current_time('mysql'),
            ];

            error_log("APDG Health: {$model_id} → {$results[$model_id]['status']} ({$ms}ms)");
        }

        // Check catalog version — warn if stale
        $catalog_ok = self::check_catalog_version();

        update_option(self::OPTION_RESULTS, [
            'results'     => $results,
            'catalog_ok'  => $catalog_ok,
            'checked_at'  => current_time('mysql'),
        ]);

        // Store failures for admin notice
        $failures = array_filter($results, fn($r) => $r['status'] === 'fail');
        if (!empty($failures)) {
            update_option('apdg_health_notice_dismissed', 0);
        }
    }

    private static function ping_model(string $api_key, string $model_id, array $provider): array {
        if (empty($api_key)) return ['ok' => false, 'reason' => 'No API key configured'];

        $response = wp_remote_post($provider['base_url'], [
            'timeout' => 15,
            'headers' => [
                'Authorization' => $provider['auth_header'] . ' ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'      => $model_id,
                'messages'   => [['role' => 'user', 'content' => self::TEST_PROMPT]],
                'max_tokens' => 20,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'reason' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true) ?: [];

        if ($code === 200 && !empty($body['choices'])) {
            return ['ok' => true];
        }

        $reason = $body['error']['message'] ?? "HTTP {$code}";
        return ['ok' => false, 'reason' => $reason];
    }

    private static function check_catalog_version(): bool {
        $stored = get_option(self::OPTION_VERSION, '');
        if ($stored !== self::CATALOG_VERSION) {
            update_option(self::OPTION_VERSION, self::CATALOG_VERSION);
        }
        return $stored === self::CATALOG_VERSION;
    }

    // ── Admin notices ─────────────────────────────────────────────────────────

    public static function show_notices(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if (get_option('apdg_health_notice_dismissed', 0)) return;

        $data = get_option(self::OPTION_RESULTS, []);
        if (empty($data['results'])) return;

        $failures = array_filter($data['results'] ?? [], fn($r) => $r['status'] === 'fail');
        $pending  = APDG_Fallback_Manager::get_pending_failure();

        if (empty($failures) && !$pending) return;

        echo '<div class="notice notice-error is-dismissible" id="apdg-health-notice">';
        echo '<p><strong>⚠️ AI Descriptions — Model Health Issue</strong></p>';

        foreach ($failures as $model_id => $result) {
            echo '<p>Model <code>' . esc_html($model_id) . '</code> failed: ' . esc_html($result['reason']) . '</p>';
        }

        if ($pending) {
            echo '<p>Auto-fallback triggered: <code>' . esc_html($pending['model']) . '</code> → <code>' . esc_html($pending['fallback']) . '</code></p>';
            echo '<p>Reason: ' . esc_html($pending['reason']) . '</p>';
        }

        echo '<p>';
        echo '<a href="' . admin_url('admin.php?page=apdg-health') . '" class="button button-primary">View Health Report</a> ';
        echo '<button class="button" onclick="apdgDismissNotice()">Dismiss</button>';
        echo '</p>';
        echo '</div>';
        echo '<script>function apdgDismissNotice(){jQuery.post(ajaxurl,{action:"apdg_dismiss_notice",nonce:"' . wp_create_nonce('apdg_nonce') . '"});document.getElementById("apdg-health-notice").remove();}</script>';
    }

    public static function ajax_dismiss(): void {
        check_ajax_referer('apdg_nonce', 'nonce');
        update_option('apdg_health_notice_dismissed', 1);
        wp_send_json_success();
    }

    public static function ajax_run_now(): void {
        check_ajax_referer('apdg_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');
        self::run();
        wp_send_json_success(get_option(self::OPTION_RESULTS, []));
    }

    /**
     * Get stored results for display in health page.
     */
    public static function get_results(): array {
        return get_option(self::OPTION_RESULTS, [
            'results'    => [],
            'checked_at' => null,
        ]);
    }
}
