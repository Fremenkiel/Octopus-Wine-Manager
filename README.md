# Octopus Wine Manager

A WordPress plugin that syncs wine products from the HotelBoss API and exports them as a formatted PDF wine list.

---

## Table of Contents

1. [Installation](#installation)
2. [Building a Production Zip](#building-a-production-zip)
3. [Configuration](#configuration)
4. [How Sync Works](#how-sync-works)
5. [Editing the PDF Template](#editing-the-pdf-template)
6. [Accessing the Public PDF Endpoint](#accessing-the-public-pdf-endpoint)
7. [Downloading the PDF from the Admin](#downloading-the-pdf-from-the-admin)
8. [Wine Type Order](#wine-type-order)
9. [Filtering Which Products Are Imported](#filtering-which-products-are-imported)
10. [Adding or Removing Row Fields](#adding-or-removing-row-fields)
11. [Custom Fonts](#custom-fonts)
12. [File Structure](#file-structure)
13. [WordPress Options Reference](#wordpress-options-reference)

---

## Installation

1. Upload the `octopus-wine-manager` folder to `wp-content/plugins/`.
2. Activate the plugin through **Plugins › Installed Plugins** in the WordPress admin.
3. Navigate to **Octopus Wine** in the sidebar to configure credentials.

> **Requires:** WordPress 6.0+, PHP 7.4+, the PHP `curl` extension, and the PHP `zip` extension (needed by mPDF for font processing).

---

## Building a Production Zip

The `vendor/mpdf` package bundles ~87 MB of international fonts, and macOS adds hidden metadata folders. Zipping the plugin naively produces a file that exceeds typical PHP upload limits and triggers WordPress's **"The link you followed has expired"** error when installing via **Plugins › Upload Plugin**.

Run the following command from the **parent directory** of `octopus-wine-manager/` to produce a clean, minimal zip:

```bash
zip -r octopus-wine-manager.zip octopus-wine-manager \
  --exclude "*.DS_Store" \
  --exclude "*__MACOSX*" \
  --exclude "octopus-wine-manager/.claude/*" \
  --exclude "octopus-wine-manager/.git/*" \
  --exclude "octopus-wine-manager/*.zip" \
  --exclude "octopus-wine-manager/node_modules/*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Ab*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Aeg*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Akk*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/ayar*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/dam*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/DBS*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Dhy*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/dro*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Fre*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Gar*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Jom*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Khmeros*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Lohit*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/MiriamMonoCLM*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/mph*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Nakula*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Raph*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Rekha*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Sahadeva*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Samyak*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Sun*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Tai*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Vij*" \
  --exclude "octopus-wine-manager/vendor/mpdf/mpdf/ttfonts/Waree*"
```

This strips unused international fonts (the plugin only uses DejaVu/Arial and the bundled TW Cen MT), macOS metadata, development tooling, and any nested zips — reducing the output from ~89 MB to ~13 MB.

---

## Configuration

Go to **Octopus Wine › Settings** and fill in:

| Field | Description |
|---|---|
| Company ID | Your HotelBoss company identifier |
| Username | HotelBoss API username |
| Password | HotelBoss API password |
| Assign Hotel | Select which hotel's products to display (populated after saving valid credentials) |

Save the settings. The hotel dropdown will populate automatically after the first successful authentication.

---

## How Sync Works

The plugin performs two types of sync, both triggered automatically every hour via WP-Cron and available manually from the **Sync** card on the settings page.

### Product Sync
- Fetches all products from `GET /api/Products/GetProducts` (language: `da`).
- Filters to only the configured product group IDs (wine categories).
- Creates or updates an `owm_product` post for each product, storing attributes as post meta (`_owm_wine`, `_owm_producer`, `_owm_country_origin`, etc.).

### Inventory Sync
- Fetches movement records from `GET /api/Products/GetInventory`.
- Aggregates the signed deltas per product to compute the current stock quantity.
- Writes the result to `_owm_quantity` on the corresponding `owm_product` post.
- **Products with quantity < 1 are excluded from the PDF.**

Manual sync buttons:
- **Sync All** — runs product sync then inventory sync.
- **Products only** — updates names, prices, and attributes.
- **Inventory only** — updates stock quantities only.

---

## Editing the PDF Template

Navigate to **Octopus Wine › PDF Template**.

The canvas editor uses Fabric.js to let you design the layout of each wine row:

### Section Headers (H1 / H2 / H3)

| Button | Role | Appears as |
|---|---|---|
| **H1 Wine Type** | Category name | Running page header (top of each page) |
| **H2 Country** | Country heading | Bold section break above country group |
| **H3 Region** | Region heading | Italic sub-section break above region group |

- H1 is rendered as a fixed **page header** — it prints at the top of every page for that category. Its x/y position on the canvas is ignored; only its style (font, size, colour, weight) is used.
- H2 and H3 positions on the canvas map to a **left-margin indent** in the PDF. Move them right to indent the heading.
- Each category gets its own page — the H1 header changes automatically on each page break.

### Row Fields

Row fields define the columns that appear on each wine's data row. The available fields are:

| Key | Description |
|---|---|
| `wine` | Composite string: Producer, Wine name, Classification, Sweetness, Volume |
| `origin` | Composite string: Country / Region / Sub-Region / Commune / Village / Vineyard / Grape |
| `vintage` | Harvest year |
| `price` | Price in DKK (formatted with 2 decimal places and ' DKK' suffix) |

To add a field, click its button in the **Row Fields** palette — it appears on the canvas. Drag it to the desired x-position. The **x-position determines column order and width**: column width is automatically calculated as the distance between adjacent field positions.

### Formatting

Select any canvas object to activate the **Formatting Toolbar**:

- **Font** — choose from Arial, Georgia, Times New Roman, Helvetica, Courier, or TW Cen MT.
- **Size** — font size in points.
- **Colour** — text colour (hex colour picker).
- **B / I** — bold / italic toggle.
- **Alignment** — left, centre, or right (affects PDF column alignment).

### Saving and Previewing

- **Save Template** — persists the current canvas layout to the database (`owm_pdf_template` option).
- **Download Wine List PDF** — generates a PDF using the **current unsaved canvas state**, useful for previewing changes before committing.
- **Reset to Default** — clears the canvas and reverts to the built-in three-column layout (Wine / Price / SKU).

> **Note:** The canvas width is fixed at **595 px**, which corresponds to the full width of an A4 page in points. Position your fields within this width.

---

## Accessing the Public PDF Endpoint

The plugin registers a public REST API endpoint that streams the wine-list PDF directly.

```
GET /wp-json/owm/v1/pdf
```

- **Authentication:** None required. The endpoint is intentionally public so the PDF can be linked to or embedded from external pages.
- **Response:** `Content-Type: application/pdf` — the browser will prompt a download of `wine-list.pdf`.
- **Template used:** The saved `owm_pdf_template` option (configured via the canvas editor).

### Example usage

Direct browser link:
```
https://yourdomain.com/wp-json/owm/v1/pdf
```

Embedding in a page with an `<a>` tag:
```html
<a href="https://yourdomain.com/wp-json/owm/v1/pdf" target="_blank">Download Wine List</a>
```

> **Note:** If WP-JSON is disabled or the REST API is restricted by a security plugin, this URL will not work. Ensure `rest_api_init` is not blocked.

---

## Downloading the PDF from the Admin

There are two ways to download the PDF from within the WordPress admin:

1. **Export card** on the main settings page — click **Download PDF**.
2. **Download Wine List PDF** link in the **Octopus Wine** sidebar submenu.
3. **Download Wine List PDF** button on the **PDF Template** page (previews current unsaved state).

All admin download routes require the `owm_manage` capability (held by Administrators and Editors).

---

## Wine Type Order

Go to **Octopus Wine › Settings** and scroll to **Wine Type Order**.

Drag the category chips to set the order in which wine types appear in the PDF. Categories not in the list are appended alphabetically after the configured ones.

The order is saved to the `owm_type_order` option as a JSON array of category name strings.

---

## Filtering Which Products Are Imported

Only products belonging to specific HotelBoss product groups are imported. The allowed group IDs are defined in:

```
src/Services/ProductSyncService.php
```

```php
private $product_group_filter = [7704, 7727, 7728, 7729, 7730, 7731, 7732, 7733, 7734, 7735, 7736, 7737, 7738];
```

To add or remove groups:
1. Open that file.
2. Edit the array — add or remove HotelBoss product group IDs.
3. Run a **Products only** sync from the admin page to apply the change.

To discover product group IDs, you can temporarily use `HotelBossClient::getProductGroups()` and inspect the returned `ProductGroupDTO` objects.

---

## Adding or Removing Row Fields

The set of available row fields is controlled by two things:

### 1. `WineFields::labels()` — palette display

`src/WineFields.php` defines which fields appear in the **Row Fields** palette on the template editor page:

```php
public static function labels(): array {
    return [
        'wine'    => 'Wine',
        'origin'  => 'Origin',
        'vintage' => 'Vintage',
        'price'   => 'Price',
    ];
}
```

Add a new key/label pair here to make a new field available in the palette.

### 2. `PdfGenerator::get_all_wines()` — field data

The key must also exist in the wine data array built in `src/PDF/PdfGenerator.php` inside `get_all_wines()`. Each array entry maps field keys to their values:

```php
$wines[] = [
    'wine'    => $wine,
    'origin'  => implode(' / ', array_filter([...])),
    'vintage' => get_post_meta($post->ID, '_owm_vintage', true) ?: '',
    'price'   => get_post_meta($post->ID, '_owm_price', true) ?: '',
    // add new keys here
];
```

Any key present in `get_all_wines()` but absent from `WineFields::labels()` is available for display in the PDF but will not appear in the palette.

---

## Custom Fonts

The plugin bundles **TW Cen MT** (`assets/fonts/tw-cen-mt-regular.ttf`) as an additional font option.

To add another custom font:

1. Place the `.ttf` file in `assets/fonts/`.
2. Register it in `PdfGenerator::generate()` under `fontdata`:
   ```php
   'fontdata' => [
       'twcenmt'   => ['R' => 'tw-cen-mt-regular.ttf'],
       'myfont'    => ['R' => 'my-font-regular.ttf'],   // add this
   ],
   ```
3. Add a mapping in `PdfGenerator::safe_font()`:
   ```php
   $custom = [
       'TW Cen MT' => 'twcenmt',
       'My Font'   => 'myfont',   // add this
   ];
   ```
4. Add a `<option>` for it in the font `<select>` in `AdminSettings::render_pdf_template_page()`.

---

## File Structure

```
octopus-wine-manager/
├── plugin.php                          # WordPress plugin header + entry point
├── octopus-wine-managment.php          # Hooks: post type, roles, cron, REST endpoint
├── composer.json                       # PHP dependencies (mPDF, FPDI)
├── assets/
│   ├── fonts/
│   │   └── tw-cen-mt-regular.ttf       # Bundled custom font
│   ├── images/
│   │   └── logo.svg                    # Admin menu icon
│   ├── scripts/
│   │   └── pdf-canvas.js               # Fabric.js canvas editor
│   └── styles/
│       └── owm-admin.css               # Admin page styles
└── src/
    ├── WineFields.php                  # Field key → label mapping for the palette
    ├── Admin/
    │   ├── AdminSettings.php           # Settings pages, sync handlers, PDF actions
    │   └── ProductMetaBox.php          # Meta box on the owm_product edit screen
    ├── API/
    │   └── HotelBossClient.php         # HTTP client for the HotelBoss REST API
    ├── DTO/
    │   ├── ProductDTO.php              # Product data object (attributes map)
    │   ├── ProductGroupDTO.php         # Product group / category data object
    │   └── InventoryMovementDTO.php    # Inventory movement record data object
    ├── PDF/
    │   └── PdfGenerator.php            # Template parsing and mPDF document builder
    └── Services/
        ├── ProductSyncService.php      # Upserts API products into owm_product posts
        └── InventorySyncService.php    # Updates stock quantities from movement records
```

---

## WordPress Options Reference

| Option key | Type | Description |
|---|---|---|
| `owm_company_id` | string | HotelBoss company ID |
| `owm_username` | string | HotelBoss API username |
| `owm_password` | string | HotelBoss API password |
| `owm_selected_hotel_id` | string | ID of the selected hotel |
| `owm_pdf_template` | JSON string | Serialised Fabric.js canvas template |
| `owm_type_order` | JSON string | Ordered array of wine category names |
| `owm_last_sync` | MySQL datetime | Timestamp of the last product sync |
| `owm_last_inventory_sync` | MySQL datetime | Timestamp of the last inventory sync |

Transients:

| Key | TTL | Description |
|---|---|---|
| `owm_hotel_list` | 1 hour | Cached hotel list from the API |

