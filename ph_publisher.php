<?php

/*
	Plugin Name: PH Publisher
	Plugin URI: #
	Description: Load your Performance Horizon publisher credentials for access to PH data. You will be able to quickly add your tracking links to any new post you create.
	Version: 1.1.5
	Author: Performance Horizon
	Author URI: http://www.performancehorizon.com/
	License: GPLv2 or later
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define( 'PH_PUBLISHER_VERSION', '1.1.5' );

require_once( plugin_dir_path( __FILE__ ) . "functions.php" );
require_once( plugin_dir_path( __FILE__ ) . "class/ph_tracking_list.php" );

/**
 * The hook into activation for setup
 */
function ph_publisher_install()
{
	if ( ! current_user_can( 'activate_plugins' ) )
	{
		return;
	}

	global $wpdb;
	global $charset_collate;

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	$str_table_name = $wpdb->prefix . 'ph_publisher_participation';
	if ($wpdb->get_var("SHOW TABLES LIKE '{$str_table_name}'") !== $str_table_name)
	{
		$sql = "CREATE TABLE IF NOT EXISTS `{$str_table_name}` (
			ID int(11) AUTO_INCREMENT NOT NULL,
			auth_id int(11) NOT NULL,
			campaign_id varchar(100) NOT NULL,
			campaign_title text NOT NULL,
			camref varchar(100) NOT NULL UNIQUE,
			tracking_link text NOT NULL,
			is_cpc enum('y','n') NOT NULL DEFAULT 'n',
			PRIMARY KEY (ID)
		) $charset_collate;";
		dbDelta( $sql );
	}

	$str_table_name = $wpdb->prefix . 'ph_publisher_auth';
	if ($wpdb->get_var("SHOW TABLES LIKE '{$str_table_name}'") !== $str_table_name)
	{
		$sql = "CREATE TABLE IF NOT EXISTS `{$str_table_name}` (
			ID int(11) AUTO_INCREMENT NOT NULL,
			wp_user_id int(11) NOT NULL UNIQUE,
			application_api_key varchar(100) NOT NULL,
			user_api_key varchar(100) NOT NULL,
			publisher_id varchar(100) NOT NULL,
			PRIMARY KEY (ID)
		) $charset_collate;";
		dbDelta( $sql );
	}

	if ( get_option( '_ph_auth_table' ) === FALSE)
	{
		add_option( '_ph_auth_table', 'ph_publisher_auth' );
	}
	if ( get_option( '_ph_participation_table' ) === FALSE)
	{
		add_option( '_ph_participation_table', 'ph_publisher_participation' );
	}
	if ( get_option( '_ph_publisher_version' ) === FALSE)
	{
		add_option( '_ph_publisher_version', PH_PUBLISHER_VERSION );
	}
}
register_activation_hook( __FILE__, 'ph_publisher_install' );


/**
 * The hook into deactivation
 */
function ph_publisher_uninstall()
{
	if ( ! current_user_can( 'update_plugins' ))
	{
		return;
	}

	global $wpdb;

	$sql = "DROP TABLE IF EXISTS " . $wpdb->prefix . get_option( '_ph_auth_table' );
	$wpdb->query( $sql );

	$sql = "DROP TABLE IF EXISTS " . $wpdb->prefix . get_option( '_ph_participation_table' );
	$wpdb->query( $sql );

	delete_option( '_ph_auth_table' );
	delete_option( '_ph_participation_table' );
	delete_option( '_ph_publisher_version' );
}
register_deactivation_hook( __FILE__, 'ph_publisher_uninstall' );


/**
 * Add the menu option to the Admin section
 */
function ph_publisher_admin()
{
	if (has_ph_publisher_links( get_current_user_id() ) && is_ph_publisher_access_verified())
	{
		add_action( "add_meta_boxes", "ph_publisher_register_meta_boxes" );
		add_filter( "mce_external_plugins", "ph_publisher_enqueue_plugin_scripts" );
		add_filter( "mce_buttons", "ph_publisher_register_buttons_editor" );
	}

	add_menu_page(
		"PH Publisher",
		"PH Publisher",
		"manage_options",
		"ph_publisher_settings",
		"ph_publisher_settings_page",
		plugin_dir_url( __FILE__ ) . 'assets/16x16-ph_icon.png'
	);
}
add_action("admin_menu", "ph_publisher_admin");

function ph_publisher_add_action_links( $links )
{
	$mylinks = array(
		'<a href="' . admin_url( 'admin.php?page=ph_publisher_settings' ) . '">Settings</a>',
		);

	return array_merge( $links, $mylinks );
}
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'ph_publisher_add_action_links' );


/**
 * Show the settings page in admin for credentials
 */
function ph_publisher_settings_page()
{
	global $wpdb;

	$str_auth_table = $wpdb->prefix . get_option( '_ph_auth_table' );
	$user_id = get_current_user_id();

	$auth_id = ph_get_auth_value( 'ID', $user_id );

	if (is_null( $auth_id ))
	{
		$sql = "INSERT INTO `{$str_auth_table}` (wp_user_id, application_api_key, user_api_key, publisher_id) VALUES ({$user_id}, '', '', '')";
		$wpdb->query( $sql );
	}

	if (isset( $_POST['submit'] ))
	{
		ph_update_publisher_auth( $user_id );
	}

	$application_api_key = ph_get_auth_value( 'application_api_key', $user_id );
	$user_api_key = ph_get_auth_value( 'user_api_key', $user_id );
	$publisher_id = ph_get_auth_value( 'publisher_id', $user_id );

	?>
	<div class="wrap">
		<h1>Enter PH API Credentials</h1>
		<form action="" method="post">
			<?php wp_nonce_field( 'ph_publisher_auth_' . get_current_user_id() ); ?>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="application_api_key">Application API Key</label></th>
						<td><input type="text" name="application_api_key" placeholder="<?php echo $application_api_key; ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="user_api_key">User API Key</label></th>
						<td><input type="text" name="user_api_key" placeholder="<?php echo $user_api_key; ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="publisher_id">Publisher ID</label></th>
						<td><input type="text" name="publisher_id" placeholder="<?php echo $publisher_id; ?>" /></td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" name="submit" id="submit" class="button button-primary" value="Update API Credentials">
				<input type="submit" name="refresh" id="refresh" class="button" value="Refresh Approved Campaigns">
			</p>
		</form>
	<?php

	if (isset( $_POST['refresh'] ))
	{
		ph_refresh_publisher_campaigns();
	}

	if ( ! is_null( $auth_id ))
	{
		ph_show_participation_details();
	}

	?>

	</div>

	<?php
}

?>