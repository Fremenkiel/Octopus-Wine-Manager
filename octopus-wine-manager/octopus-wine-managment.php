<?php

// Load Composer's autoloader so all namespaced classes under src/ and vendor/ are available.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\Services\ProductSyncService;
use OctopusWineManagment\Services\InventorySyncService;

// Instantiate the meta box immediately so its hooks register before 'init'.
new \OctopusWineManagment\Admin\ProductMetaBox();

// Bail early if WordPress has not been loaded (direct file access).
if (!defined('ABSPATH')) exit;

// ── Custom Post Type ─────────────────────────────────────────────────────────

// Register 'owm_product' as a private custom post type used to store wine
// products synced from the HotelBoss API. Posts are not publicly accessible;
// they exist only to hold wine meta data for the PDF export.
add_action('init', function() {
    register_post_type('omw_product', [
        'labels'      => ['name' => 'Octopus Wines'],
        'public'      => false,   // not listed in nav menus or search results
        'has_archive' => true,
        'supports'    => ['title', 'editor', 'thumbnail']
    ]);
});

// ── Capability Setup ─────────────────────────────────────────────────────────

// Grant the custom 'owm_manage' capability to all roles except subscribers and
// contributors. This capability gates the plugin's admin pages and settings.
// Roles are stored in the database; the has_cap() guard prevents redundant writes
// on every request once the capability has already been added.
add_action('init', function() {
    global $wp_roles;

    // Roles that should NOT have access to the plugin UI.
    $exclude_array = ['subscriber', 'contributor'];

    $all_roles = $wp_roles->roles;
    foreach ($all_roles as $role_name => $role_entry) {
        if (in_array($role_name, $exclude_array)) {
            continue;
        }
        $role = get_role($role_name);
        // Only write to the DB if the capability is missing.
        if ($role && !$role->has_cap('owm_manage')) {
            $role->add_cap('owm_manage');
        }
    }
});

// ── Admin UI ─────────────────────────────────────────────────────────────────

// Load the settings page only inside the admin, avoiding unnecessary overhead
// on front-end requests.
if (is_admin()) {
    new \OctopusWineManagment\Admin\AdminSettings();
}

// ── Manual Sync Helper ───────────────────────────────────────────────────────

/**
 * Authenticates against the HotelBoss API and runs a full product sync for
 * the Danish ('da') language. Returns the number of products synced, or false
 * on failure.
 *
 * @return int|false
 */
function omw_run_product_sync() {
    $company_id = get_option('owm_company_id', '');
    $client = new HotelBossClient($company_id);

    // Retrieve stored credentials and obtain a bearer token.
    $client->authenticate(get_option('owm_username'), get_option('owm_password'));

    $sync_service = new ProductSyncService($client);
    $result = $sync_service->sync_all_products('da');

    return $result;
}

// ── Hourly Cron Sync ─────────────────────────────────────────────────────────

/**
 * Schedules the hourly WP-Cron event 'owm_hourly_sync' if it is not already
 * queued. Hooked to 'wp' so it runs on every front-end request until the event
 * is registered.
 */
function owm_schedule_hourly_sync() {
    if (!wp_next_scheduled('owm_hourly_sync')) {
        wp_schedule_event(time(), 'hourly', 'owm_hourly_sync');
    }
}
add_action('wp', 'owm_schedule_hourly_sync');

// Callback for the hourly cron event: syncs both products and inventory.
add_action('owm_hourly_sync', function() {
    $company_id = get_option('owm_company_id', '');
    $client = new HotelBossClient($company_id);
    $client->authenticate(get_option('owm_username'), get_option('owm_password'));

    // Sync the product catalogue (names, prices, attributes).
    $product_service = new ProductSyncService($client);
    $product_service->sync_all_products('da');

    // Sync inventory quantities (stock levels via movement records).
    $inventory_service = new InventorySyncService($client);
    $inventory_service->sync_inventory();
});

// ── Public PDF REST Endpoint ─────────────────────────────────────────────────

// Registers a public, unauthenticated GET endpoint that streams the wine-list
// PDF directly to the browser.
//
// URL:  /wp-json/owm/v1/pdf
// Auth: none — accessible by anyone with the URL.
//
// mPDF sends its own Content-Type and Content-Disposition headers, so all
// existing output buffers must be flushed before calling generate().
add_action('rest_api_init', function() {
    register_rest_route('owm/v1', '/pdf', [
        'methods'             => 'GET',
        'callback'            => function() {
            // Flush any open output buffers so mPDF can write its headers cleanly.
            while (ob_get_level()) ob_end_clean();
            try {
                $generator = new \OctopusWineManagment\PDF\PdfGenerator();
                $generator->generate(); // streams PDF and exits
            } catch (\Exception $e) {
                status_header(500);
                echo 'PDF generation failed: ' . esc_html($e->getMessage());
            }
            exit;
        },
        // No authentication required — the endpoint is intentionally public so
        // the wine list PDF can be embedded or linked to from external pages.
        'permission_callback' => '__return_true',
    ]);
});

// ── Deactivation Cleanup ─────────────────────────────────────────────────────

// Remove the scheduled cron event when the plugin is deactivated so WordPress
// does not continue firing it after the plugin is gone.
register_deactivation_hook(__FILE__, function() {
    $timestamp = wp_next_scheduled('owm_hourly_sync');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'owm_hourly_sync');
    }
});
