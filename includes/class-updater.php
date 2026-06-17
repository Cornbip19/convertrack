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
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 0 );

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
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
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
		// Manual install from our zip: folder looks like owner-repo-<ref>.
		$folder  = strtolower( basename( untrailingslashit( $source ) ) );
		$pattern = strtolower( $this->owner . '-' . $this->repo );
		$alt     = strtolower( $this->repo );
		return 0 === strpos( $folder, $pattern ) || 0 === strpos( $folder, $alt . '-' );
	}

	/**
	 * Add an auth header to GitHub requests when a token is configured.
	 *
	 * @param array  $args HTTP args.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function authorize_request( $args, $url ) {
		$token = trim( (string) Settings::get( 'github_token' ) );
		if ( '' === $token ) {
			return $args;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( in_array( $host, array( 'api.github.com', 'codeload.github.com', 'github.com' ), true ) ) {
			if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			$args['headers']['Authorization'] = 'token ' . $token;
		}
		return $args;
	}

	/**
	 * Clear the cached release (after updates / forced checks).
	 */
	public function clear_cache() {
		delete_transient( $this->cache_key );
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
			return empty( $cached['version'] ) ? null : $cached;
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

		$release = array(
			'version'      => ltrim( $body['tag_name'], 'vV' ),
			'tag'          => $body['tag_name'],
			'name'         => isset( $body['name'] ) ? $body['name'] : $body['tag_name'],
			'body'         => isset( $body['body'] ) ? $body['body'] : '',
			'published_at' => isset( $body['published_at'] ) ? $body['published_at'] : '',
			'html_url'     => isset( $body['html_url'] ) ? $body['html_url'] : '',
			'package'      => $this->resolve_package( $body ),
		);

		set_transient( $this->cache_key, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * Choose the best download URL: a uploaded .zip asset if present, else the
	 * auto-generated source zipball.
	 *
	 * @param array $body Release payload.
	 * @return string
	 */
	private function resolve_package( $body ) {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['browser_download_url'] ) ) {
					return esc_url_raw( $asset['browser_download_url'] );
				}
			}
		}
		return isset( $body['zipball_url'] ) ? esc_url_raw( $body['zipball_url'] ) : '';
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
		$item->tested       = isset( $data['RequiresWP'] ) ? $data['RequiresWP'] : '';
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
		return get_plugin_data( $this->file, false, false );
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
			$notes = __( 'See the GitHub release for details.', 'convertrack' );
		}
		$heading = '<h4>' . esc_html( isset( $release['name'] ) ? $release['name'] : $release['version'] ) . '</h4>';
		// Render Markdown-ish notes safely: escape, then keep line breaks.
		return $heading . wpautop( make_clickable( esc_html( $notes ) ) );
	}
}
