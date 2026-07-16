<?php
/**
 * Self-updater that serves plugin updates straight from GitHub Releases.
 *
 * How it works:
 *   1. The latest GitHub release tag is compared with the installed version.
 *   2. If newer, WordPress is told an update is available, with the release
 *      zip as the download package.
 *   3. upgrader_source_selection renames GitHub's extracted folder back to the
 *      plugin slug so the update installs in place.
 *
 * Public repos need no credentials. For a private repo, add a GitHub personal
 * access token in Convertrack → Settings.
 *
 * @package Convertrack
 */

namespace Convertrack;

defined( 'ABSPATH' ) || exit;

class Updater {

	/** @var string Absolute path to the main plugin file. */
	private $file;

	/** @var string Plugin basename (folder/file.php). */
	private $basename;

	/** @var string GitHub owner. */
	private $owner;

	/** @var string GitHub repo. */
	private $repo;

	/** @var string Plugin slug / target folder name. */
	private $slug;

	/** @var string Transient cache key for the latest release. */
	private $cache_key;

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * @param string $file  Main plugin file (__FILE__).
	 * @param string $owner GitHub owner.
	 * @param string $repo  GitHub repository name.
	 * @param string $slug  Plugin slug / folder.
	 */
	public function __construct( $file, $owner, $repo, $slug ) {
		$this->file      = $file;
		$this->basename  = plugin_basename( $file );
		$this->owner     = $owner;
		$this->repo      = $repo;
		$this->slug      = $slug;
		$this->cache_key = 'convertrack_gh_release';
	}

	/**
	 * Hook into the update pipeline.
	 */
	public function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'authorize_request' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );

		// Honor the "force check" button on the Updates screen. Require admin
		// context and a valid upgrade-core nonce so this cannot be tripped via
		// CSRF (it only busts our release cache, but correctness matters).
		if (
			is_admin()
			&& isset( $_GET['force-check'], $_GET['_wpnonce'] )
			&& current_user_can( 'update_plugins' )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'upgrade-core' )
		) {
			delete_transient( $this->cache_key );
		}
	}

	/**
	 * Inject our update into the plugins update transient.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( empty( $release ) || empty( $release['version'] ) ) {
			return $transient;
		}

		$installed = $this->installed_version();
		$item      = $this->build_item( $release );

		if ( version_compare( $release['version'], $installed, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
		} else {
			// Record as "no update" so the Updates screen shows our info.
			$transient->no_update[ $this->basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Provide data for the "View details" modal.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action API action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( empty( $release ) ) {
			return $result;
		}

		$data = $this->plugin_header();

		$info               = new \stdClass();
		$info->name         = $data['Name'];
		$info->slug         = $this->slug;
		$info->version      = $release['version'];
		$info->author       = $data['Author'];
		$info->homepage     = $data['PluginURI'];
		$info->requires     = isset( $data['RequiresWP'] ) ? $data['RequiresWP'] : '';
		$info->requires_php = isset( $data['RequiresPHP'] ) ? $data['RequiresPHP'] : '';
		$info->last_updated = isset( $release['published_at'] ) ? $release['published_at'] : '';
		$info->download_link = $release['package'];
		$info->sections     = array(
			'description' => wp_kses_post( $data['Description'] ),
			'changelog'   => $this->format_changelog( $release ),
		);

		return $info;
	}

	/**
	 * Rename GitHub's extracted source folder to the plugin slug.
	 *
	 * @param string $source        Source folder.
	 * @param string $remote_source Parent folder.
	 * @param object $upgrader      Upgrader.
	 * @param array  $hook_extra    Extra context.
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( ! $this->is_our_upgrade( $source, $hook_extra ) ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			$source = trailingslashit( $source );
		} elseif ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			$source = trailingslashit( $desired );
		} else {
			return new \WP_Error( 'convertrack_source_normalization', __( 'Convertrack could not normalize the extracted update folder; the existing plugin was left unchanged.', 'convertrack-click-conversion-analytics' ) );
		}

		$main = trailingslashit( $source ) . basename( $this->file );
		if ( ! $wp_filesystem || ! $wp_filesystem->exists( $main ) ) {
			return new \WP_Error( 'convertrack_package_invalid', __( 'The update package does not contain the Convertrack main plugin file.', 'convertrack-click-conversion-analytics' ) );
		}
		$release = $this->get_release();
		$headers = get_file_data( $main, array( 'Version' => 'Version' ) );
		if ( empty( $release['version'] ) || empty( $headers['Version'] ) || $headers['Version'] !== $release['version'] ) {
			return new \WP_Error( 'convertrack_package_version', __( 'The extracted plugin version does not match the verified release.', 'convertrack-click-conversion-analytics' ) );
		}
		return $source;
	}

	/**
	 * Decide whether an upgrade in progress is ours.
	 *
	 * @param string $source     Source folder.
	 * @param array  $hook_extra Extra context.
	 * @return bool
	 */
	private function is_our_upgrade( $source, $hook_extra ) {
		if ( ! empty( $hook_extra['plugin'] ) ) {
			return $hook_extra['plugin'] === $this->basename;
		}
		// Manual GitHub source archives use exactly owner-repo-<hex ref>. Do not
		// claim arbitrary convertrack-* folders belonging to another package.
		$folder  = strtolower( basename( untrailingslashit( $source ) ) );
		$pattern = '/^' . preg_quote( strtolower( $this->owner . '-' . $this->repo ), '/' ) . '-[a-f0-9]{7,64}$/';
		return (bool) preg_match( $pattern, $folder );
	}

	/**
	 * Add an auth header to GitHub requests when a token is configured.
	 *
	 * @param array  $args HTTP args.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function authorize_request( $args, $url ) {
		$token = Settings::github_token();
		if ( '' === $token ) {
			return $args;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$prefix = '/repos/' . rawurlencode( $this->owner ) . '/' . rawurlencode( $this->repo );
		$unsafe_path = preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path ) || preg_match( '/%(?:2f|5c)/i', $path );
		$ours = ! $unsafe_path
			&& 'api.github.com' === $host
			&& ( 0 === strcasecmp( $path, $prefix ) || 0 === stripos( $path, $prefix . '/' ) );
		if ( $ours ) {
			if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			$args['headers']['Authorization'] = 'Bearer ' . $token;
			if ( preg_match( '#^' . preg_quote( $prefix, '#' ) . '/releases/assets/\d+$#i', $path ) ) {
				$args['headers']['Accept'] = 'application/octet-stream';
			}
		}
		return $args;
	}

	/**
	 * Clear the cached release (after updates / forced checks).
	 */
	public function clear_cache( $upgrader = null, $hook_extra = array() ) {
		unset( $upgrader );
		$is_single = ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->basename;
		$is_bulk   = ! empty( $hook_extra['plugins'] ) && in_array( $this->basename, (array) $hook_extra['plugins'], true );
		if ( $is_single || $is_bulk ) {
			delete_transient( $this->cache_key );
		}
	}

	/**
	 * Download our package and verify the GitHub-provided SHA-256 digest before
	 * WordPress extracts it.
	 *
	 * @param mixed  $reply      Existing short-circuit value.
	 * @param string $package    Package URL.
	 * @param object $upgrader   Upgrader instance.
	 * @param array  $hook_extra Upgrade context.
	 * @return mixed
	 */
	public function verify_download( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader );
		if ( false !== $reply || empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $reply;
		}
		$release = $this->get_release();
		if ( empty( $release['package'] ) || ! hash_equals( (string) $release['package'], (string) $package ) || empty( $release['sha256'] ) ) {
			return new \WP_Error( 'convertrack_release_unverified', __( 'The Convertrack release is missing an exact package or SHA-256 digest.', 'convertrack-click-conversion-analytics' ) );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = $this->download_release_asset( $release );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( strtolower( $release['sha256'] ), strtolower( $actual ) ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'convertrack_release_checksum', __( 'The Convertrack package checksum did not match the release manifest.', 'convertrack-click-conversion-analytics' ) );
		}
		return $file;
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Fetch (and cache) the latest GitHub release.
	 *
	 * @return array|null
	 */
	public function get_release() {
		$cached = get_transient( $this->cache_key );
		if ( is_array( $cached ) ) {
			if ( empty( $cached['version'] ) ) {
				return null;
			}
			if ( ! empty( $cached['package'] ) && ! empty( $cached['asset_api'] ) && ! empty( $cached['sha256'] ) && preg_match( '/^[a-f0-9]{64}$/i', (string) $cached['sha256'] ) ) {
				return $cached;
			}
			// Never trust a pre-hardening transient that lacks verification data.
			delete_transient( $this->cache_key );
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $this->owner ), rawurlencode( $this->repo ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Convertrack/' . CONVERTRACK_VERSION . '; ' . home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache an empty marker briefly so we don't hammer the API on failure.
			set_transient( $this->cache_key, array( 'version' => '' ), HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( $this->cache_key, array( 'version' => '' ), HOUR_IN_SECONDS );
			return null;
		}

		$package = $this->resolve_package( $body );
		if ( empty( $package['url'] ) || empty( $package['asset_api'] ) || empty( $package['sha256'] ) ) {
			set_transient( $this->cache_key, array( 'version' => '' ), HOUR_IN_SECONDS );
			return null;
		}

		$release = array(
			'version'      => ltrim( $body['tag_name'], 'vV' ),
			'tag'          => $body['tag_name'],
			'name'         => isset( $body['name'] ) ? $body['name'] : $body['tag_name'],
			'body'         => isset( $body['body'] ) ? $body['body'] : '',
			'published_at' => isset( $body['published_at'] ) ? $body['published_at'] : '',
			'html_url'     => isset( $body['html_url'] ) ? $body['html_url'] : '',
			'package'      => $package['url'],
			'asset_api'    => $package['asset_api'],
			'sha256'       => $package['sha256'],
		);

		set_transient( $this->cache_key, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * Choose only the exact release asset. Both its browser URL and API URL are
	 * retained: public downloads use the former, while authenticated downloads
	 * use the API endpoint so a token is never attached to a redirecting browser
	 * URL.
	 *
	 * @param array $body Release payload.
	 * @return array
	 */
	private function resolve_package( $body ) {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! isset( $asset['browser_download_url'] ) || ! preg_match( '/\.zip$/i', $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( $name === $this->slug . '.zip' ) {
					$url    = esc_url_raw( $asset['browser_download_url'] );
					$api    = isset( $asset['url'] ) ? esc_url_raw( $asset['url'] ) : '';
					$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
					$path   = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
					$api_host = strtolower( (string) wp_parse_url( $api, PHP_URL_HOST ) );
					$api_path = rawurldecode( (string) wp_parse_url( $api, PHP_URL_PATH ) );
					$digest = isset( $asset['digest'] ) ? strtolower( (string) $asset['digest'] ) : '';
					$repo_path = preg_quote( $this->owner, '#' ) . '/' . preg_quote( $this->repo, '#' );
					if (
						'github.com' !== $host
						|| ! preg_match( '#^/' . $repo_path . '/releases/download/#i', $path )
						|| 'api.github.com' !== $api_host
						|| ! preg_match( '#^/repos/' . $repo_path . '/releases/assets/\d+$#i', $api_path )
						|| ! preg_match( '/^sha256:([a-f0-9]{64})$/', $digest, $match )
					) {
						return array();
					}
					return array( 'url' => $url, 'asset_api' => $api, 'sha256' => $match[1] );
				}
			}
		}
		return array();
	}

	/**
	 * Download a release asset without exposing a configured token to GitHub's
	 * browser-download redirect host.
	 *
	 * @param array $release Verified release metadata.
	 * @return string|\WP_Error Temporary filename.
	 */
	private function download_release_asset( array $release ) {
		$token = Settings::github_token();
		if ( '' === $token ) {
			return download_url( $release['package'], 300 );
		}

		if ( empty( $release['asset_api'] ) ) {
			return new \WP_Error( 'convertrack_release_asset_api', __( 'The verified release is missing its GitHub asset endpoint.', 'convertrack-click-conversion-analytics' ) );
		}
		$file = wp_tempnam( $this->slug . '.zip' );
		if ( ! $file ) {
			return new \WP_Error( 'convertrack_release_tempfile', __( 'WordPress could not create a temporary update file.', 'convertrack-click-conversion-analytics' ) );
		}

		$response = wp_safe_remote_get(
			$release['asset_api'],
			array(
				'timeout'            => 300,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'stream'             => true,
				'filename'           => $file,
				'headers'            => array(
					'Accept'        => 'application/octet-stream',
					'Authorization' => 'Bearer ' . $token,
					'User-Agent'    => 'Convertrack/' . CONVERTRACK_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $file );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( ! $this->is_safe_asset_redirect( $location ) ) {
				wp_delete_file( $file );
				return new \WP_Error( 'convertrack_release_redirect', __( 'GitHub returned an unsafe release-asset redirect.', 'convertrack-click-conversion-analytics' ) );
			}
			// Deliberately omit Authorization on the signed object-storage URL.
			$response = wp_safe_remote_get(
				$location,
				array(
					'timeout'            => 300,
					'redirection'        => 2,
					'reject_unsafe_urls' => true,
					'stream'             => true,
					'filename'           => $file,
					'headers'            => array( 'User-Agent' => 'Convertrack/' . CONVERTRACK_VERSION ),
				)
			);
			if ( is_wp_error( $response ) ) {
				wp_delete_file( $file );
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
		}

		if ( $code < 200 || $code >= 300 || ! file_exists( $file ) || 0 === (int) filesize( $file ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'convertrack_release_download', sprintf( /* translators: %d: HTTP response code. */ __( 'The verified release asset could not be downloaded (HTTP %d).', 'convertrack-click-conversion-analytics' ), $code ) );
		}
		return $file;
	}

	/** Validate GitHub's short-lived release object-storage redirect. */
	private function is_safe_asset_redirect( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		return 'objects.githubusercontent.com' === $host || ( strlen( $host ) > 22 && '.githubusercontent.com' === substr( $host, -22 ) );
	}

	/**
	 * Build the update object WordPress expects.
	 *
	 * @param array $release Release data.
	 * @return object
	 */
	private function build_item( $release ) {
		$data = $this->plugin_header();

		$item               = new \stdClass();
		$item->id           = 'github.com/' . $this->owner . '/' . $this->repo;
		$item->slug         = $this->slug;
		$item->plugin       = $this->basename;
		$item->new_version  = $release['version'];
		$item->url          = $data['PluginURI'];
		$item->package      = $release['package'];
		$item->tested       = isset( $data['Tested'] ) ? $data['Tested'] : '';
		$item->requires_php = isset( $data['RequiresPHP'] ) ? $data['RequiresPHP'] : '';
		$item->icons        = array();
		$item->banners      = array();

		return $item;
	}

	/**
	 * Installed plugin version.
	 *
	 * @return string
	 */
	private function installed_version() {
		$data = $this->plugin_header();
		return ! empty( $data['Version'] ) ? $data['Version'] : CONVERTRACK_VERSION;
	}

	/**
	 * Read the plugin header.
	 *
	 * @return array
	 */
	private function plugin_header() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $this->file, false, false );
		$extra = get_file_data( $this->file, array( 'Tested' => 'Tested up to' ) );
		return array_merge( $data, $extra );
	}

	/**
	 * Render release notes as the changelog section.
	 *
	 * @param array $release Release data.
	 * @return string
	 */
	private function format_changelog( $release ) {
		$notes = isset( $release['body'] ) ? trim( $release['body'] ) : '';
		if ( '' === $notes ) {
			$notes = __( 'See the GitHub release for details.', 'convertrack-click-conversion-analytics' );
		}
		$heading = '<h4>' . esc_html( isset( $release['name'] ) ? $release['name'] : $release['version'] ) . '</h4>';
		// Render Markdown-ish notes safely: escape, then keep line breaks.
		return $heading . wpautop( make_clickable( esc_html( $notes ) ) );
	}
}
