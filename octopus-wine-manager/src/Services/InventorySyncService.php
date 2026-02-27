<?php

namespace OctopusWineManagment\Services;

use OctopusWineManagment\API\HotelBossClient;

class InventorySyncService {
    private $client;
    private $post_type = 'owm_product';

    public function __construct(HotelBossClient $client) {
        $this->client = $client;
    }

    /**
     * Fetches inventory movements and updates the stored quantity on each
     * matching owm_product post.
     *
     * @param string|null $dateTo ISO8601 date — pass null to fetch all movements
     * @return int|false Number of products updated, or false on error
     */
    public function sync_inventory($dateTo = null) {
        try {
            $movements = $this->client->getInventory($dateTo);

            // Aggregate net quantity per productID
            $quantities = [];
            foreach ($movements as $movement) {
                $id = $movement->productID;
                if ($id === null) {
                    continue;
                }
                if (!isset($quantities[$id])) {
                    $quantities[$id] = 0.0;
                }
                $quantities[$id] += (float) $movement->quantity;
            }

            $updated = 0;
            foreach ($quantities as $product_id => $qty) {
                $post_id = $this->get_post_id_by_api_id($product_id);
                if (!$post_id) {
                    continue;
                }
                update_post_meta($post_id, '_owm_quantity', $qty);
                $updated++;
            }

            update_option('owm_last_inventory_sync', current_time('mysql'));

            return $updated;
        } catch (\Exception $e) {
            error_log('HotelBoss Inventory Sync Error: ' . $e->getMessage());
            return false;
        }
    }

    private function get_post_id_by_api_id($api_id) {
        $query = new \WP_Query([
            'post_type'      => $this->post_type,
            'meta_key'       => '_owm_product_id',
            'meta_value'     => $api_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        return $query->have_posts() ? $query->posts[0] : null;
    }
}
