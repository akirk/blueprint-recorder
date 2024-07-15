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

class BlueprintGenerator {
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_playground_blueprint_endpoint' ) );
		add_action( 'rest_api_init', array( $this, 'add_cors_support' ) );
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
			require_once ABSPATH . 'wp-admin/includes/theme-install.php';
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

		$steps[] = array(
			'step'         => 'installTheme',
			'themeZipFile' => array(
				'resource' => 'wordpress.org/themes',
				'slug'     => $theme->get( 'TextDomain' ),
			),
		);
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
			$data = '<?php add_action(\'admin_notices\', function() {';
			$data .= 'echo \'<div class="notice notice-error is-dismissible" id="expose-blueprint-plugin-message"><p><strong>The following plugins were not loaded since they are not available in the WordPress.org plugin directory:</strong></p><ul>';
			foreach ( $ignored_plugins as $plugin ) {
				$data .= '<li>' . esc_html( $plugin ) . '</li>';
			}
			$data .= '</ul></div>\';';
			$data .= '});';
			$steps[] = array(
				'step' => 'writeFile',
				'path' => 'wordpress/wp-content/mu-plugins/expose-blueprint.php',
				'data' => $data,
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

		return new \WP_REST_Response( $blueprint, 200 );
	}
}

new BlueprintGenerator();
