<?php
/*
Plugin Name: Dottoro Theme Updater
Plugin URI: http://wordpress.org/extend/plugins/dottoro-theme-updater/
Description: Dottoro Updater plugin is an automation tool to update your Dottoro themes migrating their actual skin settings to the updated ones.
Version: 1.0
Author: Dottoro.com
Author URI: http://themeeditor.dottoro.com
Network: true
License: GPL2

Tags: dottoro, theme, updater, update

*/

/*
Copyright 2010  Dottoro.com  (email : info@dottoro.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


class Dottoro_Theme_Updater
{
	public $plugin_path;
	public $option_key;

	function __construct()
	{
		$this->plugin_path = plugin_basename(__FILE__);
		$this->option_service_key = 'dottoro_updater_service_key';
		$this->theme_update_option_key = 'dr_theme_updates';

		$this->editor_url = 'http://themeeditor.dottoro.com/';
		$this->editor_api_url = $this->editor_url . 'api/';
		$this->editor_download_url = $this->editor_api_url . 'downloadPackage.php';
		$this->dummy_download_url = $this->editor_api_url . 'downloadDummy.php';
		$this->editor_docs_url = $this->editor_url . 'docs/';

			// languages files
		load_plugin_textdomain('dottoro_updater', false, basename( dirname( __FILE__ ) ) . '/languages' );

		if ( function_exists ( 'is_multisite' ) && is_multisite () ) {
				// multisite menu
			add_action ( 'network_admin_menu', array( &$this, 'add_mu_menus' ) );
		} else {
				// single menu
			add_action ( 'admin_menu', array (&$this, 'add_menus') );
		}

			// add filter to update_themes transient
		add_filter( 'site_transient_update_themes', array( &$this, 'add_update_alert' ) );

			// add action to update_themes delete_transient
		do_action( 'deleted_site_transient', array( &$this, 'delete_update_themes_transient' ) );

			// add filter to HTTP Request
		add_filter( 'pre_http_request', array( &$this, 'change_request' ), 10, 3 );
	}

	function add_mu_menus () {
		add_submenu_page( 'plugins.php', __('Dottoro Updater', 'dottoro_updater'), __('Dottoro Updater', 'dottoro_updater'), 'switch_themes', $this->plugin_path, array( &$this, 'admin_page' ) );
	}

	function add_menus () {
		add_submenu_page( 'themes.php', __('Dottoro Updater', 'dottoro_updater'), __('Dottoro Updater', 'dottoro_updater'), 'switch_themes', $this->plugin_path, array( &$this, 'admin_page' ) );
	}

	function admin_page ()
	{
		if ( ! current_user_can( 'update_themes' ) ) {
			wp_die ( __('You do not have sufficient permissions to see this page.', 'dottoro_updater') );
		}

		$form_saved = $this->process_form();

		$service_key = get_site_option ($this->option_service_key);
	?>
		<style>
			.notice {
				padding:8px; margin:16px 0; background-color:#faf6d6; border:1px solid #c8b87d; color:#333;
			}
		</style>
		<div class="wrap">
			<?php screen_icon('tools'); ?>
			<h2><?php _e('Dottoro Theme Updater', 'dottoro_updater'); ?></h2>

			<p>
				<?php printf ( __('You need a service key to use Dottoro Theme Updater. You can request a service key on your account page on Dottoro.com under <a href="%1$s" target="_blank">Service Keys</a>', 'dottoro_updater'), esc_url(URL_DR_THEME_EDITOR . 'account/servicekeys.php') ); ?>
				<a class="theme_doc_link" target="_blank" href="<?php echo esc_url (URL_DR_THEME_EDITOR_DOCS . 'theme.php#creating_theme_desings'); ?>">[+]</a>
			</p>

			<?php
				if ( $form_saved ) {
					echo('<p class="notice">' . __('Service Key Saved. Thank You.', 'dottoro_updater') . '</p>');
				}
			?>

			<div style="margin-top:40px;">
				<form method="post" action="">
					<table class="form-table">
						<tbody>
							<tr valign="top">
								<th scope="row">
									<label for="sevice_key">
										<?php _e('Service Key', 'dottoro_updater'); ?>
									</label>
								</th>
								<td>
									<input name="service_key" class="service_key" id="service_key" value="<?php echo esc_attr ($service_key); ?>" type="text" style=" width:300px;"/>
									<div>
										<small><?php __('Here you can set your service key.', 'dottoro_updater'); ?></small>
									</div>
								</td>
							</tr>
						</tbody>
					</table>

					<p>
						<input type="hidden" name="dr_theme_submit" value="theme_updater" />
						<input type="submit" name="submit" class="button-primary autowidth" value="<?php _e('Save Service Key', 'dottoro_updater'); ?>" style="outline:none;" />
					</p>
				</form>
				<div class="notice" style="margin-top:30px;">
					<?php _e("<b>Important:</b> The service key is a security code; treat it like you treat your passwords. With the service key, anyone can download and modify your skins. If you suspect that someone might have got access to your service key, delete it and request a new one on your account page on Dottoro.com.", 'dottoro_updater'); ?>
				</div>
			</div>
		</div>
	<?php
	}

	function process_form ()
	{
		$_POST = stripslashes_deep( $_POST );
		if ( isset( $_POST['dr_theme_submit'] ) && $_POST['dr_theme_submit'] == 'theme_updater' ) {
			if ( ! isset( $_POST['service_key'] ) ) {
				return;
			}
			update_site_option ( $this->option_service_key, trim ( $_POST['service_key'] ) );
			return true;
		}
		return false;
	}



/**************************
*   Theme updates check   *
**************************/

	function check_theme_update ( $forceCheck = false )
	{
		if ( ! $forceCheck ) {
			$current = get_site_transient( $this->theme_update_option_key );
			if ( isset( $current['last_checked'] ) && ! isset ($current['error']) && 43200 > ( time( ) - $current['last_checked'] ) ) {
				return $current;
			}
		}
		if ( ! isset ( $current ) ) {
			$current = array ();
		}

			// get installed themes
		$dr_themes =  $this->get_dr_themes ();

		if ( empty ( $dr_themes ) ) {
			return array ();
		}

			// Update last_checked for current to prevent multiple blocking requests if request hangs
		$current['last_checked'] = time();
		set_site_transient ( $this->theme_update_option_key, $current );

		global $wp_version;

		$options = array(
			'timeout' 		=> ( ( defined('DOING_CRON') && DOING_CRON ) ? 30 : 3),
			'body'			=> array( 'themes' => json_encode ($dr_themes) ),
			'user-agent'	=> 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' )
		);

		$raw_response = wp_remote_post( $this->editor_api_url . 'update_check.php', $options );

		$current['last_checked'] = time ();

		if ( is_wp_error( $raw_response ) ) {
			$current['error'] = array ( 'wp_error' => $raw_response->get_error_codes () );
			set_site_transient ( $this->theme_update_option_key, $current, 10800 );
			return $current;
		}

		$response_code = wp_remote_retrieve_response_code( $raw_response );
		if ( 200 != $response_code ) {
			$current['error'] = array ( 'response_code' => $response_code );
			set_site_transient ( $this->theme_update_option_key, $current, 10800 );
			return $current;
		}

		$response = json_decode ( wp_remote_retrieve_body( $raw_response ), true );
		if ( is_null ( $response ) ) {
			$current['error'] = array ( 'invalid_response' => $raw_response );
			set_site_transient ( $this->theme_update_option_key, $current, 10800 );
			return $current;
		}

		if ( isset ( $response['error'] ) ) {
			$current['error'] = array ( 'dr_error' => $response['error'] );
			set_site_transient ( $this->theme_update_option_key, $current, 10800 );
			return $current;
		}

			// latest, development, upgrade
		$current['themes'] = $response['body'];

		set_site_transient ( $this->theme_update_option_key, $current, 43200 );
		return $current;
	}

	function get_dr_themes ( )
	{
		$themes = get_themes ();
		$dr_themes = array ();

		foreach ($themes as $themeName => $themeArgs)
		{
				// Template Dir contains the parent theme folder
				// we use Stylesheet Dir to filter child themes
			if ( isset ( $themeArgs['Stylesheet Dir'] ) )
			{
				$version_path = $themeArgs['Stylesheet Dir'] . '/common/version.php';
				$theme_datas = $this->get_theme_version_datas ( $version_path );
				if ( empty ( $theme_datas ) ) {
					continue;
				}
				$theme_datas['basedir'] = $themeArgs['Template Dir'];
				$dr_themes[$themeArgs['Template']] = $theme_datas;
			}
		}
		return $dr_themes;
	}

	function get_theme_version_datas ( $path = '' )
	{
		$datas = array ();
		if ( $path ) {
			if ( @is_file ( $path ) ) {
				require ( $path );
				if ( isset ($DR_THEME_ID) && isset ($DR_THEME_VERSION) && isset ($DR_THEME_UNIQUEID) && isset ($DR_THEME_AUTHOR) && $DR_THEME_AUTHOR == 'dottoro.com' )
				{
					$datas = array ( 
						'theme_id' 		=> $DR_THEME_ID,
						'theme_version' => $DR_THEME_VERSION,
					);
				}
			}
		}
		return $datas;
	}

	function delete_update_themes_transient ( $transient )
	{
		if ( $transient == 'update_themes' ) {
			delete_site_transient ( $this->theme_update_option_key );
		}
	}

/*******************
*   Update Alert   *
*******************/

	function is_any_obsolete ( $response = '' )
	{
		if ( ! $response ) {
			$response = get_site_transient( $this->theme_update_option_key );
		}
		if ( isset ( $response['themes'] ) ) {
			foreach ( $response['themes'] as $id => $args ) {
				if ( isset ($args['status']) && $args['status'] == 'upgrade' ) {
					return true;
				}
			}
		}
		return false;
	}

		// adds updates to update_themes transient if any
	function add_update_alert ( $value )
	{
		$response = $this->check_theme_update ();

		if ( $this->is_any_obsolete ( $response ) ) {
			if ( is_object ( $value ) ) {
				if ( ! isset ( $value->response ) ) {
					$value->response = array ();
				}

				if ( isset ( $response['themes'] ) ) {
					foreach ( $response['themes'] as $template => $args ) {
						if ( isset ($args['status']) && $args['status'] == 'upgrade' )
						{
							$value->response[$template] = array (
																	'new_version' 	=> $args['version'],
																	'url' 			=> $this->editor_url,
																	'package' 		=> $this->dummy_download_url . '?template=' . $template,
																);
						}
					}
				}
			} else {
				delete_site_transient ( $this->theme_update_option_key );
			}
		}
		return $value;
	}



/********************
*   Theme Updater   *
********************/

	function change_request ( $u, $r = array (), $url = '' )
	{
			// is dottoro update request?
		if ( strpos ( $url, $this->dummy_download_url ) === false ) {
			return $u;
		}

			// get template name
		$template = '';
		$temp_start = strpos ( $url, 'template=' );
		if ( $temp_start ) {
			$template = substr ( $url, $temp_start + strlen ('template=') );
		}

			// get installed themes
		$dr_themes =  $this->get_dr_themes ();

		if ( ! $template || empty ( $dr_themes ) || ! isset ( $dr_themes[$template] ) ) {
				// no themes installed
			return new WP_Error( 'no_theme', sprintf ( __('Theme %s not found.', 'dottoro_updater'), $template ) );
		}

		$tmpfname = '';
		if ( isset ( $r['filename']  ) && $r['filename'] ) {
			$tmpfname = $r['filename'];
		}

		return $this->download_package ( $template, $dr_themes[$template], $tmpfname );
	}

	function download_package ( $template, $args, $tmpfname = '' )
	{
		$url = $this->editor_download_url;
		$delete_temp = false;

		if ( ! $tmpfname ) {
			$delete_temp = true;
			$tmpfname = wp_tempnam($url);
			if ( ! $tmpfname ) {
				return new WP_Error( 'http_no_file', __('Could not create Temporary file.') );
			}
		}

		$skin_datas = $this->get_skin_datas ( $template, $args['basedir'] );
		if ( ! $skin_datas ) {
			return new WP_Error( 'dr_no_skins', __('No skins found.') );
		}

		$service_key = get_site_option ( $this->option_service_key );

		$settings = array (
			'body' 		=> array (
							'theme_id' 		=> $args['theme_id'],
							'wp_version' 	=> get_bloginfo( 'version' ),
							'type' 			=> 'theme',
							'SERVICE_KEY' 	=> $service_key,
							'skin_settings' => json_encode ($skin_datas['default']),
							'sites' 		=> json_encode ($skin_datas['site_skins']),
							'package_name' 	=> $template,
							'error_format' 	=> 'simple',
						),
			'timeout' 	=> 300,
			'stream' 	=> true,
			'filename' 	=> $tmpfname,
		);

			// download package
		$response = wp_remote_post( $url, $settings );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'dr_response', $response->get_error_codes () );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 != $response_code ) {
			return new WP_Error( 'response_code', $response_code () );
		}

		$binary = false;
		foreach ($response['headers'] as $key => $value) {
			if (strcasecmp (trim ($key), 'content-type') == 0) {
				$binary = (strncasecmp ("application/", trim ($value), 12) == 0);
				break;
			}
		}

		$file_contents = file_get_contents ($tmpfname);
		if ( $delete_temp ) {
			@unlink ($tmpfname);
		}

		if (!$binary) {
			return new WP_Error( 'dr_response', $file_contents );
		}
		return $response;
	}

		// Skin datas are placed by default in the theme directory
		// but the theme editor saves sniks in the uploads folder, because of multisite support
	function get_skin_datas ( $template, $template_dir = "" )
	{
		if ( ! $template_dir ) {
			$themes = get_themes ();
			foreach ( ( array ) $themes as $theme_name => $theme_args ) {
				if ( $theme_args['Template Dir'] == $theme_args['Stylesheet Dir'] && $theme_args['Template'] == $template) {
					$template_dir = $theme_args['Template Dir'];
				}
			}
		}
		if ( ! $template_dir ) {
			return false;
		}

		WP_Filesystem ();
		global $wp_filesystem;

		$skins_folder = $template_dir . '/skins';
		if ( ! $wp_filesystem->is_dir ( $skins_folder ) ) {
			return false;
		}

			//get saved skins from uploads folder
		$site_skins = array ();
		$sites_folder = $skins_folder . DIRECTORY_SEPARATOR . '/site_';
		if ( function_exists ('is_multisite') && is_multisite () )
		{
				// WP 3.2 doesn't support function to get all blog_ids
			global $wpdb;

				// no conditions needed, because all state is reversible
			$result = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs");
			foreach ( $result as $blog_id ) {
				$site_folder = $sites_folder . $blog_id . DIRECTORY_SEPARATOR;
				if ($wp_filesystem->is_file ( $site_folder . 'style_skin.css' ) && $wp_filesystem->is_file ( $site_folder . 'skin_settings.php' ) ) {
					$file_content = $this->get_skin_data ( $site_folder . 'skin_settings.php' );
					if ( ! empty ( $file_content ) ) {
						$site_skins["". $blog_id] = $file_content;
					}
				}
			}
		}
		else {
				// WP calls site_id as blog_id
				// and network_id as site_id
			$blog_id = 1;
			$site_folder = $sites_folder . $blog_id . DIRECTORY_SEPARATOR;
			if ($wp_filesystem->is_file ( $site_folder . 'style_skin.css' ) && $wp_filesystem->is_file ( $site_folder . 'skin_settings.php' ) ) {
				$file_content = $this->get_skin_data ( $site_folder . 'skin_settings.php' );
				if ( ! empty ( $file_content ) ) {
					$site_skins["". $blog_id] = $file_content;
				}
			}
		}

			// get the default skin, placed theme root / skins / default folder
		if ($wp_filesystem->is_file ( $skins_folder . '/default/style_skin.css' ) && $wp_filesystem->is_file ( $skins_folder . '/default/skin_settings.php' ) ) {
			$file_content = $this->get_skin_data ( $skins_folder . '/default/skin_settings.php' );
			if ( ! empty ( $file_content ) ) {
				$default = $file_content;
			}
		}

		return array ( 'site_skins' => $site_skins, 'default' => $default );
	}

	function get_skin_data ( $path = '' )
	{
		$datas = array ();
		if ( $path ) {
			if ( @is_file ( $path ) ) {
				require_once ( $path );
				return $skin_settings;
			}
		}
		return $datas;
	}

}

new Dottoro_Theme_Updater ();
?>