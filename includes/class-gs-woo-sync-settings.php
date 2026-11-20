<?php
defined( 'ABSPATH' ) || exit;

class GS_Woo_Sync_Settings {

    const OPTION = 'gs_woo_sync_settings';

    public static function defaults(): array {
        return [
            'sheet_url'       => '',
            'schedule'        => 'hourly',
            'trash_removed'   => 0,
            'column_map'      => [],
            'meta_map'        => [],
            // Notifications
            'notify_when'     => 'off',       // off | always | changes | errors | errors_trash
            'notify_email'    => '',          // blank = admin email
            // Attributes: [ 'Colour' => ['name_col'=>'', 'values_col'=>'Colour', 'visible'=>1, 'global'=>0] ]
            'attribute_map'   => [],
        ];
    }

    public static function get( string $key = '' ) {
        $saved = get_option( self::OPTION, [] );
        // Use array_merge so falsy saved values (0, false) are NOT overwritten by defaults
        $opts  = is_array( $saved ) ? array_merge( self::defaults(), $saved ) : self::defaults();
        return $key ? ( $opts[ $key ] ?? null ) : $opts;
    }

    public static function save( array $data ): void {
        $current = self::get();
        update_option( self::OPTION, array_merge( $current, $data ) );
    }

    /**
     * Core WC fields available for column mapping (non-meta).
     */
    public static function core_fields(): array {
        return [
            ''                  => '— Skip —',
            'name'              => 'Product Name',
            'sku'               => 'SKU',
            'regular_price'     => 'Regular Price',
            'sale_price'        => 'Sale Price',
            'sale_from'         => 'Sale Start Date',
            'sale_to'           => 'Sale End Date',
            'stock_quantity'    => 'Stock Quantity',
            'stock_status'      => 'Stock Status (instock/outofstock)',
            'description'       => 'Description',
            'short_description' => 'Short Description',
            'categories'        => 'Categories',
            'tags'              => 'Tags',
            'images'            => 'Image URL',
            'weight'            => 'Weight (kg)',
            'length'            => 'Length (cm)',
            'width'             => 'Width (cm)',
            'height'            => 'Height (cm)',
            'status'            => 'Published Status (publish/draft)',
            'type'              => 'Product Type',
            'brands'            => 'Brands',
            'gtin'              => 'GTIN / UPC / EAN / ISBN',
        ];
    }

    /**
     * Fetch all distinct meta keys from WooCommerce products live from DB.
     */
    public static function get_live_meta_keys(): array {
        global $wpdb;
        $keys = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'product'
               AND pm.meta_key != ''
             ORDER BY pm.meta_key ASC"
        );
        return is_array( $keys ) ? $keys : [];
    }

    /**
     * Known aliases: lowercase/normalised sheet header => WC core field key.
     */
    public static function core_field_aliases(): array {
        return [
            // Name
            'name'                    => 'name',
            'product name'            => 'name',
            'title'                   => 'name',
            'product title'           => 'name',
            // SKU
            'sku'                     => 'sku',
            'product sku'             => 'sku',
            'item code'               => 'sku',
            'item no'                 => 'sku',
            'product code'            => 'sku',
            'ref'                     => 'sku',
            'reference'               => 'sku',
            // Regular price
            'regular price'           => 'regular_price',
            'regular_price'           => 'regular_price',
            'price'                   => 'regular_price',
            'unit price'              => 'regular_price',
            'list price'              => 'regular_price',
            'retail price'            => 'regular_price',
            // Sale price
            'sale price'              => 'sale_price',
            'sale_price'              => 'sale_price',
            'promo price'             => 'sale_price',
            'discount price'          => 'sale_price',
            'special price'           => 'sale_price',
            // Sale dates
            'date sale price starts'  => 'sale_from',
            'sale start'              => 'sale_from',
            'sale start date'         => 'sale_from',
            'sale from'               => 'sale_from',
            'date sale price ends'    => 'sale_to',
            'sale end'                => 'sale_to',
            'sale end date'           => 'sale_to',
            'sale to'                 => 'sale_to',
            // Stock quantity
            'stock'                   => 'stock_quantity',
            'stock quantity'          => 'stock_quantity',
            'stock_quantity'          => 'stock_quantity',
            'qty'                     => 'stock_quantity',
            'quantity'                => 'stock_quantity',
            'inventory'               => 'stock_quantity',
            'available qty'           => 'stock_quantity',
            // Stock status
            'in stock?'               => 'stock_status',
            'in stock'                => 'stock_status',
            'stock status'            => 'stock_status',
            'stock_status'            => 'stock_status',
            'availability'            => 'stock_status',
            // Description
            'description'             => 'description',
            'long description'        => 'description',
            'product description'     => 'description',
            'full description'        => 'description',
            'body'                    => 'description',
            // Short description
            'short description'       => 'short_description',
            'short_description'       => 'short_description',
            'excerpt'                 => 'short_description',
            'summary'                 => 'short_description',
            'brief description'       => 'short_description',
            // Categories
            'categories'              => 'categories',
            'category'                => 'categories',
            'product category'        => 'categories',
            'product categories'      => 'categories',
            // Tags
            'tags'                    => 'tags',
            'tag'                     => 'tags',
            'product tags'            => 'tags',
            'keywords'                => 'tags',
            'labels'                  => 'tags',
            // Images
            'images'                  => 'images',
            'image'                   => 'images',
            'image url'               => 'images',
            'photo'                   => 'images',
            'photo url'               => 'images',
            'picture'                 => 'images',
            'picture url'             => 'images',
            'thumbnail'               => 'images',
            'product image'           => 'images',
            'product photo'           => 'images',
            // Weight
            'weight (kg)'             => 'weight',
            'weight'                  => 'weight',
            'weight kg'               => 'weight',
            // Dimensions
            'length (cm)'             => 'length',
            'length'                  => 'length',
            'width (cm)'              => 'width',
            'width'                   => 'width',
            'height (cm)'             => 'height',
            'height'                  => 'height',
            // Status
            'published'               => 'status',
            'status'                  => 'status',
            'product status'          => 'status',
            'active'                  => 'status',
            // Type
            'type'                    => 'type',
            'product type'            => 'type',
            // Brands
            'brands'                  => 'brands',
            'brand'                   => 'brands',
            'manufacturer'            => 'brands',
            'make'                    => 'brands',
            // GTIN
            'gtin, upc, ean, or isbn' => 'gtin',
            'gtin'                    => 'gtin',
            'upc'                     => 'gtin',
            'ean'                     => 'gtin',
            'isbn'                    => 'gtin',
            'barcode'                 => 'gtin',
        ];
    }

    /**
     * Columns we always skip — WC export fields that have no sync value.
     */
    public static function skip_list(): array {
        return [
            'id', 'is featured?', 'visibility in catalog',
            'tax status', 'tax class', 'low stock amount',
            'backorders allowed?', 'sold individually?',
            'allow customer reviews?', 'purchase note',
            'shipping class', 'download limit', 'download expiry days',
            'parent', 'grouped products', 'upsells', 'cross-sells',
            'external url', 'button text', 'position',
            // attribute columns are handled natively — not skipped
        ];
    }

    /**
     * Auto-detect the best mapping for a single sheet column header.
     * Returns: [ 'type' => 'core'|'meta'|'skip'|'none', 'value' => string ]
     */
    public static function auto_detect( string $header ): array {
        $normalised = strtolower( trim( $header ) );

        // 0. Attribute columns: "Attribute N Name", "Attribute N value(s)", etc.
        if ( preg_match( '/^attribute\s+(\d+)\s+(name|value\(s\)|values|visible|global)$/i', $normalised, $m ) ) {
            return [ 'type' => 'attribute', 'value' => 'attribute_' . $m[1] . '_' . strtolower( preg_replace('/[^a-z0-9]/i','_',$m[2]) ) ];
        }

        // 1. WooCommerce "Meta: _key" export format
        if ( preg_match( '/^meta:\s*(.+)$/i', $header, $m ) ) {
            $meta_key = trim( $m[1] );
            if ( $meta_key && $meta_key !== '_' ) {
                return [ 'type' => 'meta', 'value' => $meta_key ];
            }
            return [ 'type' => 'skip', 'value' => '' ];
        }

        // 2. Known core field aliases
        $aliases = self::core_field_aliases();
        if ( isset( $aliases[ $normalised ] ) ) {
            return [ 'type' => 'core', 'value' => $aliases[ $normalised ] ];
        }

        // 3. Known skip list
        if ( in_array( $normalised, self::skip_list(), true ) ) {
            return [ 'type' => 'skip', 'value' => '' ];
        }

        // 4. No match
        return [ 'type' => 'none', 'value' => '' ];
    }

    /**
     * Given an array of sheet headers, return a full auto-mapping suggestion.
     */
    public static function auto_map( array $headers ): array {
        $column_map    = [];
        $meta_map      = [];
        $attribute_cols = []; // headers auto-detected as attribute columns
        $skipped       = [];
        $unmapped      = [];

        foreach ( $headers as $header ) {
            $result = self::auto_detect( $header );
            switch ( $result['type'] ) {
                case 'core':
                    $column_map[ $header ] = $result['value'];
                    break;
                case 'meta':
                    $meta_map[ $header ] = $result['value'];
                    break;
                case 'attribute':
                    $attribute_cols[] = $header;
                    break;
                case 'skip':
                    $skipped[] = $header;
                    break;
                default:
                    $unmapped[] = $header;
            }
        }

        return compact( 'column_map', 'meta_map', 'attribute_cols', 'skipped', 'unmapped' );
    }
}
