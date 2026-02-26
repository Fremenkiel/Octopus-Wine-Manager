<?php

namespace OctopusWineManagment\Admin;

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\Services\ProductSyncService;
use OctopusWineManagment\PDF\PdfGenerator;

class AdminSettings {
    private $option_group = 'owm_settings_group';

    public function __construct() {
        add_action('admin_menu',             [$this, 'add_menu_page']);
        add_action('admin_init',             [$this, 'register_settings']);
        add_action('admin_enqueue_scripts',  [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_owm_generate_pdf', [$this, 'handle_generate_pdf']);
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

        add_submenu_page(
            'owm-settings',
            'PDF Template',
            'PDF Template',
            'manage_options',
            'owm-pdf-template',
            [$this, 'render_pdf_template_page']
        );
    }

    public function register_settings(): void {
        register_setting($this->option_group, 'owm_company_id');
        register_setting($this->option_group, 'owm_username');
        register_setting($this->option_group, 'owm_password');
        register_setting($this->option_group, 'owm_selected_hotel_id');
        register_setting($this->option_group, 'owm_pdf_template');

        add_settings_section('owm_auth_section', 'API Authentication', null, 'owm-settings');

        add_settings_field('owm_company_id', 'Company ID',  [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_company_id']);
        add_settings_field('owm_username',   'Username',    [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_username']);
        add_settings_field('owm_password',   'Password',    [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_password', 'type' => 'password']);

        add_settings_section('owm_sync_section', 'Synchronization', null, 'owm-settings');
        add_settings_field('owm_hotel_id', 'Assign Hotel', [$this, 'render_hotel_dropdown'], 'owm-settings', 'owm_sync_section');
    }

    public function enqueue_admin_assets($hook): void {
        $owm_pages = ['toplevel_page_owm-settings', 'octopus-wine-managment_page_owm-pdf-template'];
        if (!in_array($hook, $owm_pages, true)) {
            return;
        }

        wp_enqueue_style(
            'owm-admin-styles',
            plugin_dir_url(dirname(__DIR__, 1)) . 'assets/styles/owm-admin.css',
            [],
            '1.0.1'
        );

        // Only load Fabric.js and the canvas editor on the PDF template page
        if ($hook === 'octopus-wine-managment_page_owm-pdf-template') {
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

        if (get_option('owm_username') && !get_transient('owm_hotel_list')) {
            $this->refresh_hotel_list();
        }
        ?>
        <div class="wrap owm-settings-wrap">
            <h1>Octopus API Integration</h1>

            <div class="owm-sync-status">
                <strong>System Status:</strong>
                <?php echo esc_html(get_option('owm_last_sync') ?: 'Never synced'); ?>
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
                    This will pull all products and their attributes from the assigned hotel.
                </p>
                <input type="submit" name="owm_manual_sync" class="button button-secondary" value="Sync Products Now">
            </form>
        </div>
        <?php
    }

    public function render_pdf_template_page(): void {
        if (isset($_POST['owm_pdf_template']) && check_admin_referer('owm_pdf_template_action')) {
            $json_data = wp_unslash($_POST['owm_pdf_template']);
            $decoded   = json_decode($json_data);
            if (json_last_error() === JSON_ERROR_NONE) {
                update_option('owm_pdf_template', wp_json_encode($decoded));
                echo '<div class="notice notice-success is-dismissible"><p>Template saved.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Invalid template data — please try again.</p></div>';
            }
        }

        $row_fields = [
            'name'     => 'Wine Name',
            'price'    => 'Price',
            'vintage'   => 'Vintage',
            'origin'  =>  'Origin',
        ];
        ?>
        <div class="wrap owm-settings-wrap owm-pdf-wrap">
            <h1>PDF Wine List Template</h1>
            <p class="description">
                Add headings and row fields to the canvas, then drag them into position.
                <strong>Section Headers</strong> define the grouping titles in the PDF.
                <strong>Row Fields</strong> define the columns that repeat for every wine.
                Drag near a guide line to snap.
            </p>

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
                                        onclick="addField('origin', 'h2')">H2 &nbsp;Country</button>
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
                        <span id="owm-pdf-status" class="owm-pdf-status"></span>
                    </div>

                </div><!-- /.owm-pdf-editor -->
            </form>
        </div>
        <?php
    }

    public function handle_generate_pdf(): void {
        check_ajax_referer('owm_generate_pdf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }

        // Flush all output buffers so mPDF can send PDF headers cleanly
        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $generator = new PdfGenerator();
            $generator->generate(); // sends headers + PDF binary, then exits
        } catch (\Exception $e) {
            status_header(500);
            echo 'PDF generation failed: ' . esc_html($e->getMessage());
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
}
