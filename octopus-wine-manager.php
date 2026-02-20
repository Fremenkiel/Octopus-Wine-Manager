<?php

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\Services\ProductSyncService;

new \OctopusWineManagment\Admin\ProductMetaBox();

add_action('init', function() {
    register_post_type('omw_product', [
        'labels'      => ['name' => 'Octopus Wines'],
        'public'      => true,
        'has_archive' => true,
        'menu_icon'   => 'dashicons-cart',
        'supports'    => ['title', 'editor', 'thumbnail']
    ]);
});

if (!defined('ABSPATH')) exit;

if (is_admin()) {
    new \OctopusWineManagment\Admin\AdminSettings();
}

add_action('init', function() {
    register_post_type('hb_product', [
        'labels' => ['name' => 'HotelBoss Products'],
        'public' => true,
        'menu_icon' => 'dashicons-store',
        'supports' => ['title', 'editor', 'thumbnail']
    ]);
});

function omw_run_product_sync() {
    $company_id = get_option('omw_company_id');
    $client = new HotelBossClient($company_id);
    
    // Attempt authentication (Ideally token is cached in a transient)
    $client->authenticate(get_option('omw_username'), get_option('hb_password'));

    $sync_service = new ProductSyncService($client);
    $result = $sync_service->sync_all_products('da');
    
    return $result;
}
