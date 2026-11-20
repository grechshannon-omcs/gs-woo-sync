<?php
defined( 'ABSPATH' ) || exit;

class GS_Woo_Sync_Importer {

    private array $settings;
    private array $column_map;
    private array $meta_map;
    private array $attribute_map; // [ 'Colour' => ['name'=>'Colour','values'=>'Red|Blue','visible'=>1,'global'=>1], ... ]
    private array $results = [
        'created'        => 0,
        'updated'        => 0,
        'skipped'        => 0,
        'trashed'        => 0,
        'errors'         => 0,
        'notes'          => [],
        'created_items'  => [],  // [ ['sku'=>'', 'name'=>''] ]
        'updated_items'  => [],
        'trashed_items'  => [],
        'error_items'    => [],
    ];

    public function __construct() {
        $this->settings      = GS_Woo_Sync_Settings::get();
        $this->column_map    = $this->settings['column_map']    ?? [];
        $this->meta_map      = $this->settings['meta_map']      ?? [];
        $this->attribute_map = $this->settings['attribute_map'] ?? [];
    }

    /* ── Public entry ──────────────────────────────────────── */

    /** Option used as a run lock (add_option is atomic on the unique option_name). */
    const LOCK_OPTION = 'gs_woo_sync_running';

    /** A lock older than this (seconds) is treated as stale (crashed run). */
    const LOCK_TTL = 900;

    /** Refuse to trash more than this share of existing products in one run. */
    const MAX_TRASH_SHARE = 0.2;

    public function run( string $trigger = 'manual' ): array {
        $start = microtime( true );

        // Prevent overlapping runs (hourly cron + sheet webhook + manual
        // button can fire together and create duplicate products).
        if ( ! self::acquire_lock() ) {
            $this->results['notes'][] = 'Another sync is already running — this ' . $trigger . ' run was skipped.';
            return $this->results;
        }
        try {
            return $this->run_locked( $trigger, $start );
        } finally {
            self::release_lock();
        }
    }

    private static function acquire_lock(): bool {
        $now = time();
        if ( add_option( self::LOCK_OPTION, (string) $now, '', false ) ) {
            return true;
        }
        $since = (int) get_option( self::LOCK_OPTION, 0 );
        if ( $since > 0 && ( $now - $since ) < self::LOCK_TTL ) {
            return false;
        }
        // Stale lock from a crashed run: take it over.
        delete_option( self::LOCK_OPTION );
        return add_option( self::LOCK_OPTION, (string) $now, '', false );
    }

    private static function release_lock(): void {
        delete_option( self::LOCK_OPTION );
    }

    private function run_locked( string $trigger, float $start ): array {

        $url = $this->build_csv_url( $this->settings['sheet_url'] ?? '' );
        if ( ! $url ) {
            $this->results['notes'][] = 'Invalid or missing Google Sheet URL.';
            $this->results['errors']++;
            $this->log( $trigger, microtime( true ) - $start );
            return $this->results;
        }

        $rows = $this->fetch_csv( $url );
        if ( is_wp_error( $rows ) ) {
            $this->results['notes'][] = $rows->get_error_message();
            $this->results['errors']++;
            $this->log( $trigger, microtime( true ) - $start );
            return $this->results;
        }

        if ( empty( $rows ) ) {
            $this->results['notes'][] = 'Sheet returned no data rows.';
            $this->log( $trigger, microtime( true ) - $start );
            return $this->results;
        }

        /* Verify SKU column is mapped before proceeding */
        if ( ! $this->sku_is_mapped( $rows[0] ) ) {
            $this->results['errors']++;
            $this->results['notes'][] = 'Sync aborted: No SKU column mapped. Go to Column Mapping and map a column to "SKU".';
            $this->log( $trigger, microtime( true ) - $start );
            return $this->results;
        }

        /* Collect all product IDs that will be touched — for snapshot */
        $seen_skus      = [];
        $ids_to_snapshot = [];

        foreach ( $rows as $row ) {
            $sku = $this->get_field( $row, 'sku' );
            if ( $sku ) {
                $seen_skus[] = $sku;
                $existing_id = wc_get_product_id_by_sku( $sku );
                if ( $existing_id ) {
                    $ids_to_snapshot[] = $existing_id;
                }
            }
        }

        /* Also snapshot products that would be trashed */
        if ( filter_var( $this->settings['trash_removed'] ?? false, FILTER_VALIDATE_BOOLEAN ) && ! empty( $seen_skus ) ) {
            $all_ids = get_posts( [
                'post_type'      => 'product',
                'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ] );
            foreach ( $all_ids as $id ) {
                $sku = get_post_meta( $id, '_sku', true );
                if ( $sku && ! in_array( $sku, $seen_skus, true ) ) {
                    $ids_to_snapshot[] = $id;
                }
            }
        }

        /* Write a log entry first so we have a log_id for the snapshot */
        $log_id = $this->log( $trigger, 0, true ); // preliminary log

        /* Take snapshot of all products about to be touched */
        if ( $log_id && ! empty( $ids_to_snapshot ) ) {
            GS_Woo_Sync_Rollback::snapshot( $log_id, array_unique( $ids_to_snapshot ) );
        }

        /* Run the actual sync */
        foreach ( $rows as $row ) {
            $this->upsert_product( $row );
        }

        if ( filter_var( $this->settings['trash_removed'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
            if ( empty( $seen_skus ) ) {
                $this->results['notes'][] = 'Trash-on-removal skipped: no SKUs were read from the sheet (check SKU column mapping).';
            } else {
                $this->trash_missing( $seen_skus );
            }
        }

        /* Prune old snapshots — keep last 10 */
        GS_Woo_Sync_Rollback::prune( 10 );

        /* Update the log entry with final results */
        $duration = microtime( true ) - $start;
        $this->results['log_id'] = $log_id;
        $this->log( $trigger, $duration, false, $log_id );

        /* Send email notification */
        $this->results['duration'] = $duration;
        GS_Woo_Sync_Notifier::maybe_send( $this->results, $trigger );

        return $this->results;
    }

    /* ── URL builder ───────────────────────────────────────── */

    public function build_csv_url( string $raw ): string {
        if ( ! $raw ) return '';

        // Strip any whitespace that may have crept in
        $raw = trim( $raw );

        // Already a direct CSV export URL — return as-is
        if ( str_contains( $raw, '/export?format=csv' ) ) {
            return $raw;
        }

        // Handle /pub?output=csv (published sheet URL)
        if ( str_contains( $raw, '/pub' ) && str_contains( $raw, 'output=csv' ) ) {
            return $raw;
        }

        // Convert /pub?gid=xxx to export URL
        if ( str_contains( $raw, '/pub' ) ) {
            if ( preg_match( '/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $raw, $m ) ) {
                $id  = $m[1];
                $gid = '';
                if ( preg_match( '/[#&?]gid=(\d+)/', $raw, $g ) ) {
                    $gid = '&gid=' . $g[1];
                }
                return "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv{$gid}";
            }
        }

        // Standard Google Sheets edit/view URL
        // Handles: /edit, /edit#gid=, /edit?usp=sharing, /view, etc.
        if ( preg_match( '/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $raw, $m ) ) {
            $id  = $m[1];
            $gid = '';
            if ( preg_match( '/[#&?]gid=(\d+)/', $raw, $g ) ) {
                $gid = '&gid=' . $g[1];
            }
            return "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv{$gid}";
        }

        // Google Drive file link: drive.google.com/file/d/{ID}/view
        // Used for CSV/Excel files hosted in Drive (not native Sheets)
        if ( preg_match( '/\/file\/d\/([a-zA-Z0-9_-]+)/', $raw, $m ) ) {
            return "https://drive.google.com/uc?export=download&id={$m[1]}";
        }

        // Google Drive open link: drive.google.com/open?id={ID}
        if ( preg_match( '/[?&]id=([a-zA-Z0-9_-]+)/', $raw, $m ) ) {
            return "https://drive.google.com/uc?export=download&id={$m[1]}";
        }

        return '';
    }

    /* ── CSV fetch ─────────────────────────────────────────── */

    private function fetch_csv( string $url ): array|WP_Error {
        $args = [
            'timeout'     => 45,
            'redirection' => 10,   // Google redirects several times
            'user-agent'  => 'Mozilla/5.0 (compatible; GS-Woo-Sync/' . GS_WOO_SYNC_VERSION . ')',
            'sslverify'   => true,
            'headers'     => [
                'Accept' => 'text/csv,text/plain,*/*',
            ],
        ];



        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'fetch_failed',
                'HTTP request failed: ' . $response->get_error_message() . ' (code: ' . $response->get_error_code() . ')'
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            // Include first 300 chars of body for diagnosis
            $preview = substr( strip_tags( $body ), 0, 300 );
            return new WP_Error( 'http_error', "Sheet returned HTTP {$code}. Response: {$preview}" );
        }

        if ( ! $body ) {
            return new WP_Error( 'empty_body', 'Sheet response body was empty.' );
        }

        // Detect if Google returned an HTML error page instead of CSV
        if ( str_starts_with( ltrim( $body ), '<' ) ) {
            $preview = substr( strip_tags( $body ), 0, 300 );
            return new WP_Error( 'html_response', "Sheet returned HTML instead of CSV. Check the sheet is public. Preview: {$preview}" );
        }

        return $this->parse_csv( $body );
    }

    private function parse_csv( string $body ): array {
        // Remove BOM
        $body = ltrim( $body, "\xEF\xBB\xBF" );
        if ( '' === trim( $body ) ) return [];

        // Read as a real CSV stream so quoted cells may contain line breaks
        // (e.g. descriptions written with paragraphs).
        $fh = fopen( 'php://temp', 'r+' );
        fwrite( $fh, $body );
        rewind( $fh );

        $headers = fgetcsv( $fh, 0, ',', '"', '' );
        if ( ! $headers ) {
            fclose( $fh );
            return [];
        }
        $headers = array_map( 'trim', $headers );

        $rows = [];
        while ( ( $values = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
            if ( [ null ] === $values ) continue; // blank line
            // Rows that end early (trailing empty cells left out) are padded with blanks.
            if ( count( $values ) < count( $headers ) ) {
                $values = array_pad( $values, count( $headers ), '' );
            }
            if ( count( $values ) === count( $headers ) ) {
                $rows[] = array_combine( $headers, array_map( 'trim', $values ) );
            }
        }
        fclose( $fh );

        return $rows;
    }

    /* ── Upsert product ────────────────────────────────────── */

    private function upsert_product( array $row ): void {
        $sku = $this->get_field( $row, 'sku' );
        if ( ! $sku ) {
            $this->results['skipped']++;
            return;
        }

        /**
         * Let other plugins hold back a row, e.g. while a change made on the website
         * has not reached the sheet yet (so the sync does not undo it).
         *
         * @param bool   $skip Skip this row.
         * @param string $sku  Row SKU.
         * @param array  $row  Row values keyed by sheet header.
         */
        if ( apply_filters( 'gs_woo_sync_skip_row', false, $sku, $row ) ) {
            $this->results['skipped']++;
            $this->results['notes'][] = "Held back {$sku}: a website change is waiting to be written to the sheet.";
            return;
        }

        $product_id = wc_get_product_id_by_sku( $sku );
        $is_new     = ! $product_id;

        if ( $is_new ) {
            $type = $this->get_field( $row, 'type' ) ?: 'simple';

            // Create the post first with a placeholder title
            $product_id = wp_insert_post( [
                'post_type'   => 'product',
                'post_status' => 'draft', // draft until fully populated
                'post_title'  => $this->get_field( $row, 'name' ) ?: $sku,
            ] );

            if ( is_wp_error( $product_id ) ) {
                $this->results['errors']++;
                $msg = "Failed to create product SKU {$sku}: " . $product_id->get_error_message();
                $this->results['notes'][]       = $msg;
                $this->results['error_items'][] = [ 'sku' => $sku, 'name' => $sku, 'reason' => $msg ];
                return;
            }

            wp_set_object_terms( $product_id, $type, 'product_type' );

            // Write SKU directly to postmeta BEFORE loading via wc_get_product()
            // This prevents a second sync run creating a duplicate post
            update_post_meta( $product_id, '_sku', $sku );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            $this->results['errors']++;
            $this->results['notes'][] = "Could not load product object for ID {$product_id} (SKU: {$sku})";
            return;
        }

        /* ── Core fields ── */
        if ( $v = $this->get_field( $row, 'name' ) )              $product->set_name( $v );

        // SKU: only set if not already correct, to avoid WC duplicate-SKU exception
        $current_sku = $product->get_sku();
        if ( $current_sku !== $sku ) {
            try {
                $product->set_sku( $sku );
            } catch ( Exception $e ) {
                $this->results['notes'][] = "SKU conflict for {$sku}: " . $e->getMessage();
            }
        }
        if ( $v = $this->get_field( $row, 'regular_price' ) )     $product->set_regular_price( $v );
        if ( '' !== ( $v = $this->get_field( $row, 'sale_price' ) ) ) $product->set_sale_price( $v );
        if ( $v = $this->get_field( $row, 'description' ) )       $product->set_description( $v );
        if ( $v = $this->get_field( $row, 'short_description' ) ) $product->set_short_description( $v );
        if ( $v = $this->get_field( $row, 'weight' ) )            $product->set_weight( $v );
        if ( $v = $this->get_field( $row, 'length' ) )            $product->set_length( $v );
        if ( $v = $this->get_field( $row, 'width' ) )             $product->set_width( $v );
        if ( $v = $this->get_field( $row, 'height' ) )            $product->set_height( $v );

        /* Stock ── quantity drives status automatically if no explicit status column */
        $stock_qty_raw    = $this->get_field( $row, 'stock_quantity' );
        $stock_status_raw = $this->get_field( $row, 'stock_status' );

        if ( '' !== $stock_qty_raw ) {
            $qty = (int) $stock_qty_raw;
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $qty );

            // Derive stock status from quantity unless sheet has an explicit status column
            if ( $stock_status_raw === '' ) {
                $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
            }
        }

        if ( $stock_status_raw !== '' ) {
            // Normalise common sheet values to WC stock status strings
            $status_map = [
                '1'         => 'instock',
                'yes'       => 'instock',
                'true'      => 'instock',
                'in stock'  => 'instock',
                'instock'   => 'instock',
                '0'         => 'outofstock',
                'no'        => 'outofstock',
                'false'     => 'outofstock',
                'out of stock' => 'outofstock',
                'outofstock'   => 'outofstock',
                'onbackorder'  => 'onbackorder',
                'backorder'    => 'onbackorder',
            ];
            $normalised = $status_map[ strtolower( trim( $stock_status_raw ) ) ] ?? $stock_status_raw;
            $product->set_stock_status( $normalised );
        }

        /* Status — always publish new products unless sheet says otherwise */
        $status_val = $this->get_field( $row, 'status' );
        if ( $status_val ) {
            // Sheet value '1' or 'yes' or 'true' means publish
            if ( in_array( strtolower( $status_val ), [ '1', 'yes', 'true', 'publish', 'published' ], true ) ) {
                $product->set_status( 'publish' );
            } elseif ( in_array( strtolower( $status_val ), [ '0', 'no', 'false', 'draft' ], true ) ) {
                $product->set_status( 'draft' );
            } else {
                $product->set_status( sanitize_key( $status_val ) );
            }
        } elseif ( $is_new ) {
            // No status column mapped — default new products to publish
            $product->set_status( 'publish' );
        }

        /* Categories */
        if ( $v = $this->get_field( $row, 'categories' ) ) {
            $product->set_category_ids( $this->resolve_categories( $v ) );
        }

        /* Tags */
        if ( $v = $this->get_field( $row, 'tags' ) ) {
            $this->set_tags( $product_id, $v );
        }

        /* Brands */
        if ( $v = $this->get_field( $row, 'brands' ) ) {
            $this->set_brands( $product_id, $v );
        }

        /* GTIN */
        if ( $v = $this->get_field( $row, 'gtin' ) ) {
            update_post_meta( $product_id, '_gtin', $v );
        }

        /* ── Attributes ── */
        $this->sync_attributes( $product, $row );

        /* Save product */
        $product->save();

        /* ── Images: first URL = main image, any further URLs = gallery ── */
        if ( $v = $this->get_field( $row, 'images' ) ) {
            $urls = self::split_image_urls( $v );
            if ( $urls ) {
                $this->maybe_sideload_image( $product_id, $urls[0] );
                $this->maybe_sync_gallery( $product_id, array_slice( $urls, 1 ) );
            }
        }

        /* ── Meta map ── */
        // Build the resolved meta pairs first
        $meta_to_write = $this->resolve_meta_pairs( $row );

        // Write immediately (catches most cases)
        foreach ( $meta_to_write as $meta_key => $value ) {
            update_post_meta( $product_id, $meta_key, $value );
        }

        // Also write on a late hook AFTER all other plugins (e.g. B2B/wholesale plugins)
        // have finished processing their own save actions — this ensures our values win.
        if ( ! empty( $meta_to_write ) ) {
            $pid   = $product_id;
            $pairs = $meta_to_write;
            add_action( 'woocommerce_after_product_object_save', static function( $saved_product ) use ( $pid, $pairs ) {
                if ( (int) $saved_product->get_id() !== $pid ) return;
                foreach ( $pairs as $meta_key => $value ) {
                    update_post_meta( $pid, $meta_key, $value );
                }
            }, 999 );

            // Fallback: also hook save_post at very late priority
            add_action( 'save_post_product', static function( $post_id ) use ( $pid, $pairs ) {
                if ( (int) $post_id !== $pid ) return;
                foreach ( $pairs as $meta_key => $value ) {
                    update_post_meta( $pid, $meta_key, $value );
                }
            }, 999 );
        }

        $label = $product->get_name() ?: $sku;
        if ( $is_new ) {
            $this->results['created']++;
            $this->results['created_items'][] = [ 'sku' => $sku, 'name' => $label ];
        } else {
            $this->results['updated']++;
            $this->results['updated_items'][] = [ 'sku' => $sku, 'name' => $label ];
        }
    }

    /* ── Helper: verify SKU column is mapped ──────────────────── */

    private function sku_is_mapped( array $sample_row ): bool {
        // Check explicit column_map has a 'sku' mapping
        if ( in_array( 'sku', $this->column_map, true ) ) {
            return true;
        }
        // Fallback: check if any header in the row naturally matches 'sku'
        foreach ( array_keys( $sample_row ) as $header ) {
            if ( strtolower( trim( $header ) ) === 'sku' ) {
                return true;
            }
        }
        return false;
    }

    /* ── Helper: get mapped field value from row ───────────── */

    private function get_field( array $row, string $wc_field ): string {
        // Find the sheet column mapped to this wc_field
        $col = array_search( $wc_field, $this->column_map, true );
        if ( $col !== false && isset( $row[ $col ] ) ) {
            return trim( $row[ $col ] );
        }
        // Fallback: look for a column with a matching name
        foreach ( $row as $header => $value ) {
            if ( strtolower( trim( $header ) ) === strtolower( $wc_field ) ) {
                return trim( $value );
            }
        }
        return '';
    }

    /* ── Categories ────────────────────────────────────────── */

    /**
     * Resolve a raw categories string into an array of term IDs.
     *
     * Supports:
     *   - Multiple categories separated by commas:  "Fasteners, Tools"
     *   - Hierarchy using > separator:              "Fasteners > Bolts"
     *   - Combined:                                 "Fasteners, Fasteners > Bolts"
     *
     * Parent categories are created automatically if they don't exist.
     * The full hierarchy chain is always ensured before inserting the child.
     */
    private function resolve_categories( string $raw ): array {
        $ids     = [];
        // Split on comma — each entry may itself be a hierarchy chain
        $entries = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

        foreach ( $entries as $entry ) {
            // Normalise the > separator — handle &gt; (HTML entity), UTF-8 › and plain >
            $entry = html_entity_decode( $entry, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $entry = str_replace( [ '›', '→', '>>' ], '>', $entry );

            // Split into hierarchy levels: "Fasteners > Bolts" => ['Fasteners', 'Bolts']
            $levels = array_filter( array_map( 'trim', explode( '>', $entry ) ) );

            if ( empty( $levels ) ) continue;

            $parent_id = 0;

            foreach ( $levels as $level_name ) {
                $term = get_term_by( 'name', $level_name, 'product_cat' );

                if ( $term ) {
                    // Term exists — but verify it's under the correct parent
                    // (same name can exist under different parents)
                    if ( $parent_id > 0 ) {
                        // Look for the term specifically under this parent
                        $children = get_terms( [
                            'taxonomy'   => 'product_cat',
                            'name'       => $level_name,
                            'parent'     => $parent_id,
                            'hide_empty' => false,
                        ] );
                        if ( ! empty( $children ) && ! is_wp_error( $children ) ) {
                            $term = $children[0];
                        } else {
                            // Doesn't exist under this parent — create it there
                            $result = wp_insert_term( $level_name, 'product_cat', [ 'parent' => $parent_id ] );
                            if ( is_wp_error( $result ) ) {
                                // May already exist — try fetching again
                                $existing = get_term_by( 'name', $level_name, 'product_cat' );
                                $term     = $existing ?: null;
                            } else {
                                $term = get_term( $result['term_id'], 'product_cat' );
                            }
                        }
                    }
                    $parent_id = $term ? $term->term_id : $parent_id;
                } else {
                    // Term doesn't exist — create it under the current parent
                    $args   = $parent_id > 0 ? [ 'parent' => $parent_id ] : [];
                    $result = wp_insert_term( $level_name, 'product_cat', $args );

                    if ( is_wp_error( $result ) ) {
                        // Might be a race condition duplicate — try fetching
                        $existing  = get_term_by( 'name', $level_name, 'product_cat' );
                        $parent_id = $existing ? $existing->term_id : $parent_id;
                        if ( $existing ) $term = $existing;
                    } else {
                        $parent_id = $result['term_id'];
                        $term      = get_term( $result['term_id'], 'product_cat' );
                    }
                }
            }

            // The last level in the chain is the actual category to assign
            if ( $parent_id > 0 ) {
                $ids[] = $parent_id;
            }
        }

        return array_unique( array_filter( $ids ) );
    }

    /* ── Resolve meta pairs from a row ────────────────────── */

    /**
     * Build an array of [ meta_key => value ] from the current row,
     * applying all validation/skip rules in one place.
     */
    private function resolve_meta_pairs( array $row ): array {
        $pairs = [];
        foreach ( $this->meta_map as $sheet_col => $meta_key ) {
            if ( ! $meta_key ) continue;
            if ( preg_match( '/^attribute\s+\d+\s+(name|value\(s\)|values|visible|global)$/i', $sheet_col ) ) continue;
            if ( is_numeric( $meta_key ) ) continue;

            $value = null;
            if ( array_key_exists( $sheet_col, $row ) ) {
                $value = $row[ $sheet_col ];
            } elseif ( array_key_exists( trim( $sheet_col ), $row ) ) {
                $value = $row[ trim( $sheet_col ) ];
            } else {
                foreach ( $row as $col => $v ) {
                    if ( strcasecmp( trim( $col ), trim( $sheet_col ) ) === 0 ) {
                        $value = $v;
                        break;
                    }
                }
            }
            if ( $value !== null ) {
                $pairs[ $meta_key ] = $value;
            }
        }
        return $pairs;
    }

    /* ── Attributes ───────────────────────────────────────── */

    /**
     * Build and assign WooCommerce product attributes from the row.
     *
     * Supports two modes:
     *  A) attribute_map (from settings): maps sheet columns to named attributes
     *     e.g. { "Colour": { "name_col": "Colour", "values_col": "Colour Values" } }
     *  B) Auto-detected WC export columns: "Attribute 1 Name" + "Attribute 1 value(s)"
     */
    private function sync_attributes( WC_Product $product, array $row ): void {
        $attributes = [];

        /* ── Mode A: explicit attribute_map from settings ── */
        foreach ( $this->attribute_map as $attr_name => $cols ) {
            $name_col   = $cols['name_col']   ?? '';
            $values_col = $cols['values_col'] ?? '';
            $visible    = (bool) ( $cols['visible'] ?? 1 );
            $global     = (bool) ( $cols['global']  ?? 0 );

            // Attribute name: use the mapped column value, or the key itself
            $name = $name_col ? ( trim( $row[ $name_col ] ?? '' ) ?: $attr_name ) : $attr_name;
            $raw  = $values_col ? trim( $row[ $values_col ] ?? '' ) : '';

            if ( $name && $raw ) {
                $attributes[] = $this->build_attribute( $name, $raw, $visible, $global );
            }
        }

        /* ── Mode B: WC export "Attribute N Name / value(s)" columns ── */
        // Collect all numbered attribute groups from the row headers
        $numbered = [];
        foreach ( $row as $col => $val ) {
            if ( preg_match( '/^attribute\s+(\d+)\s+name$/i', $col, $m ) ) {
                $numbered[ (int) $m[1] ]['name_col'] = $col;
            } elseif ( preg_match( '/^attribute\s+(\d+)\s+value\(s\)$/i', $col, $m ) ||
                       preg_match( '/^attribute\s+(\d+)\s+values$/i', $col, $m ) ) {
                $numbered[ (int) $m[1] ]['values_col'] = $col;
            } elseif ( preg_match( '/^attribute\s+(\d+)\s+visible$/i', $col, $m ) ) {
                $numbered[ (int) $m[1] ]['visible_col'] = $col;
            } elseif ( preg_match( '/^attribute\s+(\d+)\s+global$/i', $col, $m ) ) {
                $numbered[ (int) $m[1] ]['global_col'] = $col;
            }
        }

        ksort( $numbered );
        foreach ( $numbered as $grp ) {
            $name    = isset( $grp['name_col'] )    ? trim( $row[ $grp['name_col'] ]    ?? '' ) : '';
            $raw     = isset( $grp['values_col'] )  ? trim( $row[ $grp['values_col'] ]  ?? '' ) : '';
            $visible = isset( $grp['visible_col'] ) ? (bool) $row[ $grp['visible_col'] ] : true;
            $global  = isset( $grp['global_col'] )  ? (bool) $row[ $grp['global_col'] ] : false;

            if ( $name && $raw ) {
                $attributes[] = $this->build_attribute( $name, $raw, $visible, $global );
            }
        }

        if ( empty( $attributes ) ) return;

        // Merge with existing attributes so we don't wipe attributes set elsewhere
        $existing = $product->get_attributes();
        foreach ( $attributes as $attr ) {
            $slug = $attr->get_name();
            $existing[ $slug ] = $attr;
        }
        $product->set_attributes( $existing );
    }

    /**
     * Build a WC_Product_Attribute from a name + pipe/comma-separated values string.
     *
     * WC_Product_Attribute API (correct methods):
     *   set_id(int)       — 0 for local, attribute taxonomy ID for global
     *   set_name(string)  — taxonomy slug (e.g. pa_colour) for global, plain name for local
     *   set_options(array)— term IDs for global, plain strings for local
     *   set_position(int) — sort order
     *   set_visible(bool) — show on product page
     *   set_variation(bool) — used for variations
     *
     * There is NO set_taxonomy() — is_taxonomy is derived from whether id > 0.
     */
    private function build_attribute( string $name, string $raw_values, bool $visible, bool $global ): WC_Product_Attribute {
        $separator = strpos( $raw_values, '|' ) !== false ? '|' : ',';
        $values    = array_values( array_filter( array_map( 'trim', explode( $separator, $raw_values ) ) ) );

        $attr = new WC_Product_Attribute();
        $attr->set_visible( $visible );
        $attr->set_variation( false );
        $attr->set_position( 0 );

        if ( $global ) {
            $taxonomy = wc_attribute_taxonomy_name( $name );
            $attr_id  = 0;

            // Get or create the global attribute taxonomy
            $all_attrs     = wc_get_attribute_taxonomies();
            $slug          = wc_sanitize_taxonomy_name( $name );
            $existing_attr = null;
            foreach ( $all_attrs as $a ) {
                if ( $a->attribute_name === $slug || $a->attribute_label === $name ) {
                    $existing_attr = $a;
                    break;
                }
            }

            if ( $existing_attr ) {
                $attr_id = (int) $existing_attr->attribute_id;
            } elseif ( ! taxonomy_exists( $taxonomy ) ) {
                $result  = wc_create_attribute( [
                    'name'     => $name,
                    'slug'     => wc_sanitize_taxonomy_name( $name ),
                    'type'     => 'select',
                    'order_by' => 'menu_order',
                ] );
                if ( ! is_wp_error( $result ) ) {
                    $attr_id = $result;
                    // Re-register so we can insert terms immediately
                    register_taxonomy( $taxonomy, 'product' );
                }
            }

            // Ensure each term exists and collect IDs
            $term_ids = [];
            foreach ( $values as $value ) {
                $term = get_term_by( 'name', $value, $taxonomy );
                if ( ! $term ) {
                    $inserted = wp_insert_term( $value, $taxonomy );
                    if ( ! is_wp_error( $inserted ) ) {
                        $term_ids[] = (int) $inserted['term_id'];
                    }
                } else {
                    $term_ids[] = (int) $term->term_id;
                }
            }

            $attr->set_id( $attr_id );         // > 0 marks this as a taxonomy attribute
            $attr->set_name( $taxonomy );
            $attr->set_options( array_filter( $term_ids ) );

        } else {
            // Local attribute — id stays 0, options are plain strings
            $attr->set_id( 0 );
            $attr->set_name( $name );
            $attr->set_options( $values );
        }

        return $attr;
    }

    /* ── Tags ──────────────────────────────────────────────── */

    private function set_tags( int $product_id, string $raw ): void {
        $names = array_map( 'trim', explode( ',', $raw ) );
        wp_set_object_terms( $product_id, $names, 'product_tag' );
    }

    /* ── Brands ────────────────────────────────────────────── */

    private function set_brands( int $product_id, string $raw ): void {
        // Supports both 'product_brand' (Perfect Brands) and 'pwb-brand' taxonomies
        $tax   = taxonomy_exists( 'product_brand' ) ? 'product_brand' : ( taxonomy_exists( 'pwb-brand' ) ? 'pwb-brand' : '' );
        if ( ! $tax ) return;
        $names = array_map( 'trim', explode( ',', $raw ) );
        wp_set_object_terms( $product_id, $names, $tax );
    }

    /* ── Image sideload ────────────────────────────────────── */

    /**
     * Split an Images cell into URLs. Several images are separated by commas
     * ("main.jpg, side.jpg, back.jpg"); a split only happens before "http", so a
     * comma inside a single URL is left alone.
     */
    public static function split_image_urls( string $raw ): array {
        $parts = preg_split( '/\s*,\s*(?=https?:\/\/)/i', trim( $raw ) );
        return array_values( array_filter( array_map( 'trim', (array) $parts ) ) );
    }

    /**
     * Gallery images (2nd, 3rd … URL in the Images cell).
     * Only re-downloads when the list of URLs changes. Images already downloaded
     * for this product are reused (URL → attachment ID kept in post meta).
     * A cell with a single URL leaves a gallery alone unless this plugin set it.
     */
    private function maybe_sync_gallery( int $product_id, array $urls ): void {
        $signature = implode( '|', $urls );
        $previous  = (string) get_post_meta( $product_id, '_gs_woo_sync_gallery_urls', true );

        if ( $signature === $previous ) return;          // unchanged
        if ( ! $urls && $previous === '' ) return;        // single-image sheet, gallery not ours

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $known = get_post_meta( $product_id, '_gs_woo_sync_gallery_map', true );
        $known = is_array( $known ) ? $known : [];
        $ids   = [];
        $map   = [];

        foreach ( $urls as $url ) {
            $existing_id = (int) ( $known[ $url ] ?? 0 );
            if ( $existing_id && get_post( $existing_id ) ) {
                $ids[]       = $existing_id;
                $map[ $url ] = $existing_id;
                continue;
            }
            $tmp = download_url( $url );
            if ( is_wp_error( $tmp ) ) {
                $this->results['notes'][] = "Gallery image download failed for product {$product_id}: " . $tmp->get_error_message();
                continue;
            }
            $file_array = [
                'name'     => basename( parse_url( $url, PHP_URL_PATH ) ) ?: 'product-image.jpg',
                'tmp_name' => $tmp,
            ];
            $attachment_id = media_handle_sideload( $file_array, $product_id );
            if ( is_wp_error( $attachment_id ) ) {
                @unlink( $tmp );
                $this->results['notes'][] = "Gallery image sideload failed for product {$product_id}: " . $attachment_id->get_error_message();
                continue;
            }
            $ids[]       = (int) $attachment_id;
            $map[ $url ] = (int) $attachment_id;
        }

        $product = wc_get_product( $product_id );
        if ( $product ) {
            $product->set_gallery_image_ids( $ids );
            $product->save();
        }
        update_post_meta( $product_id, '_gs_woo_sync_gallery_map', $map );
        // Only remember the list once every image arrived, so failures are retried next sync.
        if ( count( $ids ) === count( $urls ) ) {
            update_post_meta( $product_id, '_gs_woo_sync_gallery_urls', $signature );
        }
    }

    private function maybe_sideload_image( int $product_id, string $url ): void {
        if ( ! $url ) return;

        $existing_url = get_post_meta( $product_id, '_gs_woo_sync_image_url', true );
        if ( $existing_url === $url ) return; // unchanged — skip re-download

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            $this->results['notes'][] = "Image download failed for product {$product_id}: " . $tmp->get_error_message();
            return;
        }

        $file_array = [
            'name'     => basename( parse_url( $url, PHP_URL_PATH ) ) ?: 'product-image.jpg',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $product_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            $this->results['notes'][] = "Image sideload failed for product {$product_id}: " . $attachment_id->get_error_message();
            return;
        }

        set_post_thumbnail( $product_id, $attachment_id );
        update_post_meta( $product_id, '_gs_woo_sync_image_url', $url );
    }

    /* ── Trash removed products ────────────────────────────── */

    private function trash_missing( array $seen_skus ): void {
        if ( empty( $seen_skus ) ) return;

        $all_ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        // Safety: if the sheet suddenly lacks a large share of products
        // (rows deleted/filtered by mistake, truncated download), do not
        // mass-trash — report and stop instead.
        $to_trash = 0;
        foreach ( $all_ids as $id ) {
            $sku = get_post_meta( $id, '_sku', true );
            if ( $sku && ! in_array( $sku, $seen_skus, true ) ) {
                $to_trash++;
            }
        }
        $limit = (int) floor( count( $all_ids ) * self::MAX_TRASH_SHARE );
        if ( $to_trash > max( 1, $limit ) ) {
            $this->results['errors']++;
            $this->results['notes'][] = sprintf(
                'Trash-on-removal skipped: %d of %d products are missing from the sheet (limit %d%%). Check the sheet, or trash them manually.',
                $to_trash,
                count( $all_ids ),
                (int) ( self::MAX_TRASH_SHARE * 100 )
            );
            return;
        }

        foreach ( $all_ids as $id ) {
            $sku  = get_post_meta( $id, '_sku', true );
            if ( $sku && ! in_array( $sku, $seen_skus, true ) ) {
                $name = get_the_title( $id );
                wp_trash_post( $id );
                $this->results['trashed']++;
                $this->results['trashed_items'][] = [ 'sku' => $sku, 'name' => $name ];
            }
        }
    }

    /* ── Log ───────────────────────────────────────────────── */

    private function log( string $trigger, float $duration, bool $preliminary = false, ?int $existing_log_id = null ): int {
        global $wpdb;

        $recap_parts = [];
        foreach ( $this->results['created_items'] as $item ) {
            $recap_parts[] = '+ ' . $item['sku'] . ' (' . $item['name'] . ')';
        }
        foreach ( $this->results['updated_items'] as $item ) {
            $recap_parts[] = '~ ' . $item['sku'] . ' (' . $item['name'] . ')';
        }
        foreach ( $this->results['trashed_items'] as $item ) {
            $recap_parts[] = '🗑 ' . $item['sku'] . ' (' . $item['name'] . ')';
        }
        foreach ( $this->results['notes'] as $note ) {
            $recap_parts[] = $note;
        }

        $data = [
            'run_at'     => current_time( 'mysql' ),
            'trigger_by' => $trigger,
            'created'    => $this->results['created'],
            'updated'    => $this->results['updated'],
            'skipped'    => $this->results['skipped'],
            'trashed'    => $this->results['trashed'],
            'errors'     => $this->results['errors'],
            'duration'   => round( $duration, 2 ),
            'notes'      => $preliminary ? '(in progress…)' : implode( ' | ', $recap_parts ),
        ];

        if ( $existing_log_id ) {
            // Update existing preliminary row with final results
            $wpdb->update( $wpdb->prefix . GS_Woo_Sync_Log::TABLE, $data, [ 'id' => $existing_log_id ] );
            return $existing_log_id;
        } else {
            GS_Woo_Sync_Log::insert( $data );
            return (int) $wpdb->insert_id;
        }
    }
}
