<?php

namespace OctopusWineManagment\Admin;

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\Services\ProductSyncService;
use OctopusWineManagment\Services\InventorySyncService;
use OctopusWineManagment\PDF\PdfGenerator;
use OctopusWineManagment\WineFields;

class AdminSettings {
    private $option_group = 'owm_settings_group';

    public function __construct() {
        add_action('admin_menu',             [$this, 'add_menu_page']);
        add_action('admin_init',             [$this, 'register_settings']);
        add_action('admin_init',             [$this, 'process_pdf_template_save']);
        add_action('admin_enqueue_scripts',  [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_owm_generate_pdf',      [$this, 'handle_generate_pdf']);
        add_action('admin_action_owm_download_pdf', [$this, 'handle_download_pdf_direct']);
        add_action('owm_scheduled_inventory_sync', [$this, 'run_scheduled_inventory_sync']);
        if (!wp_next_scheduled('owm_scheduled_inventory_sync')) {
            wp_schedule_event(time(), 'hourly', 'owm_scheduled_inventory_sync');
        }
        // Allow owm_manage capability (held by admins + editors) to save these settings.
        add_filter('option_page_capability_' . $this->option_group, fn() => 'owm_manage');
    }

    public function add_menu_page(): void {
        $icon = 'dashicons-admin-plugins';
        $svg_path = dirname(__DIR__, 2) . '/assets/images/logo.svg';
        if (file_exists($svg_path)) {
            $svg = file_get_contents($svg_path);
            // Strip any inline style and replace currentColor so WP admin
            // theming (colour scheme filters) can recolour the icon correctly.
            $svg = preg_replace('/\s*style="[^"]*"/', '', $svg);
            $svg = str_replace('fill="currentColor"', 'fill="#a7aaad"', $svg);
            $icon = 'data:image/svg+xml;base64,' . base64_encode($svg);
        }

        add_menu_page(
            'OWM Settings',
            'Octopus Wine',
            'owm_manage',
            'owm-settings',
            [$this, 'render_admin_page'],
            $icon
        );

        add_submenu_page(
            'owm-settings',
            'PDF Template',
            'PDF Template',
            'owm_manage',
            'owm-pdf-template',
            [$this, 'render_pdf_template_page']
        );

        // Direct-download link in the admin sidebar menu
        global $submenu;
        $submenu['owm-settings'][] = [
            'Download Wine List PDF',
            'owm_manage',
            wp_nonce_url(admin_url('admin.php?action=owm_download_pdf'), 'owm_download_pdf_direct'),
        ];
    }

    public function register_settings(): void {
        register_setting($this->option_group, 'owm_company_id');
        register_setting($this->option_group, 'owm_username');
        register_setting($this->option_group, 'owm_password');
        register_setting($this->option_group, 'owm_selected_hotel_id');
        register_setting($this->option_group, 'owm_pdf_template');
        register_setting($this->option_group, 'owm_type_order', [
            'sanitize_callback' => function ($value) {
                $arr = is_array($value) ? $value : [];
                return wp_json_encode(array_values(array_map('sanitize_text_field', $arr)));
            },
        ]);

        add_settings_section('owm_auth_section', 'API Authentication', null, 'owm-settings');

        add_settings_field('owm_company_id', 'Company ID',  [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_company_id']);
        add_settings_field('owm_username',   'Username',    [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_username']);
        add_settings_field('owm_password',   'Password',    [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_password', 'type' => 'password']);

        add_settings_section('owm_sync_section', 'Synchronization', null, 'owm-settings');
        add_settings_field('owm_hotel_id', 'Assign Hotel', [$this, 'render_hotel_dropdown'], 'owm-settings', 'owm_sync_section');

        add_settings_section('owm_type_section', 'Wine Type Order', [$this, 'render_type_order_section_description'], 'owm-settings');
        add_settings_field('owm_type_order', 'Type Sort Order', [$this, 'render_type_order_field'], 'owm-settings', 'owm_type_section');
    }

    public function enqueue_admin_assets($hook): void {
        $owm_pages = ['toplevel_page_owm-settings', 'octopus-wine_page_owm-pdf-template'];
        if (!in_array($hook, $owm_pages, true)) {
            return;
        }

        wp_enqueue_style(
            'owm-admin-styles',
            plugin_dir_url(dirname(__DIR__, 1)) . 'assets/styles/owm-admin.css',
            [],
            '1.0.1'
        );

        // Sortable type-order list on the main settings page
        if ($hook === 'toplevel_page_owm-settings') {
            wp_enqueue_script('jquery-ui-sortable');
            wp_add_inline_script('jquery-ui-sortable', '
jQuery(function($) {
    $("#owm-type-order-list").sortable({
        handle: ".owm-drag-handle",
        placeholder: "owm-sort-placeholder",
        forcePlaceholderSize: true
    });
});
');
        }

        // Only load Fabric.js and the canvas editor on the PDF template page
        if ($hook === 'octopus-wine_page_owm-pdf-template') {
            wp_enqueue_script(
                'owm-admin-fabric',
                'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js',
                [],
                '5.3.1',
                true
            );

            wp_enqueue_script(
                'owm-admin-pdf-canvas',
                plugin_dir_url(dirname(__DIR__, 1)) . 'assets/scripts/pdf-canvas.js',
                ['owm-admin-fabric'],
                '1.1.0',
                true
            );

            wp_localize_script('owm-admin-pdf-canvas', 'owmTemplateData', [
                'template' => get_option('owm_pdf_template', ''),
                'ajaxUrl'  => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('owm_generate_pdf_nonce'),
                'fontUrl'  => plugin_dir_url(dirname(__DIR__, 1)) . 'assets/fonts/tw-cen-mt-regular.ttf',
            ]);
        }
    }

    public function render_type_order_section_description(): void {
        echo '<p class="owm-field-note">Set the order in which wine types appear in the PDF. Drag to reorder, then save.</p>';
    }

    public function render_type_order_field(): void {
        // Collect distinct type values from published owm_product posts
        global $wpdb;
        $meta_values = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type = 'owm_product'
               AND p.post_status = 'publish'
               AND pm.meta_value != ''
             ORDER BY pm.meta_value ASC",
            '_owm_product_group_name'
        ));

        // Load saved order and merge
        $saved = json_decode(get_option('owm_type_order', '[]'), true) ?: [];

        // Put unsaved types (not in $saved) in alphabetical order at the end
        $unlisted = array_diff($meta_values, $saved);
        sort($unlisted);
        $ordered = array_unique(array_merge($saved, $unlisted));

        if (empty($ordered)) {
            echo '<p class="owm-field-note">No wine types found. Sync products first.</p>';
            return;
        }

        echo '<ul id="owm-type-order-list">';
        foreach ($ordered as $type) {
            printf(
                '<li>'
                . '<span class="owm-drag-handle">&#9776;</span>'
                . '<span>%s</span>'
                . '<input type="hidden" name="owm_type_order[]" value="%s">'
                . '</li>',
                esc_html($type),
                esc_attr($type)
            );
        }
        echo '</ul>';
        echo '<p class="owm-field-note">Drag to reorder. Types not listed here will follow alphabetically.</p>';
    }

    public function render_input($args): void {
        $val  = get_option($args['name']);
        $type = $args['type'] ?? 'text';
        printf('<input type="%s" name="%s" value="%s" class="regular-text" />', $type, $args['name'], esc_attr($val));
    }

    public function render_hotel_dropdown(): void {
        $selected = get_option('owm_selected_hotel_id');
        $hotels   = get_transient('owm_hotel_list');

        if (!$hotels) {
            echo '<p class="description">Authenticate and save settings to load hotels.</p>';
            return;
        }

        echo '<select name="owm_selected_hotel_id">';
        echo '<option value="">-- Select Hotel --</option>';
        foreach ($hotels as $hotel) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($hotel['id']),
                selected($selected, $hotel['id'], false),
                esc_html($hotel['name'])
            );
        }
        echo '</select>';
    }

    public function render_admin_page(): void {
        if (isset($_POST['owm_manual_sync']) && check_admin_referer('owm_sync_action')) {
            $this->handle_sync();
        }

        if (isset($_POST['owm_manual_inventory_sync']) && check_admin_referer('owm_sync_action')) {
            $this->handle_inventory_sync();
        }

        if (isset($_POST['owm_manual_sync_all']) && check_admin_referer('owm_sync_action')) {
            $this->handle_sync();
            $this->handle_inventory_sync();
        }

        if (get_option('owm_username') && !get_transient('owm_hotel_list')) {
            $this->refresh_hotel_list();
        }

        $last_products  = get_option('owm_last_sync') ?: '';
        $last_inventory = get_option('owm_last_inventory_sync') ?: '';
        ?>
        <div class="wrap owm-settings-wrap">

            <div class="owm-page-header">
                <div>
                    <h1 class="owm-page-title">Wine Manager</h1>
                    <p class="owm-page-subtitle">Octopus API sync &amp; PDF export</p>
                </div>
                <div class="owm-status-group">
                    <div class="owm-status-chip">
                        <span class="owm-dot <?php echo $last_products ? 'owm-dot--ok' : 'owm-dot--idle'; ?>"></span>
                        <strong>Products</strong>
                        <?php echo esc_html($last_products ?: 'Never synced'); ?>
                    </div>
                    <div class="owm-status-chip">
                        <span class="owm-dot <?php echo $last_inventory ? 'owm-dot--ok' : 'owm-dot--idle'; ?>"></span>
                        <strong>Inventory</strong>
                        <?php echo esc_html($last_inventory ?: 'Never synced'); ?>
                    </div>
                </div>
            </div>

            <!-- Export -->
            <div class="owm-card">
                <div class="owm-card-label">Export</div>
                <div class="owm-card-content">
                    <p class="owm-card-desc">Generate the wine list PDF from the saved template.</p>
                    <div class="owm-card-actions">
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=owm_download_pdf'), 'owm_download_pdf_direct')); ?>"
                           class="owm-btn owm-btn--dark">
                            <svg width="13" height="13" viewBox="0 0 13 13" fill="none" aria-hidden="true">
                                <path d="M6.5 1v7.5M4 6l2.5 2.5L9 6M1.5 11h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Download PDF
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=owm-pdf-template')); ?>" class="owm-text-link">
                            Edit template &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Configuration -->
            <div class="owm-card">
                <div class="owm-card-label">Configuration</div>
                <div class="owm-card-content">
                    <form method="post" action="options.php" class="owm-form">
                        <?php
                        settings_fields($this->option_group);
                        do_settings_sections('owm-settings');
                        submit_button('Save Changes', 'primary', 'submit', false);
                        ?>
                    </form>
                </div>
            </div>

            <!-- Sync -->
            <div class="owm-card">
                <div class="owm-card-label">Sync</div>
                <div class="owm-card-content">
                    <p class="owm-card-desc">Pull the latest data from HotelBoss. Runs automatically every hour.</p>
                    <form method="post" class="owm-sync-form">
                        <?php wp_nonce_field('owm_sync_action'); ?>
                        <button type="submit" name="owm_manual_sync_all" class="owm-btn owm-btn--dark">Sync All</button>
                        <button type="submit" name="owm_manual_sync" class="owm-btn owm-btn--ghost">Products only</button>
                        <button type="submit" name="owm_manual_inventory_sync" class="owm-btn owm-btn--ghost">Inventory only</button>
                    </form>
                </div>
            </div>

        </div>
        <?php
    }

    public function process_pdf_template_save(): void {
        if (!isset($_POST['owm_pdf_template']) || !check_admin_referer('owm_pdf_template_action')) {
            return;
        }
        if (!current_user_can('owm_manage')) {
            return;
        }

        $json_data = wp_unslash($_POST['owm_pdf_template']);
        $decoded   = json_decode($json_data);
        $status    = json_last_error() === JSON_ERROR_NONE ? 'saved' : 'error';

        if ($status === 'saved') {
            update_option('owm_pdf_template', wp_json_encode($decoded));
        }

        wp_redirect(add_query_arg(['page' => 'owm-pdf-template', 'template_status' => $status], admin_url('admin.php')));
        exit;
    }

    public function render_pdf_template_page(): void {
        $status = $_GET['template_status'] ?? '';
        if ($status === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Template saved.</p></div>';
        } elseif ($status === 'error') {
            echo '<div class="notice notice-error is-dismissible"><p>Invalid template data — please try again.</p></div>';
        }

        $row_fields = WineFields::labels();
        ?>
        <div class="wrap owm-settings-wrap owm-pdf-wrap">
            <div class="owm-page-header">
                <div>
                    <h1 class="owm-page-title">PDF Template</h1>
                    <p class="owm-page-subtitle">Add fields to the canvas, drag to position, then save.</p>
                </div>
            </div>

            <form id="owm-pdf-template-form" method="post">
                <?php wp_nonce_field('owm_pdf_template_action'); ?>
                <input type="hidden" id="owm_pdf_template_data" name="owm_pdf_template">

                <div class="owm-pdf-editor">

                    <!-- Field Palette -->
                    <div class="owm-field-palette">

                        <div class="owm-palette-section">
                            <span class="owm-palette-label">Section Headers</span>
                            <div class="owm-field-buttons">
                                <button type="button" class="owm-field-btn owm-field-btn-h1"
                                        onclick="addField('category', 'h1')">H1 &nbsp;Wine Type</button>
                                <button type="button" class="owm-field-btn owm-field-btn-h2"
                                        onclick="addField('country', 'h2')">H2 &nbsp;Country</button>
                                <button type="button" class="owm-field-btn owm-field-btn-h3"
                                        onclick="addField('region', 'h3')">H3 &nbsp;Region</button>
                            </div>
                        </div>

                        <div class="owm-palette-divider"></div>

                        <div class="owm-palette-section">
                            <span class="owm-palette-label">Row Fields</span>
                            <div class="owm-field-buttons">
                                <?php foreach ($row_fields as $key => $label) : ?>
                                    <button type="button"
                                            class="owm-field-btn"
                                            onclick="addField('<?php echo esc_js($key); ?>', 'row')">
                                        <?php echo esc_html($label); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>

                    <!-- Formatting Toolbar -->
                    <div class="owm-toolbar" id="owm-formatting-toolbar">
                        <div class="owm-toolbar-inner">
                            <div class="owm-toolbar-group">
                                <label for="owm-font-family">Font</label>
                                <select id="owm-font-family">
                                    <option value="Arial">Arial</option>
                                    <option value="Georgia">Georgia</option>
                                    <option value="Times New Roman">Times New Roman</option>
                                    <option value="Helvetica">Helvetica</option>
                                    <option value="Courier">Courier</option>
                                    <option value="TW Cen MT">TW Cen MT</option>
                                </select>
                            </div>
                            <div class="owm-toolbar-group">
                                <label for="owm-font-size">Size</label>
                                <select id="owm-font-size">
                                    <?php foreach ([9,10,11,12,13,14,16,18,20,24,28,32] as $s) : ?>
                                        <option value="<?php echo $s; ?>"<?php echo $s === 14 ? ' selected' : ''; ?>><?php echo $s; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="owm-toolbar-group">
                                <label for="owm-text-color">Colour</label>
                                <input type="color" id="owm-text-color" value="#222222">
                            </div>

                            <div class="owm-toolbar-sep"></div>

                            <div class="owm-toolbar-group owm-toolbar-btns">
                                <button type="button" id="owm-btn-bold"   class="owm-icon-btn" title="Bold">B</button>
                                <button type="button" id="owm-btn-italic" class="owm-icon-btn owm-italic-lbl" title="Italic">I</button>
                            </div>

                            <div class="owm-toolbar-sep"></div>

                            <div class="owm-toolbar-group owm-toolbar-btns">
                                <button type="button" class="owm-icon-btn owm-align-btn" data-align="left"   title="Align left">
                                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="2" fill="currentColor"/><rect x="1" y="6" width="8"  height="2" fill="currentColor"/><rect x="1" y="10" width="10" height="2" fill="currentColor"/></svg>
                                </button>
                                <button type="button" class="owm-icon-btn owm-align-btn" data-align="center" title="Align centre">
                                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="2" fill="currentColor"/><rect x="3" y="6" width="8"  height="2" fill="currentColor"/><rect x="2" y="10" width="10" height="2" fill="currentColor"/></svg>
                                </button>
                                <button type="button" class="owm-icon-btn owm-align-btn" data-align="right"  title="Align right">
                                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="2" fill="currentColor"/><rect x="5" y="6" width="8"  height="2" fill="currentColor"/><rect x="3" y="10" width="10" height="2" fill="currentColor"/></svg>
                                </button>
                            </div>

                            <div class="owm-toolbar-sep"></div>

                            <div class="owm-toolbar-group">
                                <button type="button" id="owm-btn-delete" class="owm-icon-btn owm-delete-btn" title="Delete selected (Del)">
                                    <svg width="14" height="14" viewBox="0 0 14 14"><line x1="2" y1="2" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="2" x2="2" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="owm-toolbar-idle-hint">Select a field on the canvas to format it</div>
                    </div>

                    <!-- Canvas -->
                    <div class="owm-canvas-outer">
                        <canvas id="pdfCanvas"></canvas>
                        <p class="owm-canvas-note">
                            This canvas is A4-width (595 px). Each wine row in the PDF mirrors the position and style of your fields above.
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="owm-pdf-actions">
                        <input type="submit" class="button button-primary" value="Save Template">
                        <button type="button" id="owm-generate-pdf" class="button owm-btn-generate">
                            Download Wine List PDF
                        </button>
                        <button type="button" id="owm-btn-reset-default" class="button owm-btn-reset">
                            Reset to Default
                        </button>
                        <span id="owm-pdf-status" class="owm-pdf-status"></span>
                    </div>

                </div><!-- /.owm-pdf-editor -->
            </form>
        </div>
        <?php
    }

    public function handle_generate_pdf(): void {
        check_ajax_referer('owm_generate_pdf_nonce', 'nonce');

        if (!current_user_can('owm_manage')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }

        // Flush all output buffers so mPDF can send PDF headers cleanly
        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $template_json = '';
            if (!empty($_POST['template'])) {
                $raw = wp_unslash($_POST['template']);
                if (json_decode($raw) !== null) {
                    $template_json = $raw;
                }
            }

            $generator = new PdfGenerator();
            $generator->generate($template_json); // sends headers + PDF binary, then exits
        } catch (\Exception $e) {
            status_header(500);
            echo 'PDF generation failed: ' . esc_html($e->getMessage());
        }

        exit;
    }

    public function handle_download_pdf_direct(): void {
        if (!current_user_can('owm_manage')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }
        check_admin_referer('owm_download_pdf_direct');

        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $generator = new PdfGenerator();
            $generator->generate(); // uses saved owm_pdf_template option
        } catch (\Exception $e) {
            wp_die('PDF generation failed: ' . esc_html($e->getMessage()));
        }

        exit;
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
            $sync  = new ProductSyncService($client);
            $count = $sync->sync_all_products();
            echo '<div class="notice notice-success is-dismissible"><p>Successfully synced ' . intval($count) . ' products.</p></div>';
        } catch (\Exception $e) {
            echo '<div class="notice notice-error is-dismissible"><p>Sync failed: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    private function handle_inventory_sync(): void {
        $client = new HotelBossClient(get_option('owm_company_id'));
        try {
            $client->authenticate(get_option('owm_username'), get_option('owm_password'));
            $sync  = new InventorySyncService($client);
            $count = $sync->sync_inventory();
            echo '<div class="notice notice-success is-dismissible"><p>Inventory synced — ' . intval($count) . ' products updated.</p></div>';
        } catch (\Exception $e) {
            echo '<div class="notice notice-error is-dismissible"><p>Inventory sync failed: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    public function run_scheduled_inventory_sync(): void {
        $client = new HotelBossClient(get_option('owm_company_id'));
        try {
            $client->authenticate(get_option('owm_username'), get_option('owm_password'));
            $sync = new InventorySyncService($client);
            $sync->sync_inventory();
        } catch (\Exception $e) {
            error_log('Scheduled inventory sync failed: ' . $e->getMessage());
        }
    }
}
