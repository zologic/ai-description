<?php
defined('ABSPATH') || exit;

/**
 * APDG_Provider_Registry
 *
 * Single source of truth for provider config.
 * When Groq deprecates a model: update here only.
 * When adding a new provider: add a new key here only.
 */
class APDG_Provider_Registry {

    /**
     * Active provider config.
     * 'fast'    → used for bulk / mid / low tier
     * 'quality' → used for high tier manual review
     *
     * Last verified: February 2026
     * Source: https://console.groq.com/docs/deprecations
     */
    private static array $providers = [
        'groq' => [
            'base_url'   => 'https://api.groq.com/openai/v1/chat/completions',
            'auth_header' => 'Bearer',
            'timeout'    => 30,
            'models'     => [
                'fast'    => 'llama-3.1-8b-instant',       // ~30 RPM free, fastest
                'quality' => 'llama-3.3-70b-versatile',    // ~30 RPM free, best output
            ],
            'deprecated' => [
                // Keep for reference — these will return 404/400 if used
                'mixtral-8x7b-32768'  => 'Shutdown 2025-03-20',
                'llama3-8b-8192'      => 'Shutdown 2025-08-30',
                'llama3-70b-8192'     => 'Shutdown 2025-08-30',
                'gemma-7b-it'         => 'Shutdown 2024-12-18',
                'gemma2-9b-it'        => 'Shutdown 2025-10-08',
            ],
        ],
    ];

    public static function get(string $provider = 'groq'): array {
        return self::$providers[$provider] ?? self::$providers['groq'];
    }

    public static function get_model(string $speed = 'fast', string $provider = 'groq'): string {
        return self::$providers[$provider]['models'][$speed] ?? self::$providers['groq']['models']['fast'];
    }

    public static function is_deprecated(string $model_id, string $provider = 'groq'): bool {
        return isset(self::$providers[$provider]['deprecated'][$model_id]);
    }

    public static function all_current_models(string $provider = 'groq'): array {
        return self::$providers[$provider]['models'] ?? [];
    }

    /**
     * Returns list of models available for settings UI.
     * Only valid, non-deprecated models.
     */
    public static function get_ui_options(): array {
        return [
            'auto'                    => '🤖 Auto — 8B voor bulk, 70B voor High tier (aanbevolen)',
            'llama-3.1-8b-instant'   => 'llama-3.1-8b-instant — Snelst, alle tiers',
            'llama-3.3-70b-versatile' => 'llama-3.3-70b-versatile — Beste kwaliteit, alle tiers',
            'qwen/qwen3-32b'          => 'qwen/qwen3-32b — Sterk meertalig alternatief',
        ];
    }
}
