<?php
/**
 * Plugin Name: Expose Playground Blueprint
 * Description: Allow booting up a playground of the current install.
 * Version: 1.0
 * Author: Alex Kirk
 */

namespace ExposePlaygroundBlueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Controller;
use WP_REST_Server;
use WP_Query;

class BlueprintGenerator {
	private $is_logging = false;
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_playground_blueprint_endpoint' ) );
		add_action( 'rest_api_init', array( $this, 'add_cors_support' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_filter( 'query', array( $this, 'log_query' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_clear_sql_logs', array( $this, 'clear_sql_logs' ) );
	}

	public function register_playground_blueprint_endpoint() {
		register_rest_route(
			'playground/v1',
			'/blueprint',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'generate_playground_blueprint' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	public function add_cors_support() {
		add_filter(
			'rest_pre_serve_request',
			function ( $value ) {
				header( 'Access-Control-Allow-Origin: https://playground.wordpress.net' );
				header( 'Access-Control-Allow-Methods: GET' );
				header( 'Access-Control-Allow-Credentials: true' );
				return $value;
			}
		);
	}

	public function permissions_check() {
		return true;
	}
	public function check_plugin_exists( $slug ) {
		$cache_key = 'expose_blueprints_plugin_exists';
		$cache = get_transient( $cache_key );
		if ( false === $cache ) {
			$cache = array();
		}

		if ( ! isset( $cache[ $slug ] ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			$response = \plugins_api(
				'plugin_information',
				(object) array(
					'slug' => $slug,
				)
			);
			$cache[ $slug ] = ! is_wp_error( $response );
			set_transient( $cache_key, $cache, DAY_IN_SECONDS );

		}
		return $cache[ $slug ];
	}

	public function check_theme_exists( $slug ) {
		$cache_key = 'expose_blueprints_theme_exists';
		$cache = get_transient( $cache_key );
		if ( false === $cache ) {
			$cache = array();
		}

		if ( ! isset( $cache[ $slug ] ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			$response = \themes_api(
				'theme_information',
				(object) array(
					'slug' => $slug,
				)
			);
			$cache[ $slug ] = ! is_wp_error( $response );
			set_transient( $cache_key, $cache, DAY_IN_SECONDS );

		}
		return $cache[ $slug ];
	}

	public function generate_playground_blueprint() {
		global $wp_version;
		$steps = array();

		$plugins = get_option( 'active_plugins' );
		$plugin_steps = array();
		$ignored_plugins = array();
		$ignore = array();
		if ( isset( $_GET['ignore'] ) ) {
			$ignore = explode( ',', $_GET['ignore'] );
		}

		foreach ( $plugins as $plugin ) {
			$slug = explode( '/', $plugin )[0];
			if ( in_array( $slug, $ignore ) || isset( $_GET['ignore_all_plugins'] ) ) {
				continue;
			}
			if ( $this->check_plugin_exists( $slug ) ) {
				$steps[] = array(
					'step'          => 'installPlugin',
					'pluginZipFile' => array(
						'resource' => 'wordpress.org/plugins',
						'slug'     => $slug,
					),
				);
			} else {
				$ignored_plugins[] = $slug;
			}
		}

		$theme = wp_get_theme();
		if ( ! in_array( $theme->get( 'TextDomain' ), $ignore ) && $this->check_theme_exists( $theme->get( 'TextDomain' ) ) && ! isset( $_GET['ignore_theme'] )) {
			$steps[] = array(
				'step'         => 'installTheme',
				'themeZipFile' => array(
					'resource' => 'wordpress.org/themes',
					'slug'     => $theme->get( 'TextDomain' ),
				),
			);
		} else {
			$ignored_plugins[] = $theme->get( 'TextDomain' );
		}

		$site_options = array();
		foreach ( array(
			'blogname',
			'blogdescription',
			'start_of_week',
			'timezone_string',
			'date_format',
			'time_format',
			'permalink_structure',
			'rss_use_excerpt',
		) as $name ) {
			$site_options[ $name ] = get_option( $name );
		}

		$steps[] = array(
			'step'    => 'setSiteOptions',
			'options' => $site_options,
		);

		if ( ! empty( $ignored_plugins ) ) {
			$steps[] = array(
				'step' => 'mkdir',
				'path' => 'wordpress/wp-content/mu-plugins',

			);
			$data = '<' . '?php add_action("admin_notices", function() {';
			$data .= 'echo "<div class="notice notice-error is-dismissible" id="expose-blueprint-plugin-message"><p><strong>The following plugins were not loaded since they are not available in the WordPress.org plugin directory:</strong></p><ul>';
			foreach ( $ignored_plugins as $plugin ) {
				$data .= '<li>' . esc_html( $plugin ) . '</li>';
			}
			$data .= '</ul></div>";';
			$data .= '});';
			$steps[] = array(
				'step' => 'writeFile',
				'path' => 'wordpress/wp-content/mu-plugins/expose-blueprint.php',
				'data' => $data,
			);
		}

		$args = array(
			'post_type' => 'sql_log',
			'posts_per_page' => -1
		);

		$query = new WP_Query( $args );

		$sql_logs = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$sql_logs[] = get_the_content() . ";\n";
			}
		}

		wp_reset_postdata();
		if ( ! empty( $sql_logs ) ) {
			$steps[] = array(
				'step' => 'runSql',
				'sql' => $sql_logs,
			);
		}

		$blueprint = array(
			'landingPage'         => '/wp-admin/',
			'preferredVersions'   => array(
				'php' => substr( phpversion(), 0, 3 ),
				'wp'  => $wp_version,
			),
			'phpExtensionBundles' => array( 'kitchen-sink' ),
			'features'            => array(
				'networking' => true,
			),
			'login'               => true,
			'steps'               => $steps,
		);

		return $blueprint;
	}

	public function add_admin_menu() {
		add_menu_page(
			'Blueprint',       // Page title
			'Blueprint',       // Menu title
			'manage_options',         // Capability
			'blueprint',       // Menu slug
			array( $this, 'render_admin_page' ), // Callback
			'dashicons-list-view'     // Icon
		);
	}

	public function render_admin_page() {
		$blueprint = $this->generate_playground_blueprint();

		echo '<div class="wrap">';
		echo '<h1>Blueprint</h1>';
		echo '<a href="https://playground.wordpress.net/#' . rawurlencode( json_encode( $blueprint ) ) . '" target="_blank">Start Playground with the blueprint below</a><br/>';
		echo '<textarea cols="120" rows="200">' . esc_html( json_encode( $blueprint, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT ) )  . '</textarea>';

	}

	public function init() {
		// Register custom post type for logs
		register_post_type( 'sql_log', [
			'labels' => [
				'name' => 'SQL Logs',
				'singular_name' => 'SQL Log',
			],
			'public' => false,
			'show_ui' => true,
			'capability_type' => 'post',
			'supports' => [ 'title', 'editor' ],
		] );
	}

	public function log_query( $query ) {
		// If we are already logging, do not log again
		if ( $this->is_logging || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) || wp_doing_cron() ) {
			return $query;
		}

		// Log only INSERT and UPDATE queries
		if ( ! preg_match( '/^\s*(INSERT|UPDATE)\s/i', $query ) ) return $query;
		if ( strpos( $query, 'wp_options' ) !== false ) return $query;
		if ( strpos( $query, 'auto-draft' ) !== false ) return $query;

		$this->is_logging = true;
		wp_insert_post( [
			'post_type'    => 'sql_log',
			'post_title'   => current_time( 'mysql' ),
			'post_content' => $query,
			'post_status'  => 'publish',
		] );
		$this->is_logging = false;

		return $query;
	}

	public function clear_sql_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		$args = array(
			'post_type' => 'sql_log',
			'posts_per_page' => -1
		);

		$query = new WP_Query( $args );

		while ( $query->have_posts() ) {
			$query->the_post();
			wp_delete_post( get_the_ID(), true );
		}

		wp_reset_postdata();

		wp_redirect( admin_url( 'admin.php?page=sql-query-logger' ) );
		exit;
	}

}

new BlueprintGenerator();
