<?php
/*
  Plugin Name: Event Espresso - Eventbrite Integration
  Plugin URI: http://eventespresso.com/
  Description: Eventbrite integration for Event Espresso <a href="admin.php?page=support" >Support</a>

  Version: 1.0-DEV

  Author: Event Espresso
  Author URI: http://www.eventespresso.com

  Copyright (c) 2009-2013 Event Espresso  All Rights Reserved.

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */
 
//Define the version of the plugin
function espresso_eventbrite_version() {
	do_action('action_hook_espresso_log', __FILE__, __FUNCTION__, '');
	return '1.0-BETA';
}
 
//Update notifications
add_action('action_hook_espresso_eventbrite_update_api', 'ee_eventbrite_load_pue_update');
function ee_eventbrite_load_pue_update() {
	global $org_options, $espresso_check_for_updates;
	if ( $espresso_check_for_updates == false )
		return;
		
	if (file_exists(EVENT_ESPRESSO_PLUGINFULLPATH . 'class/pue/pue-client.php')) { //include the file 
		require(EVENT_ESPRESSO_PLUGINFULLPATH . 'class/pue/pue-client.php' );
		$api_key = $org_options['site_license_key'];
		$host_server_url = 'http://eventespresso.com';
		$plugin_slug = 'espresso-eventbrite-pr';
		$options = array(
			'apikey' => $api_key,
			'lang_domain' => 'event_espresso',
			'checkPeriod' => '24',
			'option_key' => 'site_license_key'
		);
		$check_for_updates = new PluginUpdateEngineChecker($host_server_url, $plugin_slug, $options); //initiate the class and start the plugin update engine!
	}
}

//Install
function espresso_eventbrite_install(){
	
	//run install routines, setup basic Integration variables within the options environment.
	add_option("espresso_eventbrite_active","true","","yes");
	update_option("espresso_eventbrite_active","true");
	add_option("espresso_eventbrite_settings","","","yes");

}

function espresso_eventbrite_deactivate(){
	update_option("espresso_eventbrite_active","false"); //set the activation flag to false
	update_option('espresso_eventbrite_settings', ""); //reset the API key to null.
}

//register basic activation / deactivation hooks for the eventbrite Integration
register_activation_hook(__FILE__,"espresso_eventbrite_install");
register_deactivation_hook(__FILE__,"espresso_eventbrite_deactivate");


global $ee_eb_options;
$ee_eb_options = get_option('espresso_eventbrite_settings');

//Save the event to eventbrite
function espresso_save_eventbrite_event($event_data){
	global $wpdb, $ee_eb_options;
	
	$notifications['success'] = array(); 
	$notifications['error']	 = array(); 
	
	if(!class_exists('Eventbrite')) { 
		require_once("Eventbrite.php"); 
	}
	
	// Initialize the API client
	//  Eventbrite API / Application key (REQUIRED)
	//   http://www.eventbrite.com/api/key/
	//  Eventbrite user_key (OPTIONAL, only needed for reading/writing private user data)
	//   http://www.eventbrite.com/userkeyapi
	$authentication_tokens = array('app_key'  => $ee_eb_options['app_key'],
								   'user_key' => $ee_eb_options['user_key']);
	$eb_client = new Eventbrite( $authentication_tokens );
	
	//see http://developer.eventbrite.com/doc/events/event_new/ for a
	// description of the available event_new parameters:
	$event_new_params = array(
		'title' => $event_data['event'],
		'start_date' => date('Y-m-d H:i:s', strtotime($event_data['start_date'] . ' ' . $event_data['event_start_time'])), // "YYYY-MM-DD HH:MM:SS"
		'end_date' => date('Y-m-d H:i:s', strtotime($event_data['end_date'] . ' ' . $event_data['event_end_time'])), // "YYYY-MM-DD HH:MM:SS"
		'privacy' => 1,  // zero for private (not available in search), 1 for public (available in search)
		'timezone' => 'GMT-8',
		'description' => $event_data['event_desc'],
		'description' => '<h4>$event_data : <pre>' . print_r($event_data,true) . '</pre> <span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span></h4>',
		'capacity' => $_REQUEST['reg_limit'],
		'status' => 'live',
		'timezone' => get_option('timezone_string')
	);
	
	//Save the event within EB
	$event_response = $eb_client->event_new($event_new_params);
	
	//Create the tickets
	if ($event_response->process->status == 'OK') {
				
		//For each Event Espresso price type, create a ticket in EB
		foreach ($_REQUEST['event_cost'] as $k => $v) {
			if ($v != '') {
				$v = (float)preg_replace('/[^0-9\.]/ui','',$v);//Removes non-integer characters
				$price_type = !empty($_REQUEST['price_type'][$k]) ? sanitize_text_field(stripslashes_deep($_REQUEST['price_type'][$k])) : __('General Admission', 'event_espresso');
		
				$ticket_new_params = array(
					'event_id' => $event_response->process->id,
					'start_date' => date('Y-m-d H:i:s', strtotime($event_data['registration_start'] . ' ' . $event_data['registration_startT'])), // "YYYY-MM-DD HH:MM:SS"
					'end_date' => date('Y-m-d H:i:s', strtotime($event_data['registration_end'] . ' ' . $event_data['registration_endT'])), // "YYYY-MM-DD HH:MM:SS"
					'is_donation ' => 0,
					'name' => $price_type,
					'price' => $v,
					'quantity_available' => $_REQUEST['reg_limit'],
					'min' => '1',
					'max' => $event_data['additional_limit'],
				);
				
				//Save the ticket details in the event
				$ticket_response = $eb_client->ticket_new($ticket_new_params);
				if ($ticket_response->process->status != 'OK') {
					$notifications['error'][] = __('An error occured. The ticket was not added to the event in Eventbrite.', 'event_espresso');
				}
			}
		}
		$notifications['success'][] = sprintf(__('Event was successfully added to Eventbrite. [%sview%s] [%sedit%s]', 'event_espresso'),'<a href="http://www.eventbrite.com/event/'.$event_response->process->id.'" target="_blank">', '</a>','<a href="http://www.eventbrite.com/edit?eid='.$event_response->process->id.'" target="_blank">', '</a>');
	}else{
		$notifications['error'][] = __('An error occured. The event was not created in Eventbrite.', 'event_espresso');
	}
	
	// display success messages
	if ( ! empty( $notifications['success'] )) { 
		$success_msg = implode( $notifications['success'], '<br />' );
	?>
		<div id="message" class="updated fade">
			<p> <strong><?php echo $success_msg; ?></strong> </p>
		</div>
	<?php
	}
	// display error messages
	if ( ! empty( $notifications['error'] )) {
		$error_msg = implode( $notifications['error'], '<br />' );
	?>
		<div id="message" class="error">
		<p> <strong><?php echo $error_msg; ?></strong> </p>
		</div>
	<?php 
	}
}

add_action('action_hook_espresso_insert_event', 'espresso_save_eventbrite_event', 100, 1);


// Begin admin settings screen ###########################
function espresso_eventbrite_settings() {
	
	if (!empty($_POST['update_eventbrite_settings']) && $_POST['update_eventbrite_settings'] == 'update') {
		
		$eventbrite_options = get_option('espresso_eventbrite_settings');
		$eventbrite_options['user_key'] = isset($_POST['user_key']) && !empty($_POST['user_key']) ? $_POST['user_key'] : '';
		$eventbrite_options['app_key'] = isset($_POST['app_key']) && !empty($_POST['app_key']) ? $_POST['app_key'] : '';
		$eventbrite_options['merchantAccountId'] = isset($_POST['merchantAccountId']) && !empty($_POST['merchantAccountId']) ? $_POST['merchantAccountId'] : '';
		
		update_option('espresso_eventbrite_settings', $eventbrite_options);
		echo '<div id="message" class="updated fade"><p><strong>' . __('Eventbrite settings saved.', 'event_espresso') . '</strong></p></div>';
	}
	$eventbrite_options = get_option('espresso_eventbrite_settings');
	$user_key = empty($eventbrite_options['user_key']) ? '' : $eventbrite_options['user_key'];
	$app_key = empty($eventbrite_options['app_key']) ? '' : $eventbrite_options['app_key'];
	$merchantAccountId = empty($eventbrite_options['merchantAccountId']) ? '' : $eventbrite_options['merchantAccountId'];
	?>

<div id="event_reg_theme" class="wrap">
	<div id="icon-options-event" class="icon32"></div>
	<h2><?php echo _e('Manage Eventbrite Settings', 'event_espresso') ?></h2>
	<?php ob_start(); ?>
	<div class="metabox-holder">
		<div class="postbox">
			<h3>
				<?php _e('Eventbrite Settings', 'event_espresso'); ?>
			</h3>
			<div class="inside">
				<form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
					<ul>
						<li>
							<label>
								<?php _e('API User Key', 'event_espresso'); ?>
							</label>
							<input type="text" name="user_key" size="25" <?php echo (isset($user_key) ? 'value="' . $user_key . '"' : "") ?>>
						</li>
						<li>
							<label>
								<?php _e('Application Key', 'event_espresso'); ?>
							</label>
							<input type="text" name="app_key" size="25" <?php echo (isset($app_key) ? 'value="' . $app_key . '"' : "") ?>>
						</li>
						<li><p><?php _e('Don\'t have an Eventbrite account?', 'event_espresso'); ?> <a href="http://www.eventbrite.com/r/eventespresso" target="_blank"><?php _e('Sign up now!', 'event_espresso'); ?></a></p></li>
						
						<li>
							<input type="hidden" name="update_eventbrite_settings" value="update">
							<p>
								<input class="button-primary" type="submit" name="Submit" value="<?php _e('Save Settings', 'event_espresso'); ?>" id="save_infusionsoft_settings" />
							</p>
						</li>
						
					</ul>
				</form>
			</div>
		</div>
	</div>
	<?php
		$main_post_content = ob_get_clean();
		espresso_choose_layout($main_post_content, event_espresso_display_right_column());
		?>
</div>
<?php
}


//Override the default event meta
if (!function_exists('ee_default_event_meta')){
	function ee_default_event_meta(){
		//return array('infusionsoft_tag_id'=>'','infusionsoft_campaign_id'=>'');
	}
}
