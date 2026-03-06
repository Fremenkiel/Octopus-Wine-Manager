<?php

namespace OctopusWineManagment\Admin;

/**
 * Adds a meta box to the 'omw_product' edit screen in the WordPress admin.
 *
 * Most wine meta is populated automatically by ProductSyncService on each sync,
 * but this meta box exposes the 'Country of Origin' field for manual correction
 * if the API returns an unexpected value.
 *
 * Meta key stored: _owm_country_origin
 */
class ProductMetaBox {
    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_country_meta_box']);
        add_action('save_post',      [$this, 'save_country_meta']);
    }

    /**
     * Registers the meta box on the 'omw_product' post type edit screen.
     * Placed in the sidebar ('side') at default priority.
     */
    public function add_country_meta_box() {
        add_meta_box(
            'owm_product_details',       // unique HTML id
            'Octopus Wine Details',      // box title
            [$this, 'render_meta_box'],  // callback
            'omw_product',               // post type
            'side',                      // context
            'default'                    // priority
        );
    }

    /**
     * Renders the meta box HTML.
     *
     * A nonce field is included so save_country_meta() can verify the submission
     * originated from this form and not from an external request.
     *
     * @param \WP_Post $post  The post being edited.
     */
    public function render_meta_box($post) {
        $origin = get_post_meta($post->ID, '_owm_country_origin', true);
        wp_nonce_field('owm_product_meta_nonce', 'owm_product_meta_nonce_field');
        ?>
        <p>
            <label for="owm_country_origin"><strong>Country of Origin:</strong></label><br>
            <input type="text" id="owm_country_origin" name="owm_country_origin"
                   value="<?php echo esc_attr($origin); ?>" class="widefat" />
        </p>
        <p class="description">This field is typically synced from the Octopus API.</p>
        <?php
    }

    /**
     * Saves the country of origin meta value when the post is saved.
     *
     * Guards against:
     *   • Missing or invalid nonce (CSRF protection)
     *   • WordPress autosave (would overwrite without user intent)
     *
     * @param int $post_id  ID of the post being saved.
     */
    public function save_country_meta($post_id) {
        // Verify nonce to prevent CSRF.
        if (!isset($_POST['owm_product_meta_nonce_field']) ||
            !wp_verify_nonce($_POST['owm_product_meta_nonce_field'], 'owm_product_meta_nonce')) {
            return;
        }

        // Skip autosaves — the user hasn't explicitly clicked "Update".
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        if (isset($_POST['owm_country_origin'])) {
            update_post_meta($post_id, '_owm_country_origin', sanitize_text_field($_POST['owm_country_origin']));
        }
    }
}
