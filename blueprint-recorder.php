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
						'url'      => 'https://github-proxy.com/proxy/?repo=akirk/blueprint-recorder&branch=main',
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
		$plugin_steps = array();
		$dependent_upon = array();
		foreach ( $plugins as $plugin ) {
			$slug = explode( '/', $plugin )[0];
			if ( in_array( $slug, $ignore ) || $ignore_all_plugins ) {
				continue;
			}
			$plugin_resource = $this->get_plugin_resource( $slug );

			if ( $plugin_resource ) {
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
				$plugin_steps[ $slug ] = array(
					'step'       => 'installPlugin',
					'pluginData' => $plugin_resource,
					'name'       => $plugin_data['Name'],
					'info'       => '',
				);

				if ( isset( $plugin_data['RequiresPlugins'] ) && ! empty( $plugin_data['RequiresPlugins'] ) ) {
					foreach ( explode( ',', $plugin_data['RequiresPlugins'] ) as $dependent ) {
						if ( ! isset( $dependent_upon[ $dependent ] ) ) {
							$dependent_upon[ $dependent ] = array();
						}
						$dependent_upon[ $dependent ][] = $slug;
					}
				}
			} else {
				$this->ignored_plugins[] = $slug;
			}
		}

		foreach ( $dependent_upon as $plugin => $dependents ) {
			if ( ! isset( $plugin_steps[ $plugin ] ) ) {
				continue;
			}
			$plugin_steps[ $plugin ]['info'] = ' (prioritized because of ' . implode(
				', ',
				array_map(
					function ( $dependent ) use ( $plugin_steps ) {
						return $plugin_steps[ $dependent ]['name'];
					},
					$dependents
				)
			) . ')';
			$steps[] = $plugin_steps[ $plugin ];
			unset( $plugin_steps[ $plugin ] );
		}
		foreach ( $plugin_steps as $plugin => $step ) {
			$steps[] = $step;
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

		$blueprint = array(
			'landingPage'         => '/',
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
				#select-users label.password {
					display: none;
				}
				#select-users input[type="checkbox"]:checked + label + label.password {
					display: inline;
				}
			</style>
			<details <?php echo get_option( 'blueprint_recorder_disabled' ) ? '' : 'open'; ?> id="select-sql-log">
				<summary>Record SQL Queries <span class="checked"></span></summary>
					<form method="post">
						<?php echo wp_nonce_field( 'blueprint' ); ?>
						<p>This will record INSERT, UPDATE, and DELETE queries that can then be inserted in the blueprint.</p>
						<?php if ( get_option( 'blueprint_recorder_disabled' ) ) : ?>
							<button name="start-recording">Start Recording Modifying SQL Queries</button>
						<?php else : ?>
							<button name="stop-recording">Stop Recording Modifying SQL Queries</button>
						<?php endif; ?>
					</form>

					<a href="" id="select-all-sql-log">Select all</a> <a href="" id="select-none-sql-log">Select none</a>
					<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=clear_sql_logs' ) ); ?>">Clear all SQL logs</a><br/>

					<select id="sql-log" multiple="multiple" size="10" style="max-width: 100%; width: 100%">
					<?php
					foreach ( get_posts(
						array(
							'post_type'      => 'sql_log',
							'posts_per_page' => -1,
						)
					) as $post ) :
						?>
						<option value="<?php echo esc_attr( $post->post_content ); ?>" selected="selected"><?php echo esc_html( $post->post_content ); ?></option>
					<?php endforeach; ?>
					</select>
			</details>

			<details id="select-pages">
				<summary>Pages <span class="checked"></span></summary>
			<?php foreach ( get_pages( array() ) as $page ) : ?>
					<label><input type="checkbox" data-id="<?php echo esc_attr( $page->ID ); ?>" onchange="updateBlueprint()" onkeyup="updateBlueprint()" data-post_title="<?php echo esc_attr( $page->post_title ); ?>" data-post_content="<?php echo esc_attr( str_replace( PHP_EOL, '\n', $page->post_content ) ); ?>" /> <?php echo esc_html( $page->post_title ); ?></label><br/>
				<?php endforeach; ?>
			</details>

			<details id="select-template-parts">
				<summary>Template Parts <span class="checked"></span></summary>
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
					<label><input type="checkbox" data-id="<?php echo esc_attr( $template_part->ID ); ?>" onchange="updateBlueprint()" onkeyup="updateBlueprint()" data-post_title="<?php echo esc_attr( $template_part->post_title ); ?>" data-post_content="<?php echo esc_attr( str_replace( PHP_EOL, '\n', $template_part->post_content ) ); ?>"/> <?php echo esc_html( $template_part->post_title ); ?></label><br/>

				<?php endforeach; ?>
			</details>

			<details>
				<summary>Media</summary>
				<a href="?media_zip_download" download="media-files.zip">Download the ZIP file of all media</a> and then upload it to somewhere web accessible.<br>
				Then, enter the URL of the uploaded ZIP file: <input type="url" id="zip-url" value="" />. The blueprint below will update.<br>
			</details>


			<details id="select-constants">
				<summary>Constants <span class="checked"></span></summary>
				<ul id="additionalconstants">
				</ul>
				<datalist id="constants">
					<?php foreach ( get_defined_constants() as $name => $value ) : ?>
						<option label="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>" />
					<?php endforeach; ?>
				</datalist>
				<datalist id="constant-values">
					<?php foreach ( get_defined_constants() as $name => $value ) : ?>
						<option label="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php endforeach; ?>
				</datalist>
				<input type="text" id="constant-name" list="constants" placeholder="Constant Name" size="30" onchange="updateConstantValue()" oninput="updateConstantValue()" onkeyup="updateConstantValue()"/>
				<span id="constant-value"></span>
				<button onclick="addConstantToBlueprint()">Add</button>
			</details>

			<details id="select-options">
				<summary>Options <span class="checked"></span></summary>
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
				<input type="text" id="option-name" list="options" placeholder="Option Name" size="30" onchange="updateOptionValue()" oninput="updateOptionValue()" onkeyup="updateOptionValue()"/>
				<span id="option-value"></span>
				<button onclick="addOptionToBlueprint()">Add</button>
			</details>

			<details id="select-plugins">
				<summary>Plugins <span class="checked"></span></summary>
					<a href="" id="select-all-plugins">Select all</a> <a href="" id="select-none-plugins">Select none</a>
					<ul>
					<?php foreach ( $blueprint['steps'] as $k => $step ) : ?>
						<?php if ( 'installPlugin' === $step['step'] ) : ?>
						<li class="plugin" id="plugin_<?php echo esc_attr( $k ); ?>"><label><input type="checkbox" id="use_plugin_<?php echo esc_attr( $k ); ?>" checked onchange="updateBlueprint()" value="<?php echo esc_attr( $k ); ?>" /> <?php echo esc_html( $step['name'] . $step['info'] ); ?></label></li>
					<?php endif; ?>
					<?php endforeach; ?>
					</ul>
			</details>

			<details id="select-theme">
				<summary>Theme</summary>
				<label><input type="checkbox" id="ignore-theme" onclick="updateBlueprint()"> Ignore Theme</label><br>
			</details>
			<details id="select-users">
				<summary>Users <span class="checked"></span></summary>
				<ul>
					<?php foreach ( get_users() as $u ) : ?>
						<?php if ( 'admin' !== $u->user_login ) : ?>
							<li>
								<input type="checkbox" data-login="<?php echo esc_attr( $u->user_login ); ?>" data-name="<?php echo esc_attr( $u->display_name ); ?>" data-role="<?php echo esc_attr( $u->roles[0] ); ?>" onchange="updateBlueprint()" id="user_<?php echo esc_attr( $u->user_login ); ?>" /> <label for="user_<?php echo esc_attr( $u->user_login ); ?>"><?php echo esc_html( $u->display_name ); ?></label>
								<label class="password">Password: <input type="text" value="" placeholder="Set a password in the blueprint" onchange="updateBlueprint()"/></label><br/>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
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
				const ignorePlugins = JSON.parse( localStorage.getItem( 'blueprint_recorder_ignore_plugins' ) || '[]' );
				for ( let i = 0; i < blueprint.steps.length; i++ ) {
					if ( blueprint.steps[i].step === 'installPlugin' &&  ignorePlugins.includes( blueprint.steps[i].name ) ) {
						document.getElementById('use_plugin_' + i).checked = false;
					}
					if ( blueprint.steps[i].step === 'installTheme' && localStorage.getItem( 'blueprint_recorder_ignore_theme' ) ) {
						document.getElementById('ignore-theme').checked = true;
						document.getElementById('select-theme').open = true;
					}
				}
				let additionalOptions = JSON.parse( localStorage.getItem( 'blueprint_recorder_additional_options' ) || '{}' );
				const additionalOptionsList = document.getElementById('additionaloptions');
				for ( const optionKey in additionalOptions ) {
					if ( additionalOptions.hasOwnProperty( optionKey ) ) {
						const li = document.createElement('li');
						const key = document.createElement('input');
						key.type = 'text';
						key.name = 'key';
						key.placeholder = 'Key';
						key.value = optionKey;
						li.appendChild(key);
						const value = document.createElement('input');
						value.type = 'text';
						value.name = 'value';
						value.placeholder = 'Value';
						value.value = additionalOptions[optionKey];
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
				const constants = JSON.parse( localStorage.getItem( 'blueprint_recorder_constants' ) || '{}' );
				const constantsList = document.getElementById('additionalconstants');
				for ( const constantKey in constants ) {
					if ( constants.hasOwnProperty( constantKey ) ) {
						const checkbox = document.querySelector( '#select-constants input[type="checkbox"][value="' + constantKey + '"]' );
						if ( checkbox ) {
							checkbox.checked = true;
							if ( typeof constants[constantKey] === 'string' ) {
								checkbox.nextSibling.value = constants[constantKey];
							}
						} else {
							const li = document.createElement('li');
							const key = document.createElement('input');
							key.type = 'text';
							key.name = 'key';
							key.placeholder = 'Key';
							key.value = constantKey;
							li.appendChild(key);
							const value = document.createElement('input');
							value.type = 'text';
							value.name = 'value';
							value.placeholder = 'Value';
							value.value = constants[constantKey];
							li.appendChild(value);
							constantsList.appendChild(li);
						}
					}
				}
				const pages = JSON.parse( localStorage.getItem( 'blueprint_recorder_pages' ) || '[]' );
				document.querySelectorAll( '#select-pages input[type="checkbox"]' ).forEach( function ( checkbox ) {
					if ( pages.includes( checkbox.getAttribute('data-id') ) ) {
						checkbox.checked = true;
					}
				} );
				const template_parts = JSON.parse( localStorage.getItem( 'blueprint_recorder_template_parts' ) || '[]' );
				document.querySelectorAll( '#select-template-parts input[type="checkbox"]' ).forEach( function ( checkbox ) {
					if ( template_parts.includes( checkbox.getAttribute('data-id') ) ) {
						checkbox.checked = true;
					}
				} );
				const zipUrl = localStorage.getItem( 'blueprint_recorder_zip_url' );
				if ( zipUrl ) {
					document.getElementById('zip-url').value = zipUrl;
				}
				document.getElementById('zip-url').addEventListener('change', function (event) {
					localStorage.setItem( 'blueprint_recorder_zip_url', event.target.value );
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
					let last_step = null;
					const steps = [], plugins = [], ignore_plugins = [];
					for ( let i = 0; i < blueprint.steps.length; i++ ) {
						if ( blueprint.steps[i].step === 'installPlugin' ) {
						if ( ! document.getElementById('use_plugin_' + i).checked ) {
								ignore_plugins.push( blueprint.steps[i].name );
								continue;
							}
							delete blueprint.steps[i].name;
							delete blueprint.steps[i].info;
							if ( blueprint.steps[i].pluginData?.url?.indexOf('blueprint-recorder') > -1 ) {
								last_step = blueprint.steps[i];
								continue;
							}
							plugins.push( blueprint.steps[i].pluginData.slug );
						}
						if ( blueprint.steps[i].step === 'setSiteOptions' ) {
							additionalOptions = {};
							document.querySelectorAll( '#select-options input[name=key]' ).forEach( function ( checkbox ) {
								if ( checkbox.value ) {
									if ( checkbox.getAttribute('type') === 'checkbox' ) {
										additionalOptions[checkbox.value] = checkbox.checked;
									} else if ( checkbox.nextSibling?.tagName === 'INPUT' ) {
										additionalOptions[checkbox.value] = checkbox.nextSibling.value;
									}
									blueprint.steps[i].options[checkbox.value] = additionalOptions[checkbox.value];
								}
							} );
							if ( Object.values(additionalOptions).length ) {
								localStorage.setItem( 'blueprint_recorder_additional_options', JSON.stringify( additionalOptions ) );
							} else {
								localStorage.removeItem( 'blueprint_recorder_additional_options' );
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
					if ( ignore_plugins.length) {
						localStorage.setItem( 'blueprint_recorder_ignore_plugins', JSON.stringify( ignore_plugins ) );
					} else {
						localStorage.removeItem( 'blueprint_recorder_ignore_plugins' );
					}

					document.querySelector( '#select-plugins .checked' ).textContent = (plugins.length + (last_step ? 1 : 0)) ? ' (' + (plugins.length + (last_step ? 1 : 0)) + ')' : '';
					const users = [], passwords = [];
					document.querySelectorAll( '#select-users input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							users.push( checkbox.getAttribute('data-login') );
							const password = checkbox.parentNode.querySelector('.password input').value;
							passwords.push( password );
							steps.push( {
								'step' : 'runPHP',
								'code' : "<" + "?php require_once 'wordpress/wp-load.php'; $data = array( 'user_login' => '" + checkbox.dataset.login + "', 'display_name' => '" + checkbox.dataset.name.replace( /'/g, "\\'" ) + "', 'role' => '" + checkbox.dataset.role + "', 'user_pass' => '" + password.replace( /'/g, "\\'" ) + "' ); wp_insert_user( $data ); ?>",
							} );
						}
					} );
					if ( users.length ) {
						localStorage.setItem( 'blueprint_recorder_users', JSON.stringify( users ) );
						localStorage.setItem( 'blueprint_recorder_passwords', JSON.stringify( passwords ) );
					} else {
						localStorage.removeItem( 'blueprint_recorder_users' );
						localStorage.removeItem( 'blueprint_recorder_passwords' );
					}
					document.querySelector( '#select-users .checked' ).textContent = users.length ? ' (' + users.length + ')' : '';

					const constants = {};
					document.querySelectorAll( '#select-constants input[name=key]' ).forEach( function ( checkbox ) {
						if ( checkbox.value ) {
							if ( checkbox.getAttribute('type') === 'checkbox' ) {
								checkbox.checked = true;
							} else if ( checkbox.nextSibling?.tagName === 'INPUT' ) {
								constants[checkbox.value] = checkbox.nextSibling.value;
							}
						}
					} );
					if ( Object.values(constants).length ) {
						localStorage.setItem( 'blueprint_recorder_constants', JSON.stringify( constants ) );
						steps.push( {
							'step' : 'defineWpConfigConsts',
							'consts' : constants,
						} );
					} else {
						localStorage.removeItem( 'blueprint_recorder_constants' );
					}
					document.querySelector( '#select-constants .checked' ).textContent = Object.values(constants).length ? ' (' + Object.values(constants).length + ')' : '';
					document.querySelector( '#select-options .checked' ).textContent = Object.values(additionalOptions).length ? ' (' + Object.values(additionalOptions).length + ')' : '';

					const pages = [];
					document.querySelectorAll( '#select-pages input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							pages.push( checkbox.getAttribute('data-id') );
							steps.push( {
								'step' : 'runPHP',
								'code' : "<" + "?php require_once 'wordpress/wp-load.php'; wp_insert_post( array( 'post_type' => 'page', 'post_title' => '" + checkbox.dataset.post_title.replace( /'/g, "\\'" ) + "', 'post_content' => '" + checkbox.dataset.post_content.replace( /'/g, "\\'" ).replace( /\\n/g, "\n" ) + "', 'post_status' => 'publish', ) ); } ?>",
							});
						}
					} );
					if ( pages.length ) {
						localStorage.setItem( 'blueprint_recorder_pages', JSON.stringify( pages ) );
					} else{
						localStorage.removeItem( 'blueprint_recorder_pages' );
					}
					document.querySelector( '#select-pages .checked' ).textContent = pages.length ? ' (' + pages.length + ')' : '';
					const template_parts = [];
					document.querySelectorAll( '#select-template-parts input[type="checkbox"]' ).forEach( function ( checkbox ) {
						if ( checkbox.checked ) {
							template_parts.push( checkbox.getAttribute('data-id') );
							steps.push( {
								'step' : 'runPHP',
								'code' : "<" + "?php require_once 'wordpress/wp-load.php'; $theme = wp_get_theme(); $term = get_term_by( 'slug', $theme->get_stylesheet(), 'wp_theme'); if ( ! $term) { $term = wp_insert_term( $theme->get_stylesheet(), 'wp_theme', ); $term_id = $term['term_id']; } else { $term_id = $term->term_id; } $post_id = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_title' => '" + checkbox.dataset.post_title.replace( /'/g, "\\'" ) + "', 'post_content' => '" + checkbox.dataset.post_content.replace( /'/g, "\\'" ).replace( /\\n/g, "\n" ) + "', 'post_status' => 'publish' ) ); wp_set_object_terms($post_id, $term_id, 'wp_theme'); ?>",
							} );
						}
					} );
					if ( template_parts.length ) {
						localStorage.setItem( 'blueprint_recorder_template_parts', JSON.stringify( template_parts ) );
					} else {
						localStorage.removeItem( 'blueprint_recorder_template_parts' );
					}
					document.querySelector( '#select-template-parts .checked' ).textContent = template_parts.length ? ' (' + template_parts.length + ')' : '';

					const sqlLog = document.getElementById('sql-log');
					let sql = '';
					for ( let i = 0; i < sqlLog.options.length; i++ ) {
						if ( sqlLog.options[i].selected ) {
							sql += sqlLog.options[i].value + "; ";
						}
					}
					if ( sql ) {
						steps.push( {
							'step' : 'runSql',
							'sql'  : {
								'resource' : 'literal',
								'name'     : 'replay.sql',
								'contents' : sql,
							}
						} );
					}

					if ( last_step ) {
						steps.push( last_step );
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
						const key = document.createElement('input');
						key.type = 'text';
						key.name = 'key';
						key.placeholder = 'Key';
						key.value = optionName;
						li.appendChild(key);
						const value = document.createElement('input');
						value.type = 'text';
						value.name = 'value';
						value.placeholder = 'Value';
						value.value = additionalOptions[optionName];
						li.appendChild(value);
						additionalOptionsList.appendChild(li);

						updateBlueprint();
					}
				}

				function updateConstantValue() {
					const constantName = document.getElementById('constant-name').value;
					if ( constantName ) {
						const constantValue = document.querySelector( '#constant-values option[label="' + constantName + '"]' );
						if ( constantValue ) {
							document.getElementById('constant-value').textContent = constantValue.getAttribute('value');
							return constantValue.getAttribute('value');
						}
					}
					return false;
				}
				function addConstantToBlueprint() {
					const constantName = document.getElementById('constant-name').value;
					if ( constantName ) {
						constants[constantName] = updateConstantValue();
						localStorage.setItem( 'blueprint_recorder_constants', JSON.stringify( constants ) );
						if ( constants[constantName] ) {
							const checkbox = document.querySelector( '#select-constants input[name=key][value="' + constantName + '"]' );
							if ( checkbox ) {
								if ( checkbox.nextSibling?.tagName === 'INPUT' ) {
									checkbox.nextSibling.value = constants[constantName];
								} else {
									checkbox.checked = true;
								}
							} else {
								const li = document.createElement('li');
								const key = document.createElement('input');
								key.type = 'text';
								key.name = 'key';
								key.placeholder = 'Key';
								key.value = constantName;
								li.appendChild(key);
								const value = document.createElement('input');
								value.type = 'text';
								value.name = 'value';
								value.placeholder = 'Value';
								value.value = constants[constantName];
								li.appendChild(value);
								constantsList.appendChild(li);
							}
							updateBlueprint();
						}
					}
				}
				document.getElementById('zip-url').addEventListener('keyup', updateBlueprint );
				document.getElementById('blueprint').addEventListener('keyup', updateBlueprint );

				document.addEventListener('change', function (event) {
					if ( event.target.matches('input') ) {
						updateBlueprint();
					}
				} );
				document.addEventListener('keyup', function (event) {
					if ( event.target.matches('input') ) {
						updateBlueprint();
					}
				} );

				document.getElementById('select-all-sql-log').addEventListener('click', function (event) {
					event.preventDefault();
					const sqlLog = document.getElementById('sql-log');
					for ( let i = 0; i < sqlLog.options.length; i++ ) {
						sqlLog.options[i].selected = true;
					}
				} );
				document.getElementById('select-none-sql-log').addEventListener('click', function (event) {
					event.preventDefault();
					const sqlLog = document.getElementById('sql-log');
					for ( let i = 0; i < sqlLog.options.length; i++ ) {
						sqlLog.options[i].selected = false;
					}
				} );

				document.getElementById('select-all-plugins').addEventListener('click', function (event) {
					event.preventDefault();
					document.querySelectorAll('#select-plugins input[type="checkbox"]').forEach(function (checkbox) {
						checkbox.checked = true;
					});
					updateBlueprint();
				});
				document.getElementById('select-none-plugins').addEventListener('click', function (event) {
					event.preventDefault();
					document.querySelectorAll('#select-plugins input[type="checkbox"]').forEach(function (checkbox) {
						checkbox.checked = false;
					});
					updateBlueprint();
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
		// Don't log certain usermeta changes.
		if ( strpos( $query, 'wp_usermeta' ) !== false ) {
			if ( strpos( $query, 'wp_user-settings-time' ) !== false ) {
				return $query;
			}
			if ( strpos( $query, 'session_tokens' ) !== false ) {
				return $query;
			}
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
