<?php
/**
 * GitHub update framework.
 *
 * Hooks into WordPress native plugin update system so the Plugins
 * page shows "Update now" when a newer GitHub release exists.
 * Also shows a legacy admin notice as a fallback.
 *
 * Checks the GitHub Releases API, caches results for 12 hours,
 * and handles the zipball folder rename on install.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_Updater {

	/**
	 * GitHub owner/repo.
	 */
	const GITHUB_OWNER = 'toxickim24';
	const GITHUB_REPO  = 'konx-affiliate-dashboard';

	/**
	 * GitHub API URL for latest release.
	 */
	const REPO_URL = 'https://api.github.com/repos/toxickim24/konx-affiliate-dashboard/releases/latest';

	/**
	 * Transient key for cached release data.
	 */
	const CACHE_KEY = 'konx_update_data';

	/**
	 * Cache duration in seconds (12 hours).
	 */
	const CACHE_EXPIRY = 43200;

	/**
	 * Plugin slug (directory name).
	 */
	const SLUG = 'konx-affiliate-dashboard';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( __CLASS__, 'post_install' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_update_notice' ) );
	}

	// ------------------------------------------------------------------
	// Native WordPress Update Integration
	// ------------------------------------------------------------------

	/**
	 * Inject update data into the WordPress update transient.
	 *
	 * Called via pre_set_site_transient_update_plugins filter.
	 *
	 * @param object $transient The update_plugins transient object.
	 * @return object Modified transient.
	 */
	public static function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::get_release_data();
		if ( ! $release || empty( $release['version'] ) ) {
			return $transient;
		}

		$plugin_file = KONX_AFFILIATE_PLUGIN_BASENAME;

		if ( version_compare( KONX_AFFILIATE_VERSION, $release['version'], '>=' ) ) {
			// Already up to date — make sure we're not stuck in the update list.
			unset( $transient->response[ $plugin_file ] );
			return $transient;
		}

		$package = $release['package_url'];

		$transient->response[ $plugin_file ] = (object) array(
			'slug'        => self::SLUG,
			'plugin'      => $plugin_file,
			'new_version' => $release['version'],
			'url'         => $release['html_url'],
			'package'     => $package,
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => '',
			'requires'    => '5.8',
			'requires_php' => '7.4',
		);

		return $transient;
	}

	/**
	 * Provide plugin information for the WordPress plugin details modal.
	 *
	 * Called via plugins_api filter.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action (query_plugins, plugin_information, etc.).
	 * @param object             $args   Plugin API arguments.
	 * @return false|object Plugin info or false to let WordPress handle it.
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::get_release_data();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'            => 'KonX Affiliate Dashboard',
			'slug'            => self::SLUG,
			'version'         => $release['version'],
			'author'          => '<a href="https://github.com/' . self::GITHUB_OWNER . '">' . self::GITHUB_OWNER . '</a>',
			'homepage'        => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'requires'        => '5.8',
			'requires_php'    => '7.4',
			'tested'          => '',
			'download_link'   => $release['package_url'],
			'trunk'           => $release['package_url'],
			'last_updated'    => $release['published_at'],
			'sections'        => array(
				'description'  => 'A custom affiliate dashboard for WooCommerce with migration tools.',
				'changelog'    => nl2br( esc_html( $release['body'] ) ),
			),
		);
	}

	/**
	 * Rename the extracted folder after install.
	 *
	 * GitHub zipball extracts as "owner-repo-hash/" but WordPress
	 * expects "konx-affiliate-dashboard/". This filter renames it.
	 *
	 * @param bool  $response   Installation response.
	 * @param array $hook_extra Extra arguments passed to the upgrader.
	 * @param array $result     Installation result data.
	 * @return array|WP_Error Modified result or error.
	 */
	public static function post_install( $response, $hook_extra, $result ) {
		// Only act on our plugin.
		if ( ! isset( $hook_extra['plugin'] ) || KONX_AFFILIATE_PLUGIN_BASENAME !== $hook_extra['plugin'] ) {
			return $result;
		}

		global $wp_filesystem;

		$install_dir = $result['destination'];
		$proper_dir  = trailingslashit( dirname( $install_dir ) ) . self::SLUG;

		// If the extracted folder is already correct, nothing to do.
		if ( $install_dir === $proper_dir ) {
			return $result;
		}

		// Rename extracted folder to the expected slug.
		$wp_filesystem->move( $install_dir, $proper_dir );

		$result['destination']      = $proper_dir;
		$result['destination_name'] = self::SLUG;
		$result['remote_destination'] = $proper_dir;

		// Re-activate if it was active.
		$active = is_plugin_active( KONX_AFFILIATE_PLUGIN_BASENAME );
		if ( $active ) {
			activate_plugin( KONX_AFFILIATE_PLUGIN_BASENAME );
		}

		return $result;
	}

	// ------------------------------------------------------------------
	// Legacy Admin Notice (Fallback)
	// ------------------------------------------------------------------

	/**
	 * Show an admin notice if a newer version is available.
	 *
	 * Kept as a secondary indicator alongside the native update row.
	 */
	public static function maybe_show_update_notice() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			return;
		}

		$release = self::get_release_data();
		if ( ! $release || empty( $release['version'] ) ) {
			return;
		}

		if ( version_compare( KONX_AFFILIATE_VERSION, $release['version'], '>=' ) ) {
			return;
		}

		// Show different message depending on package availability.
		if ( ! empty( $release['package_url'] ) ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				sprintf(
					esc_html__( 'KonX Affiliate Dashboard v%1$s is available. You are running v%2$s. Update from the Plugins page or %3$sview release on GitHub%4$s.', 'konx-affiliate-dashboard' ),
					esc_html( $release['version'] ),
					esc_html( KONX_AFFILIATE_VERSION ),
					'<a href="' . esc_url( $release['html_url'] ) . '" target="_blank">',
					'</a>'
				)
			);
		} else {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				sprintf(
					esc_html__( 'KonX Affiliate Dashboard v%1$s is available but no download package was found. %2$sView release on GitHub%3$s to download manually.', 'konx-affiliate-dashboard' ),
					esc_html( $release['version'] ),
					'<a href="' . esc_url( $release['html_url'] ) . '" target="_blank">',
					'</a>'
				)
			);
		}
	}

	// ------------------------------------------------------------------
	// GitHub API
	// ------------------------------------------------------------------

	/**
	 * Get release data from GitHub, with caching.
	 *
	 * @return array|false {
	 *     @type string $version      Semver version (no 'v' prefix).
	 *     @type string $html_url     GitHub release page URL.
	 *     @type string $package_url  Downloadable ZIP URL (asset or zipball).
	 *     @type string $published_at ISO 8601 publish date.
	 *     @type string $body         Release notes body.
	 * }
	 */
	private static function get_release_data() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : false;
		}

		$response = wp_remote_get( self::REPO_URL, array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github.v3+json' ),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure to avoid hammering the API.
			set_transient( self::CACHE_KEY, 'error', self::CACHE_EXPIRY );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, 'error', self::CACHE_EXPIRY );
			return false;
		}

		// Determine package URL: prefer release asset ZIP, fall back to zipball.
		$package_url = self::find_package_url( $body );

		$data = array(
			'version'      => ltrim( $body['tag_name'], 'v' ),
			'html_url'     => $body['html_url'],
			'package_url'  => $package_url,
			'published_at' => isset( $body['published_at'] ) ? $body['published_at'] : '',
			'body'         => isset( $body['body'] ) ? $body['body'] : '',
		);

		set_transient( self::CACHE_KEY, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Find the best package URL from a GitHub release.
	 *
	 * Priority:
	 * 1. Release asset named konx-affiliate-dashboard.zip
	 * 2. Any .zip release asset
	 * 3. GitHub source zipball (requires post_install rename)
	 *
	 * @param array $release GitHub API release response.
	 * @return string Package URL, or empty string if none found.
	 */
	private static function find_package_url( $release ) {
		// Check release assets for a ZIP.
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			// Prefer an asset named exactly "konx-affiliate-dashboard.zip".
			foreach ( $release['assets'] as $asset ) {
				if ( self::SLUG . '.zip' === $asset['name'] ) {
					return $asset['browser_download_url'];
				}
			}

			// Fall back to any .zip asset.
			foreach ( $release['assets'] as $asset ) {
				if ( '.zip' === substr( $asset['name'], -4 ) ) {
					return $asset['browser_download_url'];
				}
			}
		}

		// Fall back to GitHub source zipball.
		if ( ! empty( $release['zipball_url'] ) ) {
			return $release['zipball_url'];
		}

		return '';
	}

	/**
	 * Clear the update cache.
	 *
	 * Can be called manually or after a successful update.
	 */
	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
