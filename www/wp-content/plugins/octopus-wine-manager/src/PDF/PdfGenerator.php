<?php

namespace OctopusWineManagment\PDF;

use Mpdf\Mpdf;

class PdfGenerator {

    private const CANVAS_WIDTH = 595.0;

    private const FIELD_LABELS = [
        'name'     => 'Wine',
        'price'    => 'Price',
        'vat'      => 'VAT %',
        'sku'      => 'Item #',
        'quantity' => 'Stock',
        'location' => 'Location',
    ];

    /** Default styles used when the user hasn't placed an H1/H2/H3 element */
    private const HEADER_DEFAULTS = [
        1 => ['fontSize' => '18px', 'fontFamily' => 'Arial', 'fontWeight' => 'bold',   'fontStyle' => 'normal', 'fill' => '#1e293b', 'textAlign' => 'left'],
        2 => ['fontSize' => '13px', 'fontFamily' => 'Arial', 'fontWeight' => 'bold',   'fontStyle' => 'normal', 'fill' => '#334155', 'textAlign' => 'left'],
        3 => ['fontSize' => '11px', 'fontFamily' => 'Arial', 'fontWeight' => 'normal', 'fontStyle' => 'italic', 'fill' => '#64748b', 'textAlign' => 'left'],
    ];

    public function generate(): void {
        $template_json = get_option('owm_pdf_template', '');
        $template      = $template_json ? json_decode($template_json, true) : null;
        $parsed        = $this->extract_template($template);
        $wines         = $this->get_all_wines();
        $html          = $this->render_html($wines, $parsed['columns'], $parsed['headers']);

        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/owm-mpdf-tmp';
        wp_mkdir_p($temp_dir);

        $mpdf = new Mpdf([
            'format'        => 'A4',
            'margin_left'   => 15,
            'margin_right'  => 15,
            'margin_top'    => 20,
            'margin_bottom' => 20,
            'tempDir'       => $temp_dir,
        ]);

        $mpdf->SetTitle('Wine List');
        $mpdf->WriteHTML($html);
        $mpdf->Output('wine-list.pdf', \Mpdf\Output\Destination::DOWNLOAD);
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
            $wines[] = [
                'name'     => $post->post_title,
                'category' => get_post_meta($post->ID, '_owm_product_group_name', true) ?: 'Other',
                'origin'   => get_post_meta($post->ID, '_owm_country_origin',     true) ?: 'Unknown',
                'region'   => get_post_meta($post->ID, '_owm_region',             true) ?: '',
                'price'    => get_post_meta($post->ID, '_owm_price',              true) ?: '',
                'vat'      => get_post_meta($post->ID, '_owm_vat_percent',        true) ?: '',
                'sku'      => get_post_meta($post->ID, '_owm_product_id',         true) ?: '',
                'quantity' => get_post_meta($post->ID, '_owm_quantity',           true) ?: '',
                'location' => get_post_meta($post->ID, '_owm_storage_location',   true) ?: '',
            ];
        }

        return $wines;
    }

    // ── Template parsing ─────────────────────────────────────────────────

    /**
     * Returns ['columns' => [...], 'headers' => [1 => [...], 2 => [...], 3 => [...]]]
     */
    private function extract_template(?array $template): array {
        $default_columns = [
            ['key' => 'name',  'x' => 0,   'width' => '60%', 'fontSize' => 11],
            ['key' => 'price', 'x' => 357, 'width' => '20%', 'fontSize' => 11, 'textAlign' => 'right'],
            ['key' => 'sku',   'x' => 476, 'width' => '20%', 'fontSize' => 11, 'textAlign' => 'right'],
        ];

        if (!$template || empty($template['objects'])) {
            return ['columns' => $default_columns, 'headers' => self::HEADER_DEFAULTS];
        }

        $columns = [];
        $headers = self::HEADER_DEFAULTS; // start from defaults, override below

        foreach ($template['objects'] as $obj) {
            if (empty($obj['placeholderKey'])) continue;

            $level = isset($obj['headerLevel']) ? intval($obj['headerLevel']) : 0;

            if ($level >= 1 && $level <= 3) {
                // H1 / H2 / H3 — take its style, ignore x/y position
                $headers[$level] = [
                    'fontSize'   => isset($obj['fontSize']) ? intval($obj['fontSize']) . 'px' : self::HEADER_DEFAULTS[$level]['fontSize'],
                    'fontFamily' => $obj['fontFamily'] ?? self::HEADER_DEFAULTS[$level]['fontFamily'],
                    'fontWeight' => $obj['fontWeight'] ?? self::HEADER_DEFAULTS[$level]['fontWeight'],
                    'fontStyle'  => $obj['fontStyle']  ?? self::HEADER_DEFAULTS[$level]['fontStyle'],
                    'fill'       => $obj['fill']        ?? self::HEADER_DEFAULTS[$level]['fill'],
                    'textAlign'  => $obj['textAlign']   ?? self::HEADER_DEFAULTS[$level]['textAlign'],
                ];
            } else {
                // Row field — x position defines column order, full style applied
                $columns[] = [
                    'key'        => $obj['placeholderKey'],
                    'x'          => (float) ($obj['left'] ?? 0),
                    'fontSize'   => (int)   ($obj['fontSize'] ?? 11),
                    'fontFamily' => $obj['fontFamily'] ?? 'Arial',
                    'fontWeight' => $obj['fontWeight'] ?? 'normal',
                    'fontStyle'  => $obj['fontStyle']  ?? 'normal',
                    'fill'       => $obj['fill']        ?? '#1e293b',
                    'textAlign'  => $obj['textAlign']   ?? 'left',
                ];
            }
        }

        if (empty($columns)) {
            $columns = $default_columns;
        } else {
            usort($columns, fn($a, $b) => $a['x'] <=> $b['x']);
            $count = count($columns);
            for ($i = 0; $i < $count; $i++) {
                $next_x           = ($i + 1 < $count) ? (float) $columns[$i + 1]['x'] : self::CANVAS_WIDTH;
                $pct              = ($next_x - (float) $columns[$i]['x']) / self::CANVAS_WIDTH * 100;
                $columns[$i]['width'] = max(round($pct, 1), 5) . '%';
            }
        }

        return ['columns' => $columns, 'headers' => $headers];
    }

    // ── Rendering ────────────────────────────────────────────────────────

    private function render_html(array $wines, array $columns, array $headers): string {
        if (empty($wines)) {
            return '<html><body style="font-family:Arial;padding:40px"><p>No products found. Please sync from the API first.</p></body></html>';
        }

        // Build column header cells + per-column CSS
        $col_heads  = '';
        $col_styles = '';

        foreach ($columns as $i => $col) {
            $label = self::FIELD_LABELS[$col['key']] ?? ucwords(str_replace('_', ' ', $col['key']));
            $width = $col['width'] ?? 'auto';
            $col_heads .= sprintf('<th class="col-%d" style="width:%s">%s</th>', $i, esc_attr($width), esc_html($label));

            $fs = isset($col['fontSize']) ? intval($col['fontSize']) . 'px' : '11px';
            $ff = $this->safe_font($col['fontFamily'] ?? 'Arial');
            $fw = ($col['fontWeight'] ?? '') === 'bold'   ? 'bold'   : 'normal';
            $fi = ($col['fontStyle']  ?? '') === 'italic' ? 'italic' : 'normal';
            $fc = $this->safe_color($col['fill'] ?? '#1e293b');
            $ta = in_array($col['textAlign'] ?? 'left', ['left', 'center', 'right']) ? $col['textAlign'] : 'left';
            $col_styles .= ".col-{$i}{font-size:{$fs};font-family:{$ff};font-weight:{$fw};font-style:{$fi};color:{$fc};text-align:{$ta};}\n";
        }

        // Build header CSS from template styles
        $h1_css = $this->header_css($headers[1]);
        $h2_css = $this->header_css($headers[2]);
        $h3_css = $this->header_css($headers[3]);

        // Group: wine type → country → region → wines
        $tree = [];
        foreach ($wines as $wine) {
            $tree[$wine['category']][$wine['origin']][$wine['region'] ?: '__none__'][] = $wine;
        }
        ksort($tree);

        $body    = '';
        $colspan = count($columns);
        $total   = count($wines);

        foreach ($tree as $category => $by_country) {
            ksort($by_country);

            // H1 — Wine type (only if user placed an H1 element OR there are multiple categories)
            $body .= sprintf('<div class="pdf-h1">%s</div>', esc_html($category));

            foreach ($by_country as $country => $by_region) {
                ksort($by_region);

                // H2 — Country
                $body .= sprintf('<div class="pdf-h2">%s</div>', esc_html($country));

                $body .= '<table><thead><tr>' . $col_heads . '</tr></thead><tbody>';

                foreach ($by_region as $region => $region_wines) {
                    if ($region !== '__none__') {
                        // H3 — Region as a spanning table row
                        $body .= sprintf(
                            '<tr><td colspan="%d" class="pdf-h3">%s</td></tr>',
                            $colspan,
                            esc_html($region)
                        );
                    }

                    foreach ($region_wines as $wine) {
                        $body .= '<tr class="wine-row">';
                        foreach ($columns as $i => $col) {
                            $body .= sprintf('<td class="col-%d">%s</td>', $i,
                                esc_html($this->format_field($wine, $col['key'])));
                        }
                        $body .= '</tr>';
                    }
                }

                $body .= '</tbody></table>';
            }
        }

        $date = esc_html(date('F j, Y'));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 0; padding: 0; }

.page-header { text-align: center; margin-bottom: 24px; padding-bottom: 12px; border-bottom: 2px solid #1e293b; }
.page-header h1 { font-size: 24px; font-weight: 700; letter-spacing: 0.06em; margin: 0 0 4px; text-transform: uppercase; }
.page-header p  { font-size: 10px; color: #64748b; margin: 0; }

/* H1 — Wine type (from template) */
.pdf-h1 { {$h1_css} margin: 18px 0 4px; padding: 7px 10px; }

/* H2 — Country (from template) */
.pdf-h2 { {$h2_css} padding: 5px 0 3px; margin: 6px 0 0; border-bottom: 1px solid currentColor; }

/* Table */
table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
th { text-align: left; padding: 4px 8px; font-size: 9px; font-weight: 700;
     text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; border-bottom: 1px solid #cbd5e1; }
td { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.wine-row:nth-child(even) td { background: #f8fafc; }

/* H3 — Region as table section row (from template) */
.pdf-h3 { {$h3_css} padding: 4px 8px; background: #f1f5f9; border-top: 1px solid #e2e8f0; }

{$col_styles}
</style>
</head>
<body>
<div class="page-header">
  <h1>Wine List</h1>
  <p>{$total} wines &mdash; {$date}</p>
</div>
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
        return "font-family:{$ff};font-size:{$fs};font-weight:{$fw};font-style:{$fi};color:{$fc};text-align:{$ta};";
    }

    private function format_field(array $wine, string $key): string {
        $value = $wine[$key] ?? '';
        if ($key === 'price' && $value !== '') {
            return number_format((float) $value, 2);
        }
        return (string) $value;
    }

    private function safe_font(string $font): string {
        $allowed = ['Arial', 'Helvetica', 'Times', 'Times New Roman', 'Courier', 'Georgia'];
        return in_array($font, $allowed, true) ? $font : 'Arial';
    }

    private function safe_color(string $color): string {
        if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) return $color;
        if (preg_match('/^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/i', $color)) return $color;
        return '#1e293b';
    }
}
