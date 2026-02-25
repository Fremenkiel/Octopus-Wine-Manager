<?php

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\Services\ProductSyncService;

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

function omw_run_product_sync() {
    $company_id = get_option('owm_company_id', '');
    $client = new HotelBossClient($company_id);
    
    $client->authenticate(get_option('owm_username'), get_option('owm_password'));

    $sync_service = new ProductSyncService($client);
    $result = $sync_service->sync_all_products('da');
    
    return $result;
}
