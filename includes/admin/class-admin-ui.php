<?php
defined('ABSPATH') || exit;

/**
 * APDG_Admin_UI
 *
 * All WP admin rendering: menus, pages, product tab.
 * Contains NO generation logic. NO AJAX logic.
 * Delegates data queries to Audit_Log, Health_Check, Queue.
 */
class APDG_Admin_UI {

    public static function boot(): void {
        add_action('admin_menu',                    [__CLASS__, 'register_menus']);
        add_action('admin_enqueue_scripts',         [__CLASS__, 'enqueue_assets']);
        add_filter('woocommerce_product_data_tabs',   [__CLASS__, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'product_tab_panel']);
        add_action('admin_notices',                 [__CLASS__, 'fallback_notice']);
    }

    // ── Menus ─────────────────────────────────────────────────────────────────

    public static function register_menus(): void {
        add_menu_page('AI Descriptions', 'AI Descriptions', 'manage_woocommerce',
            'apdg-main', [__CLASS__, 'page_bulk'], 'dashicons-superhero', 58);

        $pages = [
            ['Bulk Queue',   'apdg-main',     'page_bulk'],
            ['Audit Log',    'apdg-audit',    'page_audit'],
            ['Health',       'apdg-health',   'page_health'],
            ['Settings',     'apdg-settings', 'page_settings'],
        ];

        foreach ($pages as [$label, $slug, $method]) {
            add_submenu_page('apdg-main', $label, $label, 'manage_woocommerce', $slug, [__CLASS__, $method]);
        }
    }

    public static function enqueue_assets(string $hook): void {
        $valid = [
            'toplevel_page_apdg-main',
            'ai-descriptions_page_apdg-audit',
            'ai-descriptions_page_apdg-health',
            'ai-descriptions_page_apdg-settings',
            'post.php', 'post-new.php',
        ];
        if (!in_array($hook, $valid)) return;

        wp_enqueue_style('apdg', APDG_PLUGIN_URL . 'assets/css/admin.css', [], APDG_VERSION);
        wp_enqueue_script('apdg', APDG_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], APDG_VERSION, true);
        wp_localize_script('apdg', 'apdg', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('apdg_nonce'),
        ]);
    }

    // ── Bulk Queue page ───────────────────────────────────────────────────────

    public static function page_bulk(): void {
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
        ?>
        <div class="wrap apdg-wrap">
        <h1>🤖 AI Descriptions — Bulk Queue</h1>

        <div class="apdg-top-row">
            <div class="apdg-card apdg-queue-status" id="apdg_queue_stats">
                <h3>Queue Status</h3>
                <div class="apdg-stat-row">
                    <span class="apdg-stat" id="stat_pending">— pending</span>
                    <span class="apdg-stat green" id="stat_complete">— complete</span>
                    <span class="apdg-stat orange" id="stat_generating">— processing</span>
                    <span class="apdg-stat red" id="stat_failed">— failed</span>
                </div>
                <div style="margin-top:8px; font-size:12px; color:#888;" id="stat_similarity_blocked"></div>
            </div>

            <div class="apdg-card">
                <h3>Rollout Phase Guide</h3>
                <div class="apdg-phases">
                    <div class="apdg-phase">Phase 1<br><strong>≤500</strong><br>Test category</div>
                    <div class="apdg-phase">Phase 2<br><strong>≤5k</strong><br>Top categories</div>
                    <div class="apdg-phase">Phase 3<br><strong>47k</strong><br>Full catalog</div>
                </div>
                <p style="font-size:12px;color:#888;">Monitor Search Console 14 days between phases.</p>
            </div>
        </div>

        <div class="apdg-card" style="margin-top:16px;">
            <div class="apdg-filter-row">
                <select id="apdg_filter">
                    <option value="all">All products</option>
                    <option value="no_description">Missing description</option>
                    <option value="unlocked">Unlocked only</option>
                    <option value="locked">Locked only</option>
                </select>
                <select id="apdg_category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?> (<?php echo $cat->count; ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button class="button" id="apdg_load_btn">Load Products</button>
                <button class="button button-primary" id="apdg_queue_btn" disabled>Queue Selected</button>
                <button class="button" id="apdg_cancel_btn">Cancel Queue</button>
            </div>

            <div id="apdg_product_table_wrap" style="display:none; margin-top:12px;">
                <table class="wp-list-table widefat fixed striped" id="apdg_product_table">
                    <thead><tr>
                        <th style="width:30px;"><input type="checkbox" id="apdg_select_all"></th>
                        <th>Product</th>
                        <th style="width:100px;">Category</th>
                        <th style="width:60px;">Tier</th>
                        <th style="width:70px;">Model</th>
                        <th style="width:70px;">Status</th>
                        <th style="width:70px;">Last Gen</th>
                        <th style="width:60px;">Actions</th>
                    </tr></thead>
                    <tbody id="apdg_product_tbody"></tbody>
                </table>
            </div>

            <div id="apdg_queue_log" style="display:none; margin-top:16px;">
                <h4>Queue Log</h4>
                <div id="apdg_log_output" style="background:#f6f7f7; padding:10px; height:200px; overflow-y:auto; font-family:monospace; font-size:12px;"></div>
            </div>
        </div>
        </div>
        <?php
    }

    // ── Audit page ────────────────────────────────────────────────────────────

    public static function page_audit(): void {
        $action_f = sanitize_text_field($_GET['action_filter'] ?? '');
        $zone_f   = sanitize_text_field($_GET['zone_filter'] ?? '');
        $page_n   = max(1, absint($_GET['paged'] ?? 1));
        $data     = APDG_Audit_Log::get_rows(['action' => $action_f, 'zone' => $zone_f], 50, $page_n);
        $stats    = APDG_Audit_Log::get_summary();
        ?>
        <div class="wrap apdg-wrap">
        <h1>📊 Audit Log</h1>

        <div class="apdg-audit-stats">
            <?php
            $cards = [
                ['total',       'Total events',          '#2271b1', ''],
                ['saved',       'Saved',                 '#2e7d32', 'green'],
                ['blocked_sim', 'Similarity blocked',    '#f57f17', 'orange'],
                ['rejected',    'Safety rejected',       '#c62828', 'red'],
                ['avg_sim',     'Avg similarity %',      '#555',    ''],
            ];
            foreach ($cards as [$key, $label, $color, $cls]):
                $val = $key === 'avg_sim' ? ($stats[$key] ?? '—') . '%' : ($stats[$key] ?? 0);
            ?>
            <div class="apdg-stat-card <?php echo $cls; ?>">
                <span class="apdg-stat-num" style="color:<?php echo $color; ?>"><?php echo esc_html($val); ?></span>
                <span class="apdg-stat-lbl"><?php echo esc_html($label); ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="apdg-similarity-zones">
            <?php
            $zones = [
                'allow' => ['✅ Allow', $stats['zone_allow'] ?? 0, 'allow'],
                'warn'  => ['⚠️ Warn',  $stats['zone_warn']  ?? 0, 'warn'],
                'block' => ['🚫 Block', $stats['zone_block'] ?? 0, 'block'],
            ];
            $total_zones = max(1, ($stats['zone_allow'] ?? 0) + ($stats['zone_warn'] ?? 0) + ($stats['zone_block'] ?? 0));
            foreach ($zones as [$label, $count, $cls]):
                $pct = round($count / $total_zones * 100);
            ?>
            <div class="apdg-zone-bar <?php echo $cls; ?>" style="flex:<?php echo max(1, $count); ?>"><?php echo esc_html($label); ?>: <?php echo $count; ?> (<?php echo $pct; ?>%)</div>
            <?php endforeach; ?>
        </div>

        <div class="apdg-filter-row" style="margin:14px 0; display:flex; gap:10px; align-items:center;">
            <form method="get" style="display:flex; gap:8px; align-items:center;">
                <input type="hidden" name="page" value="apdg-audit">
                <select name="action_filter">
                    <option value="">All actions</option>
                    <?php foreach (['saved','previewed','blocked_similarity','rejected'] as $a): ?>
                    <option value="<?php echo $a; ?>" <?php selected($action_f, $a); ?>><?php echo esc_html(ucfirst(str_replace('_',' ',$a))); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="zone_filter">
                    <option value="">All zones</option>
                    <?php foreach (['allow','warn','block'] as $z): ?>
                    <option value="<?php echo $z; ?>" <?php selected($zone_f, $z); ?>><?php echo ucfirst($z); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button">Filter</button>
            </form>
            <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=apdg_export_audit'), 'apdg_nonce', 'nonce'); ?>" class="button">⬇ Export CSV</a>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th style="width:140px;">Time</th>
                <th>Product</th>
                <th style="width:80px;">Action</th>
                <th style="width:50px;">Tier</th>
                <th style="width:90px;">Similarity</th>
                <th style="width:80px;">Model</th>
                <th style="width:50px;">Words</th>
                <th style="width:55px;">ms</th>
                <th>Rejection</th>
            </tr></thead>
            <tbody>
            <?php if (empty($data['rows'])): ?>
                <tr><td colspan="9" style="text-align:center;padding:20px;color:#888;">No events yet.</td></tr>
            <?php else: foreach ($data['rows'] as $r):
                $zone_color = match($r['similarity_zone'] ?? '') { 'block' => '#dc3232', 'warn' => '#dba617', default => '#2e7d32' };
                $icon = match($r['action']) { 'saved'=>'✅', 'previewed'=>'👁', 'blocked_similarity'=>'🚫', 'rejected'=>'❌', default=>'•' };
            ?>
                <tr>
                    <td style="font-size:11px;"><?php echo esc_html($r['generated_at']); ?></td>
                    <td><a href="<?php echo get_edit_post_link($r['product_id']); ?>" target="_blank"><?php echo esc_html($r['product_name']); ?></a></td>
                    <td><?php echo $icon; ?> <?php echo esc_html($r['action']); ?></td>
                    <td><span class="apdg-badge <?php echo esc_attr($r['tier']); ?>"><?php echo esc_html($r['tier']); ?></span></td>
                    <td>
                        <?php if ($r['similarity_score'] !== null): ?>
                        <span style="color:<?php echo $zone_color; ?>;font-weight:600;"><?php echo round($r['similarity_score']*100); ?>%</span>
                        <span style="font-size:11px;color:#888;">(<?php echo esc_html($r['similarity_zone']); ?>)</span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="font-size:11px;font-family:monospace;"><?php echo esc_html(basename($r['model_used'] ?? '—')); ?></td>
                    <td style="font-size:11px;"><?php echo (int)$r['word_count_long']; ?>w</td>
                    <td style="font-size:11px;"><?php echo $r['response_time_ms'] ? $r['response_time_ms'].'ms' : '—'; ?></td>
                    <td style="font-size:11px;color:#888;"><?php echo esc_html($r['rejection_reason'] ?? ''); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php
        $total_pages = ceil($data['total'] / 50);
        if ($total_pages > 1) {
            echo '<div style="margin-top:16px;">' . paginate_links(['base'=>add_query_arg('paged','%#%'),'total'=>$total_pages,'current'=>$page_n]) . '</div>';
        }
        ?>
        </div>
        <?php
    }

    // ── Health page ───────────────────────────────────────────────────────────

    public static function page_health(): void {
        $health = APDG_Health_Check::get_results();
        $catalog = APDG_Provider_Registry::all_current_models('groq');
        ?>
        <div class="wrap apdg-wrap">
        <h1>🩺 Model Health</h1>

        <div class="apdg-card">
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
                <h3 style="margin:0;">Live Model Status</h3>
                <?php if ($health['checked_at'] ?? false): ?>
                <span style="font-size:12px;color:#888;">Last checked: <?php echo human_time_diff(strtotime($health['checked_at'])); ?> ago</span>
                <?php endif; ?>
                <button class="button" id="apdg_run_health">🔄 Run Check Now</button>
            </div>

            <table class="wp-list-table widefat fixed">
                <thead><tr><th>Model ID</th><th style="width:80px;">Speed</th><th style="width:80px;">Status</th><th style="width:80px;">Latency</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($catalog as $speed => $model_id):
                    $r = ($health['results'] ?? [])[$model_id] ?? null;
                    $status_html = $r ? ($r['status']==='ok' ? '<span style="color:#2e7d32;font-weight:600;">✅ OK</span>' : '<span style="color:#dc3232;font-weight:600;">❌ Fail</span>') : '<span style="color:#aaa;">Not checked</span>';
                ?>
                <tr>
                    <td><code><?php echo esc_html($model_id); ?></code></td>
                    <td><?php echo esc_html($speed); ?></td>
                    <td><?php echo $status_html; ?></td>
                    <td><?php echo $r ? $r['ms'].'ms' : '—'; ?></td>
                    <td style="font-size:12px;color:#888;"><?php echo esc_html($r['reason'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="apdg-card" style="margin-top:16px;">
            <h3>Deprecated Models (do not use)</h3>
            <table class="wp-list-table widefat fixed">
                <thead><tr><th>Model ID</th><th>Shutdown date</th></tr></thead>
                <tbody>
                <?php foreach (APDG_Provider_Registry::get('groq')['deprecated'] as $model => $note): ?>
                <tr><td><code style="color:#dc3232;"><?php echo esc_html($model); ?></code></td><td style="color:#888;"><?php echo esc_html($note); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="apdg-card" style="margin-top:16px;">
            <h3>Model Catalog Version</h3>
            <p>Plugin catalog: <code><?php echo APDG_Health_Check::CATALOG_VERSION; ?></code></p>
            <p style="font-size:12px;color:#888;">Update <code>APDG_Provider_Registry</code> and bump <code>CATALOG_VERSION</code> when Groq announces deprecations.</p>
            <p><a href="https://console.groq.com/docs/deprecations" target="_blank" class="button">📋 Check Groq Deprecations</a></p>
        </div>
        </div>

        <script>
        jQuery('#apdg_run_health').on('click', function() {
            var $btn = jQuery(this).text('Checking…').prop('disabled', true);
            jQuery.post(apdg.ajax_url, {action: 'apdg_run_health_check', nonce: apdg.nonce}, function() {
                location.reload();
            }).always(function() { $btn.prop('disabled', false); });
        });
        </script>
        <?php
    }

    // ── Settings page ─────────────────────────────────────────────────────────

    public static function page_settings(): void {
        $key   = get_option('apdg_groq_api_key', '');
        $model = get_option('apdg_model', 'auto');
        $limit = get_option('apdg_daily_limit', 300);
        $ow    = get_option('apdg_overwrite', 0);
        ?>
        <div class="wrap apdg-wrap"><h1>⚙️ Settings</h1>

        <div class="apdg-card" style="border-left:4px solid #2271b1;margin-bottom:20px;">
            <h3 style="margin-top:0;">📋 Available Models (verified <?php echo APDG_Health_Check::CATALOG_VERSION; ?>)</h3>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <tr style="background:#f6f7f7;font-weight:600;"><td style="padding:8px;">Model ID</td><td style="padding:8px;">Speed tier</td><td style="padding:8px;">Use case</td></tr>
                <?php foreach (APDG_Provider_Registry::all_current_models('groq') as $speed => $id): ?>
                <tr <?php echo $speed==='fast'?'':'style="background:#f6f7f7"'; ?>>
                    <td style="padding:8px;font-family:monospace;"><?php echo esc_html($id); ?></td>
                    <td style="padding:8px;"><?php echo esc_html($speed); ?></td>
                    <td style="padding:8px;"><?php echo $speed==='fast' ? 'Bulk / mid / low tier' : 'High tier / manual review'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="apdg-card">
        <table class="form-table">
            <tr><th>Groq API Key</th><td>
                <input type="password" id="s_key" value="<?php echo esc_attr($key); ?>" style="width:400px;">
                <p class="description"><a href="https://console.groq.com" target="_blank">console.groq.com</a> — free key, no credit card</p>
            </td></tr>
            <tr><th>Model Selection</th><td>
                <select id="s_model">
                    <?php foreach (APDG_Provider_Registry::get_ui_options() as $val => $label): ?>
                    <option value="<?php echo esc_attr($val); ?>" <?php selected($model, $val); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Auto uses 8B for Mid/Low (throughput) and 70B for High tier (quality).</p>
            </td></tr>
            <tr><th>Daily Request Limit</th><td>
                <input type="number" id="s_dlimit" value="<?php echo esc_attr($limit); ?>" style="width:100px;" min="10" max="1000">
                <p class="description">Groq free tier: ~300/day safe. Plugin adds 3s delay. Max 30 RPM.</p>
            </td></tr>
            <tr><th>Overwrite Existing</th><td>
                <label><input type="checkbox" id="s_ow" value="1" <?php checked($ow, 1); ?>> Overwrite products with existing descriptions</label>
                <p class="description">⚠️ Keep OFF during initial rollout. Similarity gate also blocks if ≥70% similar.</p>
            </td></tr>
        </table>
        <p><button class="button button-primary" id="apdg_save_settings">💾 Opslaan</button>
           <span id="s_msg" style="margin-left:10px;color:green;display:none;"></span></p>
        </div>

        <div class="apdg-card" style="margin-top:20px;"><h3>🛡 Active Governance Rules</h3>
        <ul style="list-style:disc;padding-left:20px;line-height:2;margin:0;">
            <li><strong>Provider abstraction:</strong> Model IDs in APDG_Provider_Registry only. One file to update on deprecation.</li>
            <li><strong>Auto-fallback:</strong> 404/410 from Groq → auto-switch to fast model, admin notice triggered.</li>
            <li><strong>Weekly health check:</strong> Pings each live model. Fails trigger admin notice.</li>
            <li><strong>Similarity gate:</strong> &lt;60% allow · 60–70% warn · &gt;70% block. Normalized (brand + title stripped).</li>
            <li><strong>EU compliance filter:</strong> 30+ blocked terms covering beauty/health claims.</li>
            <li><strong>Action Scheduler queue:</strong> Background processing, 3s apart, no timeout risk.</li>
            <li><strong>Audit DB table:</strong> Every event logged with model, similarity, response time.</li>
        </ul></div>
        </div>
        <?php
    }

    // ── Product tab ───────────────────────────────────────────────────────────

    public static function add_product_tab(array $tabs): array {
        $tabs['apdg'] = ['label' => '🤖 AI Description', 'target' => 'apdg_data', 'class' => [], 'priority' => 80];
        return $tabs;
    }

    public static function product_tab_panel(): void {
        global $post;
        $pid    = $post->ID;
        $locked = (bool) get_post_meta($pid, '_apdg_locked', true);
        $tier   = get_post_meta($pid, '_apdg_tier', true) ?: 'mid';
        $last   = get_post_meta($pid, '_apdg_last_generated', true);
        $model  = APDG_Model_Manager::get_last_used($pid);
        $sim    = get_post_meta($pid, '_apdg_last_similarity', true);
        ?>
        <div id="apdg_data" class="panel woocommerce_options_panel">
        <div class="apdg-product-panel">

            <div class="apdg-panel-header">
                <h3>🤖 AI Description</h3>
                <?php if ($last): ?>
                <span class="apdg-last-gen">
                    Last: <?php echo human_time_diff(strtotime($last)); ?> ago
                    <?php if ($model): ?> · <code style="font-size:11px;"><?php echo esc_html(basename($model)); ?></code><?php endif; ?>
                    <?php if ($sim !== ''): ?> · sim: <?php echo round($sim * 100); ?>%<?php endif; ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if ($locked): ?>
            <div class="apdg-lock-notice">🔒 <strong>Vergrendeld</strong> — ontgrendel om opnieuw te genereren.</div>
            <?php endif; ?>

            <div class="apdg-row">
                <label>Merk:</label>
                <input type="text" id="apdg_brand" value="<?php echo esc_attr(get_post_meta($pid, '_apdg_brand', true)); ?>" placeholder="bv. L'Oréal, Nike…" style="width:200px;">
            </div>
            <div class="apdg-row">
                <label>Tier:</label>
                <select id="apdg_tier">
                    <option value="low"  <?php selected($tier,'low'); ?>>Low  — 120–180w · 8B</option>
                    <option value="mid"  <?php selected($tier,'mid'); ?>>Mid  — 200–350w · 8B</option>
                    <option value="high" <?php selected($tier,'high'); ?>>High — 350–550w · 70B</option>
                </select>
            </div>
            <div class="apdg-row">
                <label>Mode:</label>
                <select id="apdg_mode">
                    <option value="full">Full (short + long + meta)</option>
                    <option value="short_only">Short only</option>
                    <option value="meta_only">Meta only</option>
                </select>
            </div>
            <div class="apdg-row">
                <button class="button button-primary" id="apdg_generate_btn" <?php echo $locked ? 'disabled' : ''; ?> data-pid="<?php echo $pid; ?>">
                    ⚡ Generate Preview
                </button>
                <button class="button" id="apdg_lock_btn" data-pid="<?php echo $pid; ?>" data-locked="<?php echo $locked ? '1' : '0'; ?>">
                    <?php echo $locked ? '🔓 Unlock' : '🔒 Lock'; ?>
                </button>
                <span id="apdg_spinner" style="display:none; margin-left:8px;">Generating…</span>
            </div>

            <div id="apdg_panel_error" style="display:none; color:#dc3232; margin-top:8px;"></div>
            <div id="apdg_similarity_badge" style="display:none; margin:10px 0;"></div>

            <div id="apdg_preview_panel" style="display:none; margin-top:16px;">
                <div class="apdg-diff-tabs">
                    <button class="apdg-tab active" data-tab="short">Short</button>
                    <button class="apdg-tab" data-tab="long">Long</button>
                    <button class="apdg-tab" data-tab="meta">Meta</button>
                </div>
                <?php foreach (['short','long','meta'] as $f): ?>
                <div class="apdg-tab-panel" id="tab_<?php echo $f; ?>" style="display:<?php echo $f==='short'?'block':'none'; ?>">
                    <div class="apdg-diff-grid">
                        <div class="apdg-diff-col">
                            <div class="apdg-diff-label">Current</div>
                            <div id="apdg_orig_<?php echo $f; ?>" class="apdg-diff-content"></div>
                        </div>
                        <div class="apdg-diff-col">
                            <div class="apdg-diff-label">Generated <span style="font-size:11px;color:#888;">(editable)</span></div>
                            <div id="apdg_gen_<?php echo $f; ?>" class="apdg-diff-content apdg-editable" contenteditable="true"></div>
                        </div>
                    </div>
                    <?php if ($f === 'meta'): ?>
                    <div style="font-size:12px;margin-top:4px;">Characters: <span id="apdg_meta_chars">0</span>/155</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <div style="margin-top:12px;">
                    <button class="button button-primary" id="apdg_save_btn" data-pid="<?php echo $pid; ?>">💾 Approve &amp; Save</button>
                    <button class="button" id="apdg_discard_btn">Discard</button>
                </div>
            </div>

        </div>
        </div>
        <?php
    }

    // ── Admin notice for fallback events ──────────────────────────────────────

    public static function fallback_notice(): void {
        // This is called separately from Health_Check::show_notices to avoid duplication
        // Health_Check handles the main notice rendering
    }
}
