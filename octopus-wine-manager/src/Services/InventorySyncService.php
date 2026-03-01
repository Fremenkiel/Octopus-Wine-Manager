<?php

namespace OctopusWineManagment\Services;

use OctopusWineManagment\API\HotelBossClient;

/**
 * Synchronises HotelBoss inventory (stock quantity) into WordPress post meta.
 *
 * The HotelBoss inventory API returns a list of movement records rather than
 * absolute quantities. Each record is a delta (+/-) against the running stock
 * level. This service sums all movements per product ID to derive the current
 * quantity and writes it to the '_owm_quantity' post meta key.
 *
 * PdfGenerator excludes products with a quantity < 1 from the wine list, so
 * running this sync ensures sold-out wines disappear automatically on the next
 * PDF generation.
 *
 * Sync frequency: hourly via WP-Cron (scheduled in both AdminSettings and
 * octopus-wine-managment.php). Can also be triggered manually from the admin page.
 */
class InventorySyncService {
    private $client;

    /** WordPress custom post type used to store wine products. */
    private $post_type = 'owm_product';

    /**
     * @param HotelBossClient $client  Authenticated API client.
     */
    public function __construct(HotelBossClient $client) {
        $this->client = $client;
    }

    /**
     * Fetches all inventory movement records from the API, aggregates the net
     * quantity per product, and updates the '_owm_quantity' post meta for any
     * matching owm_product posts.
     *
     * Products that exist in the API movements but have no corresponding
     * WordPress post are silently skipped (they were likely filtered out during
     * product sync by the product_group_filter).
     *
     * @param string|null $dateTo  Optional ISO8601 upper date bound. Pass null to
     *                             fetch all movements (full stock recalculation).
     * @return int|false  Number of owm_product posts updated, or false on error.
     */
    public function sync_inventory($dateTo = null) {
        try {
            $movements = $this->client->getInventory($dateTo);

            // Aggregate net quantity per productID from all movement records.
            $quantities = [];
            foreach ($movements as $movement) {
                $id = $movement->productID;
                if ($id === null) {
                    continue; // skip movements without a product reference
                }
                if (!isset($quantities[$id])) {
                    $quantities[$id] = 0.0;
                }
                // Each movement's quantity is a signed delta (positive = stock in,
                // negative = stock out / sold).
                $quantities[$id] += (float) $movement->quantity;
            }

            // Write the computed quantities to the matching WordPress posts.
            $updated = 0;
            foreach ($quantities as $product_id => $qty) {
                $post_id = $this->get_post_id_by_api_id($product_id);
                if (!$post_id) {
                    continue; // product not imported — skip
                }
                update_post_meta($post_id, '_owm_quantity', $qty);
                $updated++;
            }

            // Record the timestamp so the admin dashboard status chip updates.
            update_option('owm_last_inventory_sync', current_time('mysql'));

            return $updated;
        } catch (\Exception $e) {
            error_log('HotelBoss Inventory Sync Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Looks up a WordPress post ID by the HotelBoss product ID stored in
     * '_owm_product_id' post meta. Returns null if no match is found.
     *
     * @param int|string $api_id  HotelBoss product ID.
     * @return int|null
     */
    private function get_post_id_by_api_id($api_id) {
        $query = new \WP_Query([
            'post_type'      => $this->post_type,
            'meta_key'       => '_owm_product_id',
            'meta_value'     => $api_id,
            'posts_per_page' => 1,
            'fields'         => 'ids', // only load IDs to keep the query lightweight
        ]);

        return $query->have_posts() ? $query->posts[0] : null;
    }
}
