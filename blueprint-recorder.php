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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

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
				$sql_logs[] = str_replace( PHP_EOL, '\n', get_the_content() );
			}
		}

		wp_reset_postdata();
		if ( ! empty( $sql_logs ) ) {
			$steps[] = array(
				'step' => 'runSql',
				'sql'  => array(
					'resource' => 'literal',
					'name'     => 'replay.sql',
					'contents' => join( ";\n", $sql_logs ),
				),
			);
		}

		$steps[] = array(
			"step" => "unzip",
			"zipFile"=> array(
				"resource"=> "url",
				"url"=> 'https://playground.wordpress.net/cors-proxy.php?MEDIA_ZIP_URL'
			),
			"extractToPath" => "/wordpress/wp-content/uploads"
		);

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

	public function render_admin_page() {
		$blueprint = $this->generate_blueprint();

		?><div class="wrap">
		<h1>Blueprint</h1>
			<a href="?media_zip_download" downloxd="media-files.zip">Download the ZIP file of all media</a> and then upload it to somewhere web accessible.<br>
			Then, enter the URL of the uploaded ZIP file: <input type="url" id="zip-url" value="" />. The blueprint below will update.<br>

			<a id="playground-link" href="https://playground.wordpress.net/#<?php echo esc_attr( str_replace( '%', '%25', wp_json_encode( $blueprint, JSON_UNESCAPED_SLASHES ) ) ); ?>" target="_blank">Start Playground with the blueprint below</a><br/>
			<textarea id="blueprint" cols="120" rows="50" style="font-family: monospace"><?php echo esc_html( wp_json_encode( $blueprint, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ); ?></textarea>

			<script>
				const originalBlueprint = document.getElementById('blueprint').value;

				function encodeStringAsBase64(str) {
					return encodeUint8ArrayAsBase64(new TextEncoder().encode(str));
				}

				function encodeUint8ArrayAsBase64(bytes) {
					const binString = String.fromCodePoint(...bytes);
					return btoa(binString);
				}

				function updateBlueprint() {
					var blueprint = originalBlueprint;
					blueprint = blueprint.replace( /MEDIA_ZIP_URL/g, document.getElementById('zip-url').value );
					blueprint = blueprint.replace( /<?php echo esc_html( preg_quote( home_url(), '/' ) ); ?>/g, 'HOME_URL' );
					const query = 'blueprint-url=data:application/json;base64,' + encodeURIComponent( encodeStringAsBase64( blueprint ) );

					document.getElementById('playground-link').href = 'https://playground.wordpress.net/?' + query;
					document.getElementById('blueprint').value = blueprint;

				}
				document.getElementById('zip-url').addEventListener('keyup', updateBlueprint );
				document.getElementById('blueprint').addEventListener('keyup', updateBlueprint );



			</script>
		</div>
		<?php
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

	public function init() {
		if (isset($_GET['media_zip_download'])) {
			$uploads = wp_upload_dir();
			$media_dir = $uploads['basedir'];
			$zip_file = 'media-files.zip';

			// Create a new ZipArchive instance
			$zip = new ZipArchive();
			if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
				$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($media_dir));

				foreach ($files as $file) {
					if (!$file->isDir()) {
						$zip->addFile($file->getRealPath(), str_replace($media_dir . '/', '', $file->getRealPath()));
					}
				}
				$zip->close();

				// Force download the ZIP file
				header('Content-Type: application/zip');
				header('Content-disposition: attachment; filename=' . basename($zip_file));
				header('Content-Length: ' . filesize($zip_file));

				// Clear output buffer
				ob_clean();
				flush();

				readfile($zip_file);

				// Delete the zip file from the server after download
				unlink($zip_file);
				exit;
			} else {
				echo 'Failed to create ZIP file.';
			}
		}

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
