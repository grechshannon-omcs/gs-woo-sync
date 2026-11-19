<?php
/**
 * Plugin Name: GS – Woo Sync
 * Plugin URI:  https://jlautier.shop
 * Description: Sync WooCommerce products from a live Google Sheet (CSV).
 * Version:     1.8.0
 * Author:      GS
 * Update URI:  https://github.com/grechshannon-omcs/gs-woo-sync
 * Text Domain: gs-woo-sync
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'GS_WOO_SYNC_VERSION', '1.8.0' );
define( 'GS_WOO_SYNC_FILE',    __FILE__ );
define( 'GS_WOO_SYNC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'GS_WOO_SYNC_URL',     plugin_dir_url( __FILE__ ) );

/* ── Autoload includes ─────────────────────────────────────── */
foreach ( [
    'class-gs-woo-sync-log.php',
    'class-gs-woo-sync-settings.php',
    'class-gs-woo-sync-rollback.php',
    'class-gs-woo-sync-importer.php',
    'class-gs-woo-sync-scheduler.php',
    'class-gs-woo-sync-notifier.php',
    'class-gs-woo-sync-webhook.php',
    'class-gs-woo-sync-admin.php',
    'class-gs-woo-sync-updater.php',
] as $file ) {
    require_once GS_WOO_SYNC_DIR . 'includes/' . $file;
}

/* ── Bootstrap ─────────────────────────────────────────────── */
function gs_woo_sync_init(): void {
    // Updates from GitHub work even if WooCommerce is inactive.
    GS_Woo_Sync_Updater::init();

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-error"><p><strong>GS – Woo Sync</strong> requires WooCommerce to be active.</p></div>';
        } );
        return;
    }
    GS_Woo_Sync_Log::init();
    GS_Woo_Sync_Rollback::create_table(); // idempotent — safe to run every boot
    GS_Woo_Sync_Scheduler::init();
    GS_Woo_Sync_Webhook::init();
    if ( ! GS_Woo_Sync_Webhook::get_token() ) GS_Woo_Sync_Webhook::generate_token();
    if ( is_admin() ) {
        GS_Woo_Sync_Admin::init();
    }
}
add_action( 'plugins_loaded', 'gs_woo_sync_init' );

/* ── Activation / Deactivation ─────────────────────────────── */
register_activation_hook( __FILE__, static function () {
    GS_Woo_Sync_Log::create_table();
    GS_Woo_Sync_Rollback::create_table();
    GS_Woo_Sync_Scheduler::schedule();
} );

register_deactivation_hook( __FILE__, static function () {
    GS_Woo_Sync_Scheduler::unschedule();
} );
