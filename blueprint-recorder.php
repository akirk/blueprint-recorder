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
	private $ignored_plugins = array();
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_playground_blueprint_endpoint' ) );
		add_action( 'rest_api_init', array( $this, 'add_cors_support' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_filter( 'query', array( $this, 'log_query' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_clear_sql_logs', array( $this, 'clear_sql_logs' ) );
		add_action(
			'admin_bar_menu',
			function ( $admin_bar ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				if ( ! $this->is_enabled ) {
					return;
				}
				$admin_bar->add_node(
					array(
						'id'    => 'blueprint-recorder',
						'title' => 'Blueprint: Recording SQL Queries',
						'href'  => admin_url( 'admin.php?page=blueprint' ),
					)
				);
			},
			100
		);
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

		if ( ! isset( $cache[ $slug ] ) || 'blueprint-recorder' === $slug ) {
			switch ( $slug ) {
				case 'blueprint-recorder':
					$cache[ $slug ] = array(
						'resource' => 'url',
						'url'      => 'https://github-proxy.com/proxy/?repo=blueprint-recorder&branch=main',
					);
					break;
				default:
					require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
					$response = \plugins_api(
						'plugin_information',
						(object) array(
							'slug' => $slug,
						)
					);
					$cache[ $slug ] = false;

					break;
			}
			if ( false === $cache[ $slug ] && ! is_wp_error( $response ) && isset( $response->download_link ) ) {
				if ( 0 === strpos( $response->download_link, 'https://downloads.wordpress.org/plugin/' ) ) {
					$cache[ $slug ] = array(
						'resource' => 'wordpress.org/plugins',
						'slug'     => $slug,
					);
				} elseif ( preg_match( '#https://github\.com/([^/]+/[^/]+)/archive/refs/(heads|tags)/([^/]+)\.zip#', $response->download_link, $matches ) ) {
					$cache[ $slug ] = array(
						'resource' => 'url',
						'url'      => "https://github-proxy.com/proxy/?repo={$matches[1]}&release={$matches[3]}",
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

	public function generate_media_step() {

		return array(
			'step'          => 'unzip',
			'zipFile'       => array(
				'resource' => 'url',
				'url'      => 'https://playground.wordpress.net/cors-proxy.php?MEDIA_ZIP_URL',
			),
			'extractToPath' => '/wordpress/wp-content/uploads',
		);
	}

	public function generate_blueprint() {
		global $wp_version;
		$steps = array();

		$plugins = get_option( 'active_plugins' );
		$plugin_steps = array();
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
			$plugin_resource = $this->get_plugin_resource( $slug );

			if ( $plugin_resource ) {
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
				$steps[] = array(
					'step'       => 'installPlugin',
					'pluginData' => $plugin_resource,
					'name'       => $plugin_data['Name'],
				);
			} else {
				$this->ignored_plugins[] = $slug;
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
			$this->ignored_plugins[] = $theme->get( 'TextDomain' );
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

		$steps[] = array(
			'step'   => 'defineWpConfigConsts',
			'consts' => array( 'WP_DEBUG' => 'true' ),
		);

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
			<style>
				details summary  {
					cursor: pointer;
					font-weight: bold;
					margin-top: 10px;
				}
				details label {
					cursor: pointer;
				}
				details ul li span {
					margin-right: 5px;
					cursor: pointer;
				}
				details ul li span:hover {
					color: #f00;
					text-decoration: line-through;
				}
			</style>
			<details <?php echo get_option( 'blueprint_recorder_disabled' ) ? '' : 'open'; ?>>
				<summary>Record SQL Queries</summary>
					<form method="post">
					<?php echo wp_nonce_field( 'blueprint' ); ?>
					<p>This will record INSERT, UPDATE, and DELETE queries that can then be inserted in the blueprint.</p>
					<?php if ( get_option( 'blueprint_recorder_disabled' ) ) : ?>
						<button name="start-recording">Start Recording Modifying SQL Queries</button>
					<?php else : ?>
						<button name="stop-recording">Stop Recording Modifying SQL Queries</button>
					<?php endif; ?>
				</form>
			</details>
			<details>
				<summary>Add Pages</summary>
			<?php foreach ( get_pages( array() ) as $page ) : ?>
					<label><input type="checkbox" data-id="<?php echo esc_attr( $page->ID ); ?>" onchange="updateBlueprint()" /> <?php echo esc_html( $page->post_title ); ?></label><br/>
				<?php endforeach; ?>
			</details>

			<details>
				<summary>Add Template Parts</summary>
				<?php
				foreach ( get_posts(
					array(
						'post_type'   => 'wp_template_part',
						'numberposts' => -1,
						'taxonomy'    => 'wp_theme',
						'term'        => wp_get_theme()->get_stylesheet(),
					)
				) as $template_part ) :
					?>
					<label><input type="checkbox" data-id="<?php echo esc_attr( $template_part->ID ); ?>" onchange="updateBlueprint()" /> <?php echo esc_html( $template_part->post_title ); ?></label><br/>

				<?php endforeach; ?>
			</details>
			<details>
				<summary>Add Widgets</summary>
					<?php
					foreach ( get_posts(
						array(
							'post_type'   => 'wp_block',
							'numberposts' => -1,
						)
					) as $widget ) :
						?>
					<label><input type="checkbox" data-id="<?php echo esc_attr( $widget->ID ); ?>" onchange="updateBlueprint()" /> <?php echo esc_html( $widget->post_title ); ?></label><br/>
					<?php endforeach; ?>
			</details>

			<details>
				<summary>Add Media</summary>
				<a href="?media_zip_download" download="media-files.zip">Download the ZIP file of all media</a> and then upload it to somewhere web accessible.<br>
				Then, enter the URL of the uploaded ZIP file: <input type="url" id="zip-url" value="" />. The blueprint below will update.<br>
			</details>

			<details>
				<summary>Add Options</summary>
				<ul id="additionaloptions"></ul>
				<datalist id="options">
					<?php foreach ( wp_load_alloptions() as $name => $value ) : ?>
						<option label="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>" />
					<?php endforeach; ?>
				</datalist>
				<datalist id="option-values">
					<?php foreach ( wp_load_alloptions() as $name => $value ) : ?>
						<option label="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php endforeach; ?>
				</datalist>
				<input type="text" id="option-name" list="options" placeholder="Option Name" size="30" onchange="updateOptionValue()" onkeyup="updateOptionValue()"/>
				<span id="option-value"></span>
				<button onclick="addOptionToBlueprint()">Add</button>
			</details>

			<details>
				<summary>Plugins</summary>
					<p>You can drag plugins to put them in the right loading order</p>
					<?php foreach ( $blueprint['steps'] as $k => $step ) : ?>
						<?php if ( 'installPlugin' === $step['step'] ) : ?>
						<label class="plugin"><input type="checkbox" id="use_plugin_<?php echo esc_attr( $k ); ?>" checked onchange="updateBlueprint()" /> <?php echo esc_html( $step['name'] ); ?></label><br/>
					<?php endif; ?>
					<?php endforeach; ?>
			</details>

			<details>
				<summary>Theme</summary>
				<label><input type="checkbox" id="ignore-theme" onclick="updateBlueprint()"> Ignore Theme</label><br>
			</details>
			<details id="select-users">
				<summary>Users</summary>
					<?php foreach ( get_users() as $u ) : ?>
						<?php if ( 'admin' !== $u->user_login ) : ?>
						<label><input type="checkbox" data-login="<?php echo esc_attr( $u->user_login ); ?>" data-name="<?php echo esc_attr( $u->display_name ); ?>" data-role="<?php echo esc_attr( $u->roles[0] ); ?>" onchange="updateBlueprint()" /> <?php echo esc_html( $u->display_name ); ?></label><br/>
					<?php endif; ?>
				<?php endforeach; ?>
			</details>
			<br>
			→ <a id="playground-link" href="https://playground.wordpress.net/#<?php echo esc_attr( str_replace( '%', '%25', wp_json_encode( $blueprint, JSON_UNESCAPED_SLASHES ) ) ); ?>" target="_blank">Start Playground with the blueprint below</a><br/>
			<br>
			<textarea id="blueprint" cols="120" rows="50" style="font-family: monospace"><?php echo esc_html( wp_json_encode( $blueprint, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ); ?></textarea>
			<script>
				const originaBlueprint = document.getElementById('blueprint').value;
				function encodeStringAsBase64(str) {
					return encodeUint8ArrayAsBase64(new TextEncoder().encode(str));
				}

				function encodeUint8ArrayAsBase64(bytes) {
					const binString = String.fromCodePoint(...bytes);
					return btoa(binString);
				}

				let blueprint = JSON.parse( originaBlueprint );
				for ( let i = 0; i < blueprint.steps.length; i++ ) {
					if ( blueprint.steps[i].step === 'installPlugin' && localStorage.getItem( 'blueprint_recorder_ignore_plugin_' + blueprint.steps[i].name ) ) {
						document.getElementById('use_plugin_' + i).checked = false;
					}
					if ( blueprint.steps[i].step === 'installTheme' && localStorage.getItem( 'blueprint_recorder_ignore_theme' ) ) {
						document.getElementById('ignore-theme').checked = true;
					}
				}
				const additionalOptions = JSON.parse( localStorage.getItem( 'blueprint_recorder_additional_options' ) || '{}' );
				const additionalOptionsList = document.getElementById('additionaloptions');
				for ( const key in additionalOptions ) {
					if ( additionalOptions.hasOwnProperty( key ) ) {
						const li = document.createElement('li');
						const k = document.createElement('span');
						k.textContent = key;
						li.appendChild(k);
						const value = document.createElement('tt');
						value.textContent = additionalOptions[key];
						li.appendChild(value);
						additionalOptionsList.appendChild(li);
					}
				}
				const users = JSON.parse( localStorage.getItem( 'blueprint_recorder_users' ) || '[]' );
				document.querySelectorAll( '#select-users input[type="checkbox"]' ).forEach( function ( checkbox ) {
					if ( users.includes( checkbox.getAttribute('data-login') ) ) {
						checkbox.checked = true;
					}
				} );
				updateBlueprint();

				function updateBlueprint() {
					let blueprint = JSON.parse( originaBlueprint );
					if ( document.getElementById('zip-url').value ) {
						blueprint.steps.push( {
							'step'          : 'unzip',
							'zipFile'       : {
								'resource' : 'url',
								'url'      : 'https://playground.wordpress.net/cors-proxy.php?' + document.getElementById('zip-url').value,
							},
							'extractToPath' : '/wordpress/wp-content/uploads',
						} );
					}
					const steps = [];
					for ( let i = 0; i < blueprint.steps.length; i++ ) {
						if ( blueprint.steps[i].step === 'installPlugin' ) {
							if ( ! document.getElementById('use_plugin_' + i).checked ) {
								localStorage.setItem( 'blueprint_recorder_ignore_plugin_' + blueprint.steps[i].name, true );
								continue;
							}
							localStorage.removeItem( 'blueprint_recorder_ignore_plugin_' + blueprint.steps[i].name );
							delete blueprint.steps[i].name;
						}
						if ( blueprint.steps[i].step === 'setSiteOptions' ) {
							for ( const key in additionalOptions ) {
								if ( additionalOptions.hasOwnProperty( key ) ) {
									blueprint.steps[i].options[key] = additionalOptions[key];
								}
							}
						}
						if ( blueprint.steps[i].step === 'installTheme' ) {
							if ( document.getElementById('ignore-theme').checked ) {
								localStorage.setItem( 'blueprint_recorder_ignore_theme', true );
								continue;
							}
							localStorage.removeItem( 'blueprint_recorder_ignore_theme' );
						}
						steps.push( blueprint.steps[i] );
					}
					const users = [];
					document.querySelectorAll( '#select-users input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							users.push( checkbox.getAttribute('data-login') );
							steps.push( {
								'step' : 'runPHP',
								'code' : "<" + "?php require_once 'wordpress/wp-load.php'; $data = array( 'user_login' => '" + checkbox.dataset.login + "', 'display_name' => '" + checkbox.dataset.name + "', 'role' => '" + checkbox.dataset.role + "' ); wp_insert_user( $data ); ?>",
							} );
						}
					} );
					if ( users.length ) {
						localStorage.setItem( 'blueprint_recorder_users', JSON.stringify( users ) );
					} else {
						localStorage.removeItem( 'blueprint_recorder_users' );
					}

					const pages = [];
					document.querySelectorAll( '#select-users input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							pages.push( checkbox.getAttribute('data-id') );
						}
					} );
					if ( pages.length ) {
						steps.push( {
							'step' : 'runPHP',
							'code' : "<" + "?php require_once 'wordpress/wp-load.php'; $pages = " + JSON.stringify( pages ) + "; foreach ( $pages as $page_id ) { $page = get_post( $page_id ); wp_insert_post( array( 'post_type' => 'page', 'post_title' => $page->post_title, 'post_content' => $page->post_content, 'post_status' => 'publish', ) ); } ?>",
						} );
					}
					const template_parts = [];
					document.querySelectorAll( '#select-users input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							template_parts.push( checkbox.getAttribute('data-id') );
						}
					} );
					if ( template_parts.length ) {
						steps.push( {
							'step' : 'runPHP',
							'code' : "<" + "?php require_once 'wordpress/wp-load.php'; $theme = wp_get_theme(); $term = get_term_by('slug', $theme->get_stylesheet(), 'wp_theme'); if ( ! $term) { 	$term = wp_insert_term( $theme->get_stylesheet(), 'wp_theme', ); $term_id = $term['term_id']; } else { $term_id = $term->term_id; } $template_parts = " + JSON.stringify( template_parts ) + "; foreach ( $template_parts as $template_part_id ) { $template_part = get_post( $template_part_id ); wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_title' => $template_part->post_title, 'post_content' => $template_part->post_content, 'post_status' => 'publish', 'taxonomy' => array( 'wp_theme' => array( $term_id ) ), ) ); } ?>",
						} );
					}
					const widgets = [];
					document.querySelectorAll( '#select-users input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							widgets.push( checkbox.getAttribute('data-id') );
						}
					} );
					if ( widgets.length ) {
						steps.push( {
							'step' : 'runPHP',
							'code' : "<" + "?php require_once 'wordpress/wp-load.php'; $widgets = " + JSON.stringify( widgets ) + "; foreach ( $widgets as $widget_id ) { $widget = get_post( $widget_id ); wp_insert_post( array( 'post_type' => 'wp_block', 'post_title' => $widget->post_title, 'post_content' => $widget->post_content, 'post_status' => 'publish', ) ); } ?>",
						} );
					}
					blueprint.steps = steps;
					blueprint = JSON.stringify( blueprint, null, 4 );
					const query = 'blueprint-url=data:application/json;base64,' + encodeURIComponent( encodeStringAsBase64( blueprint ) );

					document.getElementById('playground-link').href = 'https://playground.wordpress.net/?' + query;
					document.getElementById('blueprint').value = blueprint;

				}

				function updateOptionValue() {
					const optionName = document.getElementById('option-name').value;
					if ( optionName ) {
						const optionValue = document.querySelector( '#option-values option[label="' + optionName + '"]' );
						if ( optionValue ) {
							document.getElementById('option-value').textContent = optionValue.getAttribute('value');
							return optionValue.getAttribute('value');
						}
					}
					return false;
				}

				function addOptionToBlueprint() {
					const optionName = document.getElementById('option-name').value;
					if ( optionName ) {
						additionalOptions[optionName] = updateOptionValue();
						localStorage.setItem( 'blueprint_recorder_additional_options', JSON.stringify( additionalOptions ) );
						const additionalOptionsList = document.getElementById('additionaloptions');
						const li = document.createElement('li');
						const key = document.createElement('span');
						key.textContent = optionName;
						li.appendChild(key);
						const value = document.createElement('tt');
						value.textContent = additionalOptions[optionName];
						li.appendChild(value);
						additionalOptionsList.appendChild(li);
						document.getElementById('option-name').value = '';
						document.getElementById('option-value').textContent = '';
						updateBlueprint();
					}
				}
				document.getElementById('zip-url').addEventListener('keyup', updateBlueprint );
				document.getElementById('blueprint').addEventListener('keyup', updateBlueprint );
				document.addEventListener('click', function (event) {
					if ( event.target.matches('#additionaloptions li span') ) {
						const key = event.target.textContent;
						if ( confirm('Do you want delete the option ' + key + '?') ) {
							const additionalOptionsList = document.getElementById('additionaloptions');
							const li = event.target.closest('li');
							li.parentNode.removeChild(li);
							delete additionalOptions[key];
							localStorage.setItem( 'blueprint_recorder_additional_options', JSON.stringify( additionalOptions ) );
							updateBlueprint();
						}
						return;
					}
					if ( event.target.matches('#additionaloptions li tt') ) {
						// select the text
						const range = document.createRange();
						range.selectNodeContents(event.target);
						const sel = window.getSelection();
						sel.removeAllRanges();
						sel.addRange(range);
						return;
					}

				});

				// Add drag and drop functionality to the plugin list
				const pluginList = document.querySelectorAll('.plugin');
				pluginList.forEach( function ( plugin ) {
					plugin.setAttribute('draggable', 'true');
					plugin.addEventListener('dragstart', function (event) {
						event.dataTransfer.setData('text/plain', event.target.id);
					});
					plugin.addEventListener('dragover', function (event) {
						event.preventDefault();
					});
					plugin.addEventListener('drop', function (event) {
						event.preventDefault();
						const draggedId = event.dataTransfer.getData('text/plain');
						const draggedElement = document.getElementById(draggedId);
						const targetElement = event.target.closest('.plugin');
						if ( draggedElement && targetElement && draggedElement !== targetElement ) {
							const parent = targetElement.parentNode;
							parent.insertBefore(draggedElement, targetElement.nextSibling);
						}
					});
				});
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
		if ( isset( $_GET['media_zip_download'] ) ) {
			$uploads = wp_upload_dir();
			$media_dir = $uploads['basedir'];
			$zip_file = 'media-files.zip';

			// Create a new ZipArchive instance
			$zip = new ZipArchive();
			if ( $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true ) {
				$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $media_dir ) );

				foreach ( $files as $file ) {
					if ( ! $file->isDir() ) {
						$zip->addFile( $file->getRealPath(), str_replace( $media_dir . '/', '', $file->getRealPath() ) );
					}
				}
				$zip->close();

				// Force download the ZIP file
				header( 'Content-Type: application/zip' );
				header( 'Content-disposition: attachment; filename=' . basename( $zip_file ) );
				header( 'Content-Length: ' . filesize( $zip_file ) );

				// Clear output buffer
				ob_clean();
				flush();

				readfile( $zip_file );

				// Delete the zip file from the server after download
				unlink( $zip_file );
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

		// make sure the post content is not modified
		remove_all_filters( 'content_save_pre' );
		remove_all_filters( 'content_edit_pre' );

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
