/* APDG Admin JS v6 */
jQuery(function ($) {
    'use strict';

    // ── Product tab: Generate preview ─────────────────────────────────────────

    var currentSim = {};

    $('#apdg_generate_btn').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        var pid  = $btn.data('pid');
        $('#apdg_spinner').show();
        $('#apdg_panel_error').hide();
        $('#apdg_preview_panel').hide();
        $('#apdg_similarity_badge').hide();

        $.post(apdg.ajax_url, {
            action:     'apdg_generate_preview',
            nonce:      apdg.nonce,
            product_id: pid,
            tier:       $('#apdg_tier').val(),
            mode:       $('#apdg_mode').val(),
            brand:      $('#apdg_brand').val(),
        }, function (r) {
            $btn.prop('disabled', false);
            $('#apdg_spinner').hide();

            if (!r.success) {
                $('#apdg_panel_error').text('❌ ' + r.data).show();
                return;
            }

            var g = r.data.generated, o = r.data.original;
            currentSim = r.data.similarity || {};

            // Similarity badge
            var colors = { allow: '#2e7d32', warn: '#dba617', block: '#dc3232' };
            var msgs   = {
                allow: '✅ ' + currentSim.pct + '% gelijkenis — veilig om op te slaan',
                warn:  '⚠️ ' + currentSim.pct + '% gelijkenis — controleer voor opslaan',
                block: '🚫 ' + currentSim.pct + '% gelijkenis — te hoog, opslaan geblokkeerd',
            };
            var zone = currentSim.zone || 'allow';
            $('#apdg_similarity_badge')
                .css({ background: colors[zone], color: '#fff', padding: '6px 12px',
                       borderRadius: '4px', fontSize: '13px', fontWeight: '600', display: 'inline-block' })
                .text(msgs[zone]).show();

            // Model + response time info
            if (r.data.model_used || r.data.ms) {
                var info = [];
                if (r.data.model_used) info.push('Model: ' + r.data.model_used.split('/').pop());
                if (r.data.ms)        info.push(r.data.ms + 'ms');
                $('<div style="font-size:12px;color:#888;margin-top:4px;">' + info.join(' · ') + '</div>')
                    .insertAfter('#apdg_similarity_badge');
            }

            // Block save if similarity too high
            $('#apdg_save_btn')
                .prop('disabled', zone === 'block')
                .attr('data-sim-score', currentSim.score || 0)
                .attr('data-sim-zone', zone);

            // Populate diff
            var empty = '<em style="color:#aaa;">— leeg —</em>';
            $('#apdg_orig_short').html(o.short_description || empty);
            $('#apdg_orig_long') .html(o.long_description  || empty);
            $('#apdg_orig_meta') .text(o.meta_description  || '— leeg —');
            $('#apdg_gen_short') .html(g.short_description || '');
            $('#apdg_gen_long')  .html(g.long_description  || '');
            $('#apdg_gen_meta')  .text(g.meta_description  || '');
            updateMetaCount();

            $('#apdg_preview_panel').show();
            $('.apdg-tab[data-tab="short"]').click();

        }).fail(function () {
            $btn.prop('disabled', false);
            $('#apdg_spinner').hide();
            $('#apdg_panel_error').text('❌ Verbindingsfout. Probeer opnieuw.').show();
        });
    });

    // ── Product tab: Approve & Save ───────────────────────────────────────────

    $('#apdg_save_btn').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Opslaan…');
        var pid  = $btn.data('pid');

        $.post(apdg.ajax_url, {
            action:            'apdg_save_generated',
            nonce:             apdg.nonce,
            product_id:        pid,
            short_description: $('#apdg_gen_short').html(),
            long_description:  $('#apdg_gen_long').html(),
            meta_description:  $('#apdg_gen_meta').text(),
            brand:             $('#apdg_brand').val(),
            tier:              $('#apdg_tier').val(),
            mode:              $('#apdg_mode').val(),
            similarity_score:  $btn.attr('data-sim-score') || 0,
            similarity_zone:   $btn.attr('data-sim-zone') || 'allow',
        }, function (r) {
            $btn.prop('disabled', false).text('💾 Approve & Save');
            if (r.success) {
                $('#apdg_preview_panel').hide();
                $('#apdg_similarity_badge')
                    .html('✅ Opgeslagen! <a href="#" onclick="location.reload(); return false;" style="color:#fff;text-decoration:underline;margin-left:8px;">Pagina verversen</a>')
                    .css({ background: '#2e7d32' })
                    .show();

                // Auto-refresh after 2 seconds to show changes in WooCommerce fields
                setTimeout(function() {
                    location.reload();
                }, 2000);
            } else {
                alert('Fout: ' + r.data);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('💾 Approve & Save');
            alert('❌ Verbindingsfout. Probeer opnieuw.');
        });
    });

    // ── Product tab: Discard ──────────────────────────────────────────────────

    $('#apdg_discard_btn').on('click', function () {
        $('#apdg_preview_panel').hide();
        $('#apdg_similarity_badge').hide();
    });

    // ── Product tab: Diff tabs ────────────────────────────────────────────────

    $(document).on('click', '.apdg-tab', function () {
        var tab = $(this).data('tab');
        $('.apdg-tab').removeClass('active');
        $(this).addClass('active');
        $('.apdg-tab-panel').hide();
        $('#tab_' + tab).show();
    });

    // ── Product tab: Lock toggle ──────────────────────────────────────────────

    $('#apdg_lock_btn').on('click', function () {
        var $btn = $(this);
        var pid  = $btn.data('pid');
        $.post(apdg.ajax_url, {
            action: 'apdg_toggle_lock', nonce: apdg.nonce, product_id: pid
        }, function (r) {
            if (r.success) {
                var locked = r.data.locked;
                $btn.text(locked ? '🔓 Unlock' : '🔒 Lock').attr('data-locked', locked ? '1' : '0');
                $('#apdg_generate_btn').prop('disabled', locked);
            }
        });
    });

    // ── Meta char counter ─────────────────────────────────────────────────────

    function updateMetaCount() {
        var len = $('#apdg_gen_meta').text().length;
        $('#apdg_meta_chars').text(len).css('color', len > 155 ? '#dc3232' : len > 140 ? '#f57f17' : '#2e7d32');
    }
    $(document).on('input', '#apdg_gen_meta', updateMetaCount);

    // ── Settings save ─────────────────────────────────────────────────────────

    $('#apdg_save_settings').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        $.post(apdg.ajax_url, {
            action:      'apdg_save_settings',
            nonce:       apdg.nonce,
            api_key:     $('#s_key').val(),
            model:       $('#s_model').val(),
            daily_limit: $('#s_dlimit').val(),
            overwrite:   $('#s_ow').is(':checked') ? 1 : 0,
        }, function (r) {
            $btn.prop('disabled', false);
            $('#s_msg').text(r.success ? '✅ Opgeslagen' : '❌ ' + r.data)
                       .css('color', r.success ? 'green' : 'red').show()
                       .delay(3000).fadeOut();
        });
    });

    // ── Bulk: Load products ───────────────────────────────────────────────────

    $('#apdg_load_btn').on('click', function () {
        var $btn = $(this).text('Loading…').prop('disabled', true);

        $.post(apdg.ajax_url, {
            action:   'apdg_get_products',
            nonce:    apdg.nonce,
            filter:   $('#apdg_filter').val(),
            category: $('#apdg_category').val(),
        }, function (r) {
            $btn.text('Load Products').prop('disabled', false);
            if (!r.success) { alert(r.data); return; }

            var html = '';
            var statusColors = { complete: '#2e7d32', failed: '#dc3232', generating: '#2271b1', pending: '#888' };
            $.each(r.data.products, function (i, p) {
                var statusDot = p.status ? '<span style="color:' + (statusColors[p.status] || '#888') + ';">●</span> ' + p.status : '';
                html += '<tr>' +
                    '<td><input type="checkbox" class="apdg-product-cb" value="' + p.id + '" ' + (p.locked ? 'disabled' : '') + '></td>' +
                    '<td><a href="' + p.edit_url + '" target="_blank">' + p.name + '</a>' + (p.locked ? ' 🔒' : '') + '</td>' +
                    '<td>' + p.category + '</td>' +
                    '<td><span class="apdg-badge ' + p.tier + '">' + p.tier + '</span></td>' +
                    '<td style="font-size:11px;font-family:monospace;">' + (p.model !== '—' ? p.model.split('/').pop() : '—') + '</td>' +
                    '<td>' + statusDot + '</td>' +
                    '<td style="font-size:12px;">' + p.last_gen + '</td>' +
                    '<td><a href="' + p.edit_url + '" class="button button-small" target="_blank">Edit</a></td>' +
                    '</tr>';
            });
            $('#apdg_product_tbody').html(html || '<tr><td colspan="8" style="text-align:center;padding:20px;color:#888;">Geen producten gevonden.</td></tr>');
            $('#apdg_product_table_wrap').show();
            $('#apdg_queue_btn').prop('disabled', false);
        });
    });

    // Select all checkbox
    $('#apdg_select_all').on('change', function () {
        $('.apdg-product-cb:not(:disabled)').prop('checked', $(this).is(':checked'));
    });

    // ── Bulk: Queue selected ──────────────────────────────────────────────────

    $('#apdg_queue_btn').on('click', function () {
        var ids = $('.apdg-product-cb:checked').map(function () { return $(this).val(); }).get();
        if (!ids.length) { alert('Geen producten geselecteerd.'); return; }

        var $btn = $(this).prop('disabled', true).text('Queuing…');
        $('#apdg_queue_log').show();

        $.post(apdg.ajax_url, {
            action:      'apdg_queue_batch',
            nonce:       apdg.nonce,
            product_ids: ids,
        }, function (r) {
            $btn.prop('disabled', false).text('Queue Selected');
            if (r.success) {
                logLine('✅ ' + r.data.message, 'success');
                pollQueueStatus();
            } else {
                logLine('❌ ' + r.data, 'error');
            }
        });
    });

    // ── Bulk: Cancel queue ────────────────────────────────────────────────────

    $('#apdg_cancel_btn').on('click', function () {
        if (!confirm('Wachtrij annuleren?')) return;
        $.post(apdg.ajax_url, { action: 'apdg_queue_cancel', nonce: apdg.nonce }, function (r) {
            logLine(r.success ? '🛑 Wachtrij geannuleerd.' : '❌ ' + r.data, r.success ? 'info' : 'error');
        });
    });

    // ── Queue status polling ──────────────────────────────────────────────────

    function pollQueueStatus() {
        $.post(apdg.ajax_url, { action: 'apdg_queue_status', nonce: apdg.nonce }, function (r) {
            if (!r.success) return;
            var t = r.data.totals;
            $('#stat_pending')   .text((t.pending    || 0) + ' pending');
            $('#stat_complete')  .text((t.complete   || 0) + ' complete');
            $('#stat_generating').text((t.generating || 0) + ' processing');
            $('#stat_failed')    .text((t.failed     || 0) + ' failed');
            var simSkipped = t.skipped_similarity || 0;
            if (simSkipped) $('#stat_similarity_blocked').text(simSkipped + ' geblokkeerd door similariteit (>70%)');

            if ((t.pending || 0) > 0 || (t.generating || 0) > 0) {
                setTimeout(pollQueueStatus, 8000);
            }
        });
    }

    // Auto-poll if on bulk page
    if ($('#apdg_queue_stats').length) {
        pollQueueStatus();
    }

    // ── Log output helper ─────────────────────────────────────────────────────

    function logLine(msg, type) {
        var colors = { success: '#2e7d32', error: '#dc3232', info: '#2271b1' };
        var $log = $('#apdg_log_output');
        var time = new Date().toLocaleTimeString();
        $log.append('<div style="color:' + (colors[type] || '#555') + '">[' + time + '] ' + msg + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }
});
