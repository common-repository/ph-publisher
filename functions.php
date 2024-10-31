<?php

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

function ph_publisher_update_db_check()
{
	if ( ! current_user_can( 'update_plugins' ) )
	{
		return;
	}

	$str_current_version = get_option( '_ph_publisher_version' );

	if ($str_current_version != PH_PUBLISHER_VERSION)
	{
		ph_publisher_upgrade_install( $str_current_version, PH_PUBLISHER_VERSION);
	}
}
add_action( 'plugins_loaded', 'ph_publisher_update_db_check' );

/**
 * Will be used to manage the upgrades should any DB data need changing
 * @param  String $str_current_version The version last registered with this WP install
 * @param  String $str_release_version The current version of this plugin
 */
function ph_publisher_upgrade_install( $str_current_version, $str_release_version )
{
	update_option( '_ph_publisher_version', $str_release_version );
}

/**
 * Allow the user to change their API credentials
 * @param  Integer $user_id The WP users credentials to update
 */
function ph_update_publisher_auth( $user_id )
{
	global $wpdb;

	// Verify POST nonce
	check_admin_referer( 'ph_publisher_auth_' . get_current_user_id() );

	if ( ! is_ph_publisher_access_verified())
	{
		echo '<div class="notice notice-error is-dismissible"><p>There was an error verifying your Wordpress access.</p></div>';
		return FALSE;
	}

	/**
	 * Sanitize the data to ensure we only have a string with numbers and letter possible
	 * Can be any case
	 */
	$application_api_key = preg_replace( "/[^0-9a-zA-Z]/", "", $_POST['application_api_key'] );
	$user_api_key = preg_replace( "/[^0-9a-zA-Z]/", "", $_POST['user_api_key'] );
	$publisher_id = preg_replace( "/[^0-9a-zA-Z]/", "", $_POST['publisher_id'] );

	$str_table = $wpdb->prefix . get_option( '_ph_auth_table' );
	$wpdb->query( $wpdb->prepare( "
		UPDATE `{$str_table}`
		SET `application_api_key`= '%s',
			`user_api_key` = %s,
			`publisher_id`= '%s'
		WHERE `wp_user_id` = {$user_id}
		", array(
			sanitize_text_field( $application_api_key ),
			sanitize_text_field( $user_api_key ),
			sanitize_text_field( $publisher_id )
			)
		) );

	ph_refresh_publisher_campaigns( $application_api_key, $user_api_key, $publisher_id, $user_id );
}

/**
 * Verify that the current user can manage posts in one way
 * @return boolean If they can use the tools of this plugin
 */
function is_ph_publisher_access_verified()
{
	if( current_user_can('author') || current_user_can('editor') || current_user_can('administrator') )
	{
		return TRUE;
	}

	return FALSE;
}

/**
 * Get the details from the PH Api
 * @param  String $application_api_key The PH application_key
 * @param  String $user_api_key        The PH user_key
 * @param  String $publisher_id        The PH publisher_id
 * @param  Integer $user_id             The WP user to look up
 */
function ph_refresh_publisher_campaigns( $application_api_key = NULL, $user_api_key = NULL, $publisher_id = NULL, $user_id = NULL )
{
	global $wpdb;

	$str_table = $wpdb->prefix . get_option( '_ph_participation_table' );

	if (is_null( $user_id ))
	{
		$user_id = get_current_user_id();
	}

	if (is_null( $application_api_key ) )
	{
		$application_api_key = ph_get_auth_value( 'application_api_key', $user_id );
	}

	if (is_null( $user_api_key ) )
	{
		$user_api_key = ph_get_auth_value( 'user_api_key', $user_id );
	}

	if (is_null( $publisher_id ) )
	{
		$publisher_id = ph_get_auth_value( 'publisher_id', $user_id );
	}

	$url = "https://{$application_api_key}:{$user_api_key}@api.performancehorizon.com/user/publisher/{$publisher_id}/campaign/a/tracking";

	$obj_curl = Requests::request( $url );

	if ($obj_curl->status_code >= 400)
	{
		echo '<div class="notice notice-error is-dismissible"><p>You have not provided valid PH API credentials. Please try again.</p></div>';
		return FALSE;
	}

	if (isset( $_POST['submit'] ))
	{
		echo '<div class="notice notice-success is-dismissible"><p>Your credentials appear valid and have been saved.</p></div>';
	}

	$obj_result = json_decode( $obj_curl->body );

	$auth_id = ph_get_auth_value( 'ID', get_current_user_id() );

	$wpdb->query( "
			DELETE FROM `{$str_table}`
			WHERE `auth_id` = {$auth_id}
			"
	);

	if (count( $obj_result->campaigns ) === 0)
	{
		echo '<div class="notice notice-error is-dismissible"><p>You have no approved Performance Horizon campaigns.</p></div>';
		return FALSE;
	}

	foreach($obj_result->campaigns as $campaign)
	{
		$wpdb->query( $wpdb->prepare( "
			INSERT INTO `{$str_table}` (
				`auth_id`,
				`campaign_id`,
				`campaign_title`,
				`camref`,
				`is_cpc`,
				`tracking_link`
				)
			VALUES (
				{$auth_id},
				%s,
				%s,
				%s,
				%s,
				%s
				)
			", array(
				$campaign->campaign->campaign_id,
				$campaign->campaign->title,
				$campaign->campaign->camref,
				$campaign->campaign->is_cpc,
				$campaign->campaign->tracking_link
			)
		) );
	}

	return TRUE;
}

/**
 * Get the API credentials
 * @param  String $str_value The value to look up
 * @param  Integer $user_id   The WP user to look up
 * @return String            The desired piece of auth
 */
function ph_get_auth_value( $str_value, $user_id )
{
	global $wpdb;

	$str_table = $wpdb->prefix . get_option( '_ph_auth_table' );

	$sql = "
		SELECT `{$str_value}`
		FROM `{$str_table}`
		WHERE `wp_user_id` = {$user_id}
		";
	return $wpdb->get_var( $sql );
}

/**
 * Use the pretty Wordpress table list class
 */
function ph_show_participation_details()
{
	$campaigns_obj = new PH_Tracking_List();

	$campaigns_obj->prepare_items();
	$campaigns_obj->display();
}

function ph_publisher_register_buttons_editor( $buttons )
{
	array_push($buttons, "ph_publisher_links");
	return $buttons;
}

/**
 * Add meta box in the add new post page to pass the campaigns & so the user can see them.
 */
function ph_publisher_register_meta_boxes()
{
	add_meta_box( 'meta-box-id', __( 'Campaigns', 'textdomain' ), 'wppost_ph_publisher_campaigns_callback', 'post' );
}

/**
 * Will hold the details for TinyMCE to pick up
 * @param  Object $post The WP Post object
 */
function wppost_ph_publisher_campaigns_callback( $post )
{
	global $wpdb;

	$user_id = get_current_user_id();

	$auth_id = ph_get_auth_value( 'ID', $user_id );

	$str_table = $wpdb->prefix . get_option( '_ph_participation_table' );
	$results = $wpdb->get_results( "
		SELECT *
		FROM `{$str_table}`
		WHERE `auth_id` = {$auth_id}"
	);
	$results_json = json_encode($results);

	echo '<div id="json" style="display:none;">'.$results_json.'</div>';

	ph_show_participation_details();
}


function ph_publisher_enqueue_plugin_scripts( $plugin_array )
{
	$plugin_array["ph_publisher_links"] =  plugin_dir_url(__FILE__) . "assets/ph_publisher.js?v=" . PH_PUBLISHER_VERSION;
	return $plugin_array;
}

function has_ph_publisher_links( $user_id )
{
	global $wpdb;

	$user_id = get_current_user_id();
	$auth_id = ph_get_auth_value( 'ID', $user_id );
	if (is_null( $auth_id ))
	{
		return FALSE;
	}
	$str_table = $wpdb->prefix . get_option( '_ph_participation_table' );
	$results = $wpdb->get_results( "
		SELECT *
		FROM `{$str_table}`
		WHERE `auth_id` = {$auth_id}"
	);

	if (count( $results ) === 0)
	{
		return FALSE;
	}

	return TRUE;
}

?>