<?php
defined('ABSPATH') || exit;

/**
 * APDG_Model_Manager
 *
 * Resolves which model to use for a given product/tier.
 * Never contains API logic — only selection logic.
 */
class APDG_Model_Manager {

    public static function resolve(int $product_id = 0, string $tier = ''): string {
        $setting = get_option('apdg_model', 'auto');

        // Explicit override — user forced a specific model
        if ($setting !== 'auto') {
            // Safety net: if saved setting is a deprecated model, fall back
            if (APDG_Provider_Registry::is_deprecated($setting)) {
                error_log("APDG: Model {$setting} is deprecated. Falling back to auto.");
                return self::auto_resolve($tier ?: self::get_tier($product_id));
            }
            return $setting;
        }

        return self::auto_resolve($tier ?: self::get_tier($product_id));
    }

    private static function auto_resolve(string $tier): string {
        return match($tier) {
            'high'  => APDG_Provider_Registry::get_model('quality'),
            default => APDG_Provider_Registry::get_model('fast'),
        };
    }

    public static function get_tier(int $product_id): string {
        if (!$product_id) return 'mid';
        return get_post_meta($product_id, '_apdg_tier', true) ?: 'mid';
    }

    /**
     * Returns the model ID that was actually used for a product.
     * Stored in meta on save for audit trail.
     */
    public static function get_last_used(int $product_id): string {
        return get_post_meta($product_id, '_apdg_model_used', true) ?: '—';
    }

    public static function record_used(int $product_id, string $model): void {
        update_post_meta($product_id, '_apdg_model_used', $model);
    }
}
