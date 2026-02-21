<?php

namespace OctopusWineManagment\Admin;

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\Services\ProductSyncService;

class AdminSettings {
    private $option_group = 'owm_settings_group';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function add_menu_page(): void {
        add_menu_page(
            'OWM Settings',
            'Octopus Wine Managment',
            'manage_options',
            'owm-settings',
            [$this, 'render_admin_page'],
            'dashicons-admin-plugins'
        );
    }

    public function register_settings(): void {
        register_setting($this->option_group, 'owm_company_id');
        register_setting($this->option_group, 'owm_username');
        register_setting($this->option_group, 'owm_password');
        register_setting($this->option_group, 'owm_selected_hotel_id');

        add_settings_section('owm_auth_section', 'API Authentication', null, 'owm-settings');
        
        add_settings_field('owm_company_id', 'Company ID', [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_company_id']);
        add_settings_field('owm_username', 'Username', [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_username']);
        add_settings_field('owm_password', 'Password', [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_password', 'type' => 'password']);
        
        add_settings_section('owm_sync_section', 'Synchronization', null, 'owm-settings');
        add_settings_field('owm_hotel_id', 'Assign Hotel', [$this, 'render_hotel_dropdown'], 'owm-settings', 'owm_sync_section');
    }
    
    public function enqueue_admin_assets($hook) {
      // Only load on our specific settings page
      if ('toplevel_page_owm-settings' !== $hook) {
        return;
      }
    
      wp_enqueue_style(
        'owm-admin-styles', 
        plugin_dir_url(dirname(__DIR__, 1)) . 'assets/styles/owm-admin.css', 
        [], 
        '1.0.0'
      );
    }

    // Generic Input Renderer
    public function render_input($args): void {
        $val = get_option($args['name']);
        $type = $args['type'] ?? 'text';
        printf('<input type="%s" name="%s" value="%s" class="regular-text" />', $type, $args['name'], esc_attr($val));
    }

    // Hotel Dropdown Renderer
    public function render_hotel_dropdown(): void {
        $selected = get_option('owm_selected_hotel_id');
        $hotels = get_transient('owm_hotel_list'); // Cache list to avoid API hits on every page load

        if (!$hotels) {
            echo '<p class="description">Authenticate and save settings to load hotels.</p>';
            return;
        }

        echo '<select name="owm_selected_hotel_id">';
        echo '<option value="">-- Select Hotel --</option>';
        foreach ($hotels as $hotel) {
            printf('<option value="%s" %s>%s</option>', 
                $hotel['id'], 
                selected($selected, $hotel['id'], false), 
                esc_html($hotel['name'])
            );
        }
        echo '</select>';
    }

    public function render_admin_page(): void {
        // Handle Manual Sync Trigger
        if (isset($_POST['owm_manual_sync']) && check_admin_referer('owm_sync_action')) {
            $this->handle_sync();
        }

        // Fetch Hotels if authenticated
        if (get_option('owm_username') && !get_transient('owm_hotel_list')) {
            $this->refresh_hotel_list();
        }

        ?>
    <div class="wrap owm-settings-wrap">
        <h1>Octopus API Integration</h1>
        
        <div class="owm-sync-status">
            <strong>System Status:</strong> 
            <?php echo get_option('owm_last_sync') ?: 'Never synced'; ?>
        </div>

        <form method="post" action="options.php">
            <?php
            settings_fields($this->option_group);
            do_settings_sections('owm-settings');
            submit_button('Update Settings');
            ?>
        </form>

        <hr style="margin: 40px 0; border: 0; border-top: 1px solid #e2e8f0;">

        <h2>Data Operations</h2>
        <form method="post">
            <?php wp_nonce_field('owm_sync_action'); ?>
            <p class="description" style="margin-bottom: 15px;">
                This will pull all products and their attributes (like Origin Country) from the assigned hotel.
            </p>
            <input type="submit" name="owm_manual_sync" class="button button-secondary" value="⚡ Sync Products Now">
        </form>
    </div>
    <?php
    }

    private function refresh_hotel_list(): void {
        try {
            $client = new HotelBossClient(get_option('owm_company_id'));
            if ($client->authenticate(get_option('owm_username'), get_option('owm_password'))) {
                $hotels = $client->getHotels();
                set_transient('owm_hotel_list', $hotels, HOUR_IN_SECONDS);
            }
        } catch (\Exception $e) {
            // Silence error in dropdown
        }
    }

    private function handle_sync(): void {
        $client = new HotelBossClient(get_option('owm_company_id'));
        try {
            $client->authenticate(get_option('owm_username'), get_option('owm_password'));
            $sync = new ProductSyncService($client);
            $count = $sync->sync_all_products();
            echo "<div class='updated'><p>Successfully synced $count products!</p></div>";
        } catch (\Exception $e) {
            echo "<div class='error'><p>Sync failed: " . $e->getMessage() . "</p></div>";
        }
    }
}
