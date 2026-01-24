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
	require_once dirname(__DIR__, 2) . "/resources/paging.php";

//check permissions
	if (!permission_exists('bulkvs_view') && !permission_exists('bulkvs_numbers_domain') && !permission_exists('bulkvs_numbers_all')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//process disconnect, park, and unpark actions
	$action = $_POST['action'] ?? $_GET['action'] ?? '';
	$disconnect_tn = $_POST['disconnect_tn'] ?? $_GET['disconnect_tn'] ?? '';
	$park_tn = $_POST['park_tn'] ?? $_GET['park_tn'] ?? '';
	$unpark_destination_uuid = $_POST['unpark_destination_uuid'] ?? $_GET['unpark_destination_uuid'] ?? '';
	
	if ($action == 'unpark' && !empty($unpark_destination_uuid) && permission_exists('bulkvs_purchase')) {
		// Validate token
		$object = new token;
		if (!$object->validate($_SERVER['PHP_SELF'])) {
			message::add("Invalid token", 'negative');
			header("Location: bulkvs_numbers.php");
			return;
		}
		
		try {
			// Get current domain UUID
			if (empty($domain_uuid)) {
				throw new Exception("Current domain not available");
			}
			
			// Get destination details - fetch all fields to preserve them
			if (!isset($database) || $database === null) {
				$database = new database;
			}
			$sql = "select * from v_destinations where destination_uuid = :destination_uuid limit 1";
			$parameters['destination_uuid'] = $unpark_destination_uuid;
			$destination = $database->select($sql, $parameters, 'row');
			unset($sql, $parameters);
			
			if (empty($destination)) {
				throw new Exception("Destination not found");
			}
			
			// Update destination domain to current domain
			require_once dirname(__DIR__, 2) . "/app/destinations/resources/classes/destinations.php";
			$destinations = new destinations(['database' => $database, 'domain_uuid' => $domain_uuid]);
			
			// Prepare update array - preserve all existing fields, only change domain_uuid
			$array['destinations'][0]['destination_uuid'] = $destination['destination_uuid'];
			$array['destinations'][0]['domain_uuid'] = $domain_uuid; // Change to current domain
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
			
			message::add($text['message-unpark-success']);
			// Redirect to destination edit page
			header("Location: ../destinations/destination_edit.php?id=".urlencode($unpark_destination_uuid));
			return;
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
		
		// Reload the page
		header("Location: bulkvs_numbers.php");
		return;
	}
	
	if ($action == 'park' && !empty($park_tn) && permission_exists('bulkvs_purchase')) {
		// Validate token
		$object = new token;
		if (!$object->validate($_SERVER['PHP_SELF'])) {
			message::add("Invalid token", 'negative');
			header("Location: bulkvs_numbers.php");
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
		header("Location: bulkvs_numbers.php");
		return;
	}
	
	if ($action == 'disconnect' && !empty($disconnect_tn) && permission_exists('bulkvs_purchase')) {
		// Validate token
		$object = new token;
		if (!$object->validate($_SERVER['PHP_SELF'])) {
			message::add("Invalid token", 'negative');
			header("Location: bulkvs_numbers.php");
			return;
		}
		
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$result = $bulkvs_api->deleteNumber($disconnect_tn);
			
			// Check if delete was successful
			$status = $result['Status'] ?? $result['status'] ?? '';
			if (strtoupper($status) === 'SUCCESS') {
				// Remove from cache
				require_once "resources/classes/bulkvs_cache.php";
				$cache = new bulkvs_cache($database, $settings);
				try {
					$cache->deleteNumber($disconnect_tn);
				} catch (Exception $e) {
					// Ignore cache errors - API delete was successful
					error_log("BulkVS cache delete error: " . $e->getMessage());
				}
				
				message::add($text['message-disconnect-success'], 'positive');
			} else {
				$error_msg = $result['Description'] ?? $result['description'] ?? 'Disconnect failed';
				throw new Exception($error_msg);
			}
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
		
		// Reload the page
		header("Location: bulkvs_numbers.php");
		return;
	}

//get trunk group from settings
	$trunk_group = $settings->get('bulkvs', 'trunk_group', '');

//get park domain from settings (for checking if number is parked)
	$park_domain_name = $settings->get('bulkvs', 'park_domain', '');

//load cache class
	require_once "resources/classes/bulkvs_cache.php";
	$cache = new bulkvs_cache($database, $settings);

//get numbers from cache (fallback to API if cache is empty)
	$numbers = [];
	$error_message = '';
	$e911_map = []; // Initialize E911 map
	$use_cache = true;
	
	try {
		// Try to load from cache first
		$numbers = $cache->getNumbers($trunk_group);
		
		// If cache is empty, sync from API immediately (synchronous)
		if (empty($numbers)) {
			$use_cache = false;
			// Fetch from API directly for immediate display, then sync in background
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$api_response = $bulkvs_api->getNumbers($trunk_group);
			
			// Handle API response - it may be an array of numbers or an object with a data property
			if (isset($api_response['data']) && is_array($api_response['data'])) {
				$numbers = $api_response['data'];
			} elseif (is_array($api_response)) {
				$numbers = $api_response;
			} else {
				$numbers = [];
			}
			
			// Filter out empty/invalid entries
			$numbers = array_filter($numbers, function($number) {
				$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
				return !empty($tn);
			});
			$numbers = array_values($numbers); // Re-index array
			
			// Now try to sync to cache in background (don't block if it fails)
			try {
				$cache->syncNumbers($trunk_group);
			} catch (Exception $sync_error) {
				error_log("BulkVS background sync failed: " . $sync_error->getMessage());
			}
		}
		
		// Fetch E911 records from cache
		$e911_records = [];
		try {
			$e911_records = $cache->getE911Records();
			
			// If cache is empty, fetch from API directly
			if (empty($e911_records)) {
				require_once "resources/classes/bulkvs_api.php";
				$bulkvs_api = new bulkvs_api($settings);
				$e911_response = $bulkvs_api->getE911Records();
				if (isset($e911_response['data']) && is_array($e911_response['data'])) {
					$e911_records = $e911_response['data'];
				} elseif (is_array($e911_response)) {
					$e911_records = $e911_response;
				}
				
				// Try to sync to cache in background (don't block if it fails)
				try {
					$cache->syncE911();
				} catch (Exception $sync_error) {
					error_log("BulkVS E911 background sync failed: " . $sync_error->getMessage());
				}
			}
			
			// Create a mapping of TN to E911 record
			foreach ($e911_records as $e911_record) {
				$e911_tn = $e911_record['TN'] ?? $e911_record['tn'] ?? '';
				if (!empty($e911_tn)) {
					$e911_map[$e911_tn] = $e911_record;
				}
			}
		} catch (Exception $e) {
			// E911 fetch failed, but don't block the page - just log it
			error_log("BulkVS E911 fetch error: " . $e->getMessage());
		}
	} catch (Exception $e) {
		$error_message = $e->getMessage();
		message::add($text['message-api-error'] . ': ' . $error_message, 'negative');
	}

//check if records have changed (new or deleted)
	$has_changes_numbers = false;
	try {
		$has_changes_numbers = $cache->hasChanges('numbers');
	} catch (Exception $e) {
		// Ignore errors checking for changes
	}

//get domain filter parameter
	$domain_filter = $_GET['domain_filter'] ?? '';
	$show_domain_only = false;
	
	// Determine if we should show domain-only based on permissions and domain_filter parameter
	if (permission_exists('bulkvs_numbers_all')) {
		// User has "all" permission - check domain_filter parameter
		if ($domain_filter === 'domain') {
			$show_domain_only = true;
		}
	} elseif (permission_exists('bulkvs_numbers_domain')) {
		// User only has domain permission - always show domain-only
		$show_domain_only = true;
	}

//filter numbers by domain if needed
	if ($show_domain_only && !empty($numbers)) {
		if (!isset($database) || $database === null) {
			$database = new database;
		}
		
		// Get all destination numbers in current domain
		$sql = "select distinct destination_number ";
		$sql .= "from v_destinations ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and destination_type = 'inbound' ";
		$sql .= "and destination_enabled = 'true' ";
		$parameters['domain_uuid'] = $domain_uuid;
		$domain_destinations = $database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		
		// Build set of 10-digit destination numbers
		$destination_numbers = [];
		foreach ($domain_destinations as $dest) {
			$dest_number = $dest['destination_number'] ?? '';
			if (!empty($dest_number)) {
				$destination_numbers[$dest_number] = true;
			}
		}
		
		// Filter numbers to only include those with matching destinations
		if (!empty($destination_numbers)) {
			$filtered_numbers = [];
			foreach ($numbers as $number) {
				$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
				if (!empty($tn)) {
					// Convert 11-digit to 10-digit (remove leading "1")
					$tn_10 = preg_replace('/^1/', '', $tn);
					if (strlen($tn_10) == 10 && isset($destination_numbers[$tn_10])) {
						$filtered_numbers[] = $number;
					}
				}
			}
			$numbers = $filtered_numbers;
		} else {
			// No destinations found, clear numbers
			$numbers = [];
		}
	}

//create token (needed for disconnect action and modals)
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//get filter parameter
	$filter = $_GET['filter'] ?? $_POST['filter'] ?? '';

//get order and order by
	$order_by = $_GET['order_by'] ?? 'tn';
	$order = $_GET['order'] ?? 'asc';

//apply server-side filter to all numbers
	if (!empty($filter)) {
		$filter_lower = strtolower($filter);
		$numbers = array_filter($numbers, function($number) use ($filter_lower) {
			$tn = strtolower($number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '');
			$status = strtolower($number['Status'] ?? $number['status'] ?? '');
			$rate_center = '';
			$tier = '';
			$lidb = strtolower($number['Lidb'] ?? $number['lidb'] ?? '');
			$notes = strtolower($number['ReferenceID'] ?? $number['referenceID'] ?? '');
			
			// Extract nested fields
			if (isset($number['TN Details']) && is_array($number['TN Details'])) {
				$tn_details = $number['TN Details'];
				$rate_center = strtolower($tn_details['Rate Center'] ?? $tn_details['rate_center'] ?? '');
				$tier = strtolower($tn_details['Tier'] ?? $tn_details['tier'] ?? '');
			}
			
			// Check if filter matches any field
			return (
				strpos($tn, $filter_lower) !== false ||
				strpos($status, $filter_lower) !== false ||
				strpos($rate_center, $filter_lower) !== false ||
				strpos($tier, $filter_lower) !== false ||
				strpos($lidb, $filter_lower) !== false ||
				strpos($notes, $filter_lower) !== false
			);
		});
		$numbers = array_values($numbers); // Re-index array
	}

//build domain lookup map for all numbers (needed for sorting)
	$domain_map_all = [];
	if (!empty($numbers)) {
		// Extract 10-digit numbers from BulkVS 11-digit numbers
		$tn_10_digit = [];
		foreach ($numbers as $number) {
			$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
			if (!empty($tn)) {
				// Convert 11-digit to 10-digit (remove leading "1")
				$tn_10 = preg_replace('/^1/', '', $tn);
				if (strlen($tn_10) == 10) {
					$tn_10_digit[] = $tn_10;
				}
			}
		}
		
		// Query destinations for these numbers
		if (!empty($tn_10_digit)) {
			// Initialize database if not already set
			if (!isset($database) || $database === null) {
				$database = new database;
			}
			
			$placeholders = [];
			$parameters = [];
			foreach ($tn_10_digit as $index => $tn_10) {
				$placeholders[] = ':tn_' . $index;
				$parameters['tn_' . $index] = $tn_10;
			}
			
			$sql = "select distinct destination_number, domain_uuid, destination_uuid ";
			$sql .= "from v_destinations ";
			$sql .= "where destination_number in (" . implode(', ', $placeholders) . ") ";
			$sql .= "and destination_type = 'inbound' ";
			$sql .= "and destination_enabled = 'true' ";
			$destinations = $database->select($sql, $parameters, 'all');
			unset($sql, $parameters);
			
			// Build map of destination_number -> domain_uuid and destination_uuid
			$domain_uuids = [];
			$destination_uuids = [];
			foreach ($destinations as $dest) {
				$dest_number = $dest['destination_number'] ?? '';
				$dest_domain_uuid = $dest['domain_uuid'] ?? '';
				$dest_uuid = $dest['destination_uuid'] ?? '';
				if (!empty($dest_number) && !empty($dest_domain_uuid)) {
					// Store domain_uuid and destination_uuid for this number (use first match if multiple)
					if (!isset($domain_uuids[$dest_number])) {
						$domain_uuids[$dest_number] = $dest_domain_uuid;
						if (!empty($dest_uuid)) {
							$destination_uuids[$dest_number] = $dest_uuid;
						}
					}
				}
			}
			
			// Query domain names for unique domain_uuids
			if (!empty($domain_uuids)) {
				$unique_domain_uuids = array_unique(array_values($domain_uuids));
				$placeholders = [];
				$parameters = [];
				foreach ($unique_domain_uuids as $index => $domain_uuid) {
					$placeholders[] = ':domain_uuid_' . $index;
					$parameters['domain_uuid_' . $index] = $domain_uuid;
				}
				
				$sql = "select domain_uuid, domain_name ";
				$sql .= "from v_domains ";
				$sql .= "where domain_uuid in (" . implode(', ', $placeholders) . ") ";
				$domains = $database->select($sql, $parameters, 'all');
				unset($sql, $parameters);
				
				// Build map of domain_uuid -> domain_name
				$domain_names = [];
				foreach ($domains as $domain) {
					$domain_uuid = $domain['domain_uuid'] ?? '';
					$domain_name = $domain['domain_name'] ?? '';
					if (!empty($domain_uuid)) {
						$domain_names[$domain_uuid] = $domain_name;
					}
				}
				
				// Build final map: destination_number -> ['domain_name' => ..., 'destination_uuid' => ...]
				foreach ($domain_uuids as $dest_number => $domain_uuid) {
					if (isset($domain_names[$domain_uuid])) {
						$domain_map_all[$dest_number] = [
							'domain_name' => $domain_names[$domain_uuid],
							'destination_uuid' => $destination_uuids[$dest_number] ?? ''
						];
					}
				}
			}
		}
	}
	
	// Attach domain_name to each number record for sorting
	foreach ($numbers as &$number) {
		$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
		if (!empty($tn)) {
			$tn_10 = preg_replace('/^1/', '', $tn);
			if (strlen($tn_10) == 10 && isset($domain_map_all[$tn_10])) {
				$number['_domain_name'] = $domain_map_all[$tn_10]['domain_name'] ?? '';
			} else {
				$number['_domain_name'] = '';
			}
		} else {
			$number['_domain_name'] = '';
		}
	}
	unset($number); // Break reference

//sort the numbers array
	if (!empty($numbers) && !empty($order_by)) {
		usort($numbers, function($a, $b) use ($order_by, $order) {
			$value_a = '';
			$value_b = '';
			
			switch ($order_by) {
				case 'tn':
					$value_a = $a['TN'] ?? $a['tn'] ?? $a['telephoneNumber'] ?? '';
					$value_b = $b['TN'] ?? $b['tn'] ?? $b['telephoneNumber'] ?? '';
					break;
				case 'status':
					$value_a = $a['Status'] ?? $a['status'] ?? '';
					$value_b = $b['Status'] ?? $b['status'] ?? '';
					break;
				case 'activation_date':
					if (isset($a['TN Details']) && is_array($a['TN Details'])) {
						$value_a = $a['TN Details']['Activation Date'] ?? $a['TN Details']['activation_date'] ?? '';
					}
					if (isset($b['TN Details']) && is_array($b['TN Details'])) {
						$value_b = $b['TN Details']['Activation Date'] ?? $b['TN Details']['activation_date'] ?? '';
					}
					// Convert to timestamp for proper date sorting
					if (!empty($value_a)) {
						$ts_a = strtotime($value_a);
						$value_a = $ts_a !== false ? $ts_a : 0;
					} else {
						$value_a = 0;
					}
					if (!empty($value_b)) {
						$ts_b = strtotime($value_b);
						$value_b = $ts_b !== false ? $ts_b : 0;
					} else {
						$value_b = 0;
					}
					break;
				case 'rate_center':
					if (isset($a['TN Details']) && is_array($a['TN Details'])) {
						$value_a = $a['TN Details']['Rate Center'] ?? $a['TN Details']['rate_center'] ?? '';
					}
					if (isset($b['TN Details']) && is_array($b['TN Details'])) {
						$value_b = $b['TN Details']['Rate Center'] ?? $b['TN Details']['rate_center'] ?? '';
					}
					break;
				case 'tier':
					if (isset($a['TN Details']) && is_array($a['TN Details'])) {
						$value_a = $a['TN Details']['Tier'] ?? $a['TN Details']['tier'] ?? '';
					}
					if (isset($b['TN Details']) && is_array($b['TN Details'])) {
						$value_b = $b['TN Details']['Tier'] ?? $b['TN Details']['tier'] ?? '';
					}
					break;
				case 'lidb':
					$value_a = $a['Lidb'] ?? $a['lidb'] ?? '';
					$value_b = $b['Lidb'] ?? $b['lidb'] ?? '';
					break;
				case 'domain':
					$value_a = $a['_domain_name'] ?? '';
					$value_b = $b['_domain_name'] ?? '';
					break;
				default:
					return 0;
			}
			
			// Compare values
			if (is_numeric($value_a) && is_numeric($value_b)) {
				$result = $value_a <=> $value_b;
			} else {
				$result = strcasecmp(strval($value_a), strval($value_b));
			}
			
			return $order == 'desc' ? -$result : $result;
		});
	}

//prepare to page the results
	$num_rows = count($numbers);
	$rows_per_page = $settings->get('domain', 'paging', 50);
	$param = "";
	if ($show_domain_only) {
		$param = "&domain_filter=domain";
	}
	if (!empty($filter)) {
		$param .= "&filter=".urlencode($filter);
	}
	if (!empty($order_by)) {
		$param .= "&order_by=".urlencode($order_by);
	}
	if (!empty($order)) {
		$param .= "&order=".urlencode($order);
	}
	// Build param for th_order_by (only filter and domain_filter, order_by/order will be added by th_order_by)
	$th_order_by_param = "";
	if ($show_domain_only) {
		$th_order_by_param = "&domain_filter=domain";
	}
	if (!empty($filter)) {
		$th_order_by_param .= "&filter=".urlencode($filter);
	}
	if (!empty($_GET['page'])) {
		$page = $_GET['page'];
	}
	if (!isset($page)) { $page = 0; $_GET['page'] = 0; }
	[$paging_controls, $rows_per_page] = paging($num_rows, $param, $rows_per_page);
	[$paging_controls_mini, $rows_per_page] = paging($num_rows, $param, $rows_per_page, true);
	$offset = $rows_per_page * $page;
	
	// Slice the results array for pagination
	$paginated_numbers = [];
	if (!empty($numbers)) {
		$paginated_numbers = array_slice($numbers, $offset, $rows_per_page);
	}

//build domain lookup map for paginated numbers (use already-built domain_map_all)
	$domain_map = [];
	if (!empty($paginated_numbers)) {
		foreach ($paginated_numbers as $number) {
			$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
			if (!empty($tn)) {
				$tn_10 = preg_replace('/^1/', '', $tn);
				if (strlen($tn_10) == 10 && isset($domain_map_all[$tn_10])) {
					$domain_map[$tn_10] = $domain_map_all[$tn_10];
				}
			}
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-bulkvs-numbers'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-bulkvs-numbers']."</b>";
	if ($num_rows > 0) {
		echo "<div class='count'>".number_format($num_rows)."</div>";
	}
	echo "</div>\n";
	echo "	<div class='actions'>\n";
	// Refresh button (hidden by default, shown when new records available)
	$refresh_button_style = $has_changes_numbers ? '' : 'display: none;';
	echo "<span id='refresh_button_container' style='".$refresh_button_style."'>";
	echo button::create(['type'=>'button','label'=>$text['button-refresh'],'icon'=>'refresh','id'=>'btn_refresh','onclick'=>"refreshPage();"]);
	echo "</span>\n";
	if (permission_exists('bulkvs_view')) {
		if (permission_exists('bulkvs_e911') || permission_exists('bulkvs_e911_server') || permission_exists('bulkvs_e911_all')) {
			echo button::create(['type'=>'button','label'=>$text['label-e911'],'icon'=>'phone','link'=>'bulkvs_e911.php']);
		}
		echo button::create(['type'=>'button','label'=>$text['label-lrn-lookup'],'icon'=>'search','link'=>'bulkvs_lrn.php']);
	}
	if (permission_exists('bulkvs_search')) {
		echo button::create(['type'=>'button','label'=>$text['title-bulkvs-search'],'icon'=>'search','link'=>'bulkvs_search.php']);
	}
	// Domain/All toggle button (only visible when bulkvs_numbers_all permission exists)
	if (permission_exists('bulkvs_numbers_all')) {
		$toggle_url = 'bulkvs_numbers.php';
		$toggle_params = [];
		if (!empty($filter)) {
			$toggle_params[] = 'filter=' . urlencode($filter);
		}
		if (!empty($order_by)) {
			$toggle_params[] = 'order_by=' . urlencode($order_by);
		}
		if (!empty($order)) {
			$toggle_params[] = 'order=' . urlencode($order);
		}
		if ($show_domain_only) {
			// Currently showing domain-only, so toggle to show all
			$toggle_label = $text['button-show-all'];
		} else {
			// Currently showing all, so toggle to show domain-only
			$toggle_params[] = 'domain_filter=domain';
			$toggle_label = $text['button-show-domain'];
		}
		if (!empty($toggle_params)) {
			$toggle_url .= '?' . implode('&', $toggle_params);
		}
		echo button::create(['type'=>'button','label'=>$toggle_label,'icon'=>'','link'=>$toggle_url,'style'=>'margin-left: 15px;']);
	}
	if (!empty($numbers)) {
		echo "		<form method='get' action='' style='display: inline; margin-left: 15px;'>\n";
		if ($show_domain_only) {
			echo "			<input type='hidden' name='domain_filter' value='domain'>\n";
		}
		echo "			<input type='text' name='filter' class='txt list-search' placeholder='Filter results...' value='".escape($filter)."' style='width: 200px;'>\n";
		echo "			<input type='submit' class='btn' value='Filter' style='margin-left: 5px;'>\n";
		if (!empty($filter)) {
			$clear_url = 'bulkvs_numbers.php';
			if ($show_domain_only) {
				$clear_url .= '?domain_filter=domain';
			}
			echo "			<a href='".$clear_url."' class='btn' style='margin-left: 5px;'>Clear</a>\n";
		}
		echo "		</form>\n";
	}
	if ($paging_controls_mini != '') {
		echo "<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	// Display description with trunk group name if available
	if (!empty($trunk_group)) {
		echo "View and manage BulkVS phone numbers filtered by trunk group: ".escape($trunk_group)."\n";
	} else {
		echo $text['description-bulkvs-numbers']."\n";
	}
	echo "<br /><br />\n";

	if (!empty($error_message)) {
		echo "<div class='alert alert-warning'>".escape($error_message)."</div>\n";
		echo "<br />\n";
	}

	if (!empty($paginated_numbers)) {
		echo "<div class='card'>\n";
		echo "<table class='list' id='numbers_table'>\n";
		echo "<tr class='list-header'>\n";
		echo "	".th_order_by('tn', $text['label-telephone-number'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	".th_order_by('status', $text['label-status'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	".th_order_by('activation_date', $text['label-activation-date'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	".th_order_by('rate_center', $text['label-rate-center'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	".th_order_by('tier', $text['label-tier'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	".th_order_by('lidb', $text['label-lidb'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	<th>".$text['label-notes']."</th>\n";
		echo "	".th_order_by('domain', $text['label-domain'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	<th>".$text['label-e911']."</th>\n";
		echo "</tr>\n";

		foreach ($paginated_numbers as $number) {
			// Extract fields from API response (handling nested structures)
			$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
			$status = $number['Status'] ?? $number['status'] ?? '';
			$activation_date = '';
			$rate_center = '';
			$tier = '';
			$lidb = '';
			$notes = '';
			
			// Extract nested fields
			if (isset($number['TN Details']) && is_array($number['TN Details'])) {
				$tn_details = $number['TN Details'];
				$activation_date = $tn_details['Activation Date'] ?? $tn_details['activation_date'] ?? '';
				$rate_center = $tn_details['Rate Center'] ?? $tn_details['rate_center'] ?? '';
				$tier = $tn_details['Tier'] ?? $tn_details['tier'] ?? '';
			}
			
			// LIDB is at top level
			$lidb = $number['Lidb'] ?? $number['lidb'] ?? '';
			
			// Notes (ReferenceID)
			$notes = $number['ReferenceID'] ?? $number['referenceID'] ?? '';
			
			// Skip rows with no telephone number
			if (empty($tn)) {
				continue;
			}
			
			// Format activation date if present
			if (!empty($activation_date)) {
				// Try to format the date nicely
				$date_timestamp = strtotime($activation_date);
				if ($date_timestamp !== false) {
					$activation_date = date('Y-m-d H:i', $date_timestamp);
				}
			}
			
			// Look up domain for this number
			$domain_name = '';
			$destination_uuid = '';
			$domain_info = null;
			if (!empty($tn)) {
				// Convert 11-digit to 10-digit (remove leading "1")
				$tn_10 = preg_replace('/^1/', '', $tn);
				if (strlen($tn_10) == 10 && isset($domain_map[$tn_10])) {
					$domain_info = $domain_map[$tn_10];
					$domain_name = is_array($domain_info) ? ($domain_info['domain_name'] ?? '') : $domain_info;
					$destination_uuid = is_array($domain_info) ? ($domain_info['destination_uuid'] ?? '') : '';
				}
			}
			
			// Create edit URL for the row
			$edit_url = '';
			if (permission_exists('bulkvs_edit')) {
				$edit_url = "bulkvs_number_edit.php?tn=".urlencode($tn);
			}
			
			// Create destination edit URL
			$destination_edit_url = '';
			$has_domain_for_display = false;
			$is_parked_domain = false;
			if (!empty($destination_uuid) && permission_exists('destination_edit')) {
				$destination_edit_url = "../destinations/destination_edit.php?id=".urlencode($destination_uuid);
				$has_domain_for_display = true;
				// Check if this is a parked domain
				if (!empty($park_domain_name) && !empty($domain_name) && strcasecmp($domain_name, $park_domain_name) === 0) {
					$is_parked_domain = true;
				}
			}

			//show the data
			echo "<tr class='list-row'".(!empty($edit_url) ? " href='".$edit_url."'" : "").">\n";
			echo "	<td class='no-wrap'>".escape($tn)."</td>\n";
			echo "	<td>".escape($status)."&nbsp;</td>\n";
			echo "	<td class='no-wrap'>".escape($activation_date)."&nbsp;</td>\n";
			echo "	<td>".escape($rate_center)."&nbsp;</td>\n";
			echo "	<td>".escape($tier)."&nbsp;</td>\n";
			echo "	<td>".escape($lidb)."&nbsp;</td>\n";
			echo "	<td>".escape($notes)."&nbsp;</td>\n";
			if ($has_domain_for_display && !empty($domain_name)) {
				if ($is_parked_domain && permission_exists('bulkvs_purchase')) {
					// Show Unpark button for parked domain
					$unpark_url = "bulkvs_numbers.php?action=unpark&unpark_destination_uuid=".urlencode($destination_uuid)."&".$token['name']."=".$token['hash'];
					echo "	<td class='no-link' style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'>";
					echo button::create(['type'=>'button','class'=>'link','label'=>$text['label-unpark'],'link'=>$unpark_url,'onclick'=>'event.stopPropagation();']);
					echo "&nbsp;</td>\n";
				} else {
					// Show domain name for non-parked domains
					echo "	<td class='no-link' style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' title='".escape($domain_name)."'>";
					echo button::create(['type'=>'button','class'=>'link','label'=>escape($domain_name),'link'=>$destination_edit_url,'onclick'=>'event.stopPropagation();']);
					echo "&nbsp;</td>\n";
				}
			} else {
				// Show Disconnect | Park buttons if no domain and user has purchase permission
				if (permission_exists('bulkvs_purchase')) {
					$disconnect_modal_id = 'modal-disconnect-' . preg_replace('/[^0-9]/', '', $tn);
					$park_url = "bulkvs_numbers.php?action=park&park_tn=".urlencode($tn)."&".$token['name']."=".$token['hash'];
					echo "	<td class='no-link' style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'>";
					echo button::create(['type'=>'button','class'=>'link','label'=>$text['label-disconnect'],'onclick'=>"event.stopPropagation(); modal_open('".$disconnect_modal_id."');"]);
					echo " | ";
					echo button::create(['type'=>'button','class'=>'link','label'=>$text['label-park'],'link'=>$park_url,'onclick'=>'event.stopPropagation();']);
					echo "&nbsp;</td>\n";
				} else {
					echo "	<td style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'>&nbsp;</td>\n";
				}
			}
			
			// E911 information
			$e911_info = 'None';
			$e911_full_info = '';
			$tn_clean = preg_replace('/[^0-9]/', '', $tn); // Remove non-numeric characters for matching
			if (isset($e911_map[$tn_clean])) {
				$e911_record = $e911_map[$tn_clean];
				$caller_name = $e911_record['Caller Name'] ?? $e911_record['callerName'] ?? '';
				$address_line1 = $e911_record['Address Line 1'] ?? $e911_record['addressLine1'] ?? '';
				$city = $e911_record['City'] ?? $e911_record['city'] ?? '';
				$state = $e911_record['State'] ?? $e911_record['state'] ?? '';
				$zip = $e911_record['Zip'] ?? $e911_record['zip'] ?? '';
				
				// Build E911 display string
				$e911_parts = [];
				if (!empty($caller_name)) {
					$e911_parts[] = $caller_name;
				}
				if (!empty($address_line1)) {
					$e911_parts[] = $address_line1;
				}
				if (!empty($city) || !empty($state) || !empty($zip)) {
					$city_state_zip = trim($city . ', ' . $state . ' ' . $zip, ', ');
					if (!empty($city_state_zip)) {
						$e911_parts[] = $city_state_zip;
					}
				}
				
				if (!empty($e911_parts)) {
					$e911_full_info = implode(', ', $e911_parts);
					$e911_info = $e911_full_info;
				}
			}
			
			// Make E911 cell clickable if permission exists
			$e911_edit_url = '';
			if (permission_exists('bulkvs_edit')) {
				$e911_edit_url = "bulkvs_e911_edit.php?tn=".urlencode($tn);
			}
			
			if (!empty($e911_edit_url)) {
				echo "	<td class='no-link' style='max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' title='".escape($e911_full_info ?: $e911_info)."'>";
				echo button::create(['type'=>'button','class'=>'link','label'=>escape($e911_info),'link'=>$e911_edit_url,'onclick'=>'event.stopPropagation();']);
				echo "&nbsp;</td>\n";
			} else {
				echo "	<td style='max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' title='".escape($e911_full_info ?: $e911_info)."'>".escape($e911_info)."&nbsp;</td>\n";
			}
		
		echo "</tr>\n";
		}

		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
		if ($paging_controls != '') {
			echo "<div align='center'>".$paging_controls."</div>\n";
		}
		
		// Create disconnect modals for numbers without domains
		if (permission_exists('bulkvs_purchase')) {
			$disconnect_modals_created = [];
			foreach ($paginated_numbers as $number) {
				$tn = $number['TN'] ?? $number['tn'] ?? $number['telephoneNumber'] ?? '';
				if (empty($tn)) {
					continue;
				}
				
				// Check if this number has a domain (same logic as in table row)
				$tn_10 = preg_replace('/^1/', '', $tn);
				$has_domain = false;
				if (strlen($tn_10) == 10 && isset($domain_map[$tn_10])) {
					$domain_info = $domain_map[$tn_10];
					$destination_uuid = is_array($domain_info) ? ($domain_info['destination_uuid'] ?? '') : '';
					if (!empty($destination_uuid) && permission_exists('destination_edit')) {
						$has_domain = true;
					}
				}
				
				// Only create modal if no domain (and not already created)
				if (!$has_domain) {
					$disconnect_modal_id = 'modal-disconnect-' . preg_replace('/[^0-9]/', '', $tn);
					if (!isset($disconnect_modals_created[$disconnect_modal_id])) {
						$disconnect_modals_created[$disconnect_modal_id] = true;
						$disconnect_tn_escaped = escape($tn);
						echo modal::create([
							'id'=>$disconnect_modal_id,
							'type'=>'delete',
							'message'=>$text['message-disconnect-confirm'] . ' (' . $disconnect_tn_escaped . ')',
							'actions'=>button::create([
								'type'=>'button',
								'label'=>$text['button-continue'],
								'icon'=>'check',
								'style'=>'float: right; margin-left: 15px;',
								'collapse'=>'never',
								'onclick'=>"modal_close(); window.location.href='bulkvs_numbers.php?action=disconnect&disconnect_tn=".urlencode($tn)."&".$token['name']."=".$token['hash']."';"
							])
						]);
					}
				}
			}
		}
	} else {
		if (empty($error_message)) {
			echo "<div class='card'>\n";
			echo "	<div class='subheading'>".$text['message-no-numbers']."</div>\n";
			echo "</div>\n";
		}
	}

	echo "<br />\n";

//add JavaScript for background sync
	echo "<script type='text/javascript'>\n";
	echo "	// Function to refresh page and reset sync status\n";
	echo "	function refreshPage() {\n";
	echo "		// Reset last_record_count before reloading\n";
	echo "		var xhr = new XMLHttpRequest();\n";
	echo "		xhr.open('POST', 'bulkvs_sync.php', true);\n";
	echo "		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');\n";
	echo "		xhr.send('type=numbers&reset=1');\n";
	echo "		// Reload page immediately (don't wait for reset)\n";
	echo "		window.location.reload();\n";
	echo "	}\n";
	echo "	\n";
	echo "	// Trigger background sync on page load\n";
	echo "	(function() {\n";
	echo "		var syncType = 'numbers';\n";
	echo "		var xhr = new XMLHttpRequest();\n";
	echo "		xhr.open('GET', 'bulkvs_sync.php?type=' + syncType, true);\n";
	echo "		xhr.onreadystatechange = function() {\n";
	echo "			if (xhr.readyState === 4) {\n";
	echo "				if (xhr.status === 200) {\n";
	echo "					try {\n";
	echo "						var response = JSON.parse(xhr.responseText);\n";
	echo "						if (response.success && response.new_records !== 0) {\n";
	echo "							// Show refresh button\n";
	echo "							var refreshBtn = document.getElementById('refresh_button_container');\n";
	echo "							if (refreshBtn) {\n";
	echo "								refreshBtn.style.display = '';\n";
	echo "							}\n";
	echo "						}\n";
	echo "					} catch (e) {\n";
	echo "						// Ignore JSON parse errors\n";
	echo "					}\n";
	echo "				}\n";
	echo "			}\n";
	echo "		};\n";
	echo "		xhr.send();\n";
	echo "	})();\n";
	echo "</script>\n";

//include the footer
	require_once "resources/footer.php";

?>

