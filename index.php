<?php
/*
  Plugin Name: Event Espresso - Eventbrite Integration
  Plugin URI: http://eventespresso.com/
  Description: Eventbrite integration for Event Espresso <a href="admin.php?page=support" >Support</a>

  Version: 1.0-BETA

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
}

//register basic activation / deactivation hooks for the eventbrite Integration
register_activation_hook(__FILE__,"espresso_eventbrite_install");
register_deactivation_hook(__FILE__,"espresso_eventbrite_deactivate");


global $ee_eb_options;
$ee_eb_options = get_option('espresso_eventbrite_settings');

//Save the event to Eventbrite
function espresso_save_eventbrite_event($event_data){
	//printr( $event_data, '$event_data  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );

	if ( isset( $event_data['post_to_eventbrite'] ) && $event_data['post_to_eventbrite'] ) {
		
		global $wpdb, $org_options, $ee_eb_options;
		$eb_updated = FALSE;
		$event_id = $event_data['event_id'];
		$notifications['success'] = array(); 
		$notifications['error']	 = array(); 
		
		//Load the class files
		if(!class_exists('EE_Eventbrite')) { 
			require_once("Eventbrite.php"); 
		}
		
		// Initialize the API client
		$authentication_tokens = array('app_key'  => $ee_eb_options['app_key'], 'user_key' => $ee_eb_options['user_key']);
		$eb_client = new EE_Eventbrite( $authentication_tokens );
		
		// create unix timestamp from reg start
		$start_time = isset( $event_data['start_time'][0] ) ? $event_data['start_time'][0] : '09:00';
		$start_date = strtotime( $event_data['start_date'] . ' ' . $start_time );
		// if $start_date is in past, then set to now
		$start_date = $start_date < time() ? time() : $start_date;
		// then make sure end date is after start date
		$end_time = isset( $event_data['end_time'][0] ) ? $event_data['end_time'][0] : '17:00';
		$end_date = strtotime( $event_data['end_date'] . ' ' . $end_time );
		$end_date = $end_date < $start_date ? $start_date : $end_date;
		
		//see http://developer.eventbrite.com/doc/events/event_new/ for a
		// description of the available event_new parameters:
		$event_new_params = array(
			'title' => $event_data['event'],
			'start_date' => date( 'Y-m-d H:i:s', $start_date ), // "YYYY-MM-DD HH:MM:SS"
			'end_date' => date( 'Y-m-d H:i:s', $end_date ), // "YYYY-MM-DD HH:MM:SS"
			'privacy' => 1,  // zero for private (not available in search), 1 for public (available in search)
			'description' => $event_data['event_desc'],
			'capacity' => $event_data['reg_limit'],
			'status' => 'live',
			'timezone' => get_option('timezone_string')
		);
		//printr( $event_new_params, '$event_new_params  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );
		
		//Save the event within EB
		//$event_response = (object)array();
		
		try {
			// create event
			$event_response = $eb_client->event_new($event_new_params);
			// ERMAHGERD... FAIL

			if ($event_response->process->status == 'OK') {
				$eb_updated = TRUE;
				$ticket_ids	= array();
				//$i = 0;
				//For each Event Espresso price type, create a ticket in EB
				foreach ($event_data['event_cost'] as $k => $event_cost) {
					if ( ! empty( $event_cost )) {
						$event_cost = (float)preg_replace('/[^0-9\.]/ui','',$event_cost);//Removes non-integer characters
						$price_type = !empty($event_data['price_type'][$k]) ? sanitize_text_field(stripslashes_deep($event_data['price_type'][$k])) : __('General Admission', 'event_espresso');
						
						// create unix timestamp from reg start
						$start_date = strtotime( $event_data['registration_start'] . ' ' . $event_data['registration_startT'] );
						// if $start_date is in past, then set to now
						$start_date = $start_date < time() ? time() : $start_date;
						// then make sure end date is after start date
						$end_date = strtotime($event_data['registration_end'] . ' ' . $event_data['registration_endT']);
						$end_date = $end_date < $start_date ? $start_date : $end_date;
				
						$ticket_new_params = array(
							'event_id' => $event_response->process->id,
							'start_date' => date('Y-m-d H:i:s', $start_date ), // "YYYY-MM-DD HH:MM:SS"
							'end_date' => date('Y-m-d H:i:s', $end_date ), // "YYYY-MM-DD HH:MM:SS"
							'is_donation ' => 0,
							'name' => $price_type,
							'price' => $event_cost,
							'quantity_available' => $event_data['reg_limit'],
							'min' => '1',
							'max' => $event_data['additional_limit'],
						);
						
						try {
							//Save the ticket details in the event
							$ticket_response = $eb_client->ticket_new($ticket_new_params);
							if ($ticket_response->process->status != 'OK') {
								$eb_updated = FALSE;
								$notifications['error'][] = __('An error occured. The ticket was not added to the event in Eventbrite.', 'event_espresso');
							}else{
								$eb_updated = TRUE;
							}
							// create a ticket id using price_type plus array key
							$ticket_id = sanitize_key( $price_type ) . '-' . $k;
							//Create an array of the new ticket ids
							$ticket_ids[ $ticket_id  ] = $ticket_response->process->id;
						} catch ( Exception $e ) {
						    $notifications['error'][] = __('An error occured at Eventbrite while attempting to create a ticket price: ', 'event_espresso') . $e->getMessage();
						}		
					}
				}
				
				//Update to use Eventbrite setting
				$event_data['use_eventbrite_reg'] = isset($event_data['use_eventbrite_reg']) ? $event_data['use_eventbrite_reg'] : 0;
				
				//Merge and save the arrays
				$eb_event_data = array( 'eventbrite_id' => $event_response->process->id, 'eb_ticket_ids' => $ticket_ids, 'use_eventbrite_reg' => $event_data['use_eventbrite_reg'], 'post_to_eventbrite' => $event_data['post_to_eventbrite'] );
				do_action('action_hook_espresso_update_event_meta', $event_id, $eb_event_data);

				if ($eb_updated == TRUE){ 
					$notifications['success'][] = sprintf(__('Event was successfully added to Eventbrite. [%sview%s] [%sedit%s]', 'event_espresso'),'<a href="http://www.eventbrite.com/event/'.$event_response->process->id.'" target="_blank">', '</a>','<a href="http://www.eventbrite.com/edit?eid='.$event_response->process->id.'" target="_blank">', '</a>');
				}else{
					$notifications['error'][] = __('An error occured. The event was not updated in Eventbrite.', 'event_espresso');
				}
			}
		
		} catch ( Exception $e ) {
		    $notifications['error'][] = __('An error occured at Eventbrite while attempting to create the event: ', 'event_espresso') . $e->getMessage();
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
//	printr( $eb_event_data, '$eb_event_data  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );
//	die();
}

add_action('action_hook_espresso_insert_event_success', 'espresso_save_eventbrite_event', 100, 1);

//Update an event in Eventbrite
function espresso_update_eventbrite_event($event_data){ 
	
	//printr( $event_data, '$event_data  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );
	// if posting to EB, but there's no EB event ID, then run EB insert
	if ( isset( $event_data['post_to_eventbrite'] ) && $event_data['post_to_eventbrite'] == 1 && ( ! isset( $event_data['eventbrite_id'] ) || empty( $event_data['eventbrite_id'] ) || ! $event_data['eventbrite_id'] > 0 )) {
		do_action('action_hook_espresso_insert_event_success',$event_data);
		return;
	}
	
	global $wpdb, $org_options, $ee_eb_options;
	$success = TRUE;
	$ticket_ids = array();
	$notifications['success'] = array(); 
	$notifications['error'] = array(); 
	
	//Load the class files
	if( ! class_exists( 'EE_Eventbrite' )) { 
		require_once( 'Eventbrite.php' ); 
	}
	
	// Initialize the API client
	$authentication_tokens = array('app_key'  => $ee_eb_options['app_key'], 'user_key' => $ee_eb_options['user_key']);
	$eb_client = new EE_Eventbrite( $authentication_tokens );
	
	//Get the event meta
	$sql = "SELECT e.event_meta";
	$sql .= " FROM " . EVENTS_DETAIL_TABLE . " e ";
	$sql.= " WHERE e.id = %d";
	$event_meta = $wpdb->get_var( $wpdb->prepare( $sql, $event_data['event_id'] ));
	// derd we gets teh meta ??
	if ( $event_meta === FALSE ) {
		$success = FALSE;
		$notifications['error'][] = __('An error occured. The Event meta details could not be retrieved.', 'event_espresso');
	} else {
		//Unserilaize the meta
		$event_meta = unserialize($event_meta);
		//printr( $event_meta, '$event_meta  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );
		//Save any changes to the Eventbrite status for the event
		$event_data['use_eventbrite_reg'] = isset($event_data['use_eventbrite_reg']) ? $event_data['use_eventbrite_reg'] : 0;				

		// create unix timestamp from reg start
		$start_time = isset( $event_data['start_time'][0] ) ? $event_data['start_time'][0] : '09:00';
		$start_date = strtotime( $event_data['start_date'] . ' ' . $start_time );
		// if $start_date is in past, then set to now
		$start_date = $start_date < time() ? time() : $start_date;
		// then make sure end date is after start date
		$end_time = isset( $event_data['end_time'][0] ) ? $event_data['end_time'][0] : '17:00';
		$end_date = strtotime( $event_data['end_date'] . ' ' . $end_time );
		$end_date = $end_date < $start_date ? $start_date : $end_date;

		//see http://developer.eventbrite.com/doc/events/event_update/ for a description of available parameters:
		$event_update_params = array(
			'id' => $event_data['eventbrite_id'],
			'title' => $event_data['event'],
			'description' => $event_data['event_desc'],
			'start_date' => date( 'Y-m-d H:i:s', $start_date ), // "YYYY-MM-DD HH:MM:SS"
			'end_date' => date( 'Y-m-d H:i:s', $end_date ), // "YYYY-MM-DD HH:MM:SS"
			//'timezone' => 'MT',  
			'privacy' => 1,  // zero for private (not available in search), 1 for public (available in search)
			//'url' => '',
			'capacity' => $event_data['reg_limit'],
			'status' => 'live',
		);
		//printr( $event_update_params, '$event_update_params  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );

		try {
			//Update the event within EB
			$event_response = $eb_client->event_update( $event_update_params );			
			//Update the existing tickets
			if ( $event_response->process->status == 'OK' ) {
				//For each Event Espresso price type, update the ticket in EB
				foreach ( $event_data['event_cost'] as $k => $event_cost ) {						
					if ( ! empty( $event_cost )) {

						//Removes non-integer characters
						$event_cost = (float)preg_replace( '/[^0-9\.]/ui','', $event_cost );
						// clean ticket name
						$price_type = ! empty( $event_data['price_type'][$k] ) ? sanitize_text_field( stripslashes_deep( $event_data['price_type'][$k] )) : __('General Admission', 'event_espresso');
						// create a ticket id using price_type plus array key
						$ticket_id = sanitize_key( $price_type ) . '-' . $k;

						// shared params for both new and existing tickets
						$ticket_params = array(
							'is_donation ' => 0,
							'name' => $price_type,
							//'description' => '',
							'price' => $event_cost,
							'quantity_available' => $event_data['reg_limit'],
							'start_date' 	=> date('Y-m-d H:i:s', strtotime($event_data['registration_start'] . ' ' . $event_data['registration_startT'])), // "YYYY-MM-DD HH:MM:SS"
							'end_date' 	=> date('Y-m-d H:i:s', strtotime($event_data['registration_end'] . ' ' . $event_data['registration_endT'])), // "YYYY-MM-DD HH:MM:SS"
							//'include_fee' => 0,
							'min' => '1',
							'max' => $event_data['additional_limit'],
						);

						// is this an existing EB ticket ?
						if ( isset( $event_meta['eb_ticket_ids'][ $ticket_id ] )){
							// add these extra params
							// see: http://developer.eventbrite.com/doc/tickets/ticket_update/			
							$ticket_params['id'] = $event_meta['eb_ticket_ids'][ $ticket_id ];
							$ticket_params['hide'] = 'n';
							//printr( $ticket_params, '$ticket_params  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );
							try {
								// update the ticket 
								$ticket_response = $eb_client->ticket_update( $ticket_params );
								// ERMAHGERD... FAIL
								if ( $ticket_response->process->status == 'OK' ) {
									// track ticket id
									$ticket_ids[ $ticket_id ] = $event_meta['eb_ticket_ids'][ $ticket_id ];						
								} else {
									$success = FALSE;
									$notifications['error'][] = __('An error occured. The ticket was not saved in Eventbrite. You may need to log in and perform this action manually.', 'event_espresso');
								}
							} catch ( Exception $e ) {
							    $notifications['error'][] = __('An error occured at Eventbrite while attempting to update an existing ticket: ', 'event_espresso') . $e->getMessage();
							}
						} else {
							// new ticket
							// see:  http://developer.eventbrite.com/doc/tickets/ticket_new/
							$ticket_params['event_id'] = $event_data['eventbrite_id'];
							//printr( $ticket_params, '$ticket_params  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );
							try {
								// add the new ticket
								$ticket_response = $eb_client->ticket_new( $ticket_params );
								// ERMAHGERD... FAIL
								if ( $ticket_response->process->status == 'OK' ) {
									// track new ticket id
									$ticket_ids[ $ticket_id ] = $ticket_response->process->id;
								} else {
									$success = FALSE;
									$notifications['error'][] = __('An error occured. The ticket could not be created in Eventbrite. You may need to log in and perform this action manually.', 'event_espresso');
								}
							} catch ( Exception $e ) {
							    $notifications['error'][] = __('An error occured at Eventbrite while attempting to create a new ticket price: ', 'event_espresso') . $e->getMessage();
							}
						}
						//printr( $ticket_ids, '$ticket_ids  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );
						//printr( $ticket_response, '$ticket_response  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );

						
					}
				}
				
				if ( isset( $event_meta['eb_ticket_ids'] ) && is_array( $event_meta['eb_ticket_ids'] )) {
					//printr( $event_meta['eb_ticket_ids'], 'eb_ticket_ids  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );
					// now loop thru our previously saved EB tickets and hide any that were deleted from EE
					foreach ( $event_meta['eb_ticket_ids'] as $tkt_id => $eb_tkt_id ){
						// check for OLD $ticket_id in array of NEW $ticket_ids
						if ( ! isset( $ticket_ids[ $tkt_id ] )) {
							// this OLD EB ticket doesn't exist anymore in EE settings so we will hide it on EB
							$ticket_params = array();
							$ticket_params['id'] = $eb_tkt_id;
							$ticket_params['hide'] = 'y';
							try {
								// update existing ticket with a status of hidden
							    $ticket_response = $eb_client->ticket_update( $ticket_params );
								if ( $ticket_response->process->status == 'OK' ) {
									//Get the event meta
									$sql = "SELECT e.event_meta";
									$sql .= " FROM " . EVENTS_DETAIL_TABLE . " e ";
									$sql.= " WHERE e.id = %d";
									$event_meta = $wpdb->get_var( $wpdb->prepare( $sql, $event_data['event_id'] ));
									//Unserilaize the meta
									$event_meta = unserialize($event_meta);
									//printr( $event_meta, '$EVENT_META AFTER  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );								
									// derd we gets teh meta ??
									if ( $event_meta === FALSE ) {
										$success = FALSE;
										$notifications['error'][] = __('An error occured. The Event meta details could not be retrieved in order to remove a deleted Eventbrite ticket id.', 'event_espresso');
									} else {
										// i know it's silly, we have to check if an array element is set before we unset it  : \
										if ( isset( $event_meta['eb_ticket_ids'] ) && isset( $event_meta['eb_ticket_ids'][ $tkt_id ] )) {
											unset( $event_meta['eb_ticket_ids'][ $tkt_id ] );
											// now update the event meta
											$wpdb->update( 
												EVENTS_DETAIL_TABLE, 
												array( 'event_meta' => serialize( $event_meta )), 
												array( 'id' => $event_data['event_id'] ), 
												array('%s'), 
												array('%d')
											);		
										}						
									}
								} else {
									$success = FALSE;
									$notifications['error'][] = __('An error occured. An old ticket could not be removed from Eventbrite. You may need to log in and perform this action manually.', 'event_espresso');
								}
							} catch ( Exception $e ) {
							    $notifications['error'][] = __('An error occured at Eventbrite while attempting to hide a ticket price that was removed in Event Espresso: ', 'event_espresso') . $e->getMessage();
							}
						}
					}				
				}
				
				//now update our event meta
				if ( ! empty( $ticket_ids ) && is_array( $ticket_ids )){
					$new_event_meta = array(
						'post_to_eventbrite' => $event_data['post_to_eventbrite'],
						'eventbrite_id' => $event_data['eventbrite_id'], 
						'eb_ticket_ids'=>$ticket_ids,
						'use_eventbrite_reg' => $event_data['use_eventbrite_reg']
					);
					//printr( $new_event_meta, '$new_event_meta  <br /><span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span>', 'auto' );
					do_action( 'action_hook_espresso_update_event_meta', $event_data['event_id'], $new_event_meta );
				}				
			}		

		} catch ( Exception $e ) {
		    $notifications['error'][] = __('An error occured at Eventbrite while attempting to update the event: ', 'event_espresso') . $e->getMessage();
		}

	}
		
	if ( $success == TRUE ){ 
		$notifications['success'][] = sprintf(
			__('Event was successfully updated in Eventbrite. [%sview%s] [%sedit%s]', 'event_espresso'),
			'<a href="http://www.eventbrite.com/event/'.$event_response->process->id.'" target="_blank">', 
			'</a>',
			'<a href="http://www.eventbrite.com/edit?eid='.$event_response->process->id.'" target="_blank">', 
			'</a>'
		);
	} else {
		$notifications['error'][] = __('One or more errors occured that prevented the event from being fully updated in Eventbrite. You should log in to Eventbrite to ensure this event\'s settings.', 'event_espresso');
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
add_action('action_hook_espresso_update_event_success', 'espresso_update_eventbrite_event', 100, 1);





function espresso_delete_eventbrite_event($event_id){
	
		global $wpdb, $org_options, $ee_eb_options;
		$data = (object)array();
		
		//Get the event meta
		$sql = "SELECT e.event_meta";
		$sql .= " FROM " . EVENTS_DETAIL_TABLE . " e ";
		$sql.= " WHERE e.id = %d";
		$event_meta = $wpdb->get_var( $wpdb->prepare( $sql, $event_id ) );
		
		//Unserilaize the meta
		$event_meta = unserialize($event_meta);
		
		//If this event has an EB event id, then we delete it
		if ( isset($event_meta['eventbrite_id']) && $event_meta['eventbrite_id'] > 0) {
			
			$notifications['success'] = array(); 
			$notifications['error']	 = array(); 
			
			if(!class_exists('EE_Eventbrite')) { 
				require_once("Eventbrite.php"); 
			}
			
			// Initialize the API client
			$authentication_tokens = array('app_key'  => $ee_eb_options['app_key'],
										   'user_key' => $ee_eb_options['user_key']);
			$eb_client = new EE_Eventbrite( $authentication_tokens );
	
	
			$event_update_params = array(
				'id' => $event_meta['eventbrite_id'],	
				'status' => 'deleted'
			);
			
			//Delete the event in EB
			$event_response = $eb_client->event_update($event_update_params);

			//Update the EB event id to 0 using array merge
			$event_meta = array_merge($event_meta, array('eventbrite_id' => '0'));
			
			//Update the event meta and change the EB event id
			$sql = array( 'event_meta' => serialize($event_meta) );
			$event_id = array('id' => $event_id);
			$sql_data = array('%s');
			
			//Run the update query
			if ($wpdb->update(EVENTS_DETAIL_TABLE, $sql, $event_id, $sql_data, array('%d'))) {
				
				if ($event_response->process->status == 'OK') {
					$notifications['success'][] = __('Event was successfully deleted from Eventbrite.', 'event_espresso');
				}else{
					$notifications['error'][] = __('An error occured. The event was not deleted from Eventbrite.', 'event_espresso');
				}
				
			}
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

add_action('action_hook_espresso_delete_event_success', 'espresso_delete_eventbrite_event', 100, 1);


// Begin admin settings screen
function espresso_eventbrite_settings() {
	
	if (!empty($_POST['update_eventbrite_settings']) && $_POST['update_eventbrite_settings'] == 'update') {
		
		$eventbrite_options = get_option('espresso_eventbrite_settings');
		$eventbrite_options['user_key'] = isset($_POST['user_key']) && !empty($_POST['user_key']) ? $_POST['user_key'] : '';
		$eventbrite_options['app_key'] = isset($_POST['app_key']) && !empty($_POST['app_key']) ? $_POST['app_key'] : '';
		
		update_option('espresso_eventbrite_settings', $eventbrite_options);
		echo '<div id="message" class="updated fade"><p><strong>' . __('Eventbrite settings saved.', 'event_espresso') . '</strong></p></div>';
	}
	$eventbrite_options = get_option('espresso_eventbrite_settings');
	$user_key = empty($eventbrite_options['user_key']) ? '' : $eventbrite_options['user_key'];
	$app_key = empty($eventbrite_options['app_key']) ? '' : $eventbrite_options['app_key'];
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

//Create an advanced options section in the event editor
function espresso_eventbrite_event_editor_options($event_meta = ''){
	$values = array(
		array('id' => false,'text'=> __('No','event_espresso')),
		array('id' => true, 'text' => __('Yes', 'event_espresso'))
	);
	
	$advanced_options = '<p><strong>'.__('Eventbrite Options:', 'event_espresso').'</strong></p>';
	
		$advanced_options .= '
			<p class="inputunder">
				<label>' . __('Post to Eventbrite?', 'event_espresso') . '</label>
				' . select_input('post_to_eventbrite', $values, isset($event_meta['post_to_eventbrite']) ? $event_meta['post_to_eventbrite'] : FALSE, 'id="post_to_eventbrite"') . '
			</p>';
			$advanced_options .= '
			<p id="p_use_eventbrite_reg" class="inputunder">
				<label>' . __('Use Eventbrite Registration?', 'event_espresso') . '</label>
				' . select_input('use_eventbrite_reg', $values, isset($event_meta['use_eventbrite_reg']) ? $event_meta['use_eventbrite_reg'] : FALSE, 'id="use_eventbrite_reg"') . '
			</p>';
			
	// are we posting to eventbrite and  have an eventbrite_id ???
	if ( isset( $event_meta['post_to_eventbrite'] ) && $event_meta['post_to_eventbrite'] == 1 && isset( $event_meta['eventbrite_id'] ) && ! empty( $event_meta['eventbrite_id'] )) {
		$advanced_options .= '
			<p>
				'.sprintf(
					__('Eventbrite ID: %s | %s[ view ]%s %s[ edit ]%s', 'event_espresso'),
					$event_meta['eventbrite_id'],
					'<a href="http://www.eventbrite.com/event/'.$event_meta['eventbrite_id'].'" target="_blank">', 
					'</a>',
					'<a href="http://www.eventbrite.com/edit?eid='.$event_meta['eventbrite_id'].'" target="_blank">', 
					'</a>'
				).'
			</p>
			<input  type="hidden" name="eventbrite_id" value="'.$event_meta['eventbrite_id'].'"/>';
	} 
	
	ob_start();
	?>
	
	<script type="text/javascript">
		//<![CDATA[
		jQuery(document).ready(function($){
			window.ebp = $('select#post_to_eventbrite option:selected').val();
			window.ebr = $('select#use_eventbrite_reg option:selected').val();
			
			if(window.ebp == ''){
				$('input#post_to_eventbrite').attr('disabled', true);
				$('#p_use_eventbrite_reg').attr('style', "opacity: .3");
			}
			$('select#post_to_eventbrite').change(function(){
				window.ebp = $('select#post_to_eventbrite option:selected').val();
				if(window.ebp == ''){
					$('input#use_eventbrite_reg').attr('disabled', true);
					$('p#p_use_eventbrite_reg').attr('style', "opacity: .3");
				}else {
					$('input#use_eventbrite_reg').removeAttr('disabled', true);
					$('p#p_use_eventbrite_reg').removeAttr('style');
				}
			});
			
			if(window.ebr){
				$('select#display_reg_form').attr('disabled', true);
				$('p#p_display_reg_form').attr('style', "opacity: .3");
				$('#espresso_event_editor').append('<input id="display_reg_form_hidden" type="hidden" name="display_reg_form" value="N" />');
			}else{
				$('p#p_display_reg_form').removeAttr('style');
				$('select#display_reg_form').removeAttr('disabled', true);
				$('#display_reg_form_hidden').remove();
			}
			$('select#use_eventbrite_reg').change(function(){
				window.ebr = $('select#use_eventbrite_reg option:selected').val();
				if(window.ebr == ''){
					$('p#p_display_reg_form').removeAttr('style');
					$('select#display_reg_form').removeAttr('disabled', true);
					$('#display_reg_form_hidden').remove();
				}else{
					$('select#display_reg_form').attr('disabled', true);
					$('p#p_display_reg_form').attr('style', "opacity: .3");
					$('#espresso_event_editor').append('<input id="display_reg_form_hidden" type="hidden" name="display_reg_form" value="N" />');

				}
			});
		});
		//]]>
	</script>
	<?php
	$content = ob_get_clean();
	return $advanced_options.$content;
}
add_filter( 'filter_hook_espresso_event_editor_advanced_options', 'espresso_eventbrite_event_editor_options', 10, 1 );


//Override the default event meta
if (!function_exists('ee_default_event_meta')){
	function ee_default_event_meta(){
		//return array('infusionsoft_tag_id'=>'','infusionsoft_campaign_id'=>'');
	}
}

function espresso_eventbrite_update_event_update_meta($event_meta, $event_id){
	global $wpdb;
	//Get the event meta
		$sql = "SELECT e.event_meta";
		$sql .= " FROM " . EVENTS_DETAIL_TABLE . " e ";
		$sql.= " WHERE e.id = %d";
		$event_meta = $wpdb->get_var( $wpdb->prepare( $sql, $event_id ) );
	
		//Unserilaize the meta
		$event_meta = unserialize($event_meta);
		
		$eb_ticket_ids = array();
		
		if (isset($event_meta['eb_ticket_ids'])){
			$eb_ticket_ids = array('eb_ticket_ids' => $event_meta['eb_ticket_ids']);
		}
		
		$event_meta = array_replace($event_meta, $event_meta, $eb_ticket_ids);
		
		return $event_meta;
}

add_filter( 'filter_hook_espresso_update_event_update_meta', 'espresso_eventbrite_update_event_update_meta', 10, 2 );

//Adds a way to filter the custom meta fields
function espresso_eventbrite_hidden_meta($hiddenmeta = ''){
	$new_hiddenmeta = array("eb_ticket_ids", "use_eventbrite_reg", "post_to_eventbrite");
	$hiddenmeta = array_merge($hiddenmeta, $new_hiddenmeta);
	return $hiddenmeta;
}
add_filter( 'filter_hook_espresso_hidden_meta', 'espresso_eventbrite_hidden_meta', 10, 1 );


//Creates an Eventbrite widget to display a list of available tickets
function espresso_eventbrite_display_event_tickets($event_id, $event_meta, $all_meta){
	global $ee_eb_options;
	
	$height = 200;
	
	if ( empty( $event_meta ) ){
		$event_meta = event_espresso_get_event_meta($event_id);
	}
	//Debug
	//echo '<h4>$event_meta : <pre>' . print_r($event_meta,true) . '</pre> <span style="font-size:10px;font-weight:normal;">' . __FILE__ . '<br />line no: ' . __LINE__ . '</span></h4>';
	
	if ($event_meta['use_eventbrite_reg'] == 1){
		$num_prices = count($event_meta['eb_ticket_ids']);
		
		if ($num_prices > 1){
			$height = $num_prices * 30 + $height;
		}
		
		//Load the class files
		if(!class_exists('EE_Eventbrite')) { 
			require_once("Eventbrite.php"); 
		}
		// Initialize the API client
		$authentication_tokens = array('app_key'  => $ee_eb_options['app_key'],
									   'user_key' => $ee_eb_options['user_key']);
		$eb_client = new EE_Eventbrite( $authentication_tokens );
		
		
		$resp = $eb_client->event_get( array('id' => $event_meta['eventbrite_id']) );
		echo '<p class="event_time"><span class="span_event_time_label">' . __('Start Time:', 'event_espresso') . '</span><span class="span_event_time_value">' . event_date_display($all_meta['start_time'], get_option('time_format')) . '</span><br/><span class="span_event_time_label">' . __('End Time: ', 'event_espresso') . '</span><span class="span_event_time_value">' . event_date_display($all_meta['end_time'], get_option('time_format')) . '</span></p>';
		print( EE_Eventbrite::ticketWidget($resp->event,$height.'px') );
	}
}
add_action('action_hook_espresso_registration_page_bottom','espresso_eventbrite_display_event_tickets', 100, 3);

//Creates an Eventbrite calendar widget
function espresso_eventbrite_display_eventbrite_calendar($atts = ''){
	global $ee_eb_options, $this_event_id;
	
	if ( empty($atts) && !empty($this_event_id) ){
		$event_meta = event_espresso_get_event_meta($this_event_id);
		$atts['id'] = $event_meta['eventbrite_id'];
	}

	//Load the class files
	if(!class_exists('EE_Eventbrite')) { 
		require_once("Eventbrite.php"); 
	}
	// Initialize the API client
	$authentication_tokens = array('app_key'  => $ee_eb_options['app_key'],
									   'user_key' => $ee_eb_options['user_key']);
	$eb_client = new EE_Eventbrite( $authentication_tokens );
		
	$resp = $eb_client->event_get( array('id' => $atts['id']) );
	print( EE_Eventbrite::calendarWidget($resp->event) );
}
add_shortcode('eeeb_calendar','espresso_eventbrite_display_eventbrite_calendar');

//Creates an Eventbrite countdown widget
function espresso_eventbrite_display_eventbrite_countdown($atts = ''){
	global $ee_eb_options, $this_event_id;
	
	if ( empty($atts) && !empty($this_event_id) ){
		$event_meta = event_espresso_get_event_meta($this_event_id);
		$atts['id'] = $event_meta['eventbrite_id'];
	}

	//Load the class files
	if(!class_exists('EE_Eventbrite')) { 
		require_once("Eventbrite.php"); 
	}
	// Initialize the API client
	$authentication_tokens = array('app_key'  => $ee_eb_options['app_key'],
									   'user_key' => $ee_eb_options['user_key']);
	$eb_client = new EE_Eventbrite( $authentication_tokens );
		
	$resp = $eb_client->event_get( array('id' => $atts['id']) );
	print( EE_Eventbrite::countdownWidget($resp->event) );
}
add_shortcode('eeeb_countdown','espresso_eventbrite_display_eventbrite_countdown');

//Creates an Eventbrite registration page widget
function espresso_eventbrite_display_eventbrite_registration($atts = ''){
	global $ee_eb_options, $this_event_id;
	
	if ( empty($atts) && !empty($this_event_id) ){
		$event_meta = event_espresso_get_event_meta($this_event_id);
		$atts['id'] = $event_meta['eventbrite_id'];
	}

	//Load the class files
	if(!class_exists('EE_Eventbrite')) { 
		require_once("Eventbrite.php"); 
	}
	// Initialize the API client
	$authentication_tokens = array('app_key'  => $ee_eb_options['app_key'],
									   'user_key' => $ee_eb_options['user_key']);
	$eb_client = new EE_Eventbrite( $authentication_tokens );
		
	$resp = $eb_client->event_get( array('id' => $atts['id']) );
	print( EE_Eventbrite::registrationWidget($resp->event) );
}
add_shortcode('eeeb_registration','espresso_eventbrite_display_eventbrite_registration');

//Creates an Eventbrite ticket widget
function espresso_eventbrite_display_eventbrite_tickets($atts = ''){
	global $ee_eb_options, $this_event_id;
	$height = isset($atts['height']) ? $atts['height'] : '650px';
	if ( empty($atts) && !empty($this_event_id) ){
		$event_meta = event_espresso_get_event_meta($this_event_id);
		$atts['id'] = $event_meta['eventbrite_id'];
	}

	//Load the class files
	if(!class_exists('EE_Eventbrite')) { 
		require_once("Eventbrite.php"); 
	}
	// Initialize the API client
	$authentication_tokens = array('app_key'  => $ee_eb_options['app_key'],
									   'user_key' => $ee_eb_options['user_key']);
	$eb_client = new EE_Eventbrite( $authentication_tokens );
		
	$resp = $eb_client->event_get( array('id' => $atts['id']) );
	print( EE_Eventbrite::ticketWidget($resp->event,$height) );
}
add_shortcode('eeeb_tickets','espresso_eventbrite_display_eventbrite_tickets');