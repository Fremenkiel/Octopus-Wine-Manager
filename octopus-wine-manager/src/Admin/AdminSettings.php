<?php

namespace OctopusWineManagment\Admin;

use OctopusWineManagment\API\HotelBossClient;
use OctopusWineManagment\Services\ProductSyncService;
use OctopusWineManagment\Services\InventorySyncService;
use OctopusWineManagment\PDF\PdfGenerator;
use OctopusWineManagment\WineFields;

/**
 * Registers and renders all plugin admin pages, settings, and AJAX/action handlers.
 *
 * Pages:
 *   • Octopus Wine (top-level)  — API credentials, hotel assignment, wine-type order,
 *                                  manual sync buttons, and the PDF download link.
 *   • PDF Template (sub-page)   — Fabric.js canvas editor for the PDF layout.
 *
 * WordPress options used:
 *   owm_company_id          — HotelBoss company identifier
 *   owm_username            — HotelBoss API username
 *   owm_password            — HotelBoss API password
 *   owm_selected_hotel_id   — ID of the hotel whose products are shown
 *   owm_pdf_template        — JSON string from the canvas editor (see PdfGenerator)
 *   owm_type_order          — JSON-encoded array of category names in display order
 *   owm_last_sync           — Timestamp of the last product sync
 *   owm_last_inventory_sync — Timestamp of the last inventory sync
 */
class AdminSettings {
    /** WordPress Settings API option group — groups all owm_* options together. */
    private $option_group = 'owm_settings_group';

    /**
     * Returns the default PDF template as a plain PHP array in Fabric.js JSON format.
     *
     * This is the single authoritative definition of the default layout. It is used
     * both when seeding the option on plugin activation and when the canvas editor
     * requests the default via owmTemplateData.defaultTemplate.
     *
     * Fields mirror applyDefaultTemplate() in pdf-canvas.js — keep them in sync.
     */
    public static function default_template(): array {
        return [
            'version' => '5.3.1',
            'objects' => [
                [
                    'type'           => 'text',
                    'text'           => '{{category}}',
                    'left'           => 218.15,
                    'top'            => 24,
                    'fontSize'       => 32,
                    'fontFamily'     => 'TW Cen MT',
                    'fontWeight'     => 'normal',
                    'fontStyle'      => 'normal',
                    'fill'           => '#0a0a0a',
                    'textAlign'      => 'center',
                    'styles'         => (object)[],
                    'placeholderKey' => 'category',
                    'headerLevel'    => 1,
                ],
                [
                    'type'           => 'text',
                    'text'           => '{{country}}',
                    'left'           => 78,
                    'top'            => 90,
                    'fontSize'       => 28,
                    'fontFamily'     => 'TW Cen MT',
                    'fontWeight'     => 'bold',
                    'fontStyle'      => 'normal',
                    'fill'           => '#0a0a0a',
                    'textAlign'      => 'left',
                    'styles'         => (object)[],
                    'placeholderKey' => 'country',
                    'headerLevel'    => 2,
                ],
                [
                    'type'           => 'text',
                    'text'           => '{{region}}',
                    'left'           => 78,
                    'top'            => 154,
                    'fontSize'       => 20,
                    'fontFamily'     => 'TW Cen MT',
                    'fontWeight'     => 'normal',
                    'fontStyle'      => 'italic',
                    'fill'           => '#333333',
                    'textAlign'      => 'left',
                    'styles'         => (object)[],
                    'placeholderKey' => 'region',
                    'headerLevel'    => 3,
                ],
                [
                    'type'           => 'text',
                    'text'           => '{{vintage}}',
                    'left'           => 20,
                    'top'            => 218.66,
                    'fontSize'       => 18,
                    'fontFamily'     => 'TW Cen MT',
                    'fontWeight'     => 'normal',
                    'fontStyle'      => 'normal',
                    'fill'           => '#0a0a0a',
                    'textAlign'      => 'left',
                    'styles'         => (object)[],
                    'placeholderKey' => 'vintage',
                    'headerLevel'    => 0,
                ],
                [
                    'type'           => 'text',
                    'text'           => '{{wine}}',
                    'left'           => 78,
                    'top'            => 218.66,
                    'fontSize'       => 18,
                    'fontFamily'     => 'TW Cen MT',
                    'fontWeight'     => 'normal',
                    'fontStyle'      => 'normal',
                    'fill'           => '#0a0a0a',
                    'textAlign'      => 'left',
                    'styles'         => (object)[],
                    'placeholderKey' => 'wine',
                    'headerLevel'    => 0,
                ],
                [
                    'type'           => 'textbox',
                    'text'           => '{{price}}',
                    'left'           => 483.5,
                    'top'            => 218.66,
                    'width'          => 90.5,
                    'fontSize'       => 18,
                    'fontFamily'     => 'TW Cen MT',
                    'fontWeight'     => 'normal',
                    'fontStyle'      => 'normal',
                    'fill'           => '#0a0a0a',
                    'textAlign'      => 'right',
                    'styles'         => (object)[],
                    'placeholderKey' => 'price',
                    'headerLevel'    => 0,
                ],
                [
                    'type'           => 'text',
                    'text'           => '{{origin}}',
                    'left'           => 78,
                    'top'            => 264,
                    'fontSize'       => 18,
                    'fontFamily'     => 'TW Cen MT',
                    'fontWeight'     => 'normal',
                    'fontStyle'      => 'normal',
                    'fill'           => '#0a0a0a',
                    'textAlign'      => 'left',
                    'styles'         => (object)[],
                    'placeholderKey' => 'origin',
                    'headerLevel'    => 0,
                ],
            ],
        ];
    }

    public function __construct() {
        // Core admin hooks.
        add_action('admin_menu',            [$this, 'add_menu_page']);
        add_action('admin_init',            [$this, 'register_settings']);
        add_action('admin_init',            [$this, 'process_pdf_template_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX: generate PDF preview from the canvas editor (sends PDF binary).
        add_action('wp_ajax_owm_generate_pdf', [$this, 'handle_generate_pdf']);

        // Admin action: direct download link in the sidebar menu and settings page.
        add_action('admin_action_owm_download_pdf', [$this, 'handle_download_pdf_direct']);

        // Admin action: direct view html in the sidebar menu and settings page.
        add_action('admin_action_owm_view_html', [$this, 'handle_view_html_direct']);

        // Scheduled inventory sync callback (registered here as a fallback; the
        // hourly event is also scheduled in octopus-wine-managment.php).
        add_action('owm_scheduled_inventory_sync', [$this, 'run_scheduled_inventory_sync']);
        if (!wp_next_scheduled('owm_scheduled_inventory_sync')) {
            wp_schedule_event(time(), 'hourly', 'owm_scheduled_inventory_sync');
        }

        // Allow the custom 'owm_manage' capability (held by admins + editors) to save
        // these settings, rather than requiring 'manage_options' (admin only).
        add_filter('option_page_capability_' . $this->option_group, fn() => 'owm_manage');
    }

    // ── Menu / Pages ─────────────────────────────────────────────────────────

    /**
     * Registers the top-level menu page and its two sub-pages (PDF Template and
     * the direct PDF download link).
     *
     * The menu icon is loaded from assets/images/logo.svg and base64-encoded as
     * a data URI. Inline style attributes and hard-coded fill colours are stripped
     * so WordPress admin colour-scheme filters can recolour it correctly.
     */
    public function add_menu_page(): void {
        // Default to the WP plugin dashicon if the custom SVG is missing.
        $icon     = 'dashicons-admin-plugins';
        $svg_path = dirname(__DIR__, 2) . '/assets/images/logo.svg';
        if (file_exists($svg_path)) {
            $svg = file_get_contents($svg_path);
            // Remove any inline style blocks that would prevent WP colour theming.
            $svg  = preg_replace('/\s*style="[^"]*"/', '', $svg);
            // Replace currentColor so the admin CSS filter can re-tint the icon.
            $svg  = str_replace('fill="currentColor"', 'fill="#a7aaad"', $svg);
            $icon = 'data:image/svg+xml;base64,' . base64_encode($svg);
        }

        // Top-level: main settings + sync + export dashboard.
        add_menu_page(
            'OWM Settings',      // page <title>
            'Octopus Wine',      // sidebar menu label
            'owm_manage',        // capability required to view
            'owm-settings',      // menu slug
            [$this, 'render_admin_page'],
            $icon
        );

        // Sub-page: Fabric.js canvas editor for the PDF template.
        add_submenu_page(
            'owm-settings',
            'PDF Template',
            'PDF Template',
            'owm_manage',
            'owm-pdf-template',
            [$this, 'render_pdf_template_page']
        );

        // Append a direct-download link to the sidebar as a plain URL entry.
        // The nonce is embedded in the URL and verified in handle_download_pdf_direct().
        global $submenu;
        $submenu['owm-settings'][] = [
            'Download Wine List PDF',
            'owm_manage',
            wp_nonce_url(admin_url('admin.php?action=owm_download_pdf'), 'owm_download_pdf_direct'),
        ];
    }

    // ── Settings Registration ─────────────────────────────────────────────────

    /**
     * Registers all plugin options with the WordPress Settings API and adds
     * the corresponding fields to the main settings page.
     *
     * owm_type_order is sanitized on save: it must be an array of strings which
     * is JSON-encoded before storage to preserve ordering.
     */
    public function register_settings(): void {
        register_setting($this->option_group, 'owm_company_id');
        register_setting($this->option_group, 'owm_username');
        register_setting($this->option_group, 'owm_password');
        register_setting($this->option_group, 'owm_selected_hotel_id');
        register_setting($this->option_group, 'owm_pdf_template');
        register_setting($this->option_group, 'owm_type_order', [
            // Sanitize before storage: ensure it's an array of plain strings,
            // then JSON-encode so the order is preserved.
            'sanitize_callback' => function ($value) {
                $arr = is_array($value) ? $value : [];
                return wp_json_encode(array_values(array_map('sanitize_text_field', $arr)));
            },
        ]);

        // API Authentication section.
        add_settings_section('owm_auth_section', 'API Authentication', null, 'owm-settings');
        add_settings_field('owm_company_id', 'Company ID', [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_company_id']);
        add_settings_field('owm_username',   'Username',   [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_username']);
        add_settings_field('owm_password',   'Password',   [$this, 'render_input'], 'owm-settings', 'owm_auth_section', ['name' => 'owm_password', 'type' => 'password']);

        // Hotel selection (populated from the API after the first authenticated save).
        add_settings_section('owm_sync_section', 'Synchronization', null, 'owm-settings');
        add_settings_field('owm_hotel_id', 'Assign Hotel', [$this, 'render_hotel_dropdown'], 'owm-settings', 'owm_sync_section');

        // Drag-and-drop wine type sort order.
        add_settings_section('owm_type_section', 'Wine Type Order', [$this, 'render_type_order_section_description'], 'owm-settings');
        add_settings_field('owm_type_order', 'Type Sort Order', [$this, 'render_type_order_field'], 'owm-settings', 'owm_type_section');
    }

    // ── Asset Enqueueing ─────────────────────────────────────────────────────

    /**
     * Loads CSS and JavaScript only on the plugin's own admin pages.
     *
     * Main settings page:
     *   • owm-admin.css  — shared admin styles
     *   • jQuery UI Sortable + inline init — powers the drag-to-reorder type list
     *
     * PDF template page (additionally):
     *   • Fabric.js 5.3.1 (CDN)  — canvas rendering library
     *   • pdf-canvas.js           — plugin canvas editor built on top of Fabric.js
     *   • owmTemplateData         — JS object injected via wp_localize_script containing
     *                               the saved template JSON, AJAX URL, nonce, and font URL
     *
     * @param string $hook  Current admin page hook suffix.
     */
    public function enqueue_admin_assets($hook): void {
        $owm_pages = ['toplevel_page_owm-settings', 'octopus-wine_page_owm-pdf-template'];
        if (!in_array($hook, $owm_pages, true)) {
            return; // not our page — do nothing
        }

        // Shared admin stylesheet.
        wp_enqueue_style(
            'owm-admin-styles',
            plugin_dir_url(dirname(__DIR__, 1)) . 'assets/styles/owm-admin.css',
            [],
            '1.0.1'
        );

        // Main settings page: drag-to-reorder wine type list.
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

        // PDF template page: Fabric.js canvas editor.
        if ($hook === 'octopus-wine_page_owm-pdf-template') {
            // Load Fabric.js from CDN — must come before the canvas script.
            wp_enqueue_script(
                'owm-admin-fabric',
                'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js',
                [],
                '5.3.1',
                true
            );

            // Plugin canvas editor script (depends on Fabric.js).
            wp_enqueue_script(
                'owm-admin-pdf-canvas',
                plugin_dir_url(dirname(__DIR__, 1)) . 'assets/scripts/pdf-canvas.js',
                ['owm-admin-fabric'],
                '1.1.0',
                true
            );

            // Pass PHP-side data to the JavaScript canvas editor.
            wp_localize_script('owm-admin-pdf-canvas', 'owmTemplateData', [
                'template'        => get_option('owm_pdf_template', ''), // existing template JSON (may be empty)
                'defaultTemplate' => wp_json_encode(self::default_template()),
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'nonce'           => wp_create_nonce('owm_generate_pdf_nonce'),
                'fontUrl'         => plugin_dir_url(dirname(__DIR__, 1)) . 'assets/fonts/tw-cen-mt-regular.ttf',
            ]);
        }
    }

    // ── Field Renderers ───────────────────────────────────────────────────────

    /** Renders the descriptive paragraph above the wine-type sort order list. */
    public function render_type_order_section_description(): void {
        echo '<p class="owm-field-note">Set the order in which wine types appear in the PDF. Drag to reorder, then save.</p>';
    }

    /**
     * Renders the drag-and-drop wine-type sort order list.
     *
     * Queries the database directly for distinct product group names (rather than
     * fetching all posts) for efficiency. Merges the saved order with any new
     * categories that appeared since the last save; new ones are appended
     * alphabetically.
     *
     * Each list item contains a hidden input named 'owm_type_order[]' whose value
     * is the category name. When the form is submitted, PHP receives an array in
     * drag-drop order which register_settings() JSON-encodes for storage.
     */
    public function render_type_order_field(): void {
        global $wpdb;

        // Fetch all distinct product group names from published wine posts.
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

        // Load the previously saved order and merge in any new (unlisted) types.
        $saved    = json_decode(get_option('owm_type_order', '[]'), true) ?: [];
        $unlisted = array_diff($meta_values, $saved);
        sort($unlisted); // new types appear alphabetically after the saved ones
        $ordered  = array_unique(array_merge($saved, $unlisted));

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

    /**
     * Renders a generic text (or password) <input> for a Settings API field.
     *
     * @param array $args  Must contain 'name' (option name). Optionally 'type' ('password').
     */
    public function render_input($args): void {
        $val  = get_option($args['name']);
        $type = $args['type'] ?? 'text';
        printf('<input type="%s" name="%s" value="%s" class="regular-text" />', $type, $args['name'], esc_attr($val));
    }

    /**
     * Renders the hotel <select> dropdown.
     *
     * The hotel list is cached as a transient ('owm_hotel_list') for one hour after
     * a successful API authentication. If the transient is absent (e.g. first visit,
     * or expired), a helper message is shown prompting the user to save credentials.
     */
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

    // ── Page Renderers ────────────────────────────────────────────────────────

    /**
     * Renders the main settings page (Dashboard + Configuration + Sync cards).
     *
     * Also handles manual sync form submissions (product sync, inventory sync, or both)
     * that POST to this same page. Each action is protected by a nonce.
     *
     * After saving API credentials the hotel list transient is refreshed so the
     * hotel dropdown populates immediately without requiring a page reload.
     */
    public function render_admin_page(): void {
        // Handle manual sync form submissions posted to this page.
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

        // If credentials exist but the hotel list hasn't been loaded yet, try now.
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
                    <!-- Product sync status chip -->
                    <div class="owm-status-chip">
                        <span class="owm-dot <?php echo $last_products ? 'owm-dot--ok' : 'owm-dot--idle'; ?>"></span>
                        <strong>Products</strong>
                        <?php echo esc_html($last_products ?: 'Never synced'); ?>
                    </div>
                    <!-- Inventory sync status chip -->
                    <div class="owm-status-chip">
                        <span class="owm-dot <?php echo $last_inventory ? 'owm-dot--ok' : 'owm-dot--idle'; ?>"></span>
                        <strong>Inventory</strong>
                        <?php echo esc_html($last_inventory ?: 'Never synced'); ?>
                    </div>
                </div>
            </div>

            <!-- Export card: download the wine list PDF -->
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
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?action=owm_view_html'), 'owm_view_html_direct')); ?>"
                           class="owm-btn owm-btn--dark">
                            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" aria-hidden="true" width="13" height="13" viewBox="0 0 1024 1025">
                              <path fill="currentColor" d="M1006 555L808 815q-18 17-43.5 17T721 814.5t-18-43t18-43.5l165-216l-165-216q-18-18-18-43t18-43t43.5-18t43.5 18l198 259q18 18 18 43t-18 43M512 961q-1 3-3 11.5t-3 11.5l-3.5 10.5l-5 10.5l-7.5 8l-10.5 7l-13.5 3.5l-18 1.5q-24 0-46-25t-16-48L512 64q17-64 96-64q22 0 29.5 23.5T638 73zM303 814.5Q285 832 259.5 832T216 815L18 555Q0 537 0 512t18-43l198-259q18-18 43.5-18t43.5 18t18 43t-18 43L138 512l165 216q18 18 18 43.5t-18 43"></path>
                            </svg>  
                            View HTML
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=owm-pdf-template')); ?>" class="owm-text-link">
                            Edit template &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Configuration card: API credentials, hotel, type order -->
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

            <!-- Sync card: manual triggers for product and inventory sync -->
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

    // ── PDF Template Save ─────────────────────────────────────────────────────

    /**
     * Handles the PDF template form submission (hooked to 'admin_init' so it runs
     * before any output is sent).
     *
     * Validates the nonce and capability, then decodes the incoming JSON to verify
     * it is well-formed before storing it. Redirects back to the template page with
     * a query-string status flag ('saved' or 'error') that render_pdf_template_page()
     * converts to an admin notice.
     */
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
            // Re-encode with wp_json_encode to normalise whitespace/escaping.
            update_option('owm_pdf_template', wp_json_encode($decoded));
        }

        wp_redirect(add_query_arg(['page' => 'owm-pdf-template', 'template_status' => $status], admin_url('admin.php')));
        exit;
    }

    /**
     * Renders the PDF template canvas editor page.
     *
     * The canvas editor is powered by Fabric.js (loaded in enqueue_admin_assets()).
     * Fields are dragged from the palette onto an A4-width canvas (595 px).
     * On save the canvas JSON is serialised into the hidden input and submitted
     * to process_pdf_template_save() via the standard form POST.
     *
     * The Formatting Toolbar (font, size, colour, bold/italic, alignment) updates
     * the selected Fabric.js object in real time; those properties are included in
     * the serialised JSON and consumed by PdfGenerator::extract_template().
     */
    public function render_pdf_template_page(): void {
        // Display a save/error notice if redirected back from process_pdf_template_save().
        $status = $_GET['template_status'] ?? '';
        if ($status === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Template saved.</p></div>';
        } elseif ($status === 'error') {
            echo '<div class="notice notice-error is-dismissible"><p>Invalid template data — please try again.</p></div>';
        }

        // Row field labels come from WineFields::labels() so the palette stays in
        // sync with the fields available in PdfGenerator.
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
                <!-- The canvas editor writes the serialised Fabric.js JSON here before submit. -->
                <input type="hidden" id="owm_pdf_template_data" name="owm_pdf_template">

                <div class="owm-pdf-editor">

                    <!-- Field Palette: drag/click fields onto the canvas -->
                    <div class="owm-field-palette">

                        <div class="owm-palette-section">
                            <span class="owm-palette-label">Section Headers</span>
                            <div class="owm-field-buttons">
                                <!-- H1 maps to the wine category (page running header) -->
                                <button type="button" class="owm-field-btn owm-field-btn-h1"
                                        onclick="addField('category', 'h1')">H1 &nbsp;Wine Type</button>
                                <!-- H2 maps to the country heading -->
                                <button type="button" class="owm-field-btn owm-field-btn-h2"
                                        onclick="addField('country', 'h2')">H2 &nbsp;Country</button>
                                <!-- H3 maps to the region heading -->
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

                    <!-- Formatting Toolbar: styles the currently selected canvas object -->
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
                                    <!-- TW Cen MT is the custom font bundled with the plugin -->
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

                    <!-- Canvas: A4-width Fabric.js canvas (595 px = one A4 page width) -->
                    <div class="owm-canvas-outer">
                        <canvas id="pdfCanvas"></canvas>
                        <p class="owm-canvas-note">
                            This canvas is A4-width (595 px). Each wine row in the PDF mirrors the position and style of your fields above.
                        </p>
                    </div>

                    <!-- Form actions -->
                    <div class="owm-pdf-actions">
                        <input type="submit" class="button button-primary" value="Save Template">
                        <!-- "Download" triggers the AJAX handler (handle_generate_pdf) which
                             uses the current unsaved canvas state, not the stored option. -->
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

    // ── AJAX / Action Handlers ────────────────────────────────────────────────

    /**
     * AJAX handler: wp_ajax_owm_generate_pdf
     *
     * Called by the canvas editor's "Download Wine List PDF" button. Accepts the
     * current (potentially unsaved) template JSON via $_POST['template'] so the
     * user can preview changes before saving them. Falls back to the saved option
     * if no template is posted.
     *
     * Flushes output buffers before calling PdfGenerator::generate() so mPDF can
     * write its own Content-Type and Content-Disposition headers cleanly.
     */
    public function handle_generate_pdf(): void {
        check_ajax_referer('owm_generate_pdf_nonce', 'nonce');

        if (!current_user_can('owm_manage')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }

        // Flush all output buffers so mPDF can send PDF headers cleanly.
        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $template_json = '';
            if (!empty($_POST['template'])) {
                $raw = wp_unslash($_POST['template']);
                // Only use the posted template if it is valid JSON.
                if (json_decode($raw) !== null) {
                    $template_json = $raw;
                }
            }

            $generator = new PdfGenerator();
            $generator->generate($template_json); // streams PDF binary to the browser
        } catch (\Exception $e) {
            status_header(500);
            echo 'PDF generation failed: ' . esc_html($e->getMessage());
        }

        exit;
    }

    /**
     * Admin action handler: admin_action_owm_download_pdf
     *
     * Triggered by the nonce-protected URL used in the sidebar menu and the
     * Export card. Uses the saved template option (not a posted template).
     * Verifies capability and nonce before generating.
     */
    public function handle_download_pdf_direct(): void {
        if (!current_user_can('owm_manage')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }
        check_admin_referer('owm_download_pdf_direct');

        // Flush output buffers before mPDF writes its headers.
        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $generator = new PdfGenerator();
            $generator->generate(); // uses the stored owm_pdf_template option
        } catch (\Exception $e) {
            wp_die('PDF generation failed: ' . esc_html($e->getMessage()));
        }

        exit;
    }

    /**
     * Admin action handler: admin_action_owm_view_html
     *
     * Triggered by the nonce-protected URL used in
     * Export card.
     * Verifies capability and nonce before generating.
     */
    public function handle_view_html_direct(): void {
        if (!current_user_can('owm_manage')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }
        check_admin_referer('owm_view_html_direct');

        // Flush output buffers before mPDF writes its headers.
        while (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $generator = new PdfGenerator();
            $generator->generate_html(); // uses the stored owm_pdf_template option
        } catch (\Exception $e) {
            wp_die('HTML generation failed: ' . esc_html($e->getMessage()));
        }

        exit;
    }

    // ── Private Sync Helpers ─────────────────────────────────────────────────

    /**
     * Fetches the hotel list from the HotelBoss API and caches it as a transient
     * for one hour. Errors are silently swallowed so the settings page still loads
     * if the API is temporarily unavailable.
     */
    private function refresh_hotel_list(): void {
        try {
            $client = new HotelBossClient(get_option('owm_company_id'));
            if ($client->authenticate(get_option('owm_username'), get_option('owm_password'))) {
                $hotels = $client->getHotels();
                set_transient('owm_hotel_list', $hotels, HOUR_IN_SECONDS);
            }
        } catch (\Exception $e) {
            // Silence: the dropdown will show a "please save credentials" hint instead.
        }
    }

    /**
     * Runs a full product sync and outputs an admin notice with the result count.
     * Called from the manual sync form submission inside render_admin_page().
     */
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

    /**
     * Runs an inventory sync and outputs an admin notice with the number of
     * products whose stock quantity was updated.
     * Called from the manual sync form submission inside render_admin_page().
     */
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

    /**
     * WP-Cron callback for the 'owm_scheduled_inventory_sync' event.
     * Runs the same inventory sync as handle_inventory_sync() but without HTML
     * output; errors are written to the PHP error log instead.
     */
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
