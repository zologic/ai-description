<?php
defined('ABSPATH') || exit;

/**
 * APDG_Audit_Log
 *
 * Structured audit trail in a dedicated DB table.
 * NOT wp_options — that serializes everything into one row,
 * becomes unqueryable at 47k SKUs.
 *
 * Schema columns:
 *   id, product_id, product_name, action, tier, mode,
 *   model_used, similarity_score, similarity_zone,
 *   word_count_long, word_count_short, safety_passed,
 *   rejection_reason, response_time_ms, generated_at, saved_by
 */
class APDG_Audit_Log {

    const TABLE_VERSION = '6.0';

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'apdg_audit';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            product_id        BIGINT UNSIGNED  NOT NULL,
            product_name      VARCHAR(255)     NOT NULL DEFAULT '',
            action            VARCHAR(32)      NOT NULL DEFAULT 'generated',
            tier              VARCHAR(8)       NOT NULL DEFAULT 'mid',
            mode              VARCHAR(16)      NOT NULL DEFAULT 'full',
            model_used        VARCHAR(80)      DEFAULT NULL,
            similarity_score  DECIMAL(5,4)     DEFAULT NULL,
            similarity_zone   VARCHAR(8)       DEFAULT NULL,
            word_count_long   SMALLINT UNSIGNED DEFAULT 0,
            word_count_short  SMALLINT UNSIGNED DEFAULT 0,
            safety_passed     TINYINT(1)       NOT NULL DEFAULT 1,
            rejection_reason  VARCHAR(512)     DEFAULT NULL,
            response_time_ms  SMALLINT UNSIGNED DEFAULT NULL,
            generated_at      DATETIME         NOT NULL,
            saved_by          BIGINT UNSIGNED  DEFAULT NULL,
            PRIMARY KEY (id),
            KEY product_id   (product_id),
            KEY generated_at (generated_at),
            KEY action       (action),
            KEY similarity_zone (similarity_zone)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('apdg_audit_db_version', self::TABLE_VERSION);
    }

    /**
     * Write an audit entry.
     *
     * @param array $entry  Associative array matching table columns.
     */
    public static function write(array $entry): void {
        global $wpdb;

        $row = array_merge([
            'generated_at' => current_time('mysql'),
            'saved_by'     => get_current_user_id(),
            'action'       => 'generated',
            'model_used'   => APDG_Model_Manager::get_last_used($entry['product_id'] ?? 0),
        ], $entry);

        // Also write lightweight per-product meta for fast UI reads
        if (in_array($row['action'], ['saved', 'blocked_similarity'])) {
            update_post_meta($row['product_id'], '_apdg_last_generated',    current_time('mysql'));
            update_post_meta($row['product_id'], '_apdg_last_similarity',   $row['similarity_score'] ?? '');
            update_post_meta($row['product_id'], '_apdg_last_action',       $row['action']);
        }

        $wpdb->insert(
            $wpdb->prefix . 'apdg_audit',
            $row,
            ['%s','%s','%s','%s','%s','%s','%f','%s','%d','%d','%d','%s','%d','%s','%d']
        );
    }

    /**
     * Summary stats for audit dashboard.
     */
    public static function get_summary(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'apdg_audit';

        $row = $wpdb->get_row("
            SELECT
                COUNT(*)                        AS total,
                SUM(action='saved')             AS saved,
                SUM(action='blocked_similarity') AS blocked_sim,
                SUM(action='rejected')          AS rejected,
                SUM(action='previewed')         AS previewed,
                ROUND(AVG(similarity_score)*100, 1) AS avg_sim,
                SUM(similarity_zone='block')    AS zone_block,
                SUM(similarity_zone='warn')     AS zone_warn,
                SUM(similarity_zone='allow')    AS zone_allow,
                ROUND(AVG(response_time_ms))    AS avg_response_ms
            FROM {$table}
        ", ARRAY_A);

        return $row ?: [];
    }

    /**
     * Paginated rows with optional filters.
     */
    public static function get_rows(array $filters = [], int $per_page = 50, int $page = 1): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'apdg_audit';
        $where  = 'WHERE 1=1';
        $offset = ($page - 1) * $per_page;

        if (!empty($filters['action'])) {
            $where .= $wpdb->prepare(' AND action = %s', $filters['action']);
        }
        if (!empty($filters['zone'])) {
            $where .= $wpdb->prepare(' AND similarity_zone = %s', $filters['zone']);
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}");
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} {$where} ORDER BY generated_at DESC LIMIT %d OFFSET %d", $per_page, $offset),
            ARRAY_A
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * CSV export — last 5000 rows.
     * Sends headers and outputs directly. Call exit after.
     */
    public static function export_csv(): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}apdg_audit ORDER BY generated_at DESC LIMIT 5000",
            ARRAY_A
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="apdg-audit-' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) fputcsv($out, $row);
        }
        fclose($out);
    }
}
