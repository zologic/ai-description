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
                'max_tokens'  => 2000,
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

        $raw     = $body['choices'][0]['message']['content'] ?? '';
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $raw);
        $parsed  = json_decode(trim($cleaned), true);

        if (!$parsed) {
            preg_match('/\{.*\}/s', $cleaned, $m);
            $parsed = json_decode($m[0] ?? '', true);
        }

        if (!$parsed) {
            return new WP_Error('parse_error', 'Kon API-respons niet verwerken: ' . substr($raw, 0, 200));
        }

        return $parsed;
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
