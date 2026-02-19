<?php
defined('ABSPATH') || exit;

/**
 * APDG_Fallback_Manager
 *
 * Detects model deprecation / API errors and routes to a safe fallback.
 * Prevents silent production failures when Groq retires a model.
 */
class APDG_Fallback_Manager {

    // HTTP codes that indicate a model-level problem (not a network issue)
    private const MODEL_ERROR_CODES = [400, 404, 410];

    // Groq error message substrings that indicate model retirement
    private const DEPRECATION_SIGNALS = [
        'model not found',
        'model has been deprecated',
        'no longer available',
        'decommissioned',
        'does not exist',
        'invalid model',
    ];

    /**
     * Inspect API response. If it signals a model error:
     *   1. Log the failure
     *   2. Show admin notice
     *   3. Return the safe fallback model ID
     *
     * Returns null if the error is NOT model-related (rate limit, network, etc.)
     */
    public static function handle(int $http_code, array $body, string $model_attempted): ?string {
        if (!self::is_model_error($http_code, $body)) {
            return null;
        }

        $error_msg = $body['error']['message'] ?? 'unknown';
        $fallback  = APDG_Provider_Registry::get_model('fast'); // Always safe

        error_log("APDG Fallback: model '{$model_attempted}' failed ({$error_msg}). Switching to '{$fallback}'.");

        // Store flag so health check and admin notice can pick it up
        update_option('apdg_model_failure', [
            'model'    => $model_attempted,
            'reason'   => $error_msg,
            'fallback' => $fallback,
            'time'     => current_time('mysql'),
        ]);

        // Flip the setting to fast so it doesn't keep hitting the dead model
        if (get_option('apdg_model') === $model_attempted) {
            update_option('apdg_model', 'auto');
        }

        return $fallback;
    }

    private static function is_model_error(int $code, array $body): bool {
        if (in_array($code, self::MODEL_ERROR_CODES)) return true;

        $msg = strtolower($body['error']['message'] ?? '');
        foreach (self::DEPRECATION_SIGNALS as $signal) {
            if (str_contains($msg, $signal)) return true;
        }

        return false;
    }

    /**
     * Returns pending failure info for admin notices.
     * Clears after retrieval.
     */
    public static function get_pending_failure(): ?array {
        $failure = get_option('apdg_model_failure', null);
        if ($failure) delete_option('apdg_model_failure');
        return $failure ?: null;
    }
}
