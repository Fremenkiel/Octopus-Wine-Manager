<?php

namespace OctopusWineManagment\PDF;

use Mpdf\Mpdf;

/**
 * Generates the wine-list PDF using mPDF.
 *
 * Flow:
 *   1. Load the saved JSON template (or fall back to hard-coded defaults).
 *   2. Query all published owm_product posts that have stock > 0.
 *   3. Build an mPDF document: one page per wine category, with a running page
 *      header showing the category name.
 *   4. Stream the PDF to the browser (Content-Disposition: attachment).
 */
class PdfGenerator {

    /**
     * Logical canvas width in points that mirrors the Fabric.js editor (595 px = A4 width).
     * All x-positions stored in the template JSON are relative to this value.
     */
    private const CANVAS_WIDTH = 595.0;

    /**
     * Fallback header styles used when the template contains no H1/H2/H3 elements.
     * Each level maps to a CSS-style property bag consumed by header_css().
     *
     * Level 1 = Wine Category (page header, large and bold)
     * Level 2 = Country       (section divider, medium and bold)
     * Level 3 = Region        (sub-section divider, small and italic)
     */
    private const HEADER_DEFAULTS = [
        1 => ['fontSize' => '18px', 'fontFamily' => 'Arial', 'fontWeight' => 'bold',   'fontStyle' => 'normal', 'fill' => '#1e293b', 'textAlign' => 'left'],
        2 => ['fontSize' => '13px', 'fontFamily' => 'Arial', 'fontWeight' => 'bold',   'fontStyle' => 'normal', 'fill' => '#334155', 'textAlign' => 'left'],
        3 => ['fontSize' => '11px', 'fontFamily' => 'Arial', 'fontWeight' => 'normal', 'fontStyle' => 'italic', 'fill' => '#64748b', 'textAlign' => 'left'],
    ];

    /**
     * Generate and stream the wine-list PDF.
     *
     * @param string $template_json Optional raw JSON string from the canvas editor.
     *                              When empty the value stored in the 'owm_pdf_template'
     *                              WordPress option is used instead.
     */
    public function generate(string $template_json = ''): void {
        // Prefer the caller-supplied JSON; fall back to the saved option.
        if ($template_json === '') {
            $template_json = get_option('owm_pdf_template', '');
        }
        $template = $template_json ? json_decode($template_json, true) : null;

        // Parse the template into row column definitions and header style arrays.
        $parsed   = $this->extract_template($template);

        // Fetch all in-stock wines from the database.
        $wines    = $this->get_all_wines();

        // mPDF needs a writable temp directory for font caching and scratch files.
        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/owm-mpdf-tmp';
        wp_mkdir_p($temp_dir);

        // Point mPDF at the plugin's custom fonts directory so 'twcenmt' is available.
        $font_dir = plugin_dir_path(dirname(__DIR__, 1)) . 'assets/fonts/';

        $mpdf = new Mpdf([
            'format'        => 'A4',
            // Left margin is wider to accommodate a left-aligned page header.
            'margin_left'   => 30,
            'margin_right'  => 10,
            'margin_top'    => 22,
            'margin_bottom' => 22,
            'tempDir'       => $temp_dir,
            'fontDir'       => [$font_dir],
            // Register custom fonts by alias. The alias must match safe_font().
            'fontdata'      => [
                'twcenmt' => ['R' => 'tw-cen-mt-regular.ttf'],
            ],
            'debug' => true,
        ]);

        // Pre-register one named HTML header per category via the mPDF PHP API.
        // Using DefHTMLHeaderByName lets us switch the running header on each
        // page break without redefining it inline. The md5 hash turns the
        // category string into a safe ASCII identifier.
        // 'show-this-page="1"' ensures the header appears on the first page of
        // each category section, not only on continuation pages.
        $h1_style = $this->header_css($parsed['headers'][1]);
        foreach ($this->get_sorted_categories($wines) as $category) {
            $mpdf->DefHTMLHeaderByName(
                'cat_' . md5($category),
                '<div style="' . $h1_style . 'padding: 7px 8px;">' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '</div>'
            );
        }

        // Build the full HTML document and hand it to mPDF.
        $html = $this->render_html($wines, $parsed['rows'], $parsed['headers']);

        $mpdf->SetTitle('Wine List');
        $mpdf->WriteHTML($html);

        // Stream as a download; mPDF sends the correct Content-Type header.
        $mpdf->Output('wine-list.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    }

    /**
     * Returns the list of unique wine categories sorted by the admin-defined order
     * (stored in the 'owm_type_order' option). Categories not in the saved order
     * are appended alphabetically.
     *
     * @param array $wines Flat array of wine data from get_all_wines().
     * @return string[]
     */
    private function get_sorted_categories(array $wines): array {
        $categories  = array_values(array_unique(array_column($wines, 'category')));
        $saved_order = json_decode(get_option('owm_type_order', '[]'), true) ?: [];

        // Flip the saved array so we can look up a category's position by name.
        $order_map   = array_flip($saved_order);
        usort($categories, function ($a, $b) use ($order_map) {
            $ia = $order_map[$a] ?? PHP_INT_MAX; // unlisted → sort to end
            $ib = $order_map[$b] ?? PHP_INT_MAX;
            if ($ia !== $ib) return $ia <=> $ib;
            return strcasecmp($a, $b); // tie-break alphabetically
        });
        return $categories;
    }

    // ── Data ─────────────────────────────────────────────────────────────────

    /**
     * Queries all published owm_product posts and returns a flat array of wine
     * data arrays. Products with zero or negative stock are excluded. Products
     * that have no usable 'wine' display string (producer + wine name + extras)
     * are also excluded.
     *
     * The returned array keys map directly to the placeholder keys available in
     * the PDF template editor (e.g. 'wine', 'origin', 'price', 'vintage').
     *
     * @return array[]  Each element is an associative array of wine fields.
     */
    private function get_all_wines(): array {
        $posts = get_posts([
            'post_type'      => 'owm_product',
            'posts_per_page' => -1,       // fetch all products
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $wines = [];
        foreach ($posts as $post) {
            // Skip products with no stock — they should not appear in the wine list.
            $stock = get_post_meta($post->ID, '_owm_quantity', true) ?: 0;
            if ($stock < 1) {
                continue;
            }

            // Read all location/classification meta values from WordPress post meta.
            $country    = get_post_meta($post->ID, '_owm_country_origin', true) ?: 'N/A';
            $region     = get_post_meta($post->ID, '_owm_region',         true) ?: '';
            $sub_region = get_post_meta($post->ID, '_owm_sub_region',     true) ?: '';
            $commune    = get_post_meta($post->ID, '_owm_commune',        true) ?: '';
            $village    = get_post_meta($post->ID, '_owm_village',        true) ?: '';
            $vineyard   = get_post_meta($post->ID, '_owm_vineyard',       true) ?: '';
            $grape      = get_post_meta($post->ID, '_owm_grape',          true) ?: '';

            $wine           = get_post_meta($post->ID, '_owm_wine',           true) ?: '';
            $producer       = get_post_meta($post->ID, '_owm_producer',       true) ?: '';
            $appellation    = get_post_meta($post->ID, '_owm_appellation',    true) ?: '';
            $sweetness      = get_post_meta($post->ID, '_owm_sweetness',      true) ?: '';
            $classification = get_post_meta($post->ID, '_owm_classification', true) ?: '';
            $volume         = get_post_meta($post->ID, '_owm_volume',         true) ?: '';

            // Only append the volume suffix when it differs from the standard 0.75 L.
            // A blank value means the volume is unknown or standard, so omit it.
            if (isset($volume) && $volume !== '' && $volume !== '0.75') {
                $volume = $volume . ' L';
            } else {
                $volume = '';
            }

            // Build the single 'wine' display string shown in the row template.
            // Combines producer, wine name, classification, sweetness, and volume
            // into a comma-separated string, omitting any blank parts.
            $wine = implode(', ', array_filter([
                $producer,
                $wine,
                $classification,
                $sweetness,
                $volume,
            ]));

            // Skip this product entirely if it has no displayable wine string.
            if (!isset($wine) || $wine == '') {
                continue;
            }

            $wines[] = [
                'name'           => $post->post_title,
                // Category comes from the HotelBoss product group name; defaults to 'Andet'.
                'category'       => get_post_meta($post->ID, '_owm_product_group_name', true) ?: 'Andet',
                'country'        => $country,
                'region'         => $region,
                'sub_region'     => $sub_region,
                'commune'        => $commune,
                'village'        => $village,
                'producer'       => $producer,
                'vintage'        => get_post_meta($post->ID, '_owm_vintage',  true) ?: '',
                'grape'          => $grape,
                'price'          => get_post_meta($post->ID, '_owm_price',    true) ?: '',
                'quantity'       => get_post_meta($post->ID, '_owm_quantity', true) ?: '',
                'appellation'    => $appellation,
                'classification' => $classification,
                'sweetness'      => $sweetness,
                'vineyard'       => $village,
                // 'origin' is a combined slash-separated location string used as a single column.
                'origin'         => implode(' / ', array_filter([
                    $country,
                    $region,
                    $sub_region,
                    $commune,
                    $village,
                    $vineyard,
                    $grape,
                ])),
                'wine'           => $wine,
            ];
        }

        return $wines;
    }

    // ── Template Parsing ─────────────────────────────────────────────────────

    /**
     * Converts the raw template array (decoded from the Fabric.js JSON saved by
     * the canvas editor) into two structures consumed by render_html():
     *
     *   'rows'    — array of column definitions, keyed by y-position string.
     *               Each column has: key, x, y, fontSize, fontFamily, fontWeight,
     *               fontStyle, fill, textAlign, width (computed as a percentage).
     *
     *   'headers' — array keyed 1/2/3 with style properties for H1, H2, H3.
     *               Overrides HEADER_DEFAULTS for any heading level found in the
     *               template.
     *
     * When no template is saved, default three-column layout is returned.
     *
     * @param array|null $template Decoded template JSON, or null.
     * @return array{rows: array, headers: array}
     */
    private function extract_template(?array $template): array {
        // Hard-coded fallback layout: wine name | price | SKU
        $default_columns = [
            ['key' => 'name',  'x' => 0,   'width' => '60%', 'fontSize' => 11],
            ['key' => 'price', 'x' => 357, 'width' => '20%', 'fontSize' => 11, 'textAlign' => 'right'],
            ['key' => 'sku',   'x' => 476, 'width' => '20%', 'fontSize' => 11, 'textAlign' => 'right'],
        ];

        if (!$template || empty($template['objects'])) {
            return ['rows' => $default_columns, 'headers' => self::HEADER_DEFAULTS];
        }

        $rows    = [];
        $headers = self::HEADER_DEFAULTS; // start from defaults; override per heading level below

        foreach ($template['objects'] as $obj) {
            // Every canvas object must have a placeholderKey (e.g. 'wine', 'price', 'category').
            if (empty($obj['placeholderKey'])) continue;

            $level = isset($obj['headerLevel']) ? intval($obj['headerLevel']) : 0;

            if ($level >= 1 && $level <= 3) {
                // H1 / H2 / H3 — capture its style for the header renderer.
                // The x-position is converted to a percentage left margin for mPDF.
                $x   = (float) ($obj['left'] ?? 0);
                $pct = ($x - 20) > 0 ? ($x - 20) / self::CANVAS_WIDTH * 100 : 0;
                $ml  = max(round($pct, 1), 5) . '%';

                $ta = $obj['textAlign'] ?? self::HEADER_DEFAULTS[$level]['textAlign'];

                $headers[$level] = [
                    'fontSize'   => isset($obj['fontSize']) ? intval($obj['fontSize']) . 'px' : self::HEADER_DEFAULTS[$level]['fontSize'],
                    'fontFamily' => $obj['fontFamily'] ?? self::HEADER_DEFAULTS[$level]['fontFamily'],
                    'fontWeight' => $obj['fontWeight'] ?? self::HEADER_DEFAULTS[$level]['fontWeight'],
                    'fontStyle'  => $obj['fontStyle']  ?? self::HEADER_DEFAULTS[$level]['fontStyle'],
                    'fill'       => $obj['fill']        ?? self::HEADER_DEFAULTS[$level]['fill'],
                    'textAlign'  => $obj['textAlign']   ?? self::HEADER_DEFAULTS[$level]['textAlign'],
                    'x'          => $x,
                    'y'          => (float) ($obj['top'] ?? 0),
                    // Centre-aligned headers ignore marginLeft so they span the full width.
                    'marginLeft' => $ta == 'center' ? 'unset' : $ml,
                ];
            } else {
                // Row field — group by y-position so multiple columns on the same
                // vertical line become a single row template.
                $y = (float) ($obj['top'] ?? 0);
                // Use a dot-stripped string key to avoid float formatting issues.
                $rows[str_replace('.', '', (string) $y)][] = [
                    'key'        => $obj['placeholderKey'],
                    'x'          => (float) ($obj['left'] ?? 0),
                    'y'          => $y,
                    'fontSize'   => (int)   ($obj['fontSize'] ?? 11),
                    'fontFamily' => $obj['fontFamily'] ?? 'Arial',
                    'fontWeight' => $obj['fontWeight'] ?? 'normal',
                    'fontStyle'  => $obj['fontStyle']  ?? 'normal',
                    'fill'       => $obj['fill']        ?? '#1e293b',
                    'textAlign'  => $obj['textAlign']   ?? 'left',
                ];
            }
        }

        if (empty($rows)) {
            // Template had objects but none were row fields — use defaults.
            $rows['0'] = $default_columns;
        } else {
            // Sort each row's columns left-to-right by x-position, then compute
            // each column's percentage width as the distance to the next column.
            foreach ($rows as $key => $row) {
                usort($row, fn($a, $b) => $a['x'] <=> $b['x']);
                $count = count($row);
                for ($i = 0; $i < $count; $i++) {
                    // The last column stretches to the right edge of the canvas.
                    $next_x           = ($i + 1 < $count) ? (float) $row[$i + 1]['x'] : self::CANVAS_WIDTH;
                    $pct              = ($next_x - (float) $row[$i]['x']) / self::CANVAS_WIDTH * 100;
                    $row[$i]['width'] = max(round($pct, 1), 5) . '%'; // minimum 5% to avoid invisible columns
                }
                $rows[$key] = $row;
            }
        }

        return ['rows' => $rows, 'headers' => $headers];
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    /**
     * Builds the complete HTML string for the mPDF document.
     *
     * Structure:
     *   • One mPDF page break per wine category.
     *   • A named HTML page header (the category name) is activated on each new page
     *     and deactivated before the page break so it does not bleed onto the next one.
     *   • Within each category, wines are grouped: country → region → individual wines.
     *   • Each wine is rendered as an HTML table row. H2 (country) and H3 (region)
     *     headings are fused into the same page-break-inside:avoid table as the first
     *     wine of their group, preventing orphaned headings at the bottom of a page.
     *
     * Column widths are computed once per row template and enforced so that the
     * 'price' column is always at least 155 pt wide; any shortfall is taken from
     * the other columns proportionally.
     *
     * @param array $wines   Flat wine data from get_all_wines().
     * @param array $rows    Column definitions from extract_template().
     * @param array $headers Header style arrays (keys 1, 2, 3) from extract_template().
     * @return string  Complete HTML document ready for mPDF::WriteHTML().
     */
    private function render_html(array $wines, array $rows, array $headers): string {
        if (empty($wines)) {
            return '<html><body style="font-family:Arial;padding:40px"><p>No products found. Please sync from the API first.</p></body></html>';
        }

        // Pre-build CSS strings for country (H2) and region (H3) headings.
        $h2_css = $this->header_css($headers[2]);
        $h3_css = $this->header_css($headers[3]);

        // Build a nested tree: category → country → region → wines[].
        $tree = [];
        foreach ($wines as $wine) {
            // Use '__none__' as a sentinel key when a wine has no region, so it
            // still appears under its country but without a region header.
            $tree[$wine['category']][$wine['country']][$wine['region'] ?: '__none__'][] = $wine;
        }

        // Sort the top-level categories by the admin-configured order.
        $saved_order = json_decode(get_option('owm_type_order', '[]'), true) ?: [];
        $order_map   = array_flip($saved_order);
        uksort($tree, function ($a, $b) use ($order_map) {
            $ia = $order_map[$a] ?? PHP_INT_MAX;
            $ib = $order_map[$b] ?? PHP_INT_MAX;
            if ($ia !== $ib) return $ia <=> $ib;
            return strcasecmp($a, $b);
        });

        $body = '';

        // Collect all distinct x-positions across all row templates so we can
        // correctly compute colspan offsets for header rows.
        $coltemplate = [];
        foreach ($rows as $row) {
            foreach ($row as $col) {
                if (!in_array((string) $col['x'], $coltemplate, true)) {
                    $coltemplate[] = (string) $col['x'];
                }
            }
        }
        ksort($coltemplate);

        $first_category = true;
        foreach ($tree as $category => $by_country) {
            ksort($by_country); // sort countries alphabetically

            // Turn off the running header BEFORE the page break so the new page
            // is created with no header active — preventing the previous category's
            // title from printing at the top of the new page.
            $body .= '<sethtmlpageheader value="off"/>';

            if (!$first_category) {
                $body .= '<pagebreak />';
            }
            $first_category = false;

            // Activate the named header for this category. 'show-this-page="1"'
            // makes it appear on the opening page too.
            $body .= sprintf('<sethtmlpageheader name="cat_%s" value="on" show-this-page="1"/>', md5($category));

            foreach ($by_country as $country => $by_region) {
                ksort($by_region); // sort regions alphabetically

                $is_first_region = true;

                foreach ($by_region as $region => $region_wines) {
                    // Sort wines within a region by a hierarchy of location fields,
                    // falling back to alphabetical by name.
                    usort($region_wines, function ($a, $b) {
                        foreach (['producer', 'sub_region', 'commune', 'village', 'volume'] as $key) {
                            $cmp = strcasecmp($a[$key] ?? '', $b[$key] ?? '');
                            if ($cmp !== 0) return $cmp;
                        }
                        return strcasecmp($a['name'], $b['name']);
                    });

                    // Total number of distinct column positions in the template.
                    $ncols = max(count($coltemplate), 1);

                    // Determine which column index the H2 and H3 headers align to
                    // so we can insert an empty prefix <td> for correct indentation.
                    $h2_start = 0;
                    foreach ($coltemplate as $ci => $cx) {
                        if ((float) $cx <= (float) ($headers[2]['x'] ?? 0)) $h2_start = $ci;
                    }
                    $h3_start = 0;
                    foreach ($coltemplate as $ci => $cx) {
                        if ((float) $cx <= (float) ($headers[3]['x'] ?? 0)) $h3_start = $ci;
                    }

                    // Pre-compute column widths for each row template:
                    //   1. Enforce a minimum width for the 'price' column (155 pt).
                    //   2. Redistribute any extra taken from the price column across
                    //      the remaining columns, proportionally.
                    //   3. Absorb floating-point drift into the first non-price column
                    //      so every row sums to exactly 100%.
                    $price_min  = round(155 / self::CANVAS_WIDTH * 100, 1);
                    $row_widths = [];
                    foreach ($rows as $key => $row) {
                        $widths      = [];
                        $price_extra = 0.0;
                        foreach ($row as $i => $col) {
                            $raw = (float) ($col['width'] ?? 0);
                            if ($col['key'] === 'price' && $raw < $price_min) {
                                $price_extra = $price_min - $raw;
                                $widths[$i]  = $price_min;
                            } else {
                                $widths[$i] = $raw;
                            }
                        }
                        // Shrink non-price columns proportionally if price was widened.
                        if ($price_extra > 0) {
                            $other_total = 0.0;
                            foreach ($row as $i => $col) {
                                if ($col['key'] !== 'price') $other_total += $widths[$i];
                            }
                            if ($other_total > 0) {
                                foreach ($row as $i => $col) {
                                    if ($col['key'] !== 'price') {
                                        $widths[$i] = round($widths[$i] - ($widths[$i] / $other_total) * $price_extra, 1);
                                    }
                                }
                            }
                        }
                        // Absorb any rounding drift into the first non-price column.
                        $drift = round(100.0 - array_sum($widths), 1);
                        if ($drift !== 0.0) {
                            foreach ($row as $i => $col) {
                                if ($col['key'] !== 'price') {
                                    $widths[$i] = round($widths[$i] + $drift, 1);
                                    break;
                                }
                            }
                        }
                        $row_widths[$key] = $widths;
                    }

                    // Render each wine in this region group.
                    foreach ($region_wines as $wine_idx => $wine) {
                        $wine_rows = '';

                        // Each row template (y-position group) becomes one <tr>.
                        foreach ($rows as $key => $row) {
                            $last_i     = count($row) - 1;
                            $wine_rows .= '<tr>';
                            foreach ($row as $i => $col) {
                                $fs = isset($col['fontSize']) ? intval($col['fontSize']) . 'px' : '13px';
                                $ff = $this->safe_font($col['fontFamily'] ?? 'Arial');
                                $fw = ($col['fontWeight'] ?? '') === 'bold'   ? 'bold'   : 'normal';
                                $fi = ($col['fontStyle']  ?? '') === 'italic' ? 'italic' : 'normal';
                                $fc = $this->safe_color($col['fill'] ?? '#1e293b');
                                $ta = in_array($col['textAlign'] ?? 'left', ['left', 'center', 'right'])
                                    ? ($col['textAlign'] ?? 'left') : 'left';
                                $w  = $row_widths[$key][$i] . '%';
                                // Remove right padding from the last column.
                                $pr = ($i === $last_i) ? '0' : '8px';

                                // If this column's x-position doesn't start at column index $i,
                                // insert an empty spanning cell to push it to the right.
                                $span = array_search($col['x'], $coltemplate);
                                if ($span !== $i) {
                                    $wine_rows .= sprintf('<td colspan="%d"></td>', $span);
                                }
                                $wine_rows .= sprintf(
                                    '<td style="font-size:%s;font-family:%s;font-weight:%s;font-style:%s;color:%s;text-align:%s;width:%s;padding:0 %s 0 8px;vertical-align:middle;">%s</td>',
                                    $fs, $ff, $fw, $fi, $fc, $ta, $w, $pr,
                                    esc_html($this->format_field($wine, $col['key']))
                                );
                            }
                            $wine_rows .= '</tr>';
                        }

                        // Wrap in a table with page-break-inside:avoid so a wine row
                        // is never split across pages. The H2/H3 headings and first wine
                        // of each group share the same table for the same reason.
                        $body .= '<table style="page-break-inside:avoid;overflow:wrap;table-layout:fixed;">';

                        // Only the first wine in a region group gets H2/H3 headings.
                        if ($wine_idx === 0) {
                            // H2 country heading — only on the first region of each country.
                            if ($is_first_region) {
                                $h2_prefix = $h2_start > 0 ? sprintf('<td colspan="%d"></td>', $h2_start) : '';
                                $body .= sprintf(
                                    '<tr>%s<td colspan="%d" style="padding:16px 8px 3px;%s">%s</td></tr>',
                                    $h2_prefix, $ncols - $h2_start, $h2_css, esc_html($country)
                                );
                            }
                            // H3 region heading — omitted when the region key is the sentinel.
                            if ($region !== '__none__') {
                                $h3_prefix = $h3_start > 0 ? sprintf('<td colspan="%d"></td>', $h3_start) : '';
                                $body .= sprintf(
                                    '<tr>%s<td colspan="%d" style="padding:4px 8px;%s">%s</td></tr>',
                                    $h3_prefix, $ncols - $h3_start, $h3_css, esc_html($region)
                                );
                            }
                        }

                        $body .= $wine_rows;
                        $body .= '</table>';
                    }

                    $is_first_region = false;
                }
            }
        }

        // Wrap the body HTML in a minimal but valid HTML5 document.
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 0; padding: 0; }

/* Table */
table { width: 100%; border-collapse: collapse; margin: 5px 0; }
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Converts a header style array into an inline CSS string suitable for use
     * in an HTML style attribute.
     *
     * @param array $h  Header style array (keys: fontFamily, fontSize, fontWeight,
     *                  fontStyle, fill, textAlign, marginLeft).
     * @return string   CSS property string, e.g. "font-family:Arial;font-size:18px;..."
     */
    private function header_css(array $h): string {
        $ff = $this->safe_font($h['fontFamily'] ?? 'Arial');
        $fs = $h['fontSize']   ?? '14px';
        $fw = $h['fontWeight'] ?? 'bold';
        $fi = $h['fontStyle']  ?? 'normal';
        $fc = $this->safe_color($h['fill'] ?? '#1e293b');
        $ta = in_array($h['textAlign'] ?? 'left', ['left', 'center', 'right']) ? $h['textAlign'] : 'left';
        $ml = $h['marginLeft'] ?? 0;

        $css = "font-family:{$ff};font-size:{$fs};font-weight:{$fw};font-style:{$fi};color:{$fc};text-align:{$ta};";
        // 'unset' means the caller (centre-aligned) doesn't want a left margin.
        $css .= $ml === 'unset' ? '' : "margin-left:{$ml};";
        return $css;
    }

    /**
     * Returns the display value for a single wine field, applying any field-
     * specific formatting (e.g. appending ' DKK' to prices).
     *
     * @param array  $wine  Wine data array from get_all_wines().
     * @param string $key   Placeholder key (e.g. 'price', 'wine', 'vintage').
     * @return string
     */
    private function format_field(array $wine, string $key): string {
        $value = $wine[$key] ?? '';
        if ($key === 'price' && $value !== '') {
            // Format price to two decimal places and append currency symbol.
            return number_format((float) $value, 2) . ' DKK';
        }
        return (string) $value;
    }

    /**
     * Maps a canvas font name to its mPDF-registered key, or returns a safe
     * fallback. Only fonts explicitly registered in the mPDF constructor or
     * known safe system fonts are allowed through.
     *
     * @param string $font  Font name as stored in the template JSON.
     * @return string       mPDF-safe font name or key.
     */
    private function safe_font(string $font): string {
        // Map human-readable canvas font names to mPDF fontdata keys.
        $custom = ['TW Cen MT' => 'twcenmt'];
        if (isset($custom[$font])) return $custom[$font];

        $allowed = ['Arial', 'Helvetica', 'Times', 'Times New Roman', 'Courier', 'Georgia'];
        return in_array($font, $allowed, true) ? $font : 'Arial';
    }

    /**
     * Validates a CSS colour value to prevent template injection into HTML.
     * Accepts hex (#RGB or #RRGGBB) and rgb() syntax only; falls back to the
     * default dark colour for anything else.
     *
     * @param string $color  Raw colour string from the template JSON.
     * @return string        Validated CSS colour string.
     */
    private function safe_color(string $color): string {
        if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) return $color;
        if (preg_match('/^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/i', $color)) return $color;
        return '#1e293b'; // default dark slate
    }
}
