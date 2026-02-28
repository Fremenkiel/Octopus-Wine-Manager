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

        // Build per-column CSS
        $col_styles = '';

        foreach ($rows as $key => $row) {
          foreach ($row as $i => $col) {
            $fs = isset($col['fontSize']) ? intval($col['fontSize']) . 'px' : '11px';
            $ff = $this->safe_font($col['fontFamily'] ?? 'Arial');
            $fw = ($col['fontWeight'] ?? '') === 'bold'   ? 'bold'   : 'normal';
            $fi = ($col['fontStyle']  ?? '') === 'italic' ? 'italic' : 'normal';
            $fc = $this->safe_color($col['fill'] ?? '#1e293b');
            $ta = in_array($col['textAlign'] ?? 'left', ['left', 'center', 'right']) ? $col['textAlign'] : 'left';
            $w  = $col['width'] ?? 'auto';
            if ($col['key'] === 'price') {
                $min_pct = round(245 / self::CANVAS_WIDTH * 100, 1);
                if ((float) $w < $min_pct) {
                    $w = $min_pct . '%';
                }
            }
            $col_styles .= ".col-{$key}-{$i}{font-size:{$fs};font-family:{$ff};font-weight:{$fw};font-style:{$fi};color:{$fc};text-align:{$ta};width:{$w};}\n";
          }
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

        foreach ($tree as $category => $by_country) {
            ksort($by_country);

            // Activate running page header for this category (takes effect from
            // the next page break, so no duplication with the inline H1 below).
            $body .= sprintf('<sethtmlpageheader name="cat_%s" value="on"/>', md5($category));
            // H1 — shown inline where the category section starts
            $body .= sprintf('<div class="pdf-h1">%s</div>', esc_html($category));

            foreach ($by_country as $country => $by_region) {
                ksort($by_region);

                $is_first_region = true;

                foreach ($by_region as $region => $region_wines) {
                    // Sort wines within region: sub_region → commune → village → producer → name
                    usort($region_wines, function ($a, $b) {
                        foreach (['sub_region', 'commune', 'village', 'producer'] as $key) {
                            $cmp = strcasecmp($a[$key] ?? '', $b[$key] ?? '');
                            if ($cmp !== 0) return $cmp;
                        }
                        return strcasecmp($a['name'], $b['name']);
                    });

                    // Pull out the first wine so it can be anchored to the headers
                    // in a single page-break-inside:avoid table. This prevents headers
                    // from appearing alone at the bottom of a page.
                    $first_wine = array_shift($region_wines);

                    $body .= '<table style="page-break-inside: avoid">';

                    if ($is_first_region) {
                        // H2 — Country header row
                        $span = array_search($headers[2]['x'], $coltemplate);
                        $body .= '<tr>';
                        if ($span !== 0) {
                            $body .= sprintf('<td colspan="%d"></td>', $span);
                        }
                        $body .= sprintf(
                            '<td colspan="%d" class="pdf-h2">%s</td>',
                            count($coltemplate) - $span,
                            esc_html($country)
                        );
                        $body .= '</tr>';
                    }

                    if ($region !== '__none__') {
                        // H3 — Region header row
                        $span = array_search($headers[3]['x'], $coltemplate);
                        $body .= '<tr>';
                        if ($span !== 0) {
                            $body .= sprintf('<td colspan="%d"></td>', $span);
                        }
                        $body .= sprintf(
                            '<td colspan="%d" class="pdf-h3">%s</td>',
                            count($coltemplate) - $span,
                            esc_html($region)
                        );
                        $body .= '</tr>';
                    }

                    // First wine row(s) — anchors headers to real content
                    if ($first_wine) {
                        foreach ($rows as $key => $row) {
                            $body .= '<tr class="wine-row">';
                            foreach ($row as $i => $col) {
                                $span = array_search($col['x'], $coltemplate);
                                if ($span !== $i) {
                                    $body .= sprintf('<td colspan="%d"></td>', $span);
                                }
                                $body .= sprintf('<td class="col-%d-%d">%s</td>', $key, $i,
                                    esc_html($this->format_field($first_wine, $col['key'])));
                            }
                            $body .= '</tr>';
                        }
                    }

                    $body .= '</table>';

                    // Remaining wines — each in its own page-break-inside: avoid table
                    foreach ($region_wines as $wine) {
                        $body .= '<table style="page-break-inside: avoid">';
                        foreach ($rows as $key => $row) {
                            $body .= '<tr class="wine-row">';
                            foreach ($row as $i => $col) {
                                $span = array_search($col['x'], $coltemplate);
                                if ($span !== $i) {
                                    $body .= sprintf('<td colspan="%d"></td>', $span);
                                }
                                $body .= sprintf('<td class="col-%d-%d">%s</td>', $key, $i,
                                    esc_html($this->format_field($wine, $col['key'])));
                            }
                            $body .= '</tr>';
                        }
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

/* H2 — Country (from template) */
.pdf-h2 { padding: 16px 8px 3px; {$h2_css} }

/* H3 — Region as table section row (from template) */
.pdf-h3 { padding: 4px 8px; {$h3_css} }

/* Table */
table { width: 100%; border-collapse: collapse; margin: 5px 0px; }
td { padding: 0px 8px; vertical-align: middle; }

{$col_styles}
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
