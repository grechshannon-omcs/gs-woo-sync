<?php
defined( 'ABSPATH' ) || exit;

class GS_Woo_Sync_Scheduler {

    const HOOK = 'gs_woo_sync_cron_run';

    public static function init(): void {
        add_action( self::HOOK, [ __CLASS__, 'run' ] );
    }

    public static function run(): void {
        $importer = new GS_Woo_Sync_Importer();
        $importer->run( 'cron' );
    }

    public static function schedule(): void {
        $freq = GS_Woo_Sync_Settings::get( 'schedule' );
        if ( $freq === 'manual' ) return;
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), $freq, self::HOOK );
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    public static function reschedule(): void {
        self::unschedule();
        self::schedule();
    }

    public static function next_run(): string {
        $ts = wp_next_scheduled( self::HOOK );
        if ( ! $ts ) return 'Not scheduled';
        return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'Y-m-d H:i:s' );
    }
}
