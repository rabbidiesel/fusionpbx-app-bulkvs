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

//handle add mode (no TN provided)
	$is_add_mode = empty($tn);
	$caller_name = $_POST['caller_name'] ?? '';
	$street_address = $_POST['street_address'] ?? '';
	$location = $_POST['location'] ?? '';
	$city = $_POST['city'] ?? '';
	$state = $_POST['state'] ?? '';
	$zip = $_POST['zip'] ?? '';
	$sms_numbers = $_POST['sms_numbers'] ?? '';
	$delete_action = $_POST['delete_action'] ?? $_GET['delete_action'] ?? '';
	
	// Split street address by first space to get street number and street name
	$street_number = '';
	$street_name = '';
	if (!empty($street_address)) {
		$street_address = trim($street_address);
		$space_pos = strpos($street_address, ' ');
		if ($space_pos !== false) {
			$street_number = substr($street_address, 0, $space_pos);
			$street_name = substr($street_address, $space_pos + 1);
		} else {
			// If no space found, treat entire string as street name
			$street_name = $street_address;
		}
	}

//process form submission
	if (!empty($_POST['action']) && $_POST['action'] == 'save') {
		// For add mode, get TN from form
		if ($is_add_mode) {
			$tn = $_POST['tn'] ?? '';
			// Validate TN format: 11 digits starting with 1
			if (empty($tn)) {
				message::add("Telephone number is required", 'negative');
			} elseif (!preg_match('/^1\d{10}$/', preg_replace('/[^0-9]/', '', $tn))) {
				message::add("Telephone number must be 11 digits starting with 1", 'negative');
				$tn = ''; // Clear invalid TN
			} else {
				$tn = preg_replace('/[^0-9]/', '', $tn); // Clean TN
			}
		}
		
		if (!empty($tn)) {
		// Validate token
		$object = new token;
		if (!$object->validate($_SERVER['PHP_SELF'])) {
			message::add("Invalid token", 'negative');
			if ($is_add_mode) {
				header("Location: bulkvs_e911.php");
			} else {
				header("Location: bulkvs_e911_edit.php?tn=".urlencode($tn));
			}
			return;
		}

		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);

			// Step 1: Validate address
			$address_data = [
				'Street Number' => trim($street_number),
				'Street Name' => trim($street_name),
				'Location' => trim($location),
				'City' => trim($city),
				'State' => trim($state),
				'Zip' => trim($zip)
			];

			$validation_result = $bulkvs_api->validateAddress($address_data);
			
			// Check validation status
			$validation_status = $validation_result['Status'] ?? $validation_result['status'] ?? '';
			if (empty($validation_status) || strtoupper($validation_status) !== 'GEOCODED') {
				$error_msg = $validation_result['Description'] ?? $validation_result['description'] ?? 'Address validation failed';
				throw new Exception("Address validation failed: " . $error_msg);
			}

			$address_id = $validation_result['AddressID'] ?? $validation_result['addressID'] ?? '';
			if (empty($address_id)) {
				throw new Exception("Address validation did not return AddressID");
			}

			// Step 2: Parse SMS numbers (comma-separated)
			$sms_array = [];
			if (!empty($sms_numbers)) {
				$sms_parts = explode(',', $sms_numbers);
				foreach ($sms_parts as $sms_part) {
					$sms_clean = preg_replace('/[^0-9]/', '', trim($sms_part));
					if (!empty($sms_clean)) {
						$sms_array[] = $sms_clean;
					}
				}
			}

			// Step 3: Save E911 record
			$bulkvs_api->saveE911Record($tn, trim($caller_name), $address_id, $sms_array);
			
			// Invalidate cache - trigger a sync to update the cached record
			require_once "resources/classes/bulkvs_cache.php";
			$cache = new bulkvs_cache($database, $settings);
			// Trigger a sync in the background (don't wait for it)
			try {
				$cache->syncE911();
			} catch (Exception $e) {
				// Ignore cache sync errors - API update was successful
				error_log("BulkVS cache sync error after E911 update: " . $e->getMessage());
			}
			
			message::add($text['message-update']);
			header("Location: bulkvs_e911.php");
			return;
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
		}
	}

//process delete action
	if ($delete_action == 'delete' && !empty($tn) && permission_exists('bulkvs_purchase')) {
		// Validate token
		$object = new token;
		if (!$object->validate($_SERVER['PHP_SELF'])) {
			message::add("Invalid token", 'negative');
			if ($is_add_mode) {
				header("Location: bulkvs_e911_edit.php");
			} else {
				header("Location: bulkvs_e911_edit.php?tn=".urlencode($tn));
			}
			return;
		}
		
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$result = $bulkvs_api->deleteE911Record($tn);
			
			// Check if delete was successful
			$status = $result['Status'] ?? $result['status'] ?? '';
			if (strtoupper($status) === 'SUCCESS' || empty($status)) {
				message::add($text['message-e911-delete-success'], 'positive');
			} else {
				$error_msg = $result['Description'] ?? $result['description'] ?? 'Delete failed';
				throw new Exception($error_msg);
			}
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
		
		// Redirect back to E911 page
		header("Location: bulkvs_e911.php");
		return;
	}

//get current E911 record details from API
	$current_caller_name = '';
	$current_address_line1 = '';
	$current_address_line2 = '';
	$current_city = '';
	$current_state = '';
	$current_zip = '';
	$current_sms = [];
	$current_street_number = '';
	$current_street_name = '';
	$current_location = '';

		if (!empty($tn)) {
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$e911_record = $bulkvs_api->getE911Record($tn);
			
			// Verify the record matches the requested TN before using it
			$record_tn = $e911_record['TN'] ?? $e911_record['tn'] ?? '';
			if (!empty($e911_record) && !empty($record_tn) && $record_tn == $tn) {
				$current_caller_name = $e911_record['Caller Name'] ?? $e911_record['callerName'] ?? '';
				$current_address_line1 = $e911_record['Address Line 1'] ?? $e911_record['addressLine1'] ?? '';
				$current_address_line2 = $e911_record['Address Line 2'] ?? $e911_record['addressLine2'] ?? '';
				$current_city = $e911_record['City'] ?? $e911_record['city'] ?? '';
				$current_state = $e911_record['State'] ?? $e911_record['state'] ?? '';
				$current_zip = $e911_record['Zip'] ?? $e911_record['zip'] ?? '';
				
				// Parse Address Line 1 into Street Number and Street Name
				// Common format: "123 MAIN ST" or "123 N MAIN ST"
				if (!empty($current_address_line1)) {
					// Try to extract street number (digits at the start)
					if (preg_match('/^(\d+)\s+(.+)$/', $current_address_line1, $matches)) {
						$current_street_number = $matches[1];
						$current_street_name = $matches[2];
					} else {
						// If no number at start, put entire address in street name
						$current_street_name = $current_address_line1;
					}
				}
				
				// Location is typically Address Line 2
				$current_location = $current_address_line2;
				
				// SMS numbers (if present)
				if (isset($e911_record['Sms']) && is_array($e911_record['Sms'])) {
					$current_sms = $e911_record['Sms'];
				}
			}
		} catch (Exception $e) {
			// If no E911 record exists, that's okay - we'll create a new one
			// Only show error if it's not a "not found" type error
			if (strpos($e->getMessage(), 'not found') === false && strpos($e->getMessage(), '404') === false) {
				message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
			}
		}
	}

//set default values (use POST values if set, otherwise use current values)
	if (empty($_POST['action']) || $_POST['action'] != 'save') {
		$caller_name = $current_caller_name;
		// Combine street number and street name for display
		if (!empty($current_street_number) && !empty($current_street_name)) {
			$street_address = $current_street_number . ' ' . $current_street_name;
		} elseif (!empty($current_street_name)) {
			$street_address = $current_street_name;
		} else {
			$street_address = '';
		}
		$location = $current_location;
		$city = $current_city;
		$state = $current_state;
		$zip = $current_zip;
		$sms_numbers = !empty($current_sms) ? implode(', ', $current_sms) : '';
	} else {
		// Use POST value for street_address (already set above)
		$street_address = $_POST['street_address'] ?? '';
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-bulkvs-e911-edit'];
	require_once "resources/header.php";

//show the content
	echo "<form name='frm' id='frm' method='post' action=''>\n";
	echo "<input type='hidden' name='action' value='save'>\n";
	echo "<input type='hidden' name='tn' value='".escape($tn)."'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "<div class='action_bar' id='action_bar' style='background-color: #d32f2f; color: #ffffff;'>\n";
	echo "	<div class='heading' style='color: #ffffff;'><b>".$text['title-bulkvs-e911-edit']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'bulkvs_e911.php']);
	if (!$is_add_mode && permission_exists('bulkvs_purchase')) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_delete','style'=>'background-color: #d32f2f; color: #ffffff; border-color: #d32f2f; margin-right: 15px;','onclick'=>"modal_open('modal-e911-delete');"]);
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
	if ($is_add_mode) {
		echo "	<input type='text' class='formfld' name='tn' value='".escape($tn)."' maxlength='11' placeholder='1XXXXXXXXXX' pattern='^1\d{10}$' title='11-digit number starting with 1' required='required'>\n";
	} else {
		echo "	".escape($tn)."\n";
	}
	echo "</td>\n";
	echo "</tr>\n";

	//Caller Name
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-caller-name']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='caller_name' value='".escape($caller_name)."' maxlength='255'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//Street Address (combined street number and street name)
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-street-address']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='street_address' value='".escape($street_address)."' maxlength='305' placeholder='123 Main St'>\n";
	echo "	<br />\n";
	echo "	<span style='font-size: 11px; color: #666;'>Street number and name (e.g., \"123 Main St\")</span>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//Location
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-location']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='location' value='".escape($location)."' maxlength='255' placeholder='Suite, Unit, etc.'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//City
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-city']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='city' value='".escape($city)."' maxlength='100'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//State
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-state']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='state' value='".escape($state)."' maxlength='2' style='width: 60px;'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//Zip
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-zip']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='zip' value='".escape($zip)."' maxlength='10'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	//SMS Numbers
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-sms-numbers']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input type='text' class='formfld' name='sms_numbers' value='".escape($sms_numbers)."' maxlength='500' placeholder='Comma-separated list'>\n";
	echo "	<br />\n";
	echo "	".$text['description-sms-numbers']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	echo "</form>\n";

	// Delete confirmation modal (only if user has purchase permission and not in add mode)
	if (!$is_add_mode && permission_exists('bulkvs_purchase')) {
		echo modal::create([
			'id'=>'modal-e911-delete',
			'type'=>'delete',
			'message'=>$text['message-e911-delete-confirm'] . ' (' . escape($tn) . ')',
			'actions'=>button::create([
				'type'=>'button',
				'label'=>$text['button-continue'],
				'icon'=>'check',
				'style'=>'float: right; margin-left: 15px;',
				'collapse'=>'never',
				'onclick'=>"modal_close(); window.location.href='bulkvs_e911_edit.php?tn=".urlencode($tn)."&delete_action=delete&".$token['name']."=".$token['hash']."';"
			])
		]);
	}

//include the footer
	require_once "resources/footer.php";
?>
