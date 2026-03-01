<?php

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\Services\ProductSyncService;
use OctopusWineManagment\Services\InventorySyncService;

new \OctopusWineManagment\Admin\ProductMetaBox();

if (!defined('ABSPATH')) exit;

add_action('init', function() {
    register_post_type('omw_product', [
        'labels'      => ['name' => 'Octopus Wines'],
        'public'      => false,
        'has_archive' => true,
        'supports'    => ['title', 'editor', 'thumbnail']
    ]);
});

if (is_admin()) {
    new \OctopusWineManagment\Admin\AdminSettings();
}

// Grant the owm_manage capability to administrators and editors.
// Roles are stored in the DB; the has_cap() guard prevents redundant writes.
add_action('init', function() {
    global $wp_roles;

    $exclude_array = ['subscriber', 'contributor'];

    $all_roles = $wp_roles->roles;
    foreach ($all_roles as $role_name=>$role_entry) {
        if (in_array($role_name, $exclude_array)) {
          continue;
        }
        $role = get_role($role_name);
        if ($role && !$role->has_cap('owm_manage')) {
            $role->add_cap('owm_manage');
        }
    }
});

function omw_run_product_sync() {
    $company_id = get_option('owm_company_id', '');
    $client = new HotelBossClient($company_id);

    $client->authenticate(get_option('owm_username'), get_option('owm_password'));

    $sync_service = new ProductSyncService($client);
    $result = $sync_service->sync_all_products('da');

    return $result;
}

// --- Hourly cron sync ---

function owm_schedule_hourly_sync() {
    if (!wp_next_scheduled('owm_hourly_sync')) {
        wp_schedule_event(time(), 'hourly', 'owm_hourly_sync');
    }
}
add_action('wp', 'owm_schedule_hourly_sync');

add_action('owm_hourly_sync', function() {
    $company_id = get_option('owm_company_id', '');
    $client = new HotelBossClient($company_id);
    $client->authenticate(get_option('owm_username'), get_option('owm_password'));

    $product_service = new ProductSyncService($client);
    $product_service->sync_all_products('da');

    $inventory_service = new InventorySyncService($client);
    $inventory_service->sync_inventory();
});

register_deactivation_hook(__FILE__, function() {
    $timestamp = wp_next_scheduled('owm_hourly_sync');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'owm_hourly_sync');
    }
});
