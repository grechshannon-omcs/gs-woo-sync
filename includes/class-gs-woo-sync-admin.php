<?php
defined( 'ABSPATH' ) || exit;

class GS_Woo_Sync_Admin {

    public static function init(): void {
        add_action( 'admin_menu',         [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        add_action( 'wp_ajax_gs_woo_sync_run',         [ __CLASS__, 'ajax_run' ] );
        add_action( 'wp_ajax_gs_woo_sync_clear_log',   [ __CLASS__, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_gs_woo_sync_get_headers',  [ __CLASS__, 'ajax_get_headers' ] );
        add_action( 'wp_ajax_gs_woo_sync_get_meta_keys', [ __CLASS__, 'ajax_get_meta_keys' ] );
        add_action( 'wp_ajax_gs_woo_sync_apply_auto_map',  [ __CLASS__, 'ajax_apply_auto_map' ] );
        add_action( 'wp_ajax_gs_woo_sync_save_mapping',     [ __CLASS__, 'ajax_save_mapping' ] );
        add_action( 'wp_ajax_gs_woo_sync_diagnostics',      [ __CLASS__, 'ajax_diagnostics' ] );
        add_action( 'wp_ajax_gs_woo_sync_rollback',          [ __CLASS__, 'ajax_rollback' ] );
        add_action( 'wp_ajax_gs_woo_sync_regen_token',        [ __CLASS__, 'ajax_regen_token' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            'GS – Woo Sync',
            'GS – Woo Sync',
            'manage_woocommerce',
            'gs-woo-sync',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue( string $hook ): void {
        if ( ! str_contains( $hook, 'gs-woo-sync' ) ) return;
        wp_enqueue_style(  'gs-woo-sync-admin', GS_WOO_SYNC_URL . 'assets/css/admin.css', [], GS_WOO_SYNC_VERSION );
        wp_enqueue_script( 'gs-woo-sync-admin', GS_WOO_SYNC_URL . 'assets/js/admin.js',  [ 'jquery' ], GS_WOO_SYNC_VERSION, true );
        wp_localize_script( 'gs-woo-sync-admin', 'gsWooSync', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'gs_woo_sync_nonce' ),
        ] );
    }

    /* ── Page renderer ─────────────────────────────────────── */

    public static function render_page(): void {
        /* Handle form saves */
        if ( isset( $_POST['gs_woo_sync_save'] ) && check_admin_referer( 'gs_woo_sync_settings' ) ) {
            self::save_settings();
        }
        if ( isset( $_POST['gs_woo_sync_save_mapping'] ) && check_admin_referer( 'gs_woo_sync_mapping' ) ) {
            self::save_mapping();
        }

        $tab  = sanitize_key( $_GET['tab'] ?? 'settings' );
        $opts = GS_Woo_Sync_Settings::get();
        ?>
        <div class="wrap gs-woo-sync-wrap">
            <h1>🔄 GS – Woo Sync <span class="gs-version">v<?php echo GS_WOO_SYNC_VERSION; ?></span></h1>

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = [
                    'settings'    => '⚙️ Settings',
                    'mapping'     => '🗺️ Column Mapping',
                    'log'         => '📋 Sync Log',
                    'diagnostics' => '🔬 Diagnostics',
                    'help'        => '❓ Help',
                ];
                foreach ( $tabs as $key => $label ) {
                    $active = $tab === $key ? ' nav-tab-active' : '';
                    printf(
                        '<a href="%s" class="nav-tab%s">%s</a>',
                        esc_url( add_query_arg( [ 'page' => 'gs-woo-sync', 'tab' => $key ], admin_url( 'admin.php' ) ) ),
                        $active,
                        esc_html( $label )
                    );
                }
                ?>
            </nav>

            <div class="gs-tab-content">
                <?php
                match ( $tab ) {
                    'mapping'     => self::tab_mapping( $opts ),
                    'log'         => self::tab_log(),
                    'diagnostics' => self::tab_diagnostics(),
                    'help'        => self::tab_help(),
                    default       => self::tab_settings( $opts ),
                };
                ?>
            </div>
        </div>
        <?php
    }

    /* ── Tab: Settings ─────────────────────────────────────── */

    private static function tab_settings( array $opts ): void {
        ?>
        <form method="post" action="">
            <?php wp_nonce_field( 'gs_woo_sync_settings' ); ?>

            <table class="form-table">
                <tr>
                    <th><label for="sheet_url">Google Sheet / Drive URL</label></th>
                    <td>
                        <input type="text" id="sheet_url" name="sheet_url" class="large-text"
                               value="<?php echo esc_attr( $opts['sheet_url'] ); ?>"
                               placeholder="https://docs.google.com/spreadsheets/d/… or https://drive.google.com/file/d/…">
                        <p class="description">Paste a public Google Sheets URL <em>or</em> a Google Drive file link (CSV). The plugin extracts the ID automatically.</p>
                        <?php if ( $opts['sheet_url'] ) :
                            $importer  = new GS_Woo_Sync_Importer();
                            $built_url = $importer->build_csv_url( $opts['sheet_url'] );
                        ?>
                            <p class="description" style="margin-top:6px;">
                                <strong>Built CSV URL:</strong><br>
                                <?php if ( $built_url ) : ?>
                                    <code style="word-break:break-all;font-size:11px;"><?php echo esc_html( $built_url ); ?></code>
                                <?php else : ?>
                                    <span style="color:#d63638;">Could not parse URL. Paste a Google Sheets URL (<code>/spreadsheets/d/…</code>) or a Google Drive file link (<code>/file/d/…</code>).</span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="schedule">Auto-Sync Schedule</label></th>
                    <td>
                        <select id="schedule" name="schedule">
                            <?php
                            $schedules = [
                                'manual'     => 'Manual Only',
                                'hourly'     => 'Hourly',
                                'twicedaily' => 'Twice Daily',
                                'daily'      => 'Daily',
                            ];
                            foreach ( $schedules as $val => $label ) {
                                printf(
                                    '<option value="%s"%s>%s</option>',
                                    esc_attr( $val ),
                                    selected( $opts['schedule'], $val, false ),
                                    esc_html( $label )
                                );
                            }
                            ?>
                        </select>
                        <p class="description">Next scheduled run: <strong><?php echo esc_html( GS_Woo_Sync_Scheduler::next_run() ); ?></strong></p>
                    </td>
                </tr>
                <tr>
                    <th>Trash Removed Products</th>
                    <td>
                        <label>
                            <input type="checkbox" name="trash_removed" value="1" <?php checked( $opts['trash_removed'] ); ?>>
                            Move products to trash if they are removed from the sheet
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Email Notifications</th>
                    <td>
                        <select name="notify_when" id="notify_when">
                            <option value="off"          <?php selected( $opts['notify_when'] ?? 'off', 'off' ); ?>>Disabled</option>
                            <option value="always"       <?php selected( $opts['notify_when'] ?? 'off', 'always' ); ?>>After every sync</option>
                            <option value="changes"      <?php selected( $opts['notify_when'] ?? 'off', 'changes' ); ?>>Only when products changed</option>
                            <option value="errors_trash" <?php selected( $opts['notify_when'] ?? 'off', 'errors_trash' ); ?>>Only on errors or trashed products</option>
                            <option value="errors"       <?php selected( $opts['notify_when'] ?? 'off', 'errors' ); ?>>Only on errors</option>
                        </select>
                        <p class="description">When to send a sync summary email.</p>
                    </td>
                </tr>
                <tr id="gs-notify-email-row" <?php echo ( ( $opts['notify_when'] ?? 'off' ) === 'off' ) ? 'style="display:none"' : ''; ?>>
                    <th><label for="notify_email">Notification Email</label></th>
                    <td>
                        <input type="email" id="notify_email" name="notify_email" class="regular-text"
                               value="<?php echo esc_attr( $opts['notify_email'] ?? '' ); ?>"
                               placeholder="<?php echo esc_attr( get_option('admin_email') ); ?>">
                        <p class="description">Leave blank to use the site admin email (<?php echo esc_html( get_option('admin_email') ); ?>).</p>
                    </td>
                </tr>
            </table>

            <h2 class="title" style="margin-top:30px;">🔗 Webhook</h2>
            <p>Use this URL in Google Apps Script to trigger a sync instantly whenever your sheet is edited.</p>
            <table class="form-table">
                <tr>
                    <th>Webhook URL</th>
                    <td>
                        <?php
                        $token = GS_Woo_Sync_Webhook::get_token();
                        if ( ! $token ) $token = GS_Woo_Sync_Webhook::generate_token();
                        $wh_url = GS_Woo_Sync_Webhook::get_webhook_url();
                        ?>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <input type="text" id="gs-webhook-url" class="large-text" readonly
                                   value="<?php echo esc_attr( $wh_url ); ?>"
                                   style="font-size:12px;font-family:monospace;">
                            <button type="button" id="gs-copy-webhook" class="button">📋 Copy</button>
                            <button type="button" id="gs-regen-token" class="button">🔄 Regenerate</button>
                        </div>
                        <p class="description">⚠️ Regenerating the token will break any existing Apps Script integrations — you'll need to paste the new URL there.</p>
                    </td>
                </tr>
                <tr>
                    <th>Apps Script Code</th>
                    <td>
                        <textarea id="gs-apps-script" readonly rows="14" style="width:100%;font-family:monospace;font-size:12px;background:#f6f7f7;border:1px solid #ddd;padding:8px;"><?php echo esc_textarea( GS_Woo_Sync_Webhook::get_apps_script( $wh_url ) ); ?></textarea>
                        <p class="description">In your Google Sheet: <strong>Extensions → Apps Script</strong> → paste the above into <code>Code.gs</code> → Save → Done.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="gs_woo_sync_save" class="button button-primary">💾 Save Settings</button>
                <button type="button" id="gs-run-sync" class="button button-secondary">▶ Run Sync Now</button>
            </p>
        </form>

        <div id="gs-sync-result" class="gs-result-box" style="display:none;"></div>
        <?php
    }

    /* ── Tab: Column Mapping ───────────────────────────────── */

    private static function tab_mapping( array $opts ): void {
        $column_map    = $opts['column_map']    ?? [];
        $meta_map      = $opts['meta_map']      ?? [];
        $attribute_map = $opts['attribute_map'] ?? [];
        $core_fields   = GS_Woo_Sync_Settings::core_fields();
        $live_meta     = GS_Woo_Sync_Settings::get_live_meta_keys();
        $sheet_url     = $opts['sheet_url'] ?? '';
        ?>
        <div class="gs-mapping-header">
            <p>Map your Google Sheet column headers to WooCommerce fields.</p>
            <?php if ( $sheet_url ) : ?>
                <button type="button" id="gs-fetch-headers" class="button">🔄 Load Sheet Headers</button>
                <span id="gs-headers-status" style="margin-left:10px;"></span>
            <?php else : ?>
                <p class="notice notice-warning inline"><strong>Set your Sheet URL in Settings first.</strong></p>
            <?php endif; ?>
        </div>

        <form method="post" action="" id="gs-mapping-form">
            <?php wp_nonce_field( 'gs_woo_sync_mapping' ); ?>

            <div id="gs-mapping-table-wrap">
                <?php self::render_mapping_table( $column_map, $meta_map, $core_fields, $live_meta ); ?>
            </div>

            <p class="submit">
                <button type="submit" name="gs_woo_sync_save_mapping" class="button button-primary">💾 Save Mapping</button>
            </p>
        </form>

        <hr style="margin:30px 0;">
        <h2>🏷️ Product Attributes</h2>
        <p>Map sheet columns to WooCommerce product attributes. Values should be pipe-separated (e.g. <code>Red|Blue|Green</code>) or comma-separated.</p>
        <div id="gs-attr-detected-note" style="display:none;" class="notice notice-info inline">
            <p>✅ Attribute columns were auto-detected from your sheet headers and shown below. Review and save.</p>
        </div>

        <form method="post" action="" id="gs-attribute-form">
            <?php wp_nonce_field( 'gs_woo_sync_mapping' ); ?>
            <input type="hidden" name="gs_woo_sync_save_mapping" value="1">
            <input type="hidden" name="is_attribute_save" value="1">

            <table class="widefat gs-attr-table" style="margin-bottom:12px;">
                <thead>
                    <tr>
                        <th>Attribute Name</th>
                        <th>Values Column (sheet header)</th>
                        <th>Visible on product page</th>
                        <th>Global attribute</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="gs-attr-rows">
                    <?php foreach ( $attribute_map as $attr_name => $cols ) : ?>
                    <tr class="gs-attr-row">
                        <td><input type="text" name="attr_name[]" class="regular-text" value="<?php echo esc_attr( $attr_name ); ?>" placeholder="e.g. Colour"></td>
                        <td><input type="text" name="attr_values_col[]" class="regular-text" value="<?php echo esc_attr( $cols['values_col'] ?? '' ); ?>" placeholder="Sheet column header"></td>
                        <td style="text-align:center;">
                            <input type="hidden" name="attr_visible_val[]" class="gs-attr-visible-val" value="<?php echo (int)( $cols['visible'] ?? 1 ); ?>">
                            <input type="checkbox" class="gs-attr-visible-cb" <?php checked( $cols['visible'] ?? 1 ); ?>>
                        </td>
                        <td style="text-align:center;">
                            <input type="hidden" name="attr_global_val[]" class="gs-attr-global-val" value="<?php echo (int)( $cols['global'] ?? 0 ); ?>">
                            <input type="checkbox" class="gs-attr-global-cb" <?php checked( $cols['global'] ?? 0 ); ?>>
                        </td>
                        <td><button type="button" class="button-link gs-remove-attr-row" title="Remove">✕</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <button type="button" id="gs-add-attr-row" class="button">+ Add Attribute</button>
            <p class="submit" style="margin-top:16px;">
                <button type="submit" class="button button-primary">💾 Save Attributes</button>
            </p>
        </form>

        <script>
        var gsLiveMetaKeys = <?php echo wp_json_encode( $live_meta ); ?>;
        var gsCoreFields   = <?php echo wp_json_encode( $core_fields ); ?>;
        </script>
        <?php
    }

    private static function render_mapping_table(
        array $column_map,
        array $meta_map,
        array $core_fields,
        array $live_meta,
        array $headers = []
    ): void {
        /* Collect all known sheet columns */
        $all_cols = array_unique( array_merge(
            array_keys( $column_map ),
            array_keys( $meta_map ),
            $headers
        ) );

        if ( empty( $all_cols ) ) {
            echo '<p class="gs-notice">No sheet headers loaded yet. Click <strong>Load Sheet Headers</strong> or save a mapping below.</p>';
            echo '<div id="gs-add-manual-row"><button type="button" class="button" id="gs-add-row">+ Add Row Manually</button></div>';
            echo '<div id="gs-manual-rows"></div>';
            return;
        }
        ?>
        <table class="widefat gs-mapping-table">
            <thead>
                <tr>
                    <th>Sheet Column Header</th>
                    <th>Map to WC Core Field</th>
                    <th>— OR — Map to Meta Key</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $all_cols as $col ) :
                    // Attribute columns are handled in the Attributes section below — skip here
                    if ( preg_match( '/^attribute\s+\d+\s+(name|value\(s\)|values|visible|global)$/i', $col ) ) continue;

                    $core_val = $column_map[ $col ] ?? '';
                    $meta_val = $meta_map[ $col ]   ?? '';

                    // Clean up bad meta mappings: if the meta key looks like a number (e.g. "1") it was
                    // accidentally saved from an attribute visible/global checkbox — clear it
                    if ( $meta_val && is_numeric( $meta_val ) && ! $core_val ) {
                        $meta_val = '';
                    }
                    ?>
                    <tr class="gs-map-row">
                        <td><strong><?php echo esc_html( $col ); ?></strong>
                            <input type="hidden" name="sheet_col[]" value="<?php echo esc_attr( $col ); ?>">
                        </td>
                        <td>
                            <select name="wc_field[]" class="gs-core-select">
                                <?php foreach ( $core_fields as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $core_val, $val ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="meta_key[]" class="gs-meta-select">
                                <option value="">— None —</option>
                                <?php foreach ( $live_meta as $key ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $meta_val, $key ); ?>>
                                        <?php echo esc_html( $key ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="meta_key_custom[]" class="gs-meta-custom"
                                   placeholder="or type custom meta key"
                                   value="<?php echo ( $meta_val && ! in_array( $meta_val, $live_meta, true ) ) ? esc_attr( $meta_val ) : ''; ?>">
                        </td>
                        <td><button type="button" class="button-link gs-remove-row">✕</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="button gs-add-row" style="margin-top:10px;">+ Add Row</button>
        <?php
    }

    /* ── Tab: Sync Log ─────────────────────────────────────── */

    private static function tab_log(): void {
        $rows = GS_Woo_Sync_Log::get_rows( 100 );
        ?>
        <div class="gs-log-header">
            <button type="button" id="gs-run-sync-log" class="button button-secondary">▶ Run Sync Now</button>
            <button type="button" id="gs-clear-log" class="button button-link-delete" style="margin-left:10px;">🗑 Clear Log</button>
        </div>
        <div id="gs-sync-result" class="gs-result-box" style="display:none;margin-bottom:15px;"></div>

        <?php if ( empty( $rows ) ) : ?>
            <p>No sync runs recorded yet.</p>
        <?php else : ?>
            <table class="widefat gs-log-table">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>Trigger</th>
                        <th>Created</th>
                        <th>Updated</th>
                        <th>Skipped</th>
                        <th>Trashed</th>
                        <th>Errors</th>
                        <th>Duration</th>
                        <th>Notes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) :
                        $log_id      = (int) $row['id'];
                        $has_snapshot = GS_Woo_Sync_Rollback::has_snapshot( $log_id );
                    ?>
                        <tr>
                            <td><?php echo esc_html( $row['run_at'] ); ?></td>
                            <td><span class="gs-badge gs-badge-<?php echo esc_attr( $row['trigger_by'] ); ?>"><?php echo esc_html( $row['trigger_by'] ); ?></span></td>
                            <td class="gs-num green"><?php echo (int) $row['created']; ?></td>
                            <td class="gs-num blue"><?php echo (int) $row['updated']; ?></td>
                            <td class="gs-num"><?php echo (int) $row['skipped']; ?></td>
                            <td class="gs-num orange"><?php echo (int) $row['trashed']; ?></td>
                            <td class="gs-num red"><?php echo (int) $row['errors']; ?></td>
                            <td><?php echo esc_html( $row['duration'] ); ?>s</td>
                            <td class="gs-notes"><?php echo esc_html( $row['notes'] ); ?></td>
                            <td>
                                <?php if ( $has_snapshot ) : ?>
                                    <button type="button" class="button button-small gs-rollback-btn"
                                            data-log-id="<?php echo $log_id; ?>"
                                            title="Restore all products to their state before this sync run">
                                        ↩ Rollback
                                    </button>
                                <?php else : ?>
                                    <span style="color:#ccc;font-size:11px;">no snapshot</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /* ── Tab: Help ─────────────────────────────────────────── */

    private static function tab_help(): void {
        ?>
        <div class="gs-help">
            <h2>Getting Started</h2>
            <ol>
                <li>Make your Google Sheet <strong>publicly accessible</strong> (Share → Anyone with the link → Viewer).</li>
                <li>Paste the Sheet URL in the <strong>Settings</strong> tab and save.</li>
                <li>Go to <strong>Column Mapping</strong>, click <em>Load Sheet Headers</em>, then map each column to a WooCommerce field or meta key.</li>
                <li>Click <strong>Run Sync Now</strong> to test, then set an auto-sync schedule.</li>
            </ol>

            <h2>Column Mapping</h2>
            <ul>
                <li><strong>WC Core Field</strong> — maps to a native WooCommerce product field (name, price, stock, etc.)</li>
                <li><strong>Meta Key</strong> — maps to any <code>wp_postmeta</code> key. The dropdown is populated live from your existing WooCommerce products so it always reflects your latest meta fields.</li>
                <li>You can also type a <strong>custom meta key</strong> directly if it doesn't exist yet.</li>
            </ul>

            <h2>Supported Core Fields</h2>
            <table class="widefat">
                <thead><tr><th>Field</th><th>Description</th></tr></thead>
                <tbody>
                    <?php foreach ( GS_Woo_Sync_Settings::core_fields() as $key => $label ) :
                        if ( ! $key ) continue; ?>
                        <tr><td><code><?php echo esc_html( $key ); ?></code></td><td><?php echo esc_html( $label ); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Image Sync</h2>
            <p>Images are sideloaded into your Media Library. Re-download is skipped if the URL hasn't changed since the last sync.</p>

            <h2>Trash on Removal</h2>
            <p>When enabled, any product whose SKU is no longer present in the sheet will be moved to the WooCommerce trash after a sync run.</p>
        </div>
        <?php
    }

    /* ── Save handlers ─────────────────────────────────────── */

    private static function save_settings(): void {
        // sanitize_text_field preserves the full URL without stripping characters
        // esc_url_raw was mangling Google Sheets URLs (removing query params, hash fragments)
        $raw_url = sanitize_text_field( wp_unslash( $_POST['sheet_url'] ?? '' ) );
        GS_Woo_Sync_Settings::save( [
            'sheet_url'     => $raw_url,
            'schedule'      => sanitize_key( $_POST['schedule'] ?? 'hourly' ),
            'trash_removed' => ! empty( $_POST['trash_removed'] ) ? 1 : 0,
            'notify_when'   => sanitize_key( $_POST['notify_when'] ?? 'off' ),
            'notify_email'  => sanitize_email( $_POST['notify_email'] ?? '' ),
        ] );
        GS_Woo_Sync_Scheduler::reschedule();
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    /**
     * Parse POST arrays into column_map / meta_map and save.
     * Used by both the form submit and the AJAX save endpoint.
     */
    private static function parse_and_save_mapping( array $post ): array {
        $cols        = array_map( 'sanitize_text_field', $post['sheet_col']      ?? [] );
        $wc_fields   = array_map( 'sanitize_key',         $post['wc_field']      ?? [] );
        $meta_keys   = array_map( 'sanitize_text_field',  $post['meta_key']      ?? [] );
        $meta_custom = array_map( 'sanitize_text_field',  $post['meta_key_custom'] ?? [] );

        $column_map = [];
        $meta_map   = [];

        foreach ( $cols as $i => $col ) {
            if ( ! $col ) continue;

            // Skip attribute columns — they belong in attribute_map, not here
            if ( preg_match( '/^attribute\s+\d+\s+(name|value\(s\)|values|visible|global)$/i', $col ) ) continue;

            $wc            = $wc_fields[ $i ]   ?? '';
            $meta          = $meta_keys[ $i ]   ?? '';
            $cust          = $meta_custom[ $i ] ?? '';
            $resolved_meta = $meta ?: $cust;

            // Skip numeric-only meta keys — these are leftover from checkbox accidents
            if ( $resolved_meta && is_numeric( $resolved_meta ) ) continue;

            if ( $wc ) {
                $column_map[ $col ] = $wc;
            } elseif ( $resolved_meta ) {
                $meta_map[ $col ] = $resolved_meta;
            }
        }

        // Save attribute map if this is an attribute form save
        $attribute_map = [];
        if ( ! empty( $post['is_attribute_save'] ) ) {
            $attr_names      = array_map( 'sanitize_text_field', $post['attr_name']       ?? [] );
            $attr_value_cols = array_map( 'sanitize_text_field', $post['attr_values_col'] ?? [] );
            // Checkboxes only submit checked values, breaking numeric index alignment.
            // We encode visible/global as hidden fields with per-row values instead.
            $attr_visible = array_map( 'intval', $post['attr_visible_val'] ?? [] );
            $attr_global  = array_map( 'intval', $post['attr_global_val']  ?? [] );

            foreach ( $attr_names as $i => $attr_name ) {
                if ( ! $attr_name ) continue;
                $attribute_map[ $attr_name ] = [
                    'values_col' => $attr_value_cols[ $i ] ?? '',
                    'visible'    => $attr_visible[ $i ] ?? 1,
                    'global'     => $attr_global[ $i ]  ?? 0,
                ];
            }
            GS_Woo_Sync_Settings::save( [ 'attribute_map' => $attribute_map ] );
            return [ 'column_map' => [], 'meta_map' => [], 'attribute_map' => $attribute_map ];
        }

        GS_Woo_Sync_Settings::save( [
            'column_map' => $column_map,
            'meta_map'   => $meta_map,
        ] );

        return [ 'column_map' => $column_map, 'meta_map' => $meta_map ];
    }

    private static function save_mapping(): void {
        $result = self::parse_and_save_mapping( $_POST );
        echo '<div class="notice notice-success"><p>Column mapping saved — '
            . count( $result['column_map'] ) . ' core fields, '
            . count( $result['meta_map'] ) . ' meta fields.</p></div>';
    }

    /* ── AJAX handlers ─────────────────────────────────────── */

    public static function ajax_run(): void {
        check_ajax_referer( 'gs_woo_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        try {
            $importer = new GS_Woo_Sync_Importer();
            $result   = $importer->run( 'manual' );
            wp_send_json_success( $result );
        } catch ( Throwable $e ) {
            wp_send_json_error( 'Sync error: ' . $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        }
    }

    public static function ajax_clear_log(): void {
        check_ajax_referer( 'gs_woo_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );
        GS_Woo_Sync_Log::clear();
        wp_send_json_success( [ 'message' => 'Log cleared.' ] );
    }

    public static function ajax_get_headers(): void {
        check_ajax_referer( 'gs_woo_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        $url = sanitize_text_field( wp_unslash( $_POST['sheet_url'] ?? '' ) );
        if ( ! $url ) {
            $url = GS_Woo_Sync_Settings::get( 'sheet_url' );
        }
        if ( ! $url ) {
            wp_send_json_error( 'No sheet URL set.' );
        }

        $importer = new GS_Woo_Sync_Importer();
        $csv_url  = $importer->build_csv_url( $url );

        if ( ! $csv_url ) {
            wp_send_json_error( 'Could not parse a valid Google Sheets export URL from: ' . $url );
        }

        $response = wp_remote_get( $csv_url, [
            'timeout'     => 20,
            'redirection' => 10,
            'user-agent'  => 'Mozilla/5.0 (compatible; GS-Woo-Sync/' . GS_WOO_SYNC_VERSION . ')',
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            wp_send_json_error( "Sheet returned HTTP {$code}. Built URL: {$csv_url}" );
        }

        if ( str_starts_with( ltrim( $body ), '<' ) ) {
            wp_send_json_error( 'Sheet returned HTML — make sure the sheet is set to public (Anyone with the link → Viewer). Built URL: ' . $csv_url );
        }

        $body       = ltrim( $body, "\xEF\xBB\xBF" );
        $first_line = strtok( $body, "\n" );
        $headers    = array_map( 'trim', str_getcsv( $first_line ) );

        // Run auto-mapping and include suggestions in response
        $auto = GS_Woo_Sync_Settings::auto_map( $headers );

        wp_send_json_success( [
            'headers'   => $headers,
            'csv_url'   => $csv_url,
            'auto_map'  => $auto,
            'meta_keys' => GS_Woo_Sync_Settings::get_live_meta_keys(),
        ] );
    }

    public static function ajax_get_meta_keys(): void {
        check_ajax_referer( 'gs_woo_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );
        wp_send_json_success( [ 'meta_keys' => GS_Woo_Sync_Settings::get_live_meta_keys() ] );
    }

    /**
     * AJAX: Save mapping as serialised from the current table DOM state.
     * This is called by both the "Save Mapping" button (via form) and
     * the "Apply & Save" banner button (reads current dropdowns).
     */
    public static function ajax_save_mapping(): void {
        check_ajax_referer( 'gs_woo_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        $result = self::parse_and_save_mapping( $_POST );

        wp_send_json_success( [
            'message' => sprintf(
                'Mapping saved — %d core fields, %d meta fields.',
                count( $result['column_map'] ),
                count( $result['meta_map'] )
            ),
        ] );
    }

    /**
     * AJAX: Legacy auto-map endpoint (kept for back-compat, now just runs
     * auto_map and returns suggestions without saving — JS handles the save).
     */
    public static function ajax_apply_auto_map(): void {
        check_ajax_referer( 'gs_woo_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        $headers = array_map( 'sanitize_text_field', $_POST['headers'] ?? [] );
        if ( empty( $headers ) ) {
            wp_send_json_error( 'No headers provided.' );
        }

        $auto = GS_Woo_Sync_Settings::auto_map( $headers );
        wp_send_json_success( [ 'auto_map' => $auto ] );
    }

    /* ── Tab: Diagnostics ─────────────────────────────────── */

    private static function tab_diagnostics(): void {
        ?>
        <div class="gs-diagnostics">
            <h2>🔬 Live Diagnostics</h2>
            <p>Click the button below to run a full diagnostic check without syncing any products.</p>
            <button type="button" id="gs-run-diagnostics" class="button button-primary">▶ Run Diagnostics</button>
            <div id="gs-diag-output" style="display:none; margin-top:20px;"></div>
        </div>
        <?php
    }

    /* ── AJAX: Diagnostics ─────────────────────────────────── */

    public static function ajax_diagnostics(): void {
        check_ajax_referer( 'gs_woo_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        $report = [];
        $opts   = GS_Woo_Sync_Settings::get();

        /* ── 1. Raw DB value ── */
        $raw_db = get_option( GS_Woo_Sync_Settings::OPTION, [] );
        $report['raw_db_option']      = $raw_db;
        $report['raw_trash_removed']  = $raw_db['trash_removed'] ?? '(key not found in DB)';

        /* ── 2. Settings as resolved by get() ── */
        $report['resolved_settings'] = [
            'sheet_url'     => $opts['sheet_url']     ?: '(empty)',
            'schedule'      => $opts['schedule'],
            'trash_removed' => var_export( $opts['trash_removed'], true ),
            'column_map'    => $opts['column_map'],
            'meta_map'      => $opts['meta_map'] ?? [],
        ];

        /* ── 3. trash_removed evaluated ── */
        $trash_bool = filter_var( $opts['trash_removed'] ?? false, FILTER_VALIDATE_BOOLEAN );
        $report['trash_removed_evaluated'] = $trash_bool ? 'TRUE — trash will fire' : 'FALSE — trash will NOT fire';

        /* ── 4. SKU mapping ── */
        $sku_core_col = array_search( 'sku', $opts['column_map'] ?? [], true );
        $report['sku_mapped_column'] = $sku_core_col !== false ? $sku_core_col : '(no column mapped to SKU)';

        /* ── 5. Sheet headers & CSV fetch ── */
        $sheet_url = $opts['sheet_url'] ?? '';
        if ( $sheet_url ) {
            $importer = new GS_Woo_Sync_Importer();
            $csv_url  = $importer->build_csv_url( $sheet_url );
            $report['csv_url'] = $csv_url ?: '(could not build CSV URL)';

            if ( $csv_url ) {
                $response = wp_remote_get( $csv_url, [ 'timeout' => 20, 'redirection' => 10 ] );
                if ( is_wp_error( $response ) ) {
                    $report['csv_fetch'] = 'ERROR: ' . $response->get_error_message();
                } else {
                    $code = wp_remote_retrieve_response_code( $response );
                    $body = wp_remote_retrieve_body( $response );
                    $body = ltrim( $body, "ï»¿" );

                    if ( $code !== 200 ) {
                        $report['csv_fetch'] = "HTTP {$code}";
                    } elseif ( str_starts_with( ltrim( $body ), '<' ) ) {
                        $report['csv_fetch'] = 'ERROR: Got HTML — sheet is not public';
                    } else {
                        $first_line = strtok( $body, "
" );
                        $headers    = array_map( 'trim', str_getcsv( $first_line ) );
                        $report['csv_fetch']       = "OK — HTTP {$code}";
                        $report['sheet_headers']   = $headers;
                        $report['sheet_row_count'] = substr_count( $body, "
" );

                        // Parse all rows and collect seen SKUs
                        $lines    = array_filter( explode( "
", str_replace( "
", "
", $body ) ) );
                        $lines    = array_values( $lines );
                        array_shift( $lines ); // remove header
                        $seen_skus = [];
                        foreach ( $lines as $line ) {
                            $values = str_getcsv( $line );
                            if ( count( $values ) === count( $headers ) ) {
                                $row = array_combine( $headers, array_map( 'trim', $values ) );
                                // Try mapped column first
                                $sku = '';
                                if ( $sku_core_col !== false && isset( $row[ $sku_core_col ] ) ) {
                                    $sku = trim( $row[ $sku_core_col ] );
                                }
                                // Fallback: header named 'sku'
                                if ( ! $sku ) {
                                    foreach ( $row as $h => $v ) {
                                        if ( strtolower( trim( $h ) ) === 'sku' ) { $sku = trim( $v ); break; }
                                    }
                                }
                                if ( $sku ) $seen_skus[] = $sku;
                            }
                        }
                        $report['seen_skus_from_sheet'] = $seen_skus;
                    }
                }
            }
        } else {
            $report['csv_fetch'] = '(no sheet URL configured)';
        }

        /* ── 5b. Meta map cross-check ── */
        if ( isset( $report['sheet_headers'] ) ) {
            $meta_map   = $opts['meta_map'] ?? [];
            $col_check  = [];
            foreach ( $meta_map as $sheet_col => $meta_key ) {
                $found = in_array( $sheet_col, $report['sheet_headers'], true )
                      || in_array( trim( $sheet_col ), array_map( 'trim', $report['sheet_headers'] ), true );
                $col_check[] = [
                    'sheet_col' => $sheet_col,
                    'meta_key'  => $meta_key,
                    'found_in_sheet' => $found,
                ];
            }
            $report['meta_map_check'] = $col_check;
        }

        /* ── 6. Products in WooCommerce DB ── */
        $all_ids  = get_posts( [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $db_products = [];
        foreach ( $all_ids as $id ) {
            $sku    = get_post_meta( $id, '_sku', true );
            $status = get_post_status( $id );
            $db_products[] = [ 'id' => $id, 'sku' => $sku ?: '(no SKU)', 'status' => $status ];
        }
        $report['wc_products'] = $db_products;

        /* ── 7. Would-be trashed ── */
        if ( isset( $report['seen_skus_from_sheet'] ) && ! empty( $db_products ) ) {
            $seen     = $report['seen_skus_from_sheet'];
            $db_skus  = array_column( $db_products, 'sku' );
            $would_trash = array_values( array_filter( $db_skus, fn( $s ) => $s !== '(no SKU)' && ! in_array( $s, $seen, true ) ) );
            $report['would_be_trashed'] = $would_trash ?: '(none — all DB SKUs present in sheet)';
        }

        /* ── 8. Next cron run ── */
        $report['next_cron'] = GS_Woo_Sync_Scheduler::next_run();

        wp_send_json_success( $report );
    }

    /* ── AJAX: Rollback ────────────────────────────────────── */

    public static function ajax_rollback(): void {
        check_ajax_referer( 'gs_woo_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        $log_id = (int) ( $_POST['log_id'] ?? 0 );
        if ( ! $log_id ) wp_send_json_error( 'Invalid log ID.' );

        $result = GS_Woo_Sync_Rollback::restore( $log_id );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /* ── AJAX: Regenerate webhook token ─────────────────────── */

    public static function ajax_regen_token(): void {
        check_ajax_referer( 'gs_woo_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );

        $token = GS_Woo_Sync_Webhook::generate_token();
        $url   = GS_Woo_Sync_Webhook::get_webhook_url();
        wp_send_json_success( [
            'token'       => $token,
            'url'         => $url,
            'apps_script' => GS_Woo_Sync_Webhook::get_apps_script( $url ),
        ] );
    }

}
