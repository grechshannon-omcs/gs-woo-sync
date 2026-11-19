/* GS – Woo Sync Admin JS */
jQuery( function ( $ ) {

    const { ajax_url, nonce } = gsWooSync;

    /* ── HTML escape helper ────────────────────────────────── */
    function esc( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    /* ── Run Sync ──────────────────────────────────────────── */
    function runSync( $btn, $resultBox ) {
        const origText = $btn.text();
        $btn.prop( 'disabled', true ).text( '⏳ Syncing…' );
        $resultBox.hide().removeClass( 'gs-success gs-error' );

        $.post( ajax_url, { action: 'gs_woo_sync_run', nonce }, function ( res ) {
            $btn.prop( 'disabled', false ).text( origText );
            if ( res.success ) {
                const d = res.data;

                // Summary bar
                let html = `<div class="gs-recap-summary">
                    <span class="gs-recap-pill gs-recap-created">✅ Created: <strong>${d.created}</strong></span>
                    <span class="gs-recap-pill gs-recap-updated">🔄 Updated: <strong>${d.updated}</strong></span>
                    <span class="gs-recap-pill gs-recap-skipped">⏭ Skipped: <strong>${d.skipped}</strong></span>
                    <span class="gs-recap-pill gs-recap-trashed">🗑 Trashed: <strong>${d.trashed}</strong></span>
                    <span class="gs-recap-pill gs-recap-errors">❌ Errors: <strong>${d.errors}</strong></span>
                </div>`;

                // Detail tables
                const itemTable = ( items, emptyMsg ) => {
                    if ( ! items || ! items.length ) return `<p class="gs-recap-empty">${emptyMsg}</p>`;
                    return '<table class="gs-recap-table"><thead><tr><th>SKU</th><th>Name</th></tr></thead><tbody>'
                        + items.map( i => `<tr><td><code>${esc(i.sku)}</code></td><td>${esc(i.name)}</td></tr>` ).join('')
                        + '</tbody></table>';
                };

                if ( d.created > 0 || d.updated > 0 || d.trashed > 0 || d.errors > 0 ) {
                    html += '<div class="gs-recap-details">';

                    if ( d.created > 0 ) {
                        html += `<div class="gs-recap-section gs-recap-section-created">
                            <h4>✅ Created (${d.created})</h4>${itemTable( d.created_items, '' )}</div>`;
                    }
                    if ( d.updated > 0 ) {
                        html += `<div class="gs-recap-section gs-recap-section-updated">
                            <h4>🔄 Updated (${d.updated})</h4>${itemTable( d.updated_items, '' )}</div>`;
                    }
                    if ( d.trashed > 0 ) {
                        html += `<div class="gs-recap-section gs-recap-section-trashed">
                            <h4>🗑 Trashed (${d.trashed})</h4>${itemTable( d.trashed_items, '' )}</div>`;
                    }
                    if ( d.errors > 0 ) {
                        html += `<div class="gs-recap-section gs-recap-section-errors">
                            <h4>❌ Errors (${d.errors})</h4>`;
                        if ( d.error_items && d.error_items.length ) {
                            html += '<table class="gs-recap-table"><thead><tr><th>SKU</th><th>Reason</th></tr></thead><tbody>'
                                + d.error_items.map( i => `<tr><td><code>${esc(i.sku)}</code></td><td>${esc(i.reason)}</td></tr>` ).join('')
                                + '</tbody></table>';
                        }
                        html += '</div>';
                    }

                    html += '</div>';
                } else {
                    html += '<p class="gs-recap-empty">No changes — all products are already up to date.</p>';
                }

                if ( d.notes && d.notes.length ) {
                    html += '<p class="gs-recap-notes">📝 ' + d.notes.map( n => esc(n) ).join( '<br>📝 ' ) + '</p>';
                }

                $resultBox
                    .removeClass( 'gs-success gs-error' )
                    .addClass( d.errors > 0 ? 'gs-error' : 'gs-success' )
                    .html( html )
                    .show();
            } else {
                $resultBox.addClass( 'gs-error' ).html( '❌ ' + ( res.data || 'Unknown error' ) ).show();
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false ).text( origText );
            $resultBox.addClass( 'gs-error' ).html( '❌ AJAX request failed.' ).show();
        } );
    }

    $( '#gs-run-sync' ).on( 'click', function () {
        runSync( $( this ), $( '#gs-sync-result' ) );
    } );
    $( '#gs-run-sync-log' ).on( 'click', function () {
        runSync( $( this ), $( '#gs-sync-result' ) );
    } );

    /* ── Clear Log ─────────────────────────────────────────── */
    $( '#gs-clear-log' ).on( 'click', function () {
        if ( ! confirm( 'Clear all sync log entries?' ) ) return;
        $.post( ajax_url, { action: 'gs_woo_sync_clear_log', nonce }, function ( res ) {
            if ( res.success ) location.reload();
        } );
    } );

    /* ── Load Sheet Headers + Auto-Map ─────────────────────── */
    $( '#gs-fetch-headers' ).on( 'click', function () {
        const $btn    = $( this );
        const $status = $( '#gs-headers-status' );

        $btn.prop( 'disabled', true );
        $status.html( '<span class="gs-spinning">⏳</span> Fetching headers…' );

        $.post( ajax_url, { action: 'gs_woo_sync_get_headers', nonce }, function ( res ) {
            $btn.prop( 'disabled', false );

            if ( ! res.success ) {
                $status.html( '❌ ' + ( res.data || 'Could not load headers' ) );
                return;
            }

            const { headers, auto_map, meta_keys } = res.data;
            if ( meta_keys ) gsLiveMetaKeys = meta_keys;

            rebuildMappingTable( headers, auto_map );
            $( document ).trigger( 'gs:headers_loaded', [ { headers, auto_map } ] );

            const mapped   = Object.keys( auto_map.column_map ).length + Object.keys( auto_map.meta_map ).length;
            const skipped  = auto_map.skipped.length;
            const unmapped = auto_map.unmapped.length;

            let parts = [];
            if ( mapped )   parts.push( `<span class="gs-auto-ok">✅ ${mapped} auto-mapped</span>` );
            if ( skipped )  parts.push( `<span class="gs-auto-skip">⏭ ${skipped} skipped</span>` );
            if ( unmapped ) parts.push( `<span class="gs-auto-warn">⚠️ ${unmapped} need manual mapping</span>` );

            $status.html( headers.length + ' columns — ' + parts.join( ' | ' ) );
            showAutoSaveBar( headers, auto_map );
        } ).fail( function () {
            $btn.prop( 'disabled', false );
            $status.html( '❌ Request failed' );
        } );
    } );

    /* ── Auto-save banner ──────────────────────────────────── */
    function showAutoSaveBar( headers, auto_map ) {
        $( '#gs-auto-save-bar' ).remove();

        const warning = auto_map.unmapped.length
            ? `<small style="margin-left:12px;color:#666;">Review the ⚠️ rows below before saving.</small>`
            : '';

        const $bar = $( `
            <div id="gs-auto-save-bar" class="notice notice-info inline" style="padding:8px 12px;margin:12px 0;">
                <p style="margin:0;">
                    <strong>Auto-mapping ready.</strong> Adjust any rows below, then click save.
                    <button type="button" id="gs-apply-auto-map" class="button button-primary" style="margin-left:10px;">
                        💾 Save Mapping
                    </button>
                    ${warning}
                </p>
            </div>
        ` );

        $( '#gs-mapping-table-wrap' ).before( $bar );

        $( '#gs-apply-auto-map' ).on( 'click', function () {
            const $saveBtn = $( this );
            $saveBtn.prop( 'disabled', true ).text( '⏳ Saving…' );

            const mapping = readTableMapping();

            $.post( ajax_url, {
                action:          'gs_woo_sync_save_mapping',
                nonce,
                sheet_col:       mapping.cols,
                wc_field:        mapping.wc_fields,
                meta_key:        mapping.meta_keys,
                meta_key_custom: mapping.meta_customs,
            }, function ( res ) {
                $saveBtn.prop( 'disabled', false ).text( '💾 Save Mapping' );
                if ( res.success ) {
                    $bar.removeClass( 'notice-info' ).addClass( 'notice-success' )
                        .find( 'p' ).html( '✅ ' + res.data.message );
                } else {
                    $bar.removeClass( 'notice-info' ).addClass( 'notice-error' )
                        .find( 'p' ).html( '❌ ' + ( res.data || 'Save failed' ) );
                }
            } );
        } );
    }

    /* ── Read current table state into arrays for POST ─────── */
    function readTableMapping() {
        const cols         = [];
        const wc_fields    = [];
        const meta_keys    = [];
        const meta_customs = [];

        $( '.gs-map-row' ).each( function () {
            const $tr = $( this );
            cols.push(         $tr.find( 'input[name="sheet_col[]"]' ).val()        || '' );
            wc_fields.push(    $tr.find( 'select[name="wc_field[]"]' ).val()        || '' );
            meta_keys.push(    $tr.find( 'select[name="meta_key[]"]' ).val()        || '' );
            meta_customs.push( $tr.find( 'input[name="meta_key_custom[]"]' ).val()  || '' );
        } );

        return { cols, wc_fields, meta_keys, meta_customs };
    }

    /* ── Rebuild mapping table ─────────────────────────────── */
    function rebuildMappingTable( headers, auto_map ) {
        auto_map = auto_map || { column_map: {}, meta_map: {}, skipped: [], unmapped: [] };

        let rows = '';
        headers.forEach( function ( col ) {
            const coreVal = auto_map.column_map[ col ] || '';
            const metaVal = auto_map.meta_map[ col ]   || '';
            const isSkip  = auto_map.skipped.indexOf( col ) !== -1;
            rows += buildRow( col, coreVal, metaVal, isSkip );
        } );

        const html = `
            <table class="widefat gs-mapping-table">
                <thead>
                    <tr>
                        <th>Sheet Column</th>
                        <th>WC Core Field</th>
                        <th>— OR — Meta Key</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
            <button type="button" class="button gs-add-row" style="margin-top:10px;">+ Add Row</button>`;

        $( '#gs-mapping-table-wrap' ).html( html );
        bindRowEvents();
    }

    function buildCoreOptions( selected ) {
        return Object.entries( gsCoreFields ).map( ( [ val, label ] ) =>
            `<option value="${esc(val)}"${val === selected ? ' selected' : ''}>${esc(label)}</option>`
        ).join('');
    }

    function buildMetaOptions( selected ) {
        let opts = '<option value="">— None —</option>';
        gsLiveMetaKeys.forEach( function ( key ) {
            opts += `<option value="${esc(key)}"${key === selected ? ' selected' : ''}>${esc(key)}</option>`;
        } );
        return opts;
    }

    function buildRow( col, coreVal, metaVal, isSkipped ) {
        const coreOptions = buildCoreOptions( coreVal );
        const metaOptions = buildMetaOptions( metaVal );
        const metaCustom  = ( metaVal && gsLiveMetaKeys.indexOf( metaVal ) === -1 ) ? metaVal : '';

        let badge    = '<span class="gs-badge gs-badge-none" title="No auto-match found">?</span>';
        let rowClass = ' gs-row-unmapped';
        if ( coreVal )      { badge = '<span class="gs-badge gs-badge-auto-core" title="Auto-mapped to core field">core ✓</span>';  rowClass = ' gs-row-mapped'; }
        else if ( metaVal ) { badge = '<span class="gs-badge gs-badge-auto-meta" title="Auto-mapped to meta key">meta ✓</span>';    rowClass = ' gs-row-mapped'; }
        else if ( isSkipped){ badge = '<span class="gs-badge gs-badge-skip" title="Auto-skipped">skip</span>';                      rowClass = ' gs-row-skipped'; }

        return `
        <tr class="gs-map-row${rowClass}">
            <td><strong>${esc(col)}</strong>
                <input type="hidden" name="sheet_col[]" value="${esc(col)}">
            </td>
            <td><select name="wc_field[]" class="gs-core-select">${coreOptions}</select></td>
            <td>
                <select name="meta_key[]" class="gs-meta-select">${metaOptions}</select>
                <input type="text" name="meta_key_custom[]" class="gs-meta-custom"
                       placeholder="or type custom key" value="${esc(metaCustom)}">
            </td>
            <td class="gs-auto-badge">${badge}</td>
            <td><button type="button" class="button-link gs-remove-row" title="Remove row">✕</button></td>
        </tr>`;
    }

    /* ── Add / Remove rows ─────────────────────────────────── */
    function bindRowEvents() {
        $( document ).off( 'click.gsrows', '.gs-remove-row' ).on( 'click.gsrows', '.gs-remove-row', function () {
            $( this ).closest( 'tr' ).remove();
        } );
        $( document ).off( 'click.gsadd', '.gs-add-row' ).on( 'click.gsadd', '.gs-add-row', function () {
            const col = prompt( 'Enter the sheet column header name:' );
            if ( ! col ) return;
            $( '.gs-mapping-table tbody' ).append( buildRow( col, '', '', false ) );
        } );
        $( document ).off( 'change.gscore', '.gs-core-select' ).on( 'change.gscore', '.gs-core-select', function () {
            if ( $( this ).val() ) {
                const $tr = $( this ).closest( 'tr' );
                $tr.find( '.gs-meta-select' ).val( '' );
                $tr.find( '.gs-meta-custom' ).val( '' );
                $tr.find( '.gs-auto-badge' ).html( '<span class="gs-badge gs-badge-auto-core">core ✓</span>' );
                $tr.removeClass( 'gs-row-unmapped gs-row-skipped' ).addClass( 'gs-row-mapped' );
            }
        } );
        $( document ).off( 'change.gsmeta', '.gs-meta-select' ).on( 'change.gsmeta', '.gs-meta-select', function () {
            if ( $( this ).val() ) {
                const $tr = $( this ).closest( 'tr' );
                $tr.find( '.gs-core-select' ).val( '' );
                $tr.find( '.gs-auto-badge' ).html( '<span class="gs-badge gs-badge-auto-meta">meta ✓</span>' );
                $tr.removeClass( 'gs-row-unmapped gs-row-skipped' ).addClass( 'gs-row-mapped' );
            }
        } );
        $( document ).off( 'input.gscustom', '.gs-meta-custom' ).on( 'input.gscustom', '.gs-meta-custom', function () {
            if ( $( this ).val() ) {
                const $tr = $( this ).closest( 'tr' );
                $tr.find( '.gs-core-select' ).val( '' );
                $tr.find( '.gs-meta-select' ).val( '' );
                $tr.find( '.gs-auto-badge' ).html( '<span class="gs-badge gs-badge-auto-meta">meta ✓</span>' );
                $tr.removeClass( 'gs-row-unmapped gs-row-skipped' ).addClass( 'gs-row-mapped' );
            }
        } );
    }

    bindRowEvents();

    /* ── Diagnostics ───────────────────────────────────────── */
    $( '#gs-run-diagnostics' ).on( 'click', function () {
        const $btn = $( this );
        const $out = $( '#gs-diag-output' );
        $btn.prop( 'disabled', true ).text( '⏳ Running…' );
        $out.hide();

        $.post( ajax_url, { action: 'gs_woo_sync_diagnostics', nonce }, function ( res ) {
            $btn.prop( 'disabled', false ).text( '▶ Run Diagnostics' );

            if ( ! res.success ) {
                $out.html( '<p class="gs-diag-error">❌ ' + ( res.data || 'Failed' ) + '</p>' ).show();
                return;
            }

            const d = res.data;

            const row = ( label, value, state ) => {
                const cls = state === 'ok' ? 'gs-diag-ok' : state === 'fail' ? 'gs-diag-fail' : state === 'warn' ? 'gs-diag-warn' : 'gs-diag-info';
                return `<tr><td class="gs-diag-label">${label}</td><td class="${cls}">${value}</td></tr>`;
            };

            let html = '<table class="widefat gs-diag-table"><tbody>';

            // Sheet URL
            const hasUrl = d.resolved_settings.sheet_url !== '(empty)';
            html += row( 'Sheet URL', esc( d.resolved_settings.sheet_url ), hasUrl ? 'ok' : 'fail' );

            // CSV fetch
            const fetchOk = d.csv_fetch && d.csv_fetch.startsWith( 'OK' );
            html += row( 'CSV Fetch', esc( d.csv_fetch || '—' ), fetchOk ? 'ok' : 'fail' );
            if ( d.csv_url )           html += row( 'CSV URL', `<code style="font-size:11px;word-break:break-all;">${esc(d.csv_url)}</code>`, '' );
            if ( d.sheet_row_count !== undefined ) html += row( 'Sheet rows (excl. header)', d.sheet_row_count, '' );

            // SKU mapping
            const skuOk = d.sku_mapped_column !== '(no column mapped to SKU)';
            html += row( 'SKU column', esc( d.sku_mapped_column ), skuOk ? 'ok' : 'fail' );

            // Seen SKUs
            if ( d.seen_skus_from_sheet ) {
                html += row(
                    `SKUs read from sheet (${d.seen_skus_from_sheet.length})`,
                    d.seen_skus_from_sheet.map( s => `<code>${esc(s)}</code>` ).join( ' ' ) || '(none)',
                    d.seen_skus_from_sheet.length > 0 ? 'ok' : 'fail'
                );
            }

            // trash_removed
            html += row( 'trash_removed (raw in DB)',      esc( JSON.stringify( d.raw_trash_removed ) ), '' );
            html += row( 'trash_removed (resolved)',       esc( d.resolved_settings.trash_removed ),     '' );
            const trashOn = d.trash_removed_evaluated && d.trash_removed_evaluated.startsWith( 'TRUE' );
            html += row( 'Trash-on-removal active?', `<strong>${esc( d.trash_removed_evaluated )}</strong>`, trashOn ? 'ok' : 'warn' );

            // Would-be trashed
            if ( d.would_be_trashed !== undefined ) {
                const wt     = Array.isArray( d.would_be_trashed ) ? d.would_be_trashed : [];
                const wtText = wt.length ? wt.map( s => `<code>${esc(s)}</code>` ).join( ' ' ) : '(none — all DB SKUs present in sheet)';
                html += row( 'SKUs that WOULD be trashed', wtText, wt.length > 0 ? 'warn' : 'ok' );
            }

            html += '</tbody></table>';

            // WC Products table
            if ( d.wc_products && d.wc_products.length ) {
                html += `<h3 style="margin-top:20px;">WooCommerce Products (${d.wc_products.length})</h3>`;
                html += '<table class="widefat"><thead><tr><th>ID</th><th>SKU</th><th>Status</th><th>In Sheet?</th></tr></thead><tbody>';
                d.wc_products.forEach( function ( p ) {
                    const inSheet = d.seen_skus_from_sheet && d.seen_skus_from_sheet.includes( p.sku );
                    const badge   = d.seen_skus_from_sheet
                        ? ( inSheet ? '<span style="color:#00a32a;font-weight:600;">✅ Yes</span>' : '<span style="color:#d63638;font-weight:600;">❌ No → would trash</span>' )
                        : '—';
                    html += `<tr><td>${p.id}</td><td><code>${esc(p.sku)}</code></td><td>${esc(p.status)}</td><td>${badge}</td></tr>`;
                } );
                html += '</tbody></table>';
            }

            // Column mapping
            html += '<h3 style="margin-top:20px;">Active Column Mapping</h3>';
            html += '<table class="widefat"><thead><tr><th>Sheet Column</th><th>→ WC Field</th></tr></thead><tbody>';
            const cm = d.resolved_settings.column_map || {};
            Object.entries( cm ).forEach( ( [ col, field ] ) => {
                html += `<tr><td>${esc(col)}</td><td><code>${esc(field)}</code></td></tr>`;
            } );
            if ( ! Object.keys( cm ).length ) html += '<tr><td colspan="2" style="color:#888;">(no core field mappings saved)</td></tr>';
            html += '</tbody></table>';

            // Meta map check
        if ( d.meta_map_check && d.meta_map_check.length ) {
            html += '<h3 style="margin-top:20px;">Meta Field Mapping Check</h3>';
            html += '<table class="widefat"><thead><tr><th>Sheet Column (saved)</th><th>→ Meta Key</th><th>Found in Sheet?</th></tr></thead><tbody>';
            d.meta_map_check.forEach( function ( r ) {
                const found = r.found_in_sheet
                    ? '<span style="color:#00a32a;font-weight:600;">✅ Yes</span>'
                    : '<span style="color:#d63638;font-weight:600;">❌ No — column name mismatch</span>';
                html += `<tr><td><code>${esc(r.sheet_col)}</code></td><td><code>${esc(r.meta_key)}</code></td><td>${found}</td></tr>`;
            } );
            html += '</tbody></table>';
        } else if ( d.resolved_settings && d.resolved_settings.meta_map && Object.keys( d.resolved_settings.meta_map ).length ) {
            html += '<h3 style="margin-top:20px;">Meta Field Mapping</h3>';
            html += '<table class="widefat"><thead><tr><th>Sheet Column</th><th>→ Meta Key</th></tr></thead><tbody>';
            Object.entries( d.resolved_settings.meta_map ).forEach( ( [ col, key ] ) => {
                html += `<tr><td><code>${esc(col)}</code></td><td><code>${esc(key)}</code></td></tr>`;
            } );
            html += '</tbody></table>';
        }

        html += `<p style="margin-top:16px;color:#666;">🕐 Next scheduled sync: <strong>${esc(d.next_cron)}</strong></p>`;

            $out.html( html ).show();

        } ).fail( function ( xhr ) {
            $btn.prop( 'disabled', false ).text( '▶ Run Diagnostics' );
            $out.html( '<p class="gs-diag-error">❌ AJAX request failed. Status: ' + xhr.status + '</p>' ).show();
        } );
    } );

    /* ── Attribute rows: add / remove ─────────────────────── */
    $( document ).on( 'click', '#gs-add-attr-row', function () {
        const row = `<tr class="gs-attr-row">
            <td><input type="text" name="attr_name[]" class="regular-text" placeholder="e.g. Colour"></td>
            <td><input type="text" name="attr_values_col[]" class="regular-text" placeholder="Sheet column header"></td>
            <td style="text-align:center;">
                <input type="hidden" name="attr_visible_val[]" class="gs-attr-visible-val" value="1">
                <input type="checkbox" class="gs-attr-visible-cb" checked>
            </td>
            <td style="text-align:center;">
                <input type="hidden" name="attr_global_val[]" class="gs-attr-global-val" value="0">
                <input type="checkbox" class="gs-attr-global-cb">
            </td>
            <td><button type="button" class="button-link gs-remove-attr-row" title="Remove">✕</button></td>
        </tr>`;
        $( '#gs-attr-rows' ).append( row );
    } );

    $( document ).on( 'change', '.gs-attr-visible-cb', function () {
        $( this ).siblings( '.gs-attr-visible-val' ).val( this.checked ? 1 : 0 );
    } );
    $( document ).on( 'change', '.gs-attr-global-cb', function () {
        $( this ).siblings( '.gs-attr-global-val' ).val( this.checked ? 1 : 0 );
    } );

    $( document ).on( 'click', '.gs-remove-attr-row', function () {
        $( this ).closest( 'tr' ).remove();
    } );

    /* ── Auto-populate attribute rows from sheet headers ───── */
    // When headers are loaded and attribute columns detected, pre-fill the table
    $( document ).on( 'gs:headers_loaded', function ( e, data ) {
        if ( ! data.auto_map || ! data.auto_map.attribute_cols || ! data.auto_map.attribute_cols.length ) return;

        const attrCols = data.auto_map.attribute_cols;
        // Group by number: "Attribute 1 Name" + "Attribute 1 value(s)"
        const groups = {};
        attrCols.forEach( function ( col ) {
            const mName   = col.match( /^attribute\s+(\d+)\s+name$/i );
            const mValues = col.match( /^attribute\s+(\d+)\s+value\(s\)$/i ) || col.match( /^attribute\s+(\d+)\s+values$/i );
            if ( mName )   { groups[ mName[1] ]   = groups[ mName[1] ]   || {}; groups[ mName[1] ].name_col   = col; }
            if ( mValues ) { groups[ mValues[1] ] = groups[ mValues[1] ] || {}; groups[ mValues[1] ].values_col = col; }
        } );

        const $tbody = $( '#gs-attr-rows' );
        Object.values( groups ).forEach( function ( grp ) {
            if ( ! grp.values_col ) return; // need at least a values column
            // Check if a row with this values_col already exists
            let exists = false;
            $tbody.find( 'input[name="attr_values_col[]"]' ).each( function () {
                if ( $( this ).val() === grp.values_col ) { exists = true; }
            } );
            if ( exists ) return;

            // Derive attribute name from the name column value or the column header
            const attrName = grp.name_col ? grp.name_col.replace( /attribute\s+\d+\s+name/i, '' ).trim() || grp.values_col : grp.values_col;
            const row = `<tr class="gs-attr-row gs-attr-row-auto">
                <td><input type="text" name="attr_name[]" class="regular-text" value="${esc(attrName)}" placeholder="e.g. Colour"></td>
                <td><input type="text" name="attr_values_col[]" class="regular-text" value="${esc(grp.values_col)}" placeholder="Sheet column header"></td>
                <td style="text-align:center;">
                    <input type="hidden" name="attr_visible_val[]" class="gs-attr-visible-val" value="1">
                    <input type="checkbox" class="gs-attr-visible-cb" checked>
                </td>
                <td style="text-align:center;">
                    <input type="hidden" name="attr_global_val[]" class="gs-attr-global-val" value="0">
                    <input type="checkbox" class="gs-attr-global-cb">
                </td>
                <td><button type="button" class="button-link gs-remove-attr-row" title="Remove">✕</button></td>
            </tr>`;
            $tbody.append( row );
        } );

        if ( Object.keys( groups ).length ) {
            $( '#gs-attr-detected-note' ).show();
        }
    } );

    /* ── Notify when toggle ───────────────────────────────── */
    $( '#notify_when' ).on( 'change', function () {
        $( '#gs-notify-email-row' ).toggle( $( this ).val() !== 'off' );
    } );

    /* ── Webhook: copy URL ─────────────────────────────────── */
    $( '#gs-copy-webhook' ).on( 'click', function () {
        const $input = $( '#gs-webhook-url' );
        $input[0].select();
        document.execCommand( 'copy' );
        const $btn = $( this );
        $btn.text( '✅ Copied!' );
        setTimeout( () => $btn.text( '📋 Copy' ), 2000 );
    } );

    /* ── Webhook: regenerate token ─────────────────────────── */
    $( '#gs-regen-token' ).on( 'click', function () {
        if ( ! confirm( 'Regenerate token? Your existing Apps Script integration will stop working until you update it with the new URL.' ) ) return;
        const $btn = $( this );
        $btn.prop( 'disabled', true ).text( '⏳ Regenerating…' );

        $.post( ajax_url, { action: 'gs_woo_sync_regen_token', nonce }, function ( res ) {
            $btn.prop( 'disabled', false ).text( '🔄 Regenerate' );
            if ( res.success ) {
                $( '#gs-webhook-url' ).val( res.data.url );
                $( '#gs-apps-script' ).val( res.data.apps_script );
                alert( '✅ Token regenerated. Copy the new URL and update your Apps Script.' );
            } else {
                alert( '❌ Failed to regenerate token.' );
            }
        } );
    } );

    /* ── Rollback ──────────────────────────────────────────── */
    $( document ).on( 'click', '.gs-rollback-btn', function () {
        const logId   = $( this ).data( 'log-id' );
        const $btn    = $( this );

        if ( ! confirm( 'Rollback to the state before this sync run? This will restore all products that were created, updated, or trashed during this run.' ) ) return;

        $btn.prop( 'disabled', true ).text( '⏳ Restoring…' );

        $.post( ajax_url, { action: 'gs_woo_sync_rollback', nonce, log_id: logId }, function ( res ) {
            if ( res.success ) {
                $btn.closest( 'tr' ).css( 'background', '#e8f5e9' );
                $btn.replaceWith( '<span style="color:#2e7d32;font-weight:600;">✅ ' + esc( res.data.message ) + '</span>' );
            } else {
                alert( '❌ Rollback failed: ' + ( res.data || 'Unknown error' ) );
                $btn.prop( 'disabled', false ).text( '↩ Rollback' );
            }
        } ).fail( function () {
            alert( '❌ Request failed.' );
            $btn.prop( 'disabled', false ).text( '↩ Rollback' );
        } );
    } );

} ); // end jQuery ready
