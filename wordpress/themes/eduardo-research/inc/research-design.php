<?php
/** Bounded Theme-native visual design contract for remote Manager control. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_design_contract(): array {
    return array(
        'version'=>1,
        'option'=>'eduardo_research_design',
        'defaults'=>array('palette'=>'researcher','typography'=>'modern-sans','density'=>'balanced'),
        'palette'=>array(
            'researcher'=>array(
                'label'=>'Researcher Teal',
                'tokens'=>array(
                    'bg'=>'#F5F5F0','surface'=>'#FCFCFA','ink'=>'#0B1820','petrol'=>'#123A3A',
                    'text'=>'#293638','grey'=>'#647270','muted'=>'#51605F','accent'=>'#07776F',
                    'mint'=>'#61D6C6','cobalt'=>'#4169A8','soft'=>'#E8F2EF','line'=>'#D9E1DE',
                ),
            ),
            'ocean-lab'=>array(
                'label'=>'Ocean Lab',
                'tokens'=>array(
                    'bg'=>'#F4F7F8','surface'=>'#FCFEFF','ink'=>'#0A1B27','petrol'=>'#123848',
                    'text'=>'#263842','grey'=>'#5F7078','muted'=>'#4C5F68','accent'=>'#0A6575',
                    'mint'=>'#65D0C5','cobalt'=>'#355F9E','soft'=>'#E4F0F2','line'=>'#D5E1E4',
                ),
            ),
            'graphite-academic'=>array(
                'label'=>'Graphite Academic',
                'tokens'=>array(
                    'bg'=>'#F4F4F2','surface'=>'#FDFDFC','ink'=>'#161A1D','petrol'=>'#30373A',
                    'text'=>'#353B3E','grey'=>'#676F72','muted'=>'#555D60','accent'=>'#4B5F70',
                    'mint'=>'#92C9B8','cobalt'=>'#4D648E','soft'=>'#ECEFED','line'=>'#D9DEDC',
                ),
            ),
        ),
        'typography'=>array(
            'modern-sans'=>array(
                'label'=>'Modern Sans',
                'body'=>'Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
                'display'=>'Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
            ),
            'academic-mixed'=>array(
                'label'=>'Academic Mixed',
                'body'=>'ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
                'display'=>'Georgia,"Times New Roman",serif',
            ),
            'humanist-sans'=>array(
                'label'=>'Humanist Sans',
                'body'=>'"Avenir Next",Avenir,"Segoe UI",ui-sans-serif,system-ui,sans-serif',
                'display'=>'"Avenir Next",Avenir,"Segoe UI",ui-sans-serif,system-ui,sans-serif',
            ),
        ),
        'density'=>array(
            'compact'=>array(
                'label'=>'Compact',
                'shell_gutter'=>'2rem','section_padding'=>'clamp(3.5rem,6vw,6rem)','grid_gap'=>'.8rem','radius'=>'.9rem',
            ),
            'balanced'=>array(
                'label'=>'Balanced',
                'shell_gutter'=>'3rem','section_padding'=>'clamp(4.5rem,8vw,8rem)','grid_gap'=>'1rem','radius'=>'1.2rem',
            ),
            'airy'=>array(
                'label'=>'Airy',
                'shell_gutter'=>'4rem','section_padding'=>'clamp(5.5rem,10vw,10rem)','grid_gap'=>'1.25rem','radius'=>'1.5rem',
            ),
        ),
    );
}

function eduardo_research_design_sanitize_state($value): array {
    $contract = eduardo_research_design_contract();
    $value = is_array($value) ? $value : array();
    $state = array();
    foreach (array('palette','typography','density') as $dimension) {
        $requested = sanitize_key((string) ($value[$dimension] ?? $contract['defaults'][$dimension]));
        $state[$dimension] = isset($contract[$dimension][$requested]) ? $requested : (string) $contract['defaults'][$dimension];
    }
    return $state;
}

function eduardo_research_design_state(): array {
    $contract = eduardo_research_design_contract();
    $stored = get_option((string) $contract['option'], $contract['defaults']);
    return eduardo_research_design_sanitize_state($stored);
}

function eduardo_research_design_tokens(?array $state = null): array {
    $contract = eduardo_research_design_contract();
    $state = eduardo_research_design_sanitize_state($state ?? eduardo_research_design_state());
    $palette = (array) $contract['palette'][$state['palette']]['tokens'];
    $typography = (array) $contract['typography'][$state['typography']];
    $density = (array) $contract['density'][$state['density']];

    return array(
        '--research-bg'=>(string) $palette['bg'],
        '--research-surface'=>(string) $palette['surface'],
        '--research-ink'=>(string) $palette['ink'],
        '--research-petrol'=>(string) $palette['petrol'],
        '--research-text'=>(string) $palette['text'],
        '--research-grey'=>(string) $palette['grey'],
        '--research-muted'=>(string) $palette['muted'],
        '--research-accent'=>(string) $palette['accent'],
        '--research-mint'=>(string) $palette['mint'],
        '--research-cobalt'=>(string) $palette['cobalt'],
        '--research-soft'=>(string) $palette['soft'],
        '--research-line'=>(string) $palette['line'],
        '--research-font-body'=>(string) $typography['body'],
        '--research-font-display'=>(string) $typography['display'],
        '--research-shell-gutter'=>(string) $density['shell_gutter'],
        '--research-section-padding'=>(string) $density['section_padding'],
        '--research-grid-gap'=>(string) $density['grid_gap'],
        '--research-radius'=>(string) $density['radius'],
    );
}

function eduardo_research_design_css(?array $state = null): string {
    $tokens = eduardo_research_design_tokens($state);
    $declarations = array();
    foreach ($tokens as $name => $value) { $declarations[] = $name . ':' . $value; }
    return ':root{' . implode(';', $declarations) . '}'
        . 'body{font-family:var(--research-font-body)}'
        . 'h1,h2,h3,h4,h5,h6{font-family:var(--research-font-display)}'
        . '.research-shell{width:min(calc(100% - var(--research-shell-gutter)),var(--research-content))}'
        . '.research-section{padding-block:var(--research-section-padding)}'
        . '.research-grid{gap:var(--research-grid-gap)}';
}

function eduardo_research_render_design_tokens(): void {
    if (is_admin()) { return; }
    echo '<style id="eduardo-research-design-tokens">' . eduardo_research_design_css() . '</style>' . "\n";
}
add_action('wp_head', 'eduardo_research_render_design_tokens', 99);

function eduardo_research_design_relative_luminance(string $hex): float {
    $hex = ltrim($hex, '#');
    if (6 !== strlen($hex) || ! ctype_xdigit($hex)) { return 0.0; }
    $channels = array();
    foreach (array(0,2,4) as $offset) {
        $value = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function eduardo_research_design_contrast_ratio(string $foreground, string $background): float {
    $left = eduardo_research_design_relative_luminance($foreground);
    $right = eduardo_research_design_relative_luminance($background);
    return (max($left, $right) + 0.05) / (min($left, $right) + 0.05);
}

function eduardo_research_design_palette_accessibility(string $palette_key): array {
    $contract = eduardo_research_design_contract();
    if (! isset($contract['palette'][$palette_key])) {
        return array('accessible'=>false,'checks'=>array(),'minimum_ratio'=>0.0);
    }
    $palette = (array) $contract['palette'][$palette_key]['tokens'];
    $pairs = array(
        'ink_on_bg'=>array('ink','bg',7.0),
        'text_on_bg'=>array('text','bg',4.5),
        'grey_on_bg'=>array('grey','bg',4.5),
        'accent_on_bg'=>array('accent','bg',4.5),
        'surface_on_ink'=>array('surface','ink',7.0),
        'mint_on_ink'=>array('mint','ink',4.5),
    );
    $checks = array();
    $minimum = 99.0;
    $accessible = true;
    foreach ($pairs as $id => $pair) {
        $ratio = eduardo_research_design_contrast_ratio((string) $palette[$pair[0]], (string) $palette[$pair[1]]);
        $minimum = min($minimum, $ratio);
        $passes = $ratio >= (float) $pair[2];
        $accessible = $accessible && $passes;
        $checks[$id] = array('ratio'=>round($ratio, 2),'minimum'=>(float) $pair[2],'passes'=>$passes);
    }
    return array('accessible'=>$accessible,'checks'=>$checks,'minimum_ratio'=>round($minimum, 2));
}

function eduardo_research_design_public_contract(): array {
    $contract = eduardo_research_design_contract();
    $palettes = array();
    foreach ($contract['palette'] as $key => $preset) {
        $palettes[$key] = array(
            'label'=>(string) $preset['label'],
            'tokens'=>(array) $preset['tokens'],
            'accessibility'=>eduardo_research_design_palette_accessibility((string) $key),
        );
    }
    return array(
        'version'=>(int) $contract['version'],
        'state'=>eduardo_research_design_state(),
        'tokens'=>eduardo_research_design_tokens(),
        'presets'=>array(
            'palette'=>$palettes,
            'typography'=>$contract['typography'],
            'density'=>$contract['density'],
        ),
        'constraints'=>array(
            'arbitrary_css'=>false,
            'arbitrary_font_url'=>false,
            'arbitrary_token_name'=>false,
            'palette_wcag_contract'=>true,
        ),
    );
}
