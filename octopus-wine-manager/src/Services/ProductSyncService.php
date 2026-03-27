<?php
namespace OctopusWineManagment\Services;

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\DTO\ProductDTO;

/**
 * Synchronises HotelBoss product data into WordPress custom posts (owm_product).
 *
 * Only products belonging to the configured product group IDs are imported.
 * Existing posts are updated (matched by _owm_product_id meta); new products
 * create new posts. Products deleted from HotelBoss are removed from WordPress
 * at the end of each full sync.* PdfGenerator to omit the product from the PDF.
 */
class ProductSyncService {
    private $client;

    /** WordPress custom post type used to store wine products. */
    private $post_type = 'owm_product';

    /**
     * Product group IDs from HotelBoss that should be imported.
     * Only products belonging to one of these groups are synced — other groups
     * (food, non-wine beverages, etc.) are silently skipped.
     *
     * To update this list, edit the array and re-run a manual sync.
     */
    private $product_group_filter = [7704, 7727, 7728, 7729, 7730, 7731, 7732, 7733, 7734, 7735, 7736, 7737, 7738];

    /**
     * @param HotelBossClient $client  Authenticated API client.
     */
    public function __construct(HotelBossClient $client) {
        $this->client = $client;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Fetches all products from the API, filters by product group, and upserts
     * each matching product into WordPress.
     *
     * Records the timestamp of a successful sync in the 'owm_last_sync' option
     * so the admin dashboard can display when data was last updated.
     *
     * @param string $lang  Language code for product names/descriptions (default 'en').
     * @return int|false    Number of products synced, or false on error.
     */
    public function sync_all_products($lang = 'en'): int | false {
        try {
              $products       = $this->client->getProducts($lang);
              $sync_count     = 0;
              $synced_api_ids = [];

            foreach ($products as $productDTO) {
                // Skip products that don't belong to a wine product group.
                if (!in_array($productDTO->productGroupID, $this->product_group_filter)) {
                    continue;
                }
                $this->sync_single_product($productDTO);
                $synced_api_ids[] = $productDTO->id;
                $sync_count++;
            }
          
            // Remove WordPress posts for products deleted from HotelBoss.
            $this->remove_deleted_products($synced_api_ids);

            // Record the timestamp for the admin status chip.
            update_option('owm_last_sync', current_time('mysql'));

            return $sync_count;
        } catch (\Exception $e) {
            error_log('HotelBoss Sync Error: ' . $e->getMessage());
            return false;
        }
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Upserts a single product: updates the existing owm_product post if one
     * matches the API product ID, otherwise creates a new post. All wine
     * attributes are stored as post meta with the '_owm_' prefix.
     *
     * Attribute values from the API may be single strings or arrays (when a
     * product has multiple values for the same attribute, e.g. multiple grape
     * varieties). Multiple values are joined with ', ' before storage.
     *
     * @param ProductDTO $dto  The product data transfer object to persist.
     */
    private function sync_single_product(ProductDTO $dto): void {
        // Check whether this product already exists in WordPress.
        $existing_post_id = $this->get_post_id_by_api_id($dto->id);

        $post_data = [
            'post_title'  => $dto->name,
            'post_status' => 'publish',
            'post_type'   => 'owm_product',
        ];

        if (!$dto->hasAttributes()) {
          if(isset($existing_post_id)) {
            $this->remove_wine($existing_post_id);
          }
          return;
        }

        // Update or insert the post as appropriate.
        $post_id = $existing_post_id
            ? wp_update_post(array_merge($post_data, ['ID' => $existing_post_id]))
            : wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            // Store core product fields.
            update_post_meta($post_id, '_owm_product_id',         $dto->id);
            update_post_meta($post_id, '_owm_price',              $dto->price);
            update_post_meta($post_id, '_owm_quantity',           $dto->quantity);
            update_post_meta($post_id, '_owm_storage_location',   sanitize_text_field($dto->storageLocation ?? ''));
            update_post_meta($post_id, '_owm_product_group_name', sanitize_text_field($dto->productGroupName ?? ''));

            // Store named API attributes as individual meta keys for easy querying.
            $country = $dto->getAttributeValues('Country');
            if ($country) {
              update_post_meta($post_id, '_owm_country_origin', sanitize_text_field(implode(', ', $country)));
            } else {
              delete_post_meta($post_id, '_owm_country_origin');
            }

            $region = $dto->getAttributeValues('Region');
            if ($region) {
              update_post_meta($post_id, '_owm_region', sanitize_text_field(implode(', ', $region)));
            } else {
              delete_post_meta($post_id, '_owm_region');
            }

            $commune = $dto->getAttributeValues('Commune');
            if ($commune) {
              update_post_meta($post_id, '_owm_commune', sanitize_text_field(implode(', ', $commune)));
            } else {
              delete_post_meta($post_id, '_owm_commune');
            }

            $village = $dto->getAttributeValues('Village');
            if ($village) {
              update_post_meta($post_id, '_owm_village', sanitize_text_field(implode(', ', $village)));
            } else {
              delete_post_meta($post_id, '_owm_village');
            }

            $wine = $dto->getAttributeValues('Wine');
            if ($wine) {
              update_post_meta($post_id, '_owm_wine', sanitize_text_field(implode(', ', $wine)));
            } else {
              delete_post_meta($post_id, '_owm_wine');
            }

            $vintage = $dto->getAttributeValues('Vintage');
            if ($vintage) {
              update_post_meta($post_id, '_owm_vintage', sanitize_text_field(implode(', ', $vintage)));
            } else {
              delete_post_meta($post_id, '_owm_vintage');
            }

            $producer = $dto->getAttributeValues('Producer');
            if ($producer) {
              update_post_meta($post_id, '_owm_producer', sanitize_text_field(implode(', ', $producer)));
            } else {
              delete_post_meta($post_id, '_owm_producer');
            }

            $alcohol = $dto->getAttributeValues('Alcohol');
            if ($alcohol) {
              update_post_meta($post_id, '_owm_alcohol', sanitize_text_field(implode(', ', $alcohol)));
            } else {
              delete_post_meta($post_id, '_owm_alcohol');
            }

            $appellation = $dto->getAttributeValues('Appellation');
            if ($appellation) {
              update_post_meta($post_id, '_owm_appellation', sanitize_text_field(implode(', ', $appellation)));
            } else {
              delete_post_meta($post_id, '_owm_appellation');
            }

            $classification = $dto->getAttributeValues('Classification');
            if ($classification) {
              update_post_meta($post_id, '_owm_classification', sanitize_text_field(implode(', ', $classification)));
            } else {
              delete_post_meta($post_id, '_owm_classification');
            }

            // Volume is a raw string like '0,75' — normalise to decimal point before storage.
            $volume = $dto->getAttribute('Volume (L)');
            if ($volume) {
              update_post_meta($post_id, '_owm_volume', sanitize_text_field(str_replace(',', '.', $volume)));
            } else {
              delete_post_meta($post_id, '_owm_volume');
            }

            $subRegion = $dto->getAttributeValues('Sub-Region');
            if ($subRegion) {
              update_post_meta($post_id, '_owm_sub_region', sanitize_text_field(implode(', ', $subRegion)));
            } else {
              delete_post_meta($post_id, '_owm_sub_region');
            }

            $vineyard = $dto->getAttributeValues('Vineyard');
            if ($vineyard) {
              update_post_meta($post_id, '_owm_vineyard', sanitize_text_field(implode(', ', $vineyard)));
            } else {
              delete_post_meta($post_id, '_owm_vineyard');
            }

            $sweetness = $dto->getAttributeValues('Sweetness');
            if ($sweetness) {
              update_post_meta($post_id, '_owm_sweetness', sanitize_text_field(implode(', ', $sweetness)));
            } else {
              delete_post_meta($post_id, '_owm_sweetness');
            }

            $grapeValues = $dto->getAttributeValues('Grape');
            if ($grapeValues) {
              update_post_meta($post_id, '_owm_grape', sanitize_text_field(implode(', ', $grapeValues)));
            } else {
              delete_post_meta($post_id, '_owm_grape');
            }

            // Also store the full raw attributes array for debugging/future use.
            update_post_meta($post_id, '_owm_all_attributes', $dto->attributes);
        }
    }

    /**
      * Deletes any owm_product posts whose API product ID is not present in the
      * latest sync. Posts without an _owm_product_id (e.g. manually created) are
      * left untouched.
      *
      * @param array $synced_api_ids  Product IDs returned by the current API call.
      */
      private function remove_deleted_products(array $synced_api_ids): void {
        $query = new \WP_Query([
          'post_type'      => $this->post_type,
          'posts_per_page' => -1,
          'fields'         => 'ids',
        ]);

        foreach ($query->posts as $post_id) {
          $api_id = get_post_meta($post_id, '_owm_product_id', true);
          if ($api_id !== '' && !in_array($api_id, $synced_api_ids)) {
            $this->remove_wine($post_id);
          }
        }
      }

    /**
     * Returns the WordPress post ID of an existing owm_product that was
     * previously imported from the given HotelBoss product ID, or null if none.
     *
     * @param int|string $api_id  The HotelBoss product ID (_owm_product_id meta value).
     * @return int|null
     */
    private function get_post_id_by_api_id($api_id): int | null {
        $query = new \WP_Query([
            'post_type'      => $this->post_type,
            'meta_key'       => '_owm_product_id',
            'meta_value'     => $api_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',  // only return IDs — no need to load full posts
        ]);

        return $query->have_posts() ? $query->posts[0] : null;
    }

    /**
     * Removes all saved post meta and post related to a wine.
     *
     * @param int|string $post_id  WP post id.
     * @return int|null
     */
    private function remove_wine($post_id): void {
        wp_delete_post($post_id);
        delete_post_meta($post_id, '_owm_product_id');
        delete_post_meta($post_id, '_owm_price');
        delete_post_meta($post_id, '_owm_vat_percent');
        delete_post_meta($post_id, '_owm_quantity');
        delete_post_meta($post_id, '_owm_storage_location');
        delete_post_meta($post_id, '_owm_product_group_name');
        delete_post_meta($post_id, '_owm_country_origin');
        delete_post_meta($post_id, '_owm_region');
        delete_post_meta($post_id, '_owm_commune');
        delete_post_meta($post_id, '_owm_village');
        delete_post_meta($post_id, '_owm_wine');
        delete_post_meta($post_id, '_owm_vintage');
        delete_post_meta($post_id, '_owm_producer');
        delete_post_meta($post_id, '_owm_alcohol');
        delete_post_meta($post_id, '_owm_appellation');
        delete_post_meta($post_id, '_owm_classification');
        delete_post_meta($post_id, '_owm_volume');
        delete_post_meta($post_id, '_owm_sub_region');
        delete_post_meta($post_id, '_owm_vineyard');
        delete_post_meta($post_id, '_owm_sweetness');
        delete_post_meta($post_id, '_owm_grape');
        delete_post_meta($post_id, '_owm_all_attributes');
    }
}
