<?php
namespace OctopusWineManagment\Services;

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\DTO\ProductDTO;

class ProductSyncService {
    private $client;
    private $post_type = 'owm_product';
    private $product_group_filter = [7704, 7727, 7728, 7729, 7730, 7731, 7732, 7733, 7734, 7735, 7736, 7737, 7738];

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
                if (!in_array($productDTO->productGroupID, $this->product_group_filter)) {
                  continue;
                }
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

            $country = $dto->getAttributeValues('Country');
            if ($country) {
                update_post_meta($post_id, '_owm_country_origin', sanitize_text_field(implode(', ', $country)));
            }

            $region = $dto->getAttributeValues('Region');
            if ($region) {
                update_post_meta($post_id, '_owm_region', sanitize_text_field(implode(', ', $region)));
            }

            $commune = $dto->getAttributeValues('Commune');
            if ($commune) {
                update_post_meta($post_id, '_owm_commune', sanitize_text_field(implode(', ', $commune)));
            }

            $village = $dto->getAttributeValues('Village');
            if ($village) {
                update_post_meta($post_id, '_owm_village', sanitize_text_field(implode(', ', $village)));
            }

            $wine = $dto->getAttributeValues('Wine');
            if ($wine) {
                update_post_meta($post_id, '_owm_wine', sanitize_text_field(implode(', ', $wine)));
            }

            $vintage = $dto->getAttributeValues('Vintage');
            if ($vintage) {
                update_post_meta($post_id, '_owm_vintage', sanitize_text_field(implode(', ', $vintage)));
            }

            $producer = $dto->getAttributeValues('Producer');
            if ($producer) {
                update_post_meta($post_id, '_owm_producer', sanitize_text_field(implode(', ', $producer)));
            }

            $alcohol = $dto->getAttributeValues('Alcohol');
            if ($alcohol) {
                update_post_meta($post_id, '_owm_alcohol', sanitize_text_field(implode(', ', $alcohol)));
            }

            $appellation = $dto->getAttributeValues('Appellation');
            if ($appellation) {
                update_post_meta($post_id, '_owm_appellation', sanitize_text_field(implode(', ', $appellation)));
            }

            $classification = $dto->getAttributeValues('Classification');
            if ($classification) {
                update_post_meta($post_id, '_owm_classification', sanitize_text_field(implode(', ', $classification)));
            }

            $volume = $dto->getAttribute('Volume (L)');
            if ($volume) {
                update_post_meta($post_id, '_owm_volume', sanitize_text_field(str_replace(',', '.', $volume)));
            }

            $subRegion = $dto->getAttributeValues('Sub-Region');
            if ($subRegion) {
                update_post_meta($post_id, '_owm_sub_region', sanitize_text_field(implode(', ', $subRegion)));
            }

            $vineyard = $dto->getAttributeValues('Vineyard');
            if ($vineyard) {
                update_post_meta($post_id, '_owm_vineyard', sanitize_text_field(implode(', ', $vineyard)));
            }

            $sweetness = $dto->getAttributeValues('Sweetness');
            if ($sweetness) {
                update_post_meta($post_id, '_owm_sweetness', sanitize_text_field(implode(', ', $sweetness)));
            }

            $grapeValues = $dto->getAttributeValues('Grape');
            if (!empty($grapeValues)) {
                update_post_meta($post_id, '_owm_grape', sanitize_text_field(implode(', ', $grapeValues)));
            }

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
