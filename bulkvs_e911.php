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
	if (!permission_exists('bulkvs_e911') && !permission_exists('bulkvs_e911_server') && !permission_exists('bulkvs_e911_all') && !permission_exists('bulkvs_e911_domain')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);

//process delete action
	$action = $_POST['action'] ?? $_GET['action'] ?? '';
	$delete_tn = $_POST['delete_tn'] ?? $_GET['delete_tn'] ?? '';
	
	if ($action == 'delete' && !empty($delete_tn) && permission_exists('bulkvs_purchase')) {
		// Validate token
		$object = new token;
		if (!$object->validate($_SERVER['PHP_SELF'])) {
			message::add("Invalid token", 'negative');
			header("Location: bulkvs_e911.php");
			return;
		}
		
		try {
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$result = $bulkvs_api->deleteE911Record($delete_tn);
			
			// Check if delete was successful
			$status = $result['Status'] ?? $result['status'] ?? '';
			if (strtoupper($status) === 'SUCCESS' || empty($status)) {
				// Update destination_type_emergency to 0 in v_destinations
				// Convert 11-digit TN to 10-digit (remove leading "1")
				$tn_10 = preg_replace('/^1/', '', $delete_tn);
				if (strlen($tn_10) == 10) {
					if (!isset($database) || $database === null) {
						$database = new database;
					}
					$sql = "update v_destinations ";
					$sql .= "set destination_type_emergency = 0 ";
					$sql .= "where destination_number = :destination_number ";
					$sql .= "and destination_type = 'inbound' ";
					$sql .= "and destination_enabled = 'true' ";
					$parameters = ['destination_number' => $tn_10];
					$database->execute($sql, $parameters);
					unset($sql, $parameters);
				}
				
				// Remove from cache
				require_once "resources/classes/bulkvs_cache.php";
				$cache = new bulkvs_cache($database, $settings);
				try {
					$cache->deleteE911($delete_tn);
				} catch (Exception $e) {
					// Ignore cache errors - API delete was successful
					error_log("BulkVS cache delete error: " . $e->getMessage());
				}
				
				message::add($text['message-e911-delete-success'], 'positive');
			} else {
				$error_msg = $result['Description'] ?? $result['description'] ?? 'Delete failed';
				throw new Exception($error_msg);
			}
		} catch (Exception $e) {
			message::add($text['message-api-error'] . ': ' . $e->getMessage(), 'negative');
		}
		
		// Reload the page
		header("Location: bulkvs_e911.php");
		return;
	}

//load cache class
	require_once "resources/classes/bulkvs_cache.php";
	$cache = new bulkvs_cache($database, $settings);

//get domain filter parameter
	$domain_filter = $_GET['domain_filter'] ?? '';
	$show_domain_only = false;
	
	// Determine if we should show domain-only based on permissions and domain_filter parameter
	if (permission_exists('bulkvs_e911_all')) {
		// User has "all" permission - check domain_filter parameter
		if ($domain_filter === 'domain') {
			$show_domain_only = true;
		}
	} elseif (permission_exists('bulkvs_e911_domain')) {
		// User only has domain permission - always show domain-only
		$show_domain_only = true;
	}

//get E911 records from cache (fallback to API if cache is empty)
	$e911_records = [];
	$error_message = '';
	$e911_map = [];
	
	try {
		// Try to load from cache first
		$e911_records = $cache->getE911Records();
		error_log("BulkVS E911: Loaded " . count($e911_records) . " records from cache");
		
		// If cache is empty, fetch from API directly
		if (empty($e911_records)) {
			error_log("BulkVS E911: Cache is empty, fetching from API...");
			require_once "resources/classes/bulkvs_api.php";
			$bulkvs_api = new bulkvs_api($settings);
			$api_response = $bulkvs_api->getE911Records();
			
			// Handle API response - it may be an array of records or an object with a data property
			if (isset($api_response['data']) && is_array($api_response['data'])) {
				$e911_records = $api_response['data'];
			} elseif (is_array($api_response)) {
				$e911_records = $api_response;
			}
			error_log("BulkVS E911: Loaded " . count($e911_records) . " records from API");
			
			// Try to sync to cache in background (don't block if it fails)
			try {
				$sync_result = $cache->syncE911();
				error_log("BulkVS E911: Background sync completed");
			} catch (Exception $sync_error) {
				error_log("BulkVS E911 background sync failed (non-blocking): " . $sync_error->getMessage());
			}
		}
		
		// Create a mapping of TN to E911 record
		foreach ($e911_records as $e911_record) {
			$e911_tn = $e911_record['TN'] ?? $e911_record['tn'] ?? '';
			if (!empty($e911_tn)) {
				$e911_map[$e911_tn] = $e911_record;
			}
		}
		
		// Filter E911 records based on permissions
		// Priority: bulkvs_e911_all > bulkvs_e911_server > bulkvs_e911/bulkvs_e911_domain
		// bulkvs_e911_all: show all records (no filtering) unless domain_filter=domain
		// bulkvs_e911_server: show only E911s with destinations on this server (any domain)
		// bulkvs_e911/bulkvs_e911_domain: show only E911s with destinations in current domain
		if ($show_domain_only || (!permission_exists('bulkvs_e911_all') && !permission_exists('bulkvs_e911_server'))) {
			if (!isset($database) || $database === null) {
				$database = new database;
			}
			
			$destination_numbers = [];
			
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
			foreach ($domain_destinations as $dest) {
				$dest_number = $dest['destination_number'] ?? '';
				if (!empty($dest_number)) {
					$destination_numbers[$dest_number] = true;
				}
			}
			
			// Filter E911 records to only include those with matching destinations
			if (!empty($destination_numbers)) {
				$filtered_e911_records = [];
				foreach ($e911_records as $e911_record) {
					$e911_tn = $e911_record['TN'] ?? $e911_record['tn'] ?? '';
					if (!empty($e911_tn)) {
						// Convert 11-digit to 10-digit (remove leading "1")
						$tn_10 = preg_replace('/^1/', '', $e911_tn);
						if (strlen($tn_10) == 10 && isset($destination_numbers[$tn_10])) {
							$filtered_e911_records[] = $e911_record;
						}
					}
				}
				$e911_records = $filtered_e911_records;
				
				// Rebuild e911_map with filtered records
				$e911_map = [];
				foreach ($e911_records as $e911_record) {
					$e911_tn = $e911_record['TN'] ?? $e911_record['tn'] ?? '';
					if (!empty($e911_tn)) {
						$e911_map[$e911_tn] = $e911_record;
					}
				}
			} else {
				// No destinations found, clear records
				$e911_records = [];
				$e911_map = [];
			}
		} elseif (!permission_exists('bulkvs_e911_all') && permission_exists('bulkvs_e911_server')) {
			// Get all destination numbers on this server (any domain)
			if (!isset($database) || $database === null) {
				$database = new database;
			}
			
			$destination_numbers = [];
			$sql = "select distinct destination_number ";
			$sql .= "from v_destinations ";
			$sql .= "where destination_type = 'inbound' ";
			$sql .= "and destination_enabled = 'true' ";
			$server_destinations = $database->select($sql, null, 'all');
			unset($sql);
			
			// Build set of 10-digit destination numbers
			foreach ($server_destinations as $dest) {
				$dest_number = $dest['destination_number'] ?? '';
				if (!empty($dest_number)) {
					$destination_numbers[$dest_number] = true;
				}
			}
			
			// Filter E911 records to only include those with matching destinations
			if (!empty($destination_numbers)) {
				$filtered_e911_records = [];
				foreach ($e911_records as $e911_record) {
					$e911_tn = $e911_record['TN'] ?? $e911_record['tn'] ?? '';
					if (!empty($e911_tn)) {
						// Convert 11-digit to 10-digit (remove leading "1")
						$tn_10 = preg_replace('/^1/', '', $e911_tn);
						if (strlen($tn_10) == 10 && isset($destination_numbers[$tn_10])) {
							$filtered_e911_records[] = $e911_record;
						}
					}
				}
				$e911_records = $filtered_e911_records;
				
				// Rebuild e911_map with filtered records
				$e911_map = [];
				foreach ($e911_records as $e911_record) {
					$e911_tn = $e911_record['TN'] ?? $e911_record['tn'] ?? '';
					if (!empty($e911_tn)) {
						$e911_map[$e911_tn] = $e911_record;
					}
				}
			} else {
				// No destinations found, clear records
				$e911_records = [];
				$e911_map = [];
			}
		}
	} catch (Exception $e) {
		$error_message = $e->getMessage();
		message::add($text['message-api-error'] . ': ' . $error_message, 'negative');
	}

//check if new records are available
	$has_new_e911 = false;
	try {
		$has_new_e911 = $cache->hasNewRecords('e911');
	} catch (Exception $e) {
		// Ignore errors checking for new records
	}

//get filter parameter
	$filter = $_GET['filter'] ?? $_POST['filter'] ?? '';

//get order and order by
	$order_by = $_GET['order_by'] ?? 'tn';
	$order = $_GET['order'] ?? 'asc';

//apply server-side filter to all E911 records
	if (!empty($filter)) {
		$filter_lower = strtolower($filter);
		$e911_records = array_filter($e911_records, function($record) use ($filter_lower) {
			$tn = strtolower($record['TN'] ?? $record['tn'] ?? '');
			$caller_name = strtolower($record['Caller Name'] ?? $record['callerName'] ?? '');
			$address_line1 = strtolower($record['Address Line 1'] ?? $record['addressLine1'] ?? '');
			$city = strtolower($record['City'] ?? $record['city'] ?? '');
			$state = strtolower($record['State'] ?? $record['state'] ?? '');
			$zip = strtolower($record['Zip'] ?? $record['zip'] ?? '');
			
			// Check if filter matches any field
			return (
				strpos($tn, $filter_lower) !== false ||
				strpos($caller_name, $filter_lower) !== false ||
				strpos($address_line1, $filter_lower) !== false ||
				strpos($city, $filter_lower) !== false ||
				strpos($state, $filter_lower) !== false ||
				strpos($zip, $filter_lower) !== false
			);
		});
		$e911_records = array_values($e911_records); // Re-index array
	}

//build domain lookup map for all E911 records (needed for sorting)
	$domain_map_all = [];
	if (!empty($e911_records)) {
		// Extract 10-digit numbers from BulkVS 11-digit numbers
		$tn_10_digit = [];
		foreach ($e911_records as $record) {
			$tn = $record['TN'] ?? $record['tn'] ?? '';
			if (!empty($tn)) {
				// Convert 11-digit to 10-digit (remove leading "1")
				$tn_10 = preg_replace('/^1/', '', $tn);
				if (strlen($tn_10) == 10) {
					$tn_10_digit[] = $tn_10;
				}
			}
		}
		
		// Query destinations for these numbers (only inbound)
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
			$sql .= "and destination_enabled = 'true' ";
			$sql .= "and destination_type = 'inbound' ";
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
	
	// Attach domain_name to each E911 record for sorting
	foreach ($e911_records as &$record) {
		$tn = $record['TN'] ?? $record['tn'] ?? '';
		if (!empty($tn)) {
			$tn_10 = preg_replace('/^1/', '', $tn);
			if (strlen($tn_10) == 10 && isset($domain_map_all[$tn_10])) {
				$record['_domain_name'] = $domain_map_all[$tn_10]['domain_name'] ?? '';
			} else {
				$record['_domain_name'] = '';
			}
		} else {
			$record['_domain_name'] = '';
		}
	}
	unset($record); // Break reference

//sort the E911 records array
	if (!empty($e911_records) && !empty($order_by)) {
		usort($e911_records, function($a, $b) use ($order_by, $order) {
			$value_a = '';
			$value_b = '';
			
			switch ($order_by) {
				case 'tn':
					$value_a = $a['TN'] ?? $a['tn'] ?? '';
					$value_b = $b['TN'] ?? $b['tn'] ?? '';
					break;
				case 'caller_name':
					$value_a = $a['Caller Name'] ?? $a['callerName'] ?? '';
					$value_b = $b['Caller Name'] ?? $b['callerName'] ?? '';
					break;
				case 'address':
					// Build address string for comparison
					$addr_a_parts = [];
					$addr_b_parts = [];
					if (!empty($a['Address Line 1'] ?? $a['addressLine1'] ?? '')) {
						$addr_a_parts[] = $a['Address Line 1'] ?? $a['addressLine1'] ?? '';
					}
					if (!empty($a['City'] ?? $a['city'] ?? '')) {
						$addr_a_parts[] = $a['City'] ?? $a['city'] ?? '';
					}
					if (!empty($a['State'] ?? $a['state'] ?? '')) {
						$addr_a_parts[] = $a['State'] ?? $a['state'] ?? '';
					}
					if (!empty($b['Address Line 1'] ?? $b['addressLine1'] ?? '')) {
						$addr_b_parts[] = $b['Address Line 1'] ?? $b['addressLine1'] ?? '';
					}
					if (!empty($b['City'] ?? $b['city'] ?? '')) {
						$addr_b_parts[] = $b['City'] ?? $b['city'] ?? '';
					}
					if (!empty($b['State'] ?? $b['state'] ?? '')) {
						$addr_b_parts[] = $b['State'] ?? $b['state'] ?? '';
					}
					$value_a = implode(', ', $addr_a_parts);
					$value_b = implode(', ', $addr_b_parts);
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
	$num_rows = count($e911_records);
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
	$paginated_e911_records = [];
	if (!empty($e911_records)) {
		$paginated_e911_records = array_slice($e911_records, $offset, $rows_per_page);
	}

//build domain lookup map for paginated records (use already-built domain_map_all)
	$domain_map = [];
	if (!empty($paginated_e911_records)) {
		foreach ($paginated_e911_records as $record) {
			$tn = $record['TN'] ?? $record['tn'] ?? '';
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
	$document['title'] = $text['label-e911'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['label-e911']."</b>";
	if ($num_rows > 0) {
		echo "<div class='count'>".number_format($num_rows)."</div>";
	}
	echo "</div>\n";
	echo "	<div class='actions'>\n";
	// Refresh button (hidden by default, shown when new records available)
	$refresh_button_style = $has_new_e911 ? '' : 'display: none;';
	echo "<span id='refresh_button_container' style='".$refresh_button_style."'>";
	echo button::create(['type'=>'button','label'=>$text['button-refresh'],'icon'=>'refresh','id'=>'btn_refresh','onclick'=>"refreshPage();"]);
	echo "</span>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>'arrow-left','link'=>'bulkvs_numbers.php']);
	if (permission_exists('bulkvs_edit')) {
		echo button::create(['type'=>'button','label'=>'Add E911','icon'=>'plus','link'=>'bulkvs_e911_edit.php']);
	}
	// Domain/All toggle button (only visible when bulkvs_e911_all permission exists)
	if (permission_exists('bulkvs_e911_all')) {
		$toggle_url = 'bulkvs_e911.php';
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
	if (!empty($e911_records)) {
		echo "		<form method='get' action='' style='display: inline; margin-left: 15px;'>\n";
		if ($show_domain_only) {
			echo "			<input type='hidden' name='domain_filter' value='domain'>\n";
		}
		echo "			<input type='text' name='filter' class='txt list-search' placeholder='Filter results...' value='".escape($filter)."' style='width: 200px;'>\n";
		echo "			<input type='submit' class='btn' value='Filter' style='margin-left: 5px;'>\n";
		if (!empty($filter)) {
			$clear_url = 'bulkvs_e911.php';
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

	if (!empty($error_message)) {
		echo "<div class='alert alert-warning'>".escape($error_message)."</div>\n";
		echo "<br />\n";
	}

	if (!empty($paginated_e911_records)) {
		echo "<div class='card'>\n";
		echo "<table class='list' id='e911_table'>\n";
		echo "<tr class='list-header'>\n";
		echo "	".th_order_by('tn', $text['label-telephone-number'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	".th_order_by('caller_name', $text['label-caller-name'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	".th_order_by('address', 'Address', $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "	".th_order_by('domain', $text['label-domain'], $order_by, $order, '', '', $th_order_by_param)."\n";
		echo "</tr>\n";

		foreach ($paginated_e911_records as $record) {
			$tn = $record['TN'] ?? $record['tn'] ?? '';
			if (empty($tn)) {
				continue;
			}
			
			$caller_name = $record['Caller Name'] ?? $record['callerName'] ?? '';
			$address_line1 = $record['Address Line 1'] ?? $record['addressLine1'] ?? '';
			$address_line2 = $record['Address Line 2'] ?? $record['addressLine2'] ?? '';
			$city = $record['City'] ?? $record['city'] ?? '';
			$state = $record['State'] ?? $record['state'] ?? '';
			$zip = $record['Zip'] ?? $record['zip'] ?? '';
			
			// Build address string
			$address_parts = [];
			if (!empty($address_line1)) {
				$address_parts[] = $address_line1;
			}
			if (!empty($address_line2)) {
				$address_parts[] = $address_line2;
			}
			if (!empty($city) || !empty($state) || !empty($zip)) {
				$city_state_zip = trim($city . ', ' . $state . ' ' . $zip, ', ');
				if (!empty($city_state_zip)) {
					$address_parts[] = $city_state_zip;
				}
			}
			$address = implode(', ', $address_parts);
			
			// Look up domain for this number
			$domain_name = '';
			$destination_uuid = '';
			$tn_10 = preg_replace('/^1/', '', $tn);
			if (strlen($tn_10) == 10 && isset($domain_map[$tn_10])) {
				$domain_info = $domain_map[$tn_10];
				$domain_name = is_array($domain_info) ? ($domain_info['domain_name'] ?? '') : $domain_info;
				$destination_uuid = is_array($domain_info) ? ($domain_info['destination_uuid'] ?? '') : '';
			}
			
			// Create destination edit URL
			$destination_edit_url = '';
			$has_domain_for_display = false;
			if (!empty($destination_uuid) && permission_exists('destination_edit')) {
				$destination_edit_url = "../destinations/destination_edit.php?id=".urlencode($destination_uuid);
				$has_domain_for_display = true;
			}
			
			// Create edit URL for the row
			$edit_url = '';
			if (permission_exists('bulkvs_edit')) {
				$edit_url = "bulkvs_e911_edit.php?tn=".urlencode($tn);
			}

			//show the data
			echo "<tr class='list-row'".(!empty($edit_url) ? " href='".$edit_url."'" : "").">\n";
			echo "	<td class='no-wrap'>".escape($tn)."</td>\n";
			echo "	<td>".escape($caller_name)."&nbsp;</td>\n";
			echo "	<td style='max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' title='".escape($address)."'>".escape($address)."&nbsp;</td>\n";
			
			if ($has_domain_for_display && !empty($domain_name)) {
				echo "	<td class='no-link' style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' title='".escape($domain_name)."'>";
				echo button::create(['type'=>'button','class'=>'link','label'=>escape($domain_name),'link'=>$destination_edit_url,'onclick'=>'event.stopPropagation();']);
				echo "&nbsp;</td>\n";
			} else {
				// Show Delete button if no domain and user has purchase permission
				if (permission_exists('bulkvs_purchase')) {
					$delete_modal_id = 'modal-e911-delete-' . preg_replace('/[^0-9]/', '', $tn);
					echo "	<td class='no-link' style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'>";
					echo button::create(['type'=>'button','class'=>'link','label'=>$text['button-delete'],'onclick'=>"event.stopPropagation(); modal_open('".$delete_modal_id."');"]);
					echo "&nbsp;</td>\n";
				} else {
					echo "	<td style='max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'>&nbsp;</td>\n";
				}
			}
			
			echo "</tr>\n";
		}

		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
		if ($paging_controls != '') {
			echo "<div align='center'>".$paging_controls."</div>\n";
		}
		
		// Create delete modals for records without domains
		if (permission_exists('bulkvs_purchase')) {
			$delete_modals_created = [];
			foreach ($paginated_e911_records as $record) {
				$tn = $record['TN'] ?? $record['tn'] ?? '';
				if (empty($tn)) {
					continue;
				}
				
				// Check if this number has a domain
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
					$delete_modal_id = 'modal-e911-delete-' . preg_replace('/[^0-9]/', '', $tn);
					if (!isset($delete_modals_created[$delete_modal_id])) {
						$delete_modals_created[$delete_modal_id] = true;
						$delete_tn_escaped = escape($tn);
						echo modal::create([
							'id'=>$delete_modal_id,
							'type'=>'delete',
							'message'=>$text['message-e911-delete-confirm'] . ' (' . $delete_tn_escaped . ')',
							'actions'=>button::create([
								'type'=>'button',
								'label'=>$text['button-continue'],
								'icon'=>'check',
								'style'=>'float: right; margin-left: 15px;',
								'collapse'=>'never',
								'onclick'=>"modal_close(); window.location.href='bulkvs_e911.php?action=delete&delete_tn=".urlencode($tn)."&".$token['name']."=".$token['hash']."';"
							])
						]);
					}
				}
			}
		}
	} else {
		if (empty($error_message)) {
			echo "<div class='card'>\n";
			echo "	<div class='subheading'>No E911 records found</div>\n";
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
	echo "		xhr.send('type=e911&reset=1');\n";
	echo "		// Reload page immediately (don't wait for reset)\n";
	echo "		window.location.reload();\n";
	echo "	}\n";
	echo "	\n";
	echo "	// Trigger background sync on page load\n";
	echo "	(function() {\n";
	echo "		var syncType = 'e911';\n";
	echo "		var xhr = new XMLHttpRequest();\n";
	echo "		xhr.open('GET', 'bulkvs_sync.php?type=' + syncType, true);\n";
	echo "		xhr.onreadystatechange = function() {\n";
	echo "			if (xhr.readyState === 4) {\n";
	echo "				if (xhr.status === 200) {\n";
	echo "					try {\n";
	echo "						var response = JSON.parse(xhr.responseText);\n";
	echo "						if (response.success && response.new_records > 0) {\n";
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
