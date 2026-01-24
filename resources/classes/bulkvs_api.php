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

/**
 * BulkVS API Client
 */
class bulkvs_api {

	/**
	 * Settings object set in the constructor
	 * @var settings Settings Object
	 */
	private $settings;

	/**
	 * API base URL
	 * @var string
	 */
	private $api_url;

	/**
	 * API Key
	 * @var string
	 */
	private $api_key;

	/**
	 * API Secret
	 * @var string
	 */
	private $api_secret;

	/**
	 * Called when the object is created
	 */
	public function __construct($settings = null) {
		//set settings object
		if ($settings === null) {
			$this->settings = new settings(['database' => database::new()]);
		} else {
			$this->settings = $settings;
		}

		//get API credentials from settings
		$this->api_url = $this->settings->get('bulkvs', 'api_url', 'https://portal.bulkvs.com/api/v1.0');
		$this->api_key = $this->settings->get('bulkvs', 'api_key', '');
		$this->api_secret = $this->settings->get('bulkvs', 'api_secret', '');
	}

	/**
	 * Make an API request
	 * @param string $method HTTP method (GET, POST, PUT, DELETE)
	 * @param string $endpoint API endpoint
	 * @param array $data Request data (for POST/PUT)
	 * @param bool $return_empty_on_404 If true, return empty array on 404 instead of throwing exception
	 * @return array Response data
	 */
	private function request($method, $endpoint, $data = null, $return_empty_on_404 = false) {
		$url = rtrim($this->api_url, '/') . '/' . ltrim($endpoint, '/');

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERPWD, $this->api_key . ':' . $this->api_secret);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		$headers = [];
		$headers[] = 'Content-Type: application/json';
		$headers[] = 'Accept: application/json';

		if ($method === 'POST' || $method === 'PUT') {
			if ($data !== null) {
				$json_data = json_encode($data);
				error_log("BulkVS API Request - Method: $method, URL: $url, Data: " . $json_data);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
			}
			if ($method === 'POST') {
				curl_setopt($ch, CURLOPT_POST, true);
			} else {
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			}
		} elseif ($method === 'GET' && $data !== null) {
			// For GET requests, append data as query parameters
			$query_string = http_build_query($data);
			if ($query_string) {
				$url .= '?' . $query_string;
				curl_setopt($ch, CURLOPT_URL, $url);
			}
		} elseif ($method === 'DELETE') {
			// For DELETE requests, append data as query parameters
			if ($data !== null && is_array($data)) {
				$query_string = http_build_query($data);
				if ($query_string) {
					$url .= '?' . $query_string;
					curl_setopt($ch, CURLOPT_URL, $url);
				}
			}
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
			error_log("BulkVS API Request - Method: DELETE, URL: $url");
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		// Log response for debugging
		error_log("BulkVS API Response - HTTP Code: $http_code, Response: " . substr($response, 0, 500));

		if ($error) {
			throw new Exception("BulkVS API Error: " . $error);
		}

		// Handle 404 responses - return empty array if configured to do so
		if ($http_code == 404 && $return_empty_on_404) {
			return [];
		}

		// Handle empty responses (some endpoints may return empty body on success)
		if (empty(trim($response))) {
			if ($http_code >= 400) {
				throw new Exception("BulkVS API Error: HTTP Error $http_code");
			}
			return [];
		}

		$result = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception("BulkVS API Error: Invalid JSON response - " . json_last_error_msg() . " (Response: " . substr($response, 0, 200) . ")");
		}

		if ($http_code >= 400) {
			// Try to extract error message from various possible fields
			$error_msg = "HTTP Error $http_code";
			if (isset($result['message'])) {
				$error_msg = $result['message'];
			} elseif (isset($result['Description'])) {
				$error_msg = $result['Description'];
			} elseif (isset($result['error'])) {
				$error_msg = $result['error'];
			} elseif (isset($result['Code']) && isset($result['Description'])) {
				$error_msg = "Code " . $result['Code'] . ": " . $result['Description'];
			} elseif (!empty($result)) {
				$error_msg = "Error: " . json_encode($result);
			}
			throw new Exception("BulkVS API Error: " . $error_msg);
		}

		return $result;
	}

	/**
	 * Get numbers filtered by trunk group
	 * @param string $trunk_group Trunk group name
	 * @return array Array of number records
	 */
	public function getNumbers($trunk_group = null) {
		$params = [];
		if (!empty($trunk_group)) {
			$params['Trunk Group'] = $trunk_group;
		}
		return $this->request('GET', '/tnRecord', $params);
	}

	/**
	 * Get a single number record by telephone number
	 * @param string $tn Telephone number
	 * @return array Number record
	 */
	public function getNumber($tn) {
		$params = ['Number' => $tn];
		$result = $this->request('GET', '/tnRecord', $params);
		// API returns an array, get first element if it exists
		if (is_array($result) && !empty($result)) {
			return $result[0];
		}
		return $result;
	}

	/**
	 * Update a number's fields
	 * @param string $tn Telephone number (required)
	 * @param string $lidb LIDB/CNAM value (optional)
	 * @param string $portout_pin Portout PIN (optional)
	 * @param string $reference_id Notes/ReferenceID (optional)
	 * @param bool $sms SMS enabled (optional)
	 * @param bool $mms MMS enabled (optional)
	 * @param string $tcr Campaign/TCR (optional)
	 * @param string $webhook Webhook name (optional)
	 * @return array Response data
	 */
	public function updateNumber($tn, $lidb = null, $portout_pin = null, $reference_id = null, $sms = null, $mms = null, $tcr = null, $webhook = null) {
		$data = ['TN' => $tn];
		
		if ($lidb !== null) {
			$data['Lidb'] = $lidb;
		}
		if ($portout_pin !== null) {
			$data['Portout Pin'] = $portout_pin;
		}
		if ($reference_id !== null) {
			$data['ReferenceID'] = $reference_id;
		}
		if ($sms !== null) {
			$data['Sms'] = $sms ? true : false;
		}
		if ($mms !== null) {
			$data['Mms'] = $mms ? true : false;
		}
		if ($tcr !== null) {
			$data['Tcr'] = $tcr;
		}
		if ($webhook !== null) {
			$data['Webhook'] = $webhook;
		}
		
		return $this->request('POST', '/tnRecord', $data);
	}

	/**
	 * Search for available numbers
	 * @param string $npa Area code (3 digits)
	 * @param string $nxx Exchange code (3 digits, used with npa for 6-digit search)
	 * @return array Array of available numbers (empty array if none found)
	 */
	public function searchNumbers($npa = null, $nxx = null) {
		$params = [];
		if (!empty($npa)) {
			$params['Npa'] = $npa; // API uses capital N, lowercase pa
		}
		if (!empty($nxx)) {
			$params['Nxx'] = $nxx; // API uses capital N, lowercase xx
		}
		if (empty($params)) {
			throw new Exception("NPA must be provided");
		}
		// Pass true for return_empty_on_404 - no available numbers returns 404
		return $this->request('GET', '/orderTn', $params, true);
	}

	/**
	 * Purchase a number
	 * @param string $tn Telephone number to purchase
	 * @param string $trunk_group Trunk group to assign the number to
	 * @param string $lidb LIDB/CNAM value (optional)
	 * @param string $portout_pin Portout PIN (optional)
	 * @param string $reference_id Reference ID/Notes (optional)
	 * @return array Response data
	 */
	public function purchaseNumber($tn, $trunk_group, $lidb = '', $portout_pin = '', $reference_id = '') {
		// Always include all fields exactly as the API expects
		$data = [
			'TN' => $tn,
			'Trunk Group' => $trunk_group,
			'Lidb' => trim($lidb),
			'Portout Pin' => trim($portout_pin),
			'ReferenceID' => trim($reference_id),
			'Sms' => false,
			'Mms' => false,
			'Webhook' => '' // Send empty webhook field as required
		];
		
		// Log the data being sent
		error_log("BulkVS purchaseNumber() - Sending data: " . json_encode($data));
		
		return $this->request('POST', '/orderTn', $data);
	}

	/**
	 * Get all E911 records
	 * @return array Array of E911 records
	 */
	public function getE911Records() {
		return $this->request('GET', '/e911Record', []);
	}

	/**
	 * Get a specific E911 record by TN
	 * @param string $tn Telephone number
	 * @return array E911 record data or empty array if not found
	 */
	public function getE911Record($tn) {
		$result = $this->request('GET', '/e911Record', ['TN' => $tn]);
		// API may return array of records or single record
		if (is_array($result)) {
			// If it's an array with one element, check if it matches the requested TN
			if (count($result) == 1 && isset($result[0])) {
				$record_tn = $result[0]['TN'] ?? $result[0]['tn'] ?? '';
				// Only return if it matches the requested TN
				if ($record_tn == $tn) {
					return $result[0];
				}
				// If it doesn't match, return empty array
				return [];
			}
			// If it's an array with multiple elements, find the matching TN
			if (count($result) > 1) {
				foreach ($result as $record) {
					$record_tn = $record['TN'] ?? $record['tn'] ?? '';
					if ($record_tn == $tn) {
						return $record;
					}
				}
				// No matching TN found
				return [];
			}
		}
		// If result is not an array or is empty, return empty array
		return [];
	}

	/**
	 * Validate an address
	 * @param array $address_data Address data with Street Number, Street Name, Location, City, State, Zip
	 * @return array Validation response with Status, AddressID, and normalized address
	 */
	public function validateAddress($address_data) {
		return $this->request('POST', '/validateAddress', $address_data);
	}

	/**
	 * Save/update an E911 record
	 * @param string $tn Telephone number
	 * @param string $caller_name Caller name
	 * @param string $address_id AddressID from validateAddress
	 * @param array $sms Array of SMS numbers (optional)
	 * @return array Response data
	 */
	public function saveE911Record($tn, $caller_name, $address_id, $sms = []) {
		$data = [
			'TN' => $tn,
			'Caller Name' => $caller_name,
			'AddressID' => $address_id
		];
		if (!empty($sms) && is_array($sms)) {
			$data['Sms'] = $sms;
		}
		return $this->request('POST', '/e911Record', $data);
	}

	/**
	 * Delete a telephone number
	 * @param string $tn Telephone number to delete
	 * @return array Response data
	 */
	public function deleteNumber($tn) {
		return $this->request('DELETE', '/tnRecord', ['Number' => $tn]);
	}

	/**
	 * Delete an E911 record
	 * @param string $tn Telephone number
	 * @return array Response data
	 */
	public function deleteE911Record($tn) {
		return $this->request('DELETE', '/e911Record', ['Number' => $tn]);
	}

	/**
	 * Get list of webhooks
	 * @return array Array of webhook records
	 */
	public function getWebhooks() {
		return $this->request('GET', '/webHooks', []);
	}

	/**
	 * Get list of campaigns
	 * @return array Array of campaign records
	 */
	public function getCampaigns() {
		return $this->request('GET', '/campaigns', []);
	}
}

?>
