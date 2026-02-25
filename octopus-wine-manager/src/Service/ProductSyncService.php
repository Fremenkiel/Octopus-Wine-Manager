<?php
namespace OctopusWineManagment\Services;

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\DTO\ProductDTO;

class ProductSyncService {
    private $client;
    private $post_type = 'owm_product';

    public function __construct(HotelBossClient $client) {
        $this->client = $client;
    }

    /**
     * Main execution method
     */
    public function sync_all_products($lang = 'en') {
        try {
            $products = $this->client->getProducts($lang);
            $sync_count = 0;

            foreach ($products as $productDTO) {
                $this->sync_single_product($productDTO);
                $sync_count++;
            }

            return $sync_count;
        } catch (\Exception $e) {
            error_log('HotelBoss Sync Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Maps DTO to WordPress Post
     */
    private function sync_single_product(ProductDTO $dto) {
        $existing_post_id = $this->get_post_id_by_api_id($dto->id);

        $post_data = [
            'post_title'   => $dto->name,
            'post_status'  => 'publish',
            'post_type'    => 'owm_product',
        ];

        $post_id = $existing_post_id ? wp_update_post(array_merge($post_data, ['ID' => $existing_post_id])) : wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, '_owm_product_id',       $dto->id);
            update_post_meta($post_id, '_owm_price',            $dto->price);
            update_post_meta($post_id, '_owm_vat_percent',      $dto->vatPercent);
            update_post_meta($post_id, '_owm_quantity',         $dto->quantity);
            update_post_meta($post_id, '_owm_storage_location', sanitize_text_field($dto->storageLocation ?? ''));
            update_post_meta($post_id, '_owm_product_group_name', sanitize_text_field($dto->productGroupName ?? ''));

            $origin_country = $dto->getAttribute('Country');
            if ($origin_country) {
                update_post_meta($post_id, '_owm_country_origin', sanitize_text_field($origin_country));
            }

            $region = $dto->getAttribute('Region') ?? $dto->getAttribute('Grape region') ?? '';
            update_post_meta($post_id, '_owm_region', sanitize_text_field($region));

            update_post_meta($post_id, '_owm_all_attributes', $dto->attributes);
        }
    }

    /**
     * Helper to find existing WP Post ID for a specific API ID
     */
    private function get_post_id_by_api_id($api_id) {
        $query = new \WP_Query([
            'post_type'  => $this->post_type,
            'meta_key'   => '_owm_product_id',
            'meta_value' => $api_id,
            'posts_per_page' => 1,
            'fields'     => 'ids'
        ]);

        return $query->have_posts() ? $query->posts[0] : null;
    }
}
