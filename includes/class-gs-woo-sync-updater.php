<?php
/**
 * Self-updater: offers new versions of GS – Woo Sync from its public GitHub repo.
 *
 * How it works
 * - The version on GitHub is read from the "Version:" header of gs-woo-sync.php
 *   on the release branch (raw.githubusercontent.com). Checked at most every 12 h.
 * - If it is higher than the installed version, WordPress shows the normal
 *   "Update available" notice in Plugins and Dashboard › Updates.
 * - "Update now" downloads the branch archive from GitHub. The archive's top
 *   folder (gs-woo-sync-main) is renamed to this plugin's folder before install,
 *   so the plugin stays active and keeps its settings.
 *
 * Releasing a new version = bump "Version:" and GS_WOO_SYNC_VERSION in
 * gs-woo-sync.php and push to the release branch. See RELEASING.md.
 *
 * Turn off on a site with: define( 'GS_WOO_SYNC_DISABLE_UPDATER', true );
 */

defined( 'ABSPATH' ) || exit;

class GS_Woo_Sync_Updater {

	const REPO          = 'grechshannon-omcs/gs-woo-sync';
	const BRANCH        = 'main';
	const SLUG          = 'gs-woo-sync';
	const CACHE_KEY     = 'gs_woo_sync_remote_version';
	const CACHE_SECONDS = 12 * HOUR_IN_SECONDS;

	public static function init(): void {
		if ( defined( 'GS_WOO_SYNC_DISABLE_UPDATER' ) && GS_WOO_SYNC_DISABLE_UPDATER ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject_update' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_folder_name' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'clear_cache' ], 10, 0 );
	}

	private static function basename(): string {
		return plugin_basename( GS_WOO_SYNC_FILE );
	}

	private static function repo_url(): string {
		return 'https://github.com/' . self::REPO;
	}

	private static function package_url(): string {
		return 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip';
	}

	/**
	 * Version number published on GitHub, or '' if it can't be read.
	 * A failed check is cached for 1 hour so a GitHub outage doesn't slow wp-admin.
	 */
	public static function remote_version( bool $force = false ): string {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) && isset( $cached['version'] ) ) {
			return (string) $cached['version'];
		}

		$url      = 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/gs-woo-sync.php';
		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
		$version  = '';

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			if ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi', $body, $m ) ) {
				$version = $m[1];
			}
		}

		set_site_transient(
			self::CACHE_KEY,
			[ 'version' => $version ],
			'' === $version ? HOUR_IN_SECONDS : self::CACHE_SECONDS
		);
		return $version;
	}

	/** Adds this plugin to WordPress's list of available updates when GitHub has a newer version. */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = self::remote_version();
		$item   = (object) [
			'id'           => self::repo_url(),
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $remote ?: GS_WOO_SYNC_VERSION,
			'url'          => self::repo_url(),
			'package'      => self::package_url(),
			'requires_php' => '8.0',
		];

		if ( $remote && version_compare( $remote, GS_WOO_SYNC_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = [];
			}
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/** Content of the "View details" pop-up in Plugins. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}

		$remote = self::remote_version();
		return (object) [
			'name'          => 'GS – Woo Sync',
			'slug'          => self::SLUG,
			'version'       => $remote ?: GS_WOO_SYNC_VERSION,
			'author'        => 'OMC Developments',
			'homepage'      => self::repo_url(),
			'download_link' => self::package_url(),
			'requires'      => '5.8',
			'requires_php'  => '8.0',
			'sections'      => [
				'description' => '<p>Syncs WooCommerce products from a live Google Sheet.</p>',
				'changelog'   => '<p>See <a href="' . esc_url( self::repo_url() . '/blob/' . self::BRANCH . '/CHANGELOG.md' ) . '" target="_blank" rel="noopener">CHANGELOG.md on GitHub</a>.</p>',
			],
		];
	}

	/**
	 * GitHub branch archives unpack to "gs-woo-sync-main/". Rename that to the
	 * installed folder name so WordPress replaces the plugin in place.
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::basename() ) {
			return $source;
		}

		global $wp_filesystem;
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ), true ) ) {
			return $wanted;
		}
		return new WP_Error( 'gs_woo_sync_rename_failed', 'GS – Woo Sync update: could not rename the downloaded folder.' );
	}

	public static function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}
}
