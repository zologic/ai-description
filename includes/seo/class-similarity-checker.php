<?php
defined('ABSPATH') || exit;

/**
 * APDG_Similarity_Checker
 *
 * Cosine similarity comparison for generated vs existing content.
 * Normalized: strips HTML, removes brand/title noise, Dutch stopwords.
 * Independent of generation — can be called from anywhere.
 *
 * Zones:
 *   block  >= 0.70  Hard block (content unchanged, no SEO value)
 *   warn   >= 0.60  Soft warning (review before saving)
 *   allow  <  0.60  Safe (meaningful improvement)
 */
class APDG_Similarity_Checker {

    private const DUTCH_STOPWORDS = [
        'de','het','een','van','in','is','dat','op','en','te','voor','met',
        'aan','er','maar','om','ook','als','dan','dit','bij','zo','al','niet',
        'zijn','was','worden','heeft','hebben','werd','kan','zal','meer',
        'door','over','naar','uit','nog','wel','of','want','hoe','wat',
        'wie','waar','we','ze','hij','zij','ik','u','onze','uw','hun',
        'elk','alle','elke','wordt','had',
    ];

    /**
     * @param string $existing  Current product description
     * @param string $generated New AI-generated description
     * @param array  $noise     Tokens to strip (brand name, product title words)
     * @return array ['score'=>float, 'zone'=>string, 'pct'=>int]
     */
    public static function compare(string $existing, string $generated, array $noise = []): array {
        $vec_a = self::vectorize($existing,  $noise);
        $vec_b = self::vectorize($generated, $noise);

        // Not enough content for meaningful comparison
        if (count($vec_a) < 5 || count($vec_b) < 5) {
            return ['score' => 0.0, 'zone' => 'allow', 'pct' => 0, 'note' => 'insufficient_length'];
        }

        $score = self::cosine($vec_a, $vec_b);
        $zone  = match(true) {
            $score >= 0.70 => 'block',
            $score >= 0.60 => 'warn',
            default        => 'allow',
        };

        return ['score' => $score, 'zone' => $zone, 'pct' => (int) round($score * 100)];
    }

    private static function vectorize(string $text, array $noise): array {
        // Strip HTML, decode entities, lowercase
        $text = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'), 'UTF-8');

        // Remove noise tokens (brand, title words)
        foreach ($noise as $token) {
            $t = mb_strtolower(trim($token), 'UTF-8');
            if (strlen($t) > 2) $text = str_replace($t, ' ', $text);
        }

        // Remove punctuation, numbers, non-alpha
        $text   = preg_replace('/[^a-z\x{00C0}-\x{024F}\s]/u', ' ', $text);
        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filter stopwords and short tokens
        $tokens = array_filter(
            $tokens,
            fn($t) => strlen($t) > 2 && !in_array($t, self::DUTCH_STOPWORDS)
        );

        // TF vector
        $counts = array_count_values($tokens);
        $total  = count($tokens) ?: 1;
        return array_map(fn($c) => $c / $total, $counts);
    }

    private static function cosine(array $vec_a, array $vec_b): float {
        $terms = array_unique(array_merge(array_keys($vec_a), array_keys($vec_b)));
        $dot = $mag_a = $mag_b = 0.0;

        foreach ($terms as $t) {
            $a = $vec_a[$t] ?? 0.0;
            $b = $vec_b[$t] ?? 0.0;
            $dot   += $a * $b;
            $mag_a += $a * $a;
            $mag_b += $b * $b;
        }

        if ($mag_a === 0.0 || $mag_b === 0.0) return 0.0;
        return round(min(1.0, $dot / (sqrt($mag_a) * sqrt($mag_b))), 4);
    }
}
