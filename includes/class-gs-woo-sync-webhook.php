<?php
defined( 'ABSPATH' ) || exit;

class GS_Woo_Sync_Webhook {

    const ROUTE_NS  = 'gs-woo-sync/v1';
    const ROUTE     = '/trigger';
    const TOKEN_KEY = 'gs_woo_sync_webhook_token';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
    }

    public static function register_route(): void {
        register_rest_route( self::ROUTE_NS, self::ROUTE, [
            'methods'             => [ 'GET', 'POST' ],
            'callback'            => [ __CLASS__, 'handle' ],
            'permission_callback' => [ __CLASS__, 'authenticate' ],
        ] );
    }

    public static function authenticate( WP_REST_Request $request ): bool {
        $token    = self::get_token();
        $provided = $request->get_param( 'token' )
            ?? $request->get_header( 'X-GS-Token' )
            ?? '';
        // A token sent as an array (?token[]=) would make hash_equals() throw; treat it as wrong.
        if ( ! is_string( $provided ) ) {
            return false;
        }
        return $token && hash_equals( $token, $provided );
    }

    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $importer = new GS_Woo_Sync_Importer();
        $result   = $importer->run( 'webhook' );
        return new WP_REST_Response( [
            'success' => true,
            'result'  => $result,
        ], 200 );
    }

    /* ── Token management ──────────────────────────────────── */

    public static function get_token(): string {
        return (string) get_option( self::TOKEN_KEY, '' );
    }

    public static function generate_token(): string {
        $token = wp_generate_password( 32, false );
        update_option( self::TOKEN_KEY, $token );
        return $token;
    }

    public static function get_webhook_url(): string {
        $token = self::get_token();
        if ( ! $token ) return '';
        return rest_url( self::ROUTE_NS . self::ROUTE ) . '?token=' . $token;
    }

    /* ── Google Apps Script snippet ────────────────────────── */

    public static function get_apps_script( string $url ): string {
        // JSON-encode the URL so any special characters are safely escaped for JS
        $safe_url = json_encode( $url );
        return <<<JS
// GS - Woo Sync: Auto-trigger on sheet edit
// Paste this into Extensions > Apps Script > Code.gs

var SYNC_URL = {$safe_url};

function onEdit(e) {
  triggerWooSync();
}

function triggerWooSync() {
  var options = {
    method: "post",
    muteHttpExceptions: true
  };
  try {
    var response = UrlFetchApp.fetch(SYNC_URL, options);
    Logger.log("Sync triggered: " + response.getContentText());
  } catch(err) {
    Logger.log("Sync error: " + err.toString());
  }
}
JS;
    }
}
