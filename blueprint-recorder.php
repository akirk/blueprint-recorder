<?php
/**
 * Plugin Name: Blueprint Recorder for WordPress Playground
 * Description: Generate a blueprint for the current install.
 * Version: 1.0
 * Author: Alex Kirk
 */

namespace BlueprintRecorder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Controller;
use WP_REST_Server;
use WP_Query;

class BlueprintRecorder {
	private $is_enabled = true;
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
				'callback'            => array( $this, 'generate_blueprint' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	public function add_cors_support() {
		add_filter(
			'rest_pre_serve_request',
			function ( $value, $result, $request ) {
				if ( $request->get_route() !== '/playground/v1/blueprint' ) {
					return $value;
				}
				if ( isset( $_SERVER['HTTP_ORIGIN'] ) && 'https://playground.wordpress.net' === $_SERVER['HTTP_ORIGIN'] ) {
					header( 'Access-Control-Allow-Origin: https://playground.wordpress.net' );
					header( 'Access-Control-Allow-Methods: GET' );
					header( 'Access-Control-Allow-Credentials: true' );
				}
				return $value;
			},
			10,
			3
		);
	}

	public function permissions_check() {
		return true;
	}
	public function get_plugin_resource( $slug ) {
		$cache_key = 'blueprint_recorder_plugin_zip';
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
			$cache[ $slug ] = false;
			if ( ! is_wp_error( $response ) && isset( $response->download_link ) ) {
				if ( 0 === strpos( $response->download_link, 'https://downloads.wordpress.org/plugin/' ) ) {
					$cache[ $slug ] = array(
						'resource' => 'wordpress.org/plugins',
						'slug'     => $slug,
					);
				} elseif ( preg_match( '#https://github\.com/([^/]+/[^/]+)/archive/refs/(heads|tags)/([^/]+)\.zip#', $response->download_link, $matches)) {
					$cache[ $slug ] = array(
						'resource' => 'url',
						'url'     => "https://github-proxy.com/proxy/?repo={$matches[1]}&release={$matches[3]}",
					);
				}
			}

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

	public function generate_blueprint() {
		global $wp_version;
		$steps = array();

		$plugins = get_option( 'active_plugins' );
		$plugin_steps = array();
		$ignored_plugins = array();
		$ignore = array();
		$ignore_all_plugins = false;
		$ignore_theme = false;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ignore'] ) ) {
			// This is just a comma separated list of plugin slugs that is queried.
			$ignore = explode( ',', wp_unslash( $_GET['ignore'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		if ( isset( $_GET['ignore_all_plugins'] ) ) {
			$ignore_all_plugins = true;
		}
		if ( isset( $_GET['ignore_theme'] ) ) {
			$ignore_theme = true;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		foreach ( $plugins as $plugin ) {
			$slug = explode( '/', $plugin )[0];
			if ( in_array( $slug, $ignore ) || $ignore_all_plugins ) {
				continue;
			}
			$plugin_zip = $this->get_plugin_resource( $slug );
			if ( $plugin_zip ) {
				$steps[] = array(
					'step'          => 'installPlugin',
					'pluginZipFile' => $plugin_zip,
				);
			} else {
				$ignored_plugins[] = $slug;
			}
		}

		$theme = wp_get_theme();
		if ( ! in_array( $theme->get( 'TextDomain' ), $ignore ) && $this->check_theme_exists( $theme->get( 'TextDomain' ) ) && ! $ignore_theme ) {
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
			$data .= 'echo "<div class="notice notice-error is-dismissible" id="blueprint-recorder-message"><p><strong>The following plugins were not loaded since they are not available in the WordPress.org plugin directory:</strong></p><ul>';
			foreach ( $ignored_plugins as $plugin ) {
				$data .= '<li>' . esc_html( $plugin ) . '</li>';
			}
			$data .= '</ul></div>";';
			$data .= '});';
			$steps[] = array(
				'step' => 'writeFile',
				'path' => 'wordpress/wp-content/mu-plugins/blueprint-recorder-message.php',
				'data' => $data,
			);
		}

		$args = array(
			'post_type'      => 'sql_log',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
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
			$part1 = '$sql = "';
			$part2 = '"; error_log( $sql );  $wp_query->query( $sql ); error_log( $wpdb->last_error );';
			$steps[] = array(
				'step' => 'runPHP',
				'code'  => '<?php require_once "wordpress/wp-load.php"; ' . $part1 . join( $part2 . $part1, wp_slash( $sql_logs ) ) . $part2,
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
			'Blueprint',       // Page title.
			'Blueprint',       // Menu title.
			'manage_options',  // Capability.
			'blueprint',       // Menu slug.
			array( $this, 'render_admin_page' ), // Callback.
			'dashicons-list-view'     // Icon.
		);
		add_action( 'load-toplevel_page_blueprint', array( $this, 'process_blueprint_admin' ) );
	}

	public function process_blueprint_admin() {
		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'blueprint' ) ) {
			return;
		}
		if ( isset( $_POST['stop-recording'] ) ) {
			update_option( 'blueprint_recorder_disabled', true );
		} elseif ( isset( $_POST['start-recording'] ) ) {
			delete_option( 'blueprint_recorder_disabled' );
		}
	}
	public function render_admin_page() {
		$blueprint = $this->generate_blueprint();

		?><div class="wrap">
		<h1>Blueprint</h1>
			<form method="post">
				<?php echo wp_nonce_field( 'blueprint' ); ?>
				<?php if ( get_option( 'blueprint_recorder_disabled' ) ) : ?>
					<button name="start-recording">Resume Recording</button>
				<?php else: ?>
					<button name="stop-recording">Stop Recording</button>
				<?php endif; ?>
			</form>
			<a href="https://playground.wordpress.net/#<?php echo esc_attr( str_replace( '%', '%25', wp_json_encode( $blueprint, JSON_UNESCAPED_SLASHES ) ) ); ?>" target="_blank">Start Playground with the blueprint below</a><br/>
			<textarea cols="120" rows="50" style="font-family: monospace"><?php echo esc_html( wp_json_encode( $blueprint, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ); ?></textarea>
		</div>
		<?php
	}

	public function init() {
		if ( get_option( 'blueprint_recorder_disabled' ) ) {
			$this->is_enabled = false;
		}

		register_post_type(
			'sql_log',
			array(
				'labels'          => array(
					'name'          => 'SQL Logs',
					'singular_name' => 'SQL Log',
				),
				'public'          => false,
				'show_ui'         => true,
				'capability_type' => 'post',
				'supports'        => array( 'title', 'editor' ),
			)
		);
	}

	public function log_query( $query ) {
		if (
			! $this->is_enabled
			// If we are already logging, do not log again.
			|| $this->is_logging
			|| ( defined( 'WP_INSTALLING' ) && WP_INSTALLING )
			|| wp_doing_cron() ) {
			return $query;
		}

		// Log only INSERT and UPDATE queries.
		if ( ! preg_match( '/^\s*(INSERT|UPDATE)\s/i', $query ) ) {
			return $query;
		}
		// Don't log option changes.
		if ( strpos( $query, 'wp_options' ) !== false ) {
			return $query;
		}
		// Don't log auto-drafts.
		if ( strpos( $query, 'auto-draft' ) !== false ) {
			return $query;
		}

		$this->is_logging = true;
		wp_insert_post(
			array(
				'post_type'    => 'sql_log',
				'post_title'   => current_time( 'mysql' ),
				'post_content' => $query,
				'post_status'  => 'publish',
			)
		);
		$this->is_logging = false;

		return $query;
	}

	public function clear_sql_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		$args = array(
			'post_type'      => 'sql_log',
			'posts_per_page' => -1,
		);

		$query = new WP_Query( $args );

		while ( $query->have_posts() ) {
			$query->the_post();
			wp_delete_post( get_the_ID(), true );
		}

		wp_reset_postdata();

		wp_safe_redirect( admin_url( 'admin.php?page=sql-query-logger' ) );
		exit;
	}
}

new BlueprintRecorder();
