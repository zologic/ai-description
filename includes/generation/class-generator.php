<?php
defined('ABSPATH') || exit;

/**
 * APDG_Generator
 *
 * Handles single-product API call + response parsing + safety validation.
 * Does NOT handle bulk, queueing, or UI.
 * Uses Provider Registry for endpoints, Model Manager for model selection,
 * Fallback Manager for automatic recovery from deprecated models.
 */
class APDG_Generator {

    // Safety blocklist — hard block before saving
    private const BLOCKED_TERMS = [
        '100%', 'garandeert', 'klinisch bewezen', 'geneest', 'therapeutisch',
        'medisch bewezen', 'anti-aging effect', 'anti-aging werking',
        'dermatologisch getest', 'clinically proven', 'guaranteed', 'miracle',
        'revolutionair', 'beste prijs', 'koop nu', 'vandaag besteld',
        'op voorraad', 'nummer 1', '100% effectief',
        // EU beauty/health compliance
        'vermindert rimpels', 'behandelt acne', 'herstelt beschadigde huid',
        'voorkomt haaruitval', 'stimuleert haargroei', 'verwijdert pigmentvlekken',
        'geneest psoriasis', 'verlicht eczeem', 'anti-cellulitis', 'vetverbrandend',
        'versnelt de stofwisseling', 'detoxificeert', 'zuivert het bloed',
    ];

    private const QUALITY_FLOOR = [
        'short_min_words' => 30,
        'long_min_words'  => ['high' => 200, 'mid' => 100, 'low' => 60],
        'meta_min_chars'  => 80,
        'meta_max_chars'  => 165,
    ];

    /**
     * Generate content for a product.
     *
     * @param WC_Product $product
     * @param string     $tier   high|mid|low
     * @param string     $mode   full|short_only|meta_only
     * @return array|WP_Error   Cleaned content fields or error
     */
    public static function run(WC_Product $product, string $tier = 'mid', string $mode = 'full') {
        $api_key = get_option('apdg_groq_api_key', '');
        if (empty($api_key)) return new WP_Error('no_api_key', 'Groq API key niet ingesteld.');

        $product_data = APDG_Prompt_Builder::extract_product_data($product);
        $model        = APDG_Model_Manager::resolve($product->get_id(), $tier);
        $provider     = APDG_Provider_Registry::get('groq');

        $result = self::call_api($api_key, $model, $product_data, $tier, $mode, $provider);

        // Auto-fallback if model is deprecated/invalid
        if (is_wp_error($result) && $result->get_error_code() === 'model_error') {
            $fallback = APDG_Fallback_Manager::handle(
                $result->get_error_data('http_code') ?? 0,
                $result->get_error_data('body') ?? [],
                $model
            );
            if ($fallback && $fallback !== $model) {
                $result = self::call_api($api_key, $fallback, $product_data, $tier, $mode, $provider);
                if (!is_wp_error($result)) {
                    $model = $fallback; // Record what was actually used
                }
            }
        }

        if (is_wp_error($result)) return $result;

        // Record which model was actually used (for audit trail)
        APDG_Model_Manager::record_used($product->get_id(), $model);

        return self::validate($result, $tier);
    }

    /**
     * Calculate appropriate max_tokens based on tier and mode.
     *
     * @param string $tier high|mid|low
     * @param string $mode full|short_only|meta_only
     * @return int Token limit appropriate for tier and mode
     */
    private static function get_max_tokens_for_tier(string $tier, string $mode = 'full'): int {
        // Mode-specific optimization for reduced-scope generation
        if ($mode === 'short_only') {
            return 600; // Short description only: ~90 words + system prompt + buffer
        }
        if ($mode === 'meta_only') {
            return 300; // Meta description only: ~155 chars + system prompt + buffer
        }

        // Tier-based limits for full mode
        // Calculations based on:
        // - Dutch text: ~1.5-2 tokens per word
        // - HTML tags: ~100 tokens
        // - System prompt: ~200 tokens
        // - Safety buffer: ~200 tokens per tier
        switch ($tier) {
            case 'high':
                // 350-550 words long_description + 90 words short + meta + HTML + buffer
                return 1800;

            case 'mid':
                // 200-350 words long_description + 90 words short + meta + HTML + buffer
                return 1400;

            case 'low':
                // 120-180 words long_description + 90 words short + meta + HTML + buffer
                return 1000;

            default:
                // Unknown tier - default to mid-tier value and log warning
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[APDG] Unknown tier '{$tier}', defaulting to mid (1400 tokens)");
                }
                return 1400;
        }
    }

    private static function call_api(
        string $api_key,
        string $model,
        array  $product_data,
        string $tier,
        string $mode,
        array  $provider
    ) {
        $response = wp_remote_post($provider['base_url'], [
            'timeout' => $provider['timeout'],
            'headers' => [
                'Authorization' => $provider['auth_header'] . ' ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'       => $model,
                'messages'    => [
                    ['role' => 'system', 'content' => APDG_Prompt_Builder::system()],
                    ['role' => 'user',   'content' => APDG_Prompt_Builder::user($product_data, $tier, $mode)],
                ],
                'temperature' => 0.35,
                'max_tokens'  => self::get_max_tokens_for_tier($tier, $mode),
            ]),
        ]);

        if (is_wp_error($response)) return $response;

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true) ?: [];

        if ($code === 429) {
            return new WP_Error('rate_limit', 'Groq rate limit bereikt. Wacht even en probeer opnieuw.');
        }

        if (isset($body['error']) || in_array($code, [400, 404, 410])) {
            $err = new WP_Error('model_error', $body['error']['message'] ?? "HTTP {$code}");
            $err->add_data(['http_code' => $code, 'body' => $body], 'model_error');
            return $err;
        }

        // Step 1: Extract and log raw response
        $raw = $body['choices'][0]['message']['content'] ?? '';

        if (empty($raw)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[APDG] Empty API response - no content returned');
            }
            return new WP_Error('empty_response', 'API returned empty response');
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $max_tokens_used = self::get_max_tokens_for_tier($tier, $mode);
            error_log("[APDG] Raw API response length: " . strlen($raw) . " chars | max_tokens: {$max_tokens_used}");
            error_log("[APDG] Response preview: " . substr($raw, 0, 500));
        }

        // Step 2: Clean markdown code fences
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $raw);
        if ($cleaned !== $raw && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[APDG] Stripped markdown code fences from response');
        }

        // Step 2.5: Remove unescaped control characters that cause JSON_ERROR_CTRL_CHAR
        // Control characters (0x00-0x1F, 0x7F) must be escaped in JSON strings
        // Replace tabs/newlines/carriage returns with spaces, remove others
        $cleaned = preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            function($matches) {
                $char = $matches[0];
                return match(ord($char)) {
                    9 => ' ',    // tab -> space
                    10 => ' ',   // newline -> space
                    13 => '',    // carriage return -> remove
                    default => '', // other control chars -> remove
                };
            },
            $cleaned
        );
        if (defined('WP_DEBUG') && WP_DEBUG && $cleaned !== preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $raw)) {
            error_log('[APDG] Cleaned control characters from response');
        }

        // Step 3: First parse attempt
        $parsed = json_decode(trim($cleaned), true);
        $json_error = json_last_error();
        $json_error_msg = json_last_error_msg();

        if ($parsed !== null && $json_error === JSON_ERROR_NONE) {
            // Success on first attempt
            return $parsed;
        }

        // Step 4: JSON repair attempt
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[APDG] First JSON parse failed: {$json_error_msg} (Code: {$json_error})");
            error_log('[APDG] Attempting JSON repair...');
        }

        $repaired = self::attempt_json_repair($cleaned);
        $parsed = json_decode($repaired, true);
        $json_error = json_last_error();

        if ($parsed !== null && $json_error === JSON_ERROR_NONE) {
            // Repair succeeded
            return $parsed;
        }

        // Step 5: Regex fallback parser
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[APDG] JSON repair failed, trying regex fallback...');
        }

        preg_match('/\{.*\}/s', $cleaned, $m);
        if (!empty($m[0])) {
            $parsed = json_decode($m[0], true);
            $json_error = json_last_error();

            if ($parsed !== null && $json_error === JSON_ERROR_NONE) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[APDG] Warning: Used regex fallback parser successfully');
                }
                return $parsed;
            }
        }

        // Step 6: All parsing attempts failed - enhanced error logging
        $json_error_msg = json_last_error_msg();
        $json_error_code = json_last_error();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[APDG] JSON parse FAILED - all strategies exhausted');
            error_log("[APDG] JSON Error: {$json_error_msg} (Code: {$json_error_code})");
            error_log("[APDG] Tier: {$tier} | Mode: {$mode} | Model: {$model}");
            error_log("[APDG] Raw response (" . strlen($raw) . " chars): {$raw}");
        }

        $error = new WP_Error(
            'json_parse_error',
            "JSON parsing failed: {$json_error_msg}. Response: " . substr($raw, 0, 300)
        );

        $error->add_data([
            'raw_response' => $raw,
            'json_error' => $json_error_msg,
            'json_error_code' => $json_error_code,
            'tier' => $tier,
            'mode' => $mode,
            'model' => $model,
        ], 'json_parse_error');

        return $error;
    }

    /**
     * Attempt to repair common JSON formatting/truncation issues.
     *
     * @param string $json_string Potentially malformed JSON
     * @return string Repaired JSON if successful, original string otherwise
     */
    private static function attempt_json_repair(string $json_string): string {
        $json_string = trim($json_string);

        if (empty($json_string)) {
            return $json_string;
        }

        // Fast path: If already valid, return immediately
        $test = json_decode($json_string, true);
        if ($test !== null && json_last_error() === JSON_ERROR_NONE) {
            return $json_string;
        }

        // Strategy 1: Fix missing closing braces
        $open_braces  = substr_count($json_string, '{');
        $close_braces = substr_count($json_string, '}');
        $open_brackets  = substr_count($json_string, '[');
        $close_brackets = substr_count($json_string, ']');

        if ($open_braces > $close_braces || $open_brackets > $close_brackets) {
            $repaired = $json_string;
            $repaired .= str_repeat(']', $open_brackets - $close_brackets);
            $repaired .= str_repeat('}', $open_braces - $close_braces);

            $test = json_decode($repaired, true);
            if ($test !== null && json_last_error() === JSON_ERROR_NONE) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[APDG] JSON repair succeeded: Fixed missing closing braces/brackets');
                }
                return $repaired;
            }
        }

        // Strategy 2: Close truncated string values and add missing braces
        if (!str_ends_with($json_string, '}') && !str_ends_with($json_string, '"')) {
            // String was likely cut off mid-value
            $repaired = $json_string . '"';

            // Now add missing closing braces
            $open_braces  = substr_count($repaired, '{');
            $close_braces = substr_count($repaired, '}');
            $repaired .= str_repeat('}', $open_braces - $close_braces);

            $test = json_decode($repaired, true);
            if ($test !== null && json_last_error() === JSON_ERROR_NONE) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[APDG] JSON repair succeeded: Closed truncated string');
                }
                return $repaired;
            }
        }

        // Strategy 3: Remove trailing commas before closing braces/brackets
        $repaired = preg_replace('/,\s*([}\]])/', '$1', $json_string);
        if ($repaired !== $json_string) {
            $test = json_decode($repaired, true);
            if ($test !== null && json_last_error() === JSON_ERROR_NONE) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[APDG] JSON repair succeeded: Removed trailing commas');
                }
                return $repaired;
            }
        }

        // Strategy 4: Fix unescaped quotes in Dutch text (conservative approach)
        // Look for patterns like: "text"middle_quote"text" and escape middle quote
        $repaired = preg_replace_callback(
            '/"([^"]*)"([^",:\}\]]*)"/',
            function($matches) {
                // Only fix if middle section doesn't look like a key/value separator
                if (!str_contains($matches[2], ':') && strlen($matches[2]) < 10) {
                    return '"' . $matches[1] . '\\"' . $matches[2] . '"';
                }
                return $matches[0];
            },
            $json_string
        );

        if ($repaired !== $json_string) {
            $test = json_decode($repaired, true);
            if ($test !== null && json_last_error() === JSON_ERROR_NONE) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[APDG] JSON repair succeeded: Fixed unescaped quotes');
                }
                return $repaired;
            }
        }

        // All repair strategies failed
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[APDG] JSON repair failed: All strategies exhausted');
        }

        return $json_string; // Return original
    }

    private static function validate(array $data, string $tier) {
        // Safety blocklist
        $all_text = strtolower(implode(' ', array_map('strip_tags', array_filter($data))));
        $hits     = array_filter(self::BLOCKED_TERMS, fn($t) => str_contains($all_text, strtolower($t)));
        if (!empty($hits)) {
            return new WP_Error('safety_violation', 'Geblokkeerde termen: ' . implode(', ', array_values($hits)));
        }

        // Quality floor
        $errors = [];
        if (!empty($data['short_description'])) {
            $w = str_word_count(strip_tags($data['short_description']));
            if ($w < self::QUALITY_FLOOR['short_min_words']) {
                $errors[] = "Korte beschrijving te kort ({$w} woorden)";
            }
        }
        if (!empty($data['long_description'])) {
            $w   = str_word_count(strip_tags($data['long_description']));
            $min = self::QUALITY_FLOOR['long_min_words'][$tier] ?? 100;
            if ($w < $min)  $errors[] = "Lange beschrijving te kort ({$w}w, min {$min})";
            if (!str_contains($data['long_description'], '<h3>')) $errors[] = 'Geen <h3>-koppen gevonden';
        }
        if (!empty($data['meta_description'])) {
            $l = mb_strlen(strip_tags($data['meta_description']));
            if ($l < self::QUALITY_FLOOR['meta_min_chars']) $errors[] = "Meta te kort ({$l} tekens)";
            if ($l > self::QUALITY_FLOOR['meta_max_chars']) $errors[] = "Meta te lang ({$l} tekens)";
        }
        if (!empty($errors)) return new WP_Error('quality_floor', implode(' | ', $errors));

        return [
            'short_description' => isset($data['short_description']) ? wp_kses_post($data['short_description']) : null,
            'long_description'  => isset($data['long_description'])  ? wp_kses_post($data['long_description'])  : null,
            'meta_description'  => isset($data['meta_description'])  ? sanitize_text_field($data['meta_description']) : null,
        ];
    }

    /**
     * Save generated content to a WC product.
     * Separated from generation so preview flow can call these independently.
     */
    public static function save(WC_Product $product, array $content, string $brand = ''): void {
        $pid = $product->get_id();

        if (!empty($content['short_description'])) $product->set_short_description($content['short_description']);
        if (!empty($content['long_description']))  $product->set_description($content['long_description']);
        $product->save();

        if (!empty($content['meta_description'])) {
            update_post_meta($pid, '_yoast_wpseo_metadesc',  $content['meta_description']);
            update_post_meta($pid, 'rank_math_description',  $content['meta_description']);
        }
        if (!empty($brand)) update_post_meta($pid, '_apdg_brand', $brand);

        update_post_meta($pid, '_apdg_last_generated', current_time('mysql'));
        update_post_meta($pid, '_apdg_ai_status',      'complete');
    }
}
