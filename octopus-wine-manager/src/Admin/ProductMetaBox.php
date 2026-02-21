<?php

namespace OctopusWineManagment\Admin;

class ProductMetaBox {
    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_country_meta_box']);
        add_action('save_post', [$this, 'save_country_meta']);
    }

    public function add_country_meta_box() {
        add_meta_box(
            'owm_product_details',
            'Octopus Wine Details',
            [$this, 'render_meta_box'],
            'omw_product',
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        $origin = get_post_meta($post->ID, '_owm_country_origin', true);
        wp_nonce_field('owm_product_meta_nonce', 'owm_product_meta_nonce_field');
        ?>
        <p>
            <label for="owm_country_origin"><strong>Country of Origin:</strong></label><br>
            <input type="text" id="owm_country_origin" name="owm_country_origin" value="<?php echo esc_attr($origin); ?>" class="widefat" />
        </p>
        <p class="description">This field is typically synced from the Octopus API.</p>
        <?php
    }

    public function save_country_meta($post_id) {
        if (!isset($_POST['owm_product_meta_nonce_field']) || !wp_verify_nonce($_POST['owm_product_meta_nonce_field'], 'owm_product_meta_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        
        if (isset($_POST['owm_country_origin'])) {
            update_post_meta($post_id, '_owm_country_origin', sanitize_text_field($_POST['owm_country_origin']));
        }
    }
}
