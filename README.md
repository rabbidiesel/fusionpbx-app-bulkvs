# BulkVS FusionPBX App

A FusionPBX application that integrates with the BulkVS API to manage phone numbers, update Portout PIN and CNAM information, purchase new phone numbers, and manage E911 records directly from the FusionPBX interface.

## Overview

This app provides seamless integration between FusionPBX and BulkVS, allowing administrators to:

- View all phone numbers in their BulkVS account filtered by trunk group
- Update Portout PIN, LIDB/CNAM, SMS/MMS settings, and Notes for individual numbers
- Search for available phone numbers by area code (NPA) or area code + exchange (NPANXX)
- Purchase phone numbers and automatically create destinations in FusionPBX
- View and manage E911 records for phone numbers
- Edit E911 records with address validation

## Features

- **Number Management**: View and manage all BulkVS numbers filtered by configured trunk group
  - Display status, activation date, rate center, tier, LIDB, notes, domain, and E911 information
  - Click on a number row to edit number details
  - Click on domain to edit the destination
  - Click on E911 record in the table to edit E911 information for that number
  - Access E911 management page via the E911 button in the action bar
- **Number Editing**: Update LIDB/CNAM, Portout PIN, SMS/MMS settings, and Notes for individual numbers
- **Number Search**: Search for available numbers by NPA (3-digit area code) or NPANXX (6-digit area code + exchange)
- **Number Purchase**: Purchase numbers directly from the interface with automatic destination creation
  - Configure purchase settings: Domain, LIDB, Portout PIN, Reference ID
  - Automatically creates destination with proper context and prefix
  - Redirects to destination edit page after purchase
- **E911 Management**: View and manage E911 records
  - Access E911 management from the Numbers page action bar or directly from the menu
  - View E911 information in the numbers table
  - Edit existing E911 records from the table or E911 page
  - Create new E911 records for any number
  - Address validation before saving
  - SMS number configuration for E911 records
- **Server-Side Pagination**: Efficient pagination for large result sets
- **Server-Side Filtering**: Filter all results, not just the current page
- **Permission-Based Access**: Granular permissions for viewing, editing, searching, and purchasing
- **Database Caching**: Fast page loads with automatic background synchronization
  - Numbers and E911 records cached in PostgreSQL for instant display
  - Background sync keeps cache up-to-date without blocking the UI
  - Refresh button appears when new or deleted records are detected
  - Cache automatically matches API data exactly (adds new, removes deleted)
- **FusionPBX Standards**: Built using FusionPBX frameworks and follows standard app patterns
- **No External Dependencies**: Uses standard PHP cURL library (no additional packages required)

## Database Caching System

The app includes a sophisticated caching system that stores BulkVS numbers and E911 records in PostgreSQL for fast page loads. This eliminates the need to wait for slow API calls when viewing large datasets.

### How It Works

1. **Initial Load**: When you first open the Numbers or E911 page, data is loaded instantly from the cache
2. **Background Sync**: After the page loads, a background sync automatically updates the cache with the latest data from the BulkVS API
3. **Change Detection**: If new records are added or existing records are deleted, a refresh button appears at the top of the page
4. **Cache Synchronization**: The cache is kept in sync with the API - new numbers are added, deleted/disconnected numbers are removed

### Cache Tables

The caching system creates three tables in your PostgreSQL database:

- **v_bulkvs_numbers_cache**: Stores cached number data
- **v_bulkvs_e911_cache**: Stores cached E911 record data
- **v_bulkvs_sync_status**: Tracks sync status and prevents concurrent syncs

These tables are automatically created when you run the FusionPBX upgrade after installing/updating the app.

### Refresh Button

The refresh button appears in the action bar when:
- New numbers are added to your BulkVS account
- Numbers are deleted or disconnected
- E911 records are added or removed

Clicking the refresh button reloads the page with the latest cached data and resets the change detection.

### Manual Cache Management

The cache is automatically managed, but you can manually trigger a sync by:
- Reloading the page (triggers background sync)
- Using the AJAX sync endpoint: `bulkvs_sync.php?type=numbers` or `bulkvs_sync.php?type=e911`

### Cache Behavior

- **Empty Cache**: If the cache is empty, data is fetched directly from the API for immediate display, then a background sync populates the cache
- **Stale Sync Detection**: If a sync appears stuck (running for more than 2 minutes), it's automatically reset
- **Exact Match**: The cache always matches the API exactly - deleted numbers are automatically removed from the cache
- **Trunk Group Filtering**: Numbers cache respects trunk group filtering - only numbers for your configured trunk group are cached

## Requirements

- FusionPBX installation
- PHP with cURL extension enabled
- PostgreSQL database (for caching)
- BulkVS API account with API credentials
- Valid BulkVS trunk group configured

## Installation

1. Navigate to the FusionPBX app directory:
   ```bash
   cd /var/www/fusionpbx/app
   ```

2. Clone the repository:
   ```bash
   git clone https://github.com/eliweaver732/fusionpbx-app-bulkvs.git
   ```

3. Rename the directory:
   ```bash
   mv fusionpbx-app-bulkvs bulkvs
   ```

4. Navigate to **Advanced > Upgrade** in FusionPBX and run the upgrade to register the app

5. Configure the app settings (see Configuration section below)

## Configuration

Before using the app, you must configure the BulkVS API credentials and trunk group in FusionPBX Default Settings:

1. Navigate to **Advanced > Default Settings**
2. Configure the following settings under the `bulkvs` category:

   - **bulkvs/api_key**: Your BulkVS API Key/Username
   - **bulkvs/api_secret**: Your BulkVS API Secret/Password
   - **bulkvs/trunk_group**: Your BulkVS Trunk Group name for this server(case-sensitive)
   - **bulkvs/api_url**: BulkVS API URL (default: `https://portal.bulkvs.com/api/v1.0`)

## Permissions

The app uses the following permissions:

- **bulkvs_view**: View BulkVS numbers list
- **bulkvs_edit**: Edit number details and E911 records
- **bulkvs_search**: Search for available numbers
- **bulkvs_purchase**: Purchase numbers

Assign these permissions to user groups as needed through **Advanced > Groups**.

## Usage

### Viewing Numbers

1. Navigate to **Apps > BulkVS > Numbers**
2. All numbers filtered by your configured trunk group will be displayed
3. Use the filter box to search through numbers
4. Click on a number row to edit its details
5. Click on the domain to edit the destination
6. Click on the E911 record in the table to edit E911 information for a specific number
7. Use the **E911** button in the action bar (top left) to view and manage all E911 records

### Editing a Number

1. Click on a number row in the numbers table
2. Update the following fields:
   - **LIDB**: Caller ID Name (up to 15 alphanumeric characters and spaces, auto-uppercased)
   - **Portout PIN**: Port-out PIN for number porting
   - **Notes**: Reference ID or notes
   - **SMS**: Enable/disable SMS
   - **MMS**: Enable/disable MMS
3. Click **Save**

### Searching for Numbers

1. Navigate to **Apps > BulkVS > Search & Purchase Numbers**
2. Enter 3 digits (area code) or 6 digits (area code + exchange) in the search field
3. Configure purchase settings (if you have purchase permission):
   - **Domain**: Select the domain for the destination
   - **LIDB**: Caller ID Name (optional)
   - **Portout PIN**: 8-digit PIN (auto-generated if empty)
   - **Reference ID**: Notes or reference (optional)
4. Click **Search**
5. Review the search results
6. Click **Purchase** next to a number to purchase it

### Purchasing a Number

1. Search for available numbers (see Searching for Numbers above)
2. Configure purchase settings in the form
3. Click **Purchase** next to the desired number
4. The number will be:
   - Purchased in BulkVS and assigned to your trunk group
   - Automatically created as a destination in the selected FusionPBX domain
   - Created with context set to 'public' and prefix set to '1'
   - Destination description set to the Reference ID
   - Ready to use for routing calls
5. You will be redirected to the destination edit page

### Managing E911 Records

You can access E911 management in two ways:

**From the Numbers Page:**
1. Navigate to **Apps > BulkVS > Numbers**
2. Click the **E911** button in the action bar (top left) to view all E911 records, or
3. Click on the E911 record (or "None" if no record exists) in the table for a specific number

**From the E911 Page:**
1. Navigate to **Apps > BulkVS > E911** (or click the E911 button from the Numbers page)
2. View all E911 records or click on a record to edit it

**Editing/Creating E911 Records:**
1. From either the Numbers page or E911 page, click on an E911 record (or "None" to create new)
2. Fill in the E911 information:
   - **Caller Name**: Name associated with the E911 record
   - **Street Number**: Street number
   - **Street Name**: Street name
   - **Location**: Suite, unit, etc. (optional)
   - **City**: City name
   - **State**: State abbreviation (2 letters)
   - **Zip**: ZIP code
   - **SMS Numbers**: Comma-separated list of SMS numbers (optional)
4. Click **Save**
5. The address will be validated first, then the E911 record will be saved

## File Structure

```
bulkvs/
├── app_config.php              # App configuration, permissions, default settings, and database schema
├── app_menu.php                # Menu items for the app
├── app_languages.php           # Language strings for UI elements
├── bulkvs_numbers.php          # Main numbers list page
├── bulkvs_number_edit.php     # Number edit page
├── bulkvs_e911_edit.php        # E911 record edit page
├── bulkvs_search.php           # Search and purchase page
├── bulkvs_sync.php             # AJAX endpoint for background synchronization
└── resources/
    └── classes/
        ├── bulkvs_api.php      # BulkVS API client class
        └── bulkvs_cache.php    # Database caching and synchronization class
```

## API Client

The `bulkvs_api` class provides methods for interacting with the BulkVS API:

- `getNumbers($trunk_group)`: Retrieve numbers filtered by trunk group
- `getNumber($tn)`: Get details for a specific number
- `updateNumber($tn, $lidb, $portout_pin, $reference_id, $sms, $mms)`: Update number details
- `searchNumbers($npa, $nxx)`: Search for available numbers
- `purchaseNumber($tn, $trunk_group, $lidb, $portout_pin, $reference_id)`: Purchase a number
- `getE911Records()`: Get all E911 records
- `getE911Record($tn)`: Get E911 record for a specific number
- `validateAddress($address_data)`: Validate an address and get AddressID
- `saveE911Record($tn, $caller_name, $address_id, $sms)`: Save/update E911 record

## Cache Client

The `bulkvs_cache` class provides methods for managing cached data:

- `getNumbers($trunk_group)`: Retrieve numbers from cache filtered by trunk group
- `getE911Records()`: Retrieve E911 records from cache
- `syncNumbers($trunk_group)`: Synchronize numbers from API to cache (background)
- `syncE911()`: Synchronize E911 records from API to cache (background)
- `hasChanges($sync_type)`: Check if records have changed (added or deleted)
- `hasNewRecords($sync_type)`: Check if new records exist (legacy method)
- `getSyncStatus($sync_type)`: Get sync status for numbers or E911
- `resetLastRecordCount($sync_type)`: Reset change detection after refresh
- `clearCache($sync_type)`: Clear cache for a sync type
- `updateNumber($tn, $number_data)`: Update a single number in cache
- `deleteNumber($tn)`: Delete a number from cache
- `updateE911($tn, $e911_data)`: Update a single E911 record in cache
- `deleteE911($tn)`: Delete an E911 record from cache

## API Documentation

For detailed information about the BulkVS API, refer to the official documentation:
https://portal.bulkvs.com/api/v1.0/openapi

## Troubleshooting

### Numbers Not Appearing

- Verify your API credentials are correct in Default Settings
- Ensure the trunk group matches exactly (case-sensitive)
- Check that your BulkVS account has numbers assigned to the specified trunk group

### API Errors

- Verify your API credentials are valid
- Check that your BulkVS account has sufficient permissions
- Ensure the API URL is correct (default should work)
- Check FusionPBX error logs for detailed error messages

### Purchase Failures

- Ensure the trunk group is configured in Default Settings
- Verify you have permissions to purchase numbers in BulkVS
- Check that you have sufficient account balance (if required by BulkVS)
- Verify domain permissions if purchasing to a different domain
- Ensure a domain is selected before clicking Purchase

### E911 Record Issues

- Verify address information is complete and accurate
- Check that the address validation returns "GEOCODED" status
- Ensure state is a valid 2-letter abbreviation
- Check that ZIP code is valid

### Cache Issues

- **Cache Not Updating**: If the cache appears stale, reload the page to trigger a background sync
- **Sync Stuck**: If sync appears stuck, you can force reset it by accessing `bulkvs_sync.php?type=numbers&force_reset=1` or `bulkvs_sync.php?type=e911&force_reset=1`
- **Missing Data**: If data doesn't appear in cache, check PostgreSQL logs and FusionPBX error logs for database errors
- **Refresh Button Not Appearing**: Ensure the sync completed successfully - check sync status in the database table `v_bulkvs_sync_status`
- **Deleted Numbers Still Showing**: The cache should automatically remove deleted numbers, but if they persist, reload the page to trigger a sync

## License

This project is licensed under the Mozilla Public License Version 1.1 (MPL 1.1).
