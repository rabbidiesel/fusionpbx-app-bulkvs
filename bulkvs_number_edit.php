<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2025
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!permission_exists('bulkvs_edit')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//get http variables
	$tn = $_GET['tn'] ?? '';
	$lidb = $_POST['lidb'] ?? '';
	$portout_pin = $_POST['portout_pin'] ?? '';
	$notes = $_POST['notes'] ?? '';
	$sms = isset($_POST['sms']) && $_POST['sms'] == '1' ? true : false;
	$mms = isset($_POST['mms']) && $_POST['mms'] == '1' ? true : false;
	$tcr = $_POST['tcr'] ?? '';
	$webhook = $_POST['webhook'] ?? '';
	$park_tn = $_POST['park_tn'] ?? $_GET['park_tn'] ?? '';
	$action = $_POST['action'] ?? $_GET['action'] ?? '';

//process park action
	if ($action == 'park' && !empty($park_tn) && permission_exists('bulkvs_purchase')) {
		// Validate token
		$object = new token;
		if (!$object->validate($_SERVER['PHP_SELF'])) {
			message::add("Invalid token", 'negative');
			header("Location: bulkvs_number_edit.php?tn=".urlencode($tn));
			return;
		}
		
		try {
			// Get park domain from settings
			$park_domain_name = $settings->get('bulkvs', 'park_domain', '');
			if (empty($park_domain_name)) {
				throw new Exception("Park Domain must be configured in default settings");
			}
			
			// Look up domain UUID from domain name
			if (!isset($database) || $database === null) {
				$database = new database;
			}
			$sql = "select domain_uuid from v_domains where domain_name = :domain_name and domain_enabled = true limit 1";
			$parameters['domain_name'] = $park_domain_name;
			$park_domain_uuid = $database->select($sql, $parameters, 'column');
			unset($sql, $parameters);
			
			if (empty($park_domain_uuid)) {
				throw new Exception("Park Domain '$park_domain_name' not found or not enabled");
			}
			
			// Check if destination already exists for this number
			$destination_number = preg_replace('/[^0-9]/', '', $park_tn); // Remove non-numeric characters
			$tn_10 = preg_replace('/^1/', '', $destination_number); // Convert to 10-digit
			$existing_destination_uuid = null;
			if (strlen($tn_10) == 10) {
				$sql = "select destination_uuid from v_destinations where destination_number = :destination_number and destination_type = 'inbound' and destination_enabled = 'true' limit 1";
				$parameters['destination_number'] = $tn_10;
				$existing_destination_uuid = $database->select($sql, $parameters, 'column');
				unset($sql, $parameters);
			}
			
			require_once dirname(__DIR__, 2) . "/app/destinations/resources/classes/destinations.php";
			
			if (!empty($existing_destination_uuid)) {
				// Destination already exists, update domain to park domain
				$sql = "select * from v_destinations where destination_uuid = :destination_uuid limit 1";
				$parameters['destination_uuid'] = $existing_destination_uuid;
				$destination = $database->select($sql, $parameters, 'row');
				unset($sql, $parameters);
				
				if (empty($destination)) {
					throw new Exception("Destination not found");
				}
				
				$destinations = new destinations(['database' => $database, 'domain_uuid' => $park_domain_uuid]);
				
				// Prepare update array - preserve all existing fields, only change domain_uuid
				$array['destinations'][0]['destination_uuid'] = $destination['destination_uuid'];
				$array['destinations'][0]['domain_uuid'] = $park_domain_uuid; // Change to park domain
				$array['destinations'][0]['destination_number'] = $destination['destination_number'];
				$array['destinations'][0]['destination_type'] = $destination['destination_type'] ?? 'inbound';
				$array['destinations'][0]['destination_prefix'] = $destination['destination_prefix'] ?? '1';
				$array['destinations'][0]['destination_context'] = $destination['destination_context'] ?? 'public';
				$array['destinations'][0]['destination_enabled'] = $destination['destination_enabled'] ?? 'true';
				if (isset($destination['destination_description'])) {
					$array['destinations'][0]['destination_description'] = $destination['destination_description'];
				}
				if (isset($destination['destination_caller_id_name'])) {
					$array['destinations'][0]['destination_caller_id_name'] = $destination['destination_caller_id_name'];
				}
				if (isset($destination['destination_caller_id_number'])) {
					$array['destinations'][0]['destination_caller_id_number'] = $destination['destination_caller_id_number'];
				}
				if (isset($destination['destination_accountcode'])) {
					$array['destinations'][0]['destination_accountcode'] = $destination['destination_accountcode'];
				}
				if (isset($destination['destination_effective_caller_id_name'])) {
					$array['destinations'][0]['destination_effective_caller_id_name'] = $destination['destination_effective_caller_id_name'];
				}
				if (isset($destination['destination_effective_caller_id_number'])) {
					$array['destinations'][0]['destination_effective_caller_id_number'] = $destination['destination_effective_caller_id_number'];
				}
				
				// Grant temporary permissions
				$p = permissions::new();
				$p->add('destination_edit', 'temp');
				$p->add('dialplan_edit', 'temp');
				$p->add('dialplan_detail_edit', 'temp');
				
				// Save the destination
				$database->app_name = 'destinations';
				$database->app_uuid = '5ec89622-b19c-3559-64f0-afde802ab139';
				$database->save($array);
				
				// Revoke temporary permissions
				$p->delete('destination_edit', 'temp');
				$p->delete('dialplan_edit', 'temp');
				$p->delete('dialplan_detail_edit', 'temp');
				
				$destination_uuid = $existing_destination_uuid;
			} else {
				// Create new destination in FusionPBX (similar to purchase flow)
				$destination = new destinations(['database' => $database, 'domain_uuid' => $park_domain_uuid]);
				
				$destination_uuid = uuid();
				
				// Prepare destination array
				$array['destinations'][0]['destination_uuid'] = $destination_uuid;
				$array['destinations'][0]['domain_uuid'] = $park_domain_uuid;
				$array['destinations'][0]['destination_type'] = 'inbound';
				$array['destinations'][0]['destination_number'] = $tn_10;
				$array['destinations'][0]['destination_prefix'] = '1';
				$array['destinations'][0]['destination_context'] = 'public';
				$array['destinations'][0]['destination_enabled'] = 'true';
				$array['destinations'][0]['destination_description'] = 'Parked number';
				
				// Grant temporary permissions
				$p = permissions::new();
				$p->add('destination_add', 'temp');
				$p->add('dialplan_add', 'temp');
				$p->add('dialplan_detail_add', 'temp');
				
				// Save the destination
				$database->app_name = 'destinations';
				$database->app_uuid = '5ec89622-b19c-3559-64f0-afde802ab139';
				$database->save($array);
				
				// Revoke temporary permissions
				$p->delete('destination_add', 'temp');
				$p->delete('dialplan_add', 'temp');
				$p->delete('dialplan_detail_add', 'temp');
			}
			
			message::add($text['message-park-success']);
			// Redirect to destination edit page
			header("Location: ../destinations/destination_edit.php?id=".urlencode($destination_uuid));
			return;
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
		
		// Reload the page
		header("Location: bulkvs_number_edit.php?tn=".urlencode($tn));
		return;
	}

//process form submission
	if (!empty($_POST['action']) && $_POST['action'] == 'save' && !empty($tn)) {
		$lidb = isset($_POST['lidb']) ? $_POST['lidb'] : null;
		// Validate and sanitize LIDB: uppercase, alphanumeric and spaces only, max 15 characters
		if ($lidb !== null) {
			$lidb = preg_replace('/[^A-Z0-9 ]/', '', strtoupper(trim($lidb)));
			$lidb = substr($lidb, 0, 15);
			if (empty($lidb)) {
				$lidb = null; // Convert empty string back to null
			}
		}
		$portout_pin = isset($_POST['portout_pin']) ? $_POST['portout_pin'] : null;
		$notes = isset($_POST['notes']) ? $_POST['notes'] : null;
		// Always send SMS/MMS values (true/false) when form is submitted so we can enable/disable them
		$sms = isset($_POST['sms']) && $_POST['sms'] == '1';
		$mms = isset($_POST['mms']) && $_POST['mms'] == '1';
		// Only process campaign/webhook if user has permission
		$tcr = null;
		$webhook = null;
		if (permission_exists('bulkvs_messaging')) {
			$tcr = isset($_POST['tcr']) && $_POST['tcr'] !== '' ? $_POST['tcr'] : null;
			$webhook = isset($_POST['webhook']) && $_POST['webhook'] !== '' ? $_POST['webhook'] : null;
		}

		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			// Always pass SMS/MMS (not null) so API can update them
			$bulkvs_api->updateNumber($tn, $lidb, $portout_pin, $notes, $sms, $mms, $tcr, $webhook);
			
			// Update destination caller ID name when LIDB is updated (including when cleared)
			// Check if LIDB field was submitted (even if empty/null)
			if (isset($_POST['lidb'])) {
				try {
					// Initialize database if not already set
					if (!isset($database) || $database === null) {
						$database = new database;
					}
					
					// Convert TN to 10-digit format (remove leading "1")
					$tn_10 = preg_replace('/^1/', '', preg_replace('/[^0-9]/', '', $tn));
					
					if (strlen($tn_10) == 10) {
						// Direct database update - set caller ID name to match LIDB value
						$lidb_value = ($lidb !== null) ? $lidb : '';
						$sql = "update v_destinations set destination_caller_id_name = :destination_caller_id_name where destination_number = :destination_number and destination_type = 'inbound' and destination_enabled = 'true'";
						$parameters['destination_caller_id_name'] = $lidb_value;
						$parameters['destination_number'] = $tn_10;
						$database->execute($sql, $parameters);
						unset($sql, $parameters);
					}
				} catch (Exception $dest_e) {
					// Log error but don't fail the whole update - API update was successful
					error_log("BulkVS destination update error after LIDB update: " . $dest_e->getMessage());
				}
			}
			
			// Invalidate cache - trigger a sync to update the cached record
			require_once "resources/classes/bulkvs_cache.php";
			$cache = new bulkvs_cache($database, $settings);
			$trunk_group = $settings->get('bulkvs', 'trunk_group', '');
			// Trigger a sync in the background (don't wait for it)
			try {
				$cache->syncNumbers($trunk_group);
			} catch (Exception $e) {
				// Ignore cache sync errors - API update was successful
				error_log("BulkVS cache sync error after number update: " . $e->getMessage());
			}
			
			message::add($text['message-update']);
			header("Location: bulkvs_numbers.php");
			return;
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
	}

//get current number details from API
	$current_lidb = '';
	$current_portout_pin = '';
	$current_notes = '';
	$current_sms = false;
	$current_mms = false;
	$current_tcr = '';
	$current_webhook = '';
	if (!empty($tn)) {
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$number = $bulkvs_api->getNumber($tn);
			
			// Extract fields from API response
			$current_lidb_raw = $number['Lidb'] ?? $number['lidb'] ?? '';
			// Sanitize LIDB from API: uppercase, alphanumeric and spaces only, max 15 characters
			$current_lidb = preg_replace('/[^A-Z0-9 ]/', '', strtoupper($current_lidb_raw));
			$current_lidb = substr($current_lidb, 0, 15);
			$current_portout_pin = $number['Portout Pin'] ?? $number['portoutPin'] ?? '';
			$current_notes = $number['ReferenceID'] ?? $number['referenceID'] ?? '';
			
			// Extract SMS/MMS from nested Messaging object
			if (isset($number['Messaging']) && is_array($number['Messaging'])) {
				$messaging = $number['Messaging'];
				$current_sms = isset($messaging['Sms']) ? (bool)$messaging['Sms'] : false;
				$current_mms = isset($messaging['Mms']) ? (bool)$messaging['Mms'] : false;
				$current_tcr = $messaging['Tcr'] ?? $messaging['tcr'] ?? '';
				$current_webhook = $messaging['Webhook'] ?? $messaging['webhook'] ?? '';
			}
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
	}

//set default values (use POST values if set, otherwise use current values)
	if (empty($_POST['action']) || $_POST['action'] != 'save') {
		$lidb = $current_lidb;
		// Ensure LIDB is uppercase and sanitized for display
		if (!empty($lidb)) {
			$lidb = preg_replace('/[^A-Z0-9 ]/', '', strtoupper($lidb));
			$lidb = substr($lidb, 0, 15);
		}
		$portout_pin = $current_portout_pin;
		$notes = $current_notes;
		$sms = $current_sms;
		$mms = $current_mms;
		$tcr = $current_tcr;
		$webhook = $current_webhook;
	}

//fetch webhooks and campaigns for dropdowns (only if user has permission)
	$webhooks = [];
	$campaigns = [];
	$has_messaging_permission = permission_exists('bulkvs_messaging');
	if ($has_messaging_permission) {
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$webhooks = $bulkvs_api->getWebhooks();
			$campaigns = $bulkvs_api->getCampaigns();
			// Ensure arrays are returned
			if (!is_array($webhooks)) {
				$webhooks = [];
			}
			if (!is_array($campaigns)) {
				$campaigns = [];
			}
		} catch (Exception $e) {
			// Log error but don't block the page
			error_log("BulkVS Error fetching webhooks/campaigns: " . $e->getMessage());
			$webhooks = [];
			$campaigns = [];
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-bulkvs-number-edit'];
	require_once "resources/header.php";

//show the content
	echo "<form name='frm' id='frm' method='post' action=''>\n";
	echo "<input type='hidden' name='action' value='save'>\n";
	echo "<input type='hidden' name='tn' value='".escape($tn)."'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-bulkvs-number-edit']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'bulkvs_numbers.php']);
	if (!empty($tn) && permission_exists('bulkvs_purchase')) {
		echo button::create(['type'=>'button','label'=>$text['label-park'],'icon'=>'park','onclick'=>"modal_open('modal-park');"]);
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	//telephone number
	echo "<tr>\n";
	echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-telephone-number']."\n";
	echo "</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	".escape($tn)."\n";
	echo "</td>\n";
	echo "</tr>\n";

	//LIDB
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-lidb']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='lidb' id='lidb' value='".escape($lidb)."' maxlength='15' pattern='[A-Z0-9 ]{0,15}' title='Up to 15 alphanumeric characters and spaces (letters will be converted to uppercase)' oninput=\"this.value = this.value.replace(/[^A-Z0-9 ]/gi, '').toUpperCase();\">\n";
	echo "</td>\n";
	echo "</tr>\n";

	//Portout PIN
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-portout-pin']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='portout_pin' value='".escape($portout_pin)."' maxlength='10'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//Notes
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-notes']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='notes' value='".escape($notes)."' maxlength='255'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//SMS
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-sms']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='sms'>\n";
	echo "		<option value='0'".($sms ? '' : " selected").">".$text['label-disabled']."</option>\n";
	echo "		<option value='1'".($sms ? " selected" : '').">".$text['label-enabled']."</option>\n";
	echo "	</select>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//MMS
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-mms']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='mms'>\n";
	echo "		<option value='0'".($mms ? '' : " selected").">".$text['label-disabled']."</option>\n";
	echo "		<option value='1'".($mms ? " selected" : '').">".$text['label-enabled']."</option>\n";
	echo "	</select>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//Campaign (only show if user has messaging permission)
	if ($has_messaging_permission) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-campaign']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='tcr'>\n";
		echo "		<option value=''>".$text['label-none']."</option>\n";
		foreach ($campaigns as $campaign) {
			$campaign_tcr = $campaign['Tcr'] ?? $campaign['tcr'] ?? '';
			$campaign_brand = $campaign['Brand'] ?? $campaign['brand'] ?? '';
			if (!empty($campaign_tcr)) {
				$selected = ($tcr == $campaign_tcr) ? " selected" : "";
				$display = $campaign_tcr;
				if (!empty($campaign_brand)) {
					$display .= " - " . escape($campaign_brand);
				}
				echo "		<option value='".escape($campaign_tcr)."'".$selected.">".escape($display)."</option>\n";
			}
		}
		echo "	</select>\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	//Webhook (only show if user has messaging permission)
	if ($has_messaging_permission) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-webhook']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='webhook'>\n";
		echo "		<option value=''>".$text['label-none']."</option>\n";
		foreach ($webhooks as $webhook_item) {
			$webhook_name = $webhook_item['Webhook'] ?? $webhook_item['webhook'] ?? '';
			$webhook_desc = $webhook_item['Description'] ?? $webhook_item['description'] ?? '';
			if (!empty($webhook_name)) {
				$selected = ($webhook == $webhook_name) ? " selected" : "";
				$display = escape($webhook_name);
				if (!empty($webhook_desc)) {
					$display .= " - " . escape($webhook_desc);
				}
				echo "		<option value='".escape($webhook_name)."'".$selected.">".$display."</option>\n";
			}
		}
		echo "	</select>\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	echo "</form>\n";

	// Park confirmation modal (only if user has purchase permission and TN is set)
	if (!empty($tn) && permission_exists('bulkvs_purchase')) {
		$park_tn_escaped = escape($tn);
		echo modal::create([
			'id'=>'modal-park',
			'type'=>'confirm',
			'message'=>$text['message-park-confirm'] . ' (' . $park_tn_escaped . ')?',
			'actions'=>button::create([
				'type'=>'button',
				'label'=>$text['button-continue'],
				'icon'=>'check',
				'style'=>'float: right; margin-left: 15px;',
				'collapse'=>'never',
				'onclick'=>"modal_close(); window.location.href='bulkvs_number_edit.php?tn=".urlencode($tn)."&action=park&park_tn=".urlencode($tn)."&".$token['name']."=".$token['hash']."';"
			])
		]);
	}

//include the footer
	require_once "resources/footer.php";

?>

