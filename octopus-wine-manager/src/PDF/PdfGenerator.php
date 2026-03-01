<?php

namespace OctopusWineManagment\PDF;

use Mpdf\Mpdf;

class PdfGenerator {

    private const CANVAS_WIDTH = 595.0;

    /** Default styles used when the user hasn't placed an H1/H2/H3 element */
    private const HEADER_DEFAULTS = [
        1 => ['fontSize' => '18px', 'fontFamily' => 'Arial', 'fontWeight' => 'bold',   'fontStyle' => 'normal', 'fill' => '#1e293b', 'textAlign' => 'left'],
        2 => ['fontSize' => '13px', 'fontFamily' => 'Arial', 'fontWeight' => 'bold',   'fontStyle' => 'normal', 'fill' => '#334155', 'textAlign' => 'left'],
        3 => ['fontSize' => '11px', 'fontFamily' => 'Arial', 'fontWeight' => 'normal', 'fontStyle' => 'italic', 'fill' => '#64748b', 'textAlign' => 'left'],
    ];

    public function generate(string $template_json = ''): void {
        if ($template_json === '') {
            $template_json = get_option('owm_pdf_template', '');
        }
        $template = $template_json ? json_decode($template_json, true) : null;
        $parsed   = $this->extract_template($template);
        $wines    = $this->get_all_wines();

        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/owm-mpdf-tmp';
        wp_mkdir_p($temp_dir);

        $font_dir = plugin_dir_path(dirname(__DIR__, 1)) . 'assets/fonts/';

        $mpdf = new Mpdf([
            'format'        => 'A4',
            'margin_left'   => 30,
            'margin_right'  => 10,
            'margin_top'    => 22,
            'margin_bottom' => 22,
            'tempDir'       => $temp_dir,
            'fontDir'       => [$font_dir],
            'fontdata'      => [
                'twcenmt' => ['R' => 'tw-cen-mt-regular.ttf'],
            ],
        ]);

        // Register one named HTML header per category via the PHP API.
        // The header activates from the page AFTER the category starts (no
        // show-this-page), so the inline H1 div handles the opening page and
        // subsequent pages get the running header — no duplication.
        $h1_style = $this->header_css($parsed['headers'][1]);
        foreach ($this->get_sorted_categories($wines) as $category) {
            $mpdf->DefHTMLHeaderByName(
                'cat_' . md5($category),
                '<div style="padding:1mm 0;' . $h1_style . '">' . htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . '</div>'
            );
        }

        $html = $this->render_html($wines, $parsed['rows'], $parsed['headers']);

        $mpdf->SetTitle('Wine List');
        $mpdf->WriteHTML($html);
        $mpdf->Output('wine-list.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    }

    private function get_sorted_categories(array $wines): array {
        $categories  = array_values(array_unique(array_column($wines, 'category')));
        $saved_order = json_decode(get_option('owm_type_order', '[]'), true) ?: [];
        $order_map   = array_flip($saved_order);
        usort($categories, function ($a, $b) use ($order_map) {
            $ia = $order_map[$a] ?? PHP_INT_MAX;
            $ib = $order_map[$b] ?? PHP_INT_MAX;
            if ($ia !== $ib) return $ia <=> $ib;
            return strcasecmp($a, $b);
        });
        return $categories;
    }

    // ── Data ─────────────────────────────────────────────────────────────

    private function get_all_wines(): array {
        $posts = get_posts([
            'post_type'      => 'owm_product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $wines = [];
        foreach ($posts as $post) {
            $stock = get_post_meta($post->ID, '_owm_quantity', true) ?: 0;
            if ($stock < 1) {
              continue;
            }

            $country = get_post_meta($post->ID, '_owm_country_origin',     true) ?: 'N/A';
            $region = get_post_meta($post->ID, '_owm_region',             true) ?: '';
            $sub_region = get_post_meta($post->ID, '_owm_sub_region',         true) ?: '';
            $commune = get_post_meta($post->ID, '_owm_commune',            true) ?: '';
            $village = get_post_meta($post->ID, '_owm_village',            true) ?: '';
            $vineyard = get_post_meta($post->ID, '_owm_vineyard',           true) ?: '';
            $grape = get_post_meta($post->ID, '_owm_grape',              true) ?: '';

            $wine = get_post_meta($post->ID, '_owm_wine',           true) ?: '';
            $producer = get_post_meta($post->ID, '_owm_producer',           true) ?: '';
            $appellation = get_post_meta($post->ID, '_owm_appellation',        true) ?: '';
            $sweetness = get_post_meta($post->ID, '_owm_sweetness',          true) ?: '';
            $classification = get_post_meta($post->ID, '_owm_classification',           true) ?: '';
            $volume = get_post_meta($post->ID, '_owm_volume',           true) ?: '';

            if (isset($volume) && $volume !== '' && $volume !== '0.75') {
              $volume = $volume . ' L';
            } else {
              $volume = '';
            }

            $wine = implode(', ', array_filter([
                                    $producer,
                                    $wine,
                                    $classification,
                                    $sweetness,
                                    $volume,
                                 ]));

            if (!isset($wine) || $wine == '') {
              continue;
            }

            $wines[] = [
                'name'           => $post->post_title,
                'category'       => get_post_meta($post->ID, '_owm_product_group_name', true) ?: 'Andet',
                'country'        => $country,
                'region'         => $region,
                'sub_region'     => $sub_region,
                'commune'        => $commune,
                'village'        => $village,
                'producer'       => $producer,
                'vintage'        => get_post_meta($post->ID, '_owm_vintage',            true) ?: '',
                'grape'          => $grape,
                'price'          => get_post_meta($post->ID, '_owm_price',              true) ?: '',
                'quantity'       => get_post_meta($post->ID, '_owm_quantity',           true) ?: '',
                'appellation'    => $appellation,
                'classification' => $classification,
                'sweetness'      => $sweetness,
                'vineyard'       => $village,
                'origin'         => implode(' / ', array_filter([
                                    $country,
                                    $region,
                                    $sub_region,
                                    $commune,
                                    $village,
                                    $vineyard,
                                    $grape,
                                 ])),
                'wine'         => $wine,
            ];
        }

        return $wines;
    }

    // ── Template parsing ─────────────────────────────────────────────────

    /**
     * Returns ['rows' => [...], 'headers' => [1 => [...], 2 => [...], 3 => [...]]]
     */
    private function extract_template(?array $template): array {
        $default_columns = [
            ['key' => 'name',  'x' => 0,   'width' => '60%', 'fontSize' => 11],
            ['key' => 'price', 'x' => 357, 'width' => '20%', 'fontSize' => 11, 'textAlign' => 'right'],
            ['key' => 'sku',   'x' => 476, 'width' => '20%', 'fontSize' => 11, 'textAlign' => 'right'],
        ];

        if (!$template || empty($template['objects'])) {
            return ['rows' => $default_columns, 'headers' => self::HEADER_DEFAULTS];
        }

        $rows = [];
        $headers = self::HEADER_DEFAULTS; // start from defaults, override below

        foreach ($template['objects'] as $obj) {
            if (empty($obj['placeholderKey'])) continue;

            $level = isset($obj['headerLevel']) ? intval($obj['headerLevel']) : 0;

            if ($level >= 1 && $level <= 3) {
                // H1 / H2 / H3 — take its style, ignore x/y position
                $x           =  (float) ($obj['left'] ?? 0);
                $pct              = ($x - 20) > 0 ? ($x - 20) / self::CANVAS_WIDTH * 100 : 0;
                $ml = max(round($pct, 1), 5) . '%';

                $ta = $obj['textAlign']   ?? self::HEADER_DEFAULTS[$level]['textAlign'];

                $headers[$level] = [
                    'fontSize'   => isset($obj['fontSize']) ? intval($obj['fontSize']) . 'px' : self::HEADER_DEFAULTS[$level]['fontSize'],
                    'fontFamily' => $obj['fontFamily'] ?? self::HEADER_DEFAULTS[$level]['fontFamily'],
                    'fontWeight' => $obj['fontWeight'] ?? self::HEADER_DEFAULTS[$level]['fontWeight'],
                    'fontStyle'  => $obj['fontStyle']  ?? self::HEADER_DEFAULTS[$level]['fontStyle'],
                    'fill'       => $obj['fill']        ?? self::HEADER_DEFAULTS[$level]['fill'],
                    'textAlign'  => $obj['textAlign']   ?? self::HEADER_DEFAULTS[$level]['textAlign'],
                    'x'          => $x,
                    'y'          => (float) ($obj['top'] ?? 0),
                    'marginLeft'=> $ta == 'center' ? 'unset' : $ml,
                ];
            } else {
                // Row field — x position defines column order, full style applied
                $y = (float) ($obj['top'] ?? 0);
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
            $rows['0'] = $default_columns;
        } else {
            foreach($rows as $key=>$row) {
              usort($row, fn($a, $b) => $a['x'] <=> $b['x']);
              $count = count($row);
              for ($i = 0; $i < $count; $i++) {
                $next_x           = ($i + 1 < $count) ? (float) $row[$i + 1]['x'] : self::CANVAS_WIDTH;
                $pct              = ($next_x - (float) $row[$i]['x']) / self::CANVAS_WIDTH * 100;
                $row[$i]['width'] = max(round($pct, 1), 5) . '%';
              }
              $rows[$key] = $row;
            }
        }

        return ['rows' => $rows, 'headers' => $headers];
    }

    // ── Rendering ────────────────────────────────────────────────────────

    private function render_html(array $wines, array $rows, array $headers): string {
        if (empty($wines)) {
            return '<html><body style="font-family:Arial;padding:40px"><p>No products found. Please sync from the API first.</p></body></html>';
        }

        // Build header CSS from template styles
        $h1_css = $this->header_css($headers[1]);
        $h2_css = $this->header_css($headers[2]);
        $h3_css = $this->header_css($headers[3]);

        // Group: wine type → country → region → wines
        $tree = [];
        foreach ($wines as $wine) {
            $tree[$wine['category']][$wine['country']][$wine['region'] ?: '__none__'][] = $wine;
        }

        // Sort types by saved order, then alphabetically for unlisted types
        $saved_order = json_decode(get_option('owm_type_order', '[]'), true) ?: [];
        $order_map   = array_flip($saved_order);
        uksort($tree, function ($a, $b) use ($order_map) {
            $ia = $order_map[$a] ?? PHP_INT_MAX;
            $ib = $order_map[$b] ?? PHP_INT_MAX;
            if ($ia !== $ib) return $ia <=> $ib;
            return strcasecmp($a, $b);
        });

        $body    = '';
        $coltemplate = [];
        foreach($rows as $row) {
          foreach($row as $col) {
            if (!in_array((string) $col['x'], $coltemplate, true)) {
              $coltemplate[] = (string) $col['x'];
            }
          }
        }
        ksort($coltemplate);

        $first_category = true;
        foreach ($tree as $category => $by_country) {
            ksort($by_country);

            // Turn off the running header BEFORE the page break so the new
            // page is created with no header active — preventing the previous
            // category's title from printing at the top of the new page.
            $body .= '<sethtmlpageheader value="off"/>';

            if (!$first_category) {
                $body .= '<pagebreak />';
            }
            $first_category = false;
            $body .= sprintf('<div class="pdf-h1">%s</div>', esc_html($category));
            // Activate running header after the inline H1 so it only repeats
            // on continuation pages, never on the same page as the H1.
            $body .= sprintf('<sethtmlpageheader name="cat_%s" value="on"/>', md5($category));

            foreach ($by_country as $country => $by_region) {
                ksort($by_region);

                $is_first_region = true;

                foreach ($by_region as $region => $region_wines) {
                    usort($region_wines, function ($a, $b) {
                        foreach (['producer', 'sub_region', 'commune', 'village', 'volume'] as $key) {
                            $cmp = strcasecmp($a[$key] ?? '', $b[$key] ?? '');
                            if ($cmp !== 0) return $cmp;
                        }
                        return strcasecmp($a['name'], $b['name']);
                    });

                    // H2/H3 headers and the first wine of each region are merged
                    // into one page-break-inside:avoid table so the headers can
                    // never be stranded alone at the bottom of a page.
                    $ncols = max(count($coltemplate), 1);

                    foreach ($region_wines as $wine_idx => $wine) {
                        // Build the wine's <tr> rows first so we can embed them
                        // in either the header table or a standalone table.
                        $wine_rows = '';
                        foreach ($rows as $key => $row) {
                            $wine_rows .= '<tr>';
                            foreach ($row as $i => $col) {
                                $fs = isset($col['fontSize']) ? intval($col['fontSize']) . 'px' : '11px';
                                $ff = $this->safe_font($col['fontFamily'] ?? 'Arial');
                                $fw = ($col['fontWeight'] ?? '') === 'bold'   ? 'bold'   : 'normal';
                                $fi = ($col['fontStyle']  ?? '') === 'italic' ? 'italic' : 'normal';
                                $fc = $this->safe_color($col['fill'] ?? '#1e293b');
                                $ta = in_array($col['textAlign'] ?? 'left', ['left', 'center', 'right']) ? ($col['textAlign'] ?? 'left') : 'left';
                                $w  = $col['width'] ?? 'auto';
                                if ($col['key'] === 'price') {
                                    $min_pct = round(124 / self::CANVAS_WIDTH * 100, 1);
                                    if ((float) $w < $min_pct) {
                                        $w = $min_pct . '%';
                                    }
                                }
                                $span = array_search($col['x'], $coltemplate);
                                if ($span !== $i) {
                                    $wine_rows .= sprintf('<td colspan="%d"></td>', $span);
                                }
                                $wine_rows .= sprintf(
                                    '<td style="font-size:%s;font-family:%s;font-weight:%s;font-style:%s;color:%s;text-align:%s;width:%s;padding:0 8px;vertical-align:middle;">%s</td>',
                                    $fs, $ff, $fw, $fi, $fc, $ta, $w,
                                    esc_html($this->format_field($wine, $col['key']))
                                );
                            }
                            $wine_rows .= '</tr>';
                        }

                        $body .= '<table style="page-break-inside:avoid;">';

                        if ($wine_idx === 0) {
                            if ($is_first_region) {
                                $body .= sprintf(
                                    '<tr><td colspan="%d" style="padding:16px 8px 3px;%s">%s</td></tr>',
                                    $ncols, $h2_css, esc_html($country)
                                );
                            }
                            if ($region !== '__none__') {
                                $body .= sprintf(
                                    '<tr><td colspan="%d" style="padding:4px 8px;%s">%s</td></tr>',
                                    $ncols, $h3_css, esc_html($region)
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

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 0; padding: 0; }

/* H1 — Wine type (from template) */
.pdf-h1 { margin: 18px 0 4px; padding: 7px 8px; page-break-after: avoid; {$h1_css} }

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

    // ── Helpers ───────────────────────────────────────────────────────────

    private function header_css(array $h): string {
        $ff = $this->safe_font($h['fontFamily'] ?? 'Arial');
        $fs = $h['fontSize']   ?? '14px';
        $fw = $h['fontWeight'] ?? 'bold';
        $fi = $h['fontStyle']  ?? 'normal';
        $fc = $this->safe_color($h['fill'] ?? '#1e293b');
        $ta = in_array($h['textAlign'] ?? 'left', ['left', 'center', 'right']) ? $h['textAlign'] : 'left';
        $ml = $h['marginLeft'] ?? 0;
        
        $css = "font-family:{$ff};font-size:{$fs};font-weight:{$fw};font-style:{$fi};color:{$fc};text-align:{$ta};";
        $css .= $ml === 'unset' ? '' : "margin-left:{$ml};";
        return $css;
    }

    private function format_field(array $wine, string $key): string {
        $value = $wine[$key] ?? '';
        if ($key === 'price' && $value !== '') {
            return number_format((float) $value, 2) . ' DKK';
        }
        return (string) $value;
    }

    private function safe_font(string $font): string {
        // Map canvas font names to their mPDF-registered keys
        $custom = ['TW Cen MT' => 'twcenmt'];
        if (isset($custom[$font])) return $custom[$font];

        $allowed = ['Arial', 'Helvetica', 'Times', 'Times New Roman', 'Courier', 'Georgia'];
        return in_array($font, $allowed, true) ? $font : 'Arial';
    }

    private function safe_color(string $color): string {
        if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) return $color;
        if (preg_match('/^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/i', $color)) return $color;
        return '#1e293b';
    }
}
