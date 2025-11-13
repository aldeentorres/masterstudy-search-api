<?php
/**
 * GitHub Updater for MasterStudy Search API
 * 
 * Checks for updates from GitHub releases and enables automatic updates
 * 
 * @package MasterStudy_Search_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MasterStudy_Search_API_GitHub_Updater {

	/**
	 * GitHub repository owner
	 */
	private $owner = 'aldeentorres';

	/**
	 * GitHub repository name
	 */
	private $repo = 'masterstudy-search-api';

	/**
	 * Plugin file path
	 */
	private $plugin_file;

	/**
	 * Plugin slug
	 */
	private $plugin_slug;

	/**
	 * Current plugin version
	 */
	private $current_version;

	/**
	 * Constructor
	 */
	public function __construct( $plugin_file ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_slug = 'masterstudy-search-api';
		$this->current_version = MasterStudy_Search_API::VERSION;

		// Hook into WordPress update system
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );
	}

	/**
	 * Check for updates from GitHub
	 */
	public function check_for_updates( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$latest_release = $this->get_latest_release();

		if ( $latest_release && version_compare( $this->current_version, $latest_release['version'], '<' ) ) {
			$plugin_data = get_plugin_data( $this->plugin_file );
			$plugin_basename = plugin_basename( $this->plugin_file );

			$transient->response[ $plugin_basename ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $plugin_basename,
				'new_version' => $latest_release['version'],
				'url'         => $plugin_data['PluginURI'],
				'package'     => $latest_release['download_url'],
				'icons'       => array(),
				'banners'     => array(),
				'banners_rtl' => array(),
			);
		}

		return $transient;
	}

	/**
	 * Get plugin information for the update popup
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		$latest_release = $this->get_latest_release();

		if ( ! $latest_release ) {
			return $result;
		}

		$plugin_data = get_plugin_data( $this->plugin_file );

		$result = (object) array(
			'name'          => $plugin_data['Name'],
			'slug'          => $this->plugin_slug,
			'version'       => $latest_release['version'],
			'author'        => $plugin_data['Author'],
			'author_profile' => $plugin_data['AuthorURI'],
			'homepage'      => $plugin_data['PluginURI'],
			'short_description' => $plugin_data['Description'],
			'sections'      => array(
				'description' => $plugin_data['Description'],
				'changelog'   => $latest_release['changelog'],
			),
			'download_link' => $latest_release['download_url'],
			'banners'       => array(),
			'icons'         => array(),
		);

		return $result;
	}

	/**
	 * Get latest release from GitHub
	 */
	private function get_latest_release() {
		$cache_key = 'ms_search_api_latest_release';
		$cached = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			$this->owner,
			$this->repo
		);

		$response = wp_remote_get( $api_url, array(
			'timeout' => 10,
			'headers' => array(
				'Accept' => 'application/vnd.github.v3+json',
			),
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['tag_name'] ) || ! isset( $body['zipball_url'] ) ) {
			return false;
		}

		// Extract version from tag (remove 'v' prefix if present)
		$version = ltrim( $body['tag_name'], 'v' );

		// Get changelog from release body
		$changelog = isset( $body['body'] ) ? $body['body'] : '';

		$release_data = array(
			'version'      => $version,
			'download_url' => $body['zipball_url'],
			'changelog'    => $changelog,
		);

		// Cache for 12 hours
		set_transient( $cache_key, $release_data, 12 * HOUR_IN_SECONDS );

		return $release_data;
	}

	/**
	 * Post-install hook to activate the plugin
	 */
	public function post_install( $response, $hook_extra, $result ) {
		$plugin_basename = plugin_basename( $this->plugin_file );
		
		if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $plugin_basename ) {
			// Reactivate the plugin
			activate_plugin( $hook_extra['plugin'] );
		}

		return $response;
	}
}

