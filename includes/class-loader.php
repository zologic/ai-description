<?php
defined('ABSPATH') || exit;

class APDG_Loader {

    /**
     * Runtime boot (called on plugins_loaded)
     */
    public static function init(): void {

        // Only boot if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }

        self::load_core();
        self::load_generation();
        self::load_seo();
        self::load_monitoring();
        self::load_admin();

        // Boot runtime services (safe order)
        if (class_exists('APDG_Queue')) {
            APDG_Queue::boot();
        }

        if (class_exists('APDG_Health_Check')) {
            APDG_Health_Check::boot();
        }

        if (class_exists('APDG_Admin_UI')) {
            APDG_Admin_UI::boot();
        }

        if (class_exists('APDG_Ajax_Handlers')) {
            APDG_Ajax_Handlers::boot();
        }
    }

    /**
     * Activation hook
     * NOTE: init() does NOT run during activation
     */
    public static function activate(): void {

        if (!defined('APDG_INCLUDES')) {
            return;
        }

        // Load only what activation requires
        require_once APDG_INCLUDES . 'monitoring/class-audit-log.php';
        require_once APDG_INCLUDES . 'generation/class-queue.php';
        require_once APDG_INCLUDES . 'monitoring/class-health-check.php';

        // Create required tables
        if (class_exists('APDG_Audit_Log')) {
            APDG_Audit_Log::create_table();
        }

        if (class_exists('APDG_Queue')) {
            APDG_Queue::create_table();
        }

        // Default options (only added if not existing)
        add_option('apdg_groq_api_key', '');
        add_option('apdg_model', 'auto');
        add_option('apdg_daily_limit', 300);
        add_option('apdg_overwrite', 0);
        add_option('apdg_db_version', '6.0');

        // Schedule weekly health check
        if (!wp_next_scheduled('apdg_health_check')) {
            wp_schedule_event(time(), 'weekly', 'apdg_health_check');
        }
    }

    /**
     * Deactivation hook
     */
    public static function deactivate(): void {
        wp_clear_scheduled_hook('apdg_health_check');
    }

    /* =====================================================
       LOAD GROUPS (Runtime Only)
       ===================================================== */

    private static function load_core(): void {
        require_once APDG_INCLUDES . 'core/class-provider-registry.php';
        require_once APDG_INCLUDES . 'core/class-model-manager.php';
        require_once APDG_INCLUDES . 'core/class-fallback-manager.php';
    }

    private static function load_generation(): void {
        require_once APDG_INCLUDES . 'generation/class-prompt-builder.php';
        require_once APDG_INCLUDES . 'generation/class-generator.php';
        require_once APDG_INCLUDES . 'generation/class-queue.php';
    }

    private static function load_seo(): void {
        require_once APDG_INCLUDES . 'seo/class-similarity-checker.php';
    }

    private static function load_monitoring(): void {
        require_once APDG_INCLUDES . 'monitoring/class-audit-log.php';
        require_once APDG_INCLUDES . 'monitoring/class-health-check.php';
    }

    private static function load_admin(): void {
        require_once APDG_INCLUDES . 'admin/class-ajax-handlers.php';
        require_once APDG_INCLUDES . 'admin/class-admin-ui.php';
    }
}