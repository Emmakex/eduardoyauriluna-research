<?php
/** Bounded Theme-owned section/component presentation variants. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_design_variant_contract(): array {
    return array(
        'version'=>1,
        'option'=>'eduardo_research_design_variants',
        'defaults'=>array(
            'hero'=>'orbit',
            'sections'=>'layered',
            'cards'=>'bordered',
            'collections'=>'editorial-grid',
        ),
        'hero'=>array(
            'orbit'=>array('label'=>'Research Orbit','description'=>'Two-column hero with the Theme-owned research-system orbit visual.'),
            'editorial'=>array('label'=>'Editorial','description'=>'Single-column editorial reading flow with the orbit visual below the copy.'),
            'compact'=>array('label'=>'Compact','description'=>'Focused text-first hero; the decorative aria-hidden orbit is suppressed.'),
        ),
        'sections'=>array(
            'layered'=>array('label'=>'Layered','description'=>'Current layered Theme treatment with soft tonal sections.'),
            'plain'=>array('label'=>'Plain','description'=>'Restrained white-space-led sections with borders instead of tonal blocks.'),
            'bands'=>array('label'=>'Bands','description'=>'Alternating bounded tonal bands while preserving section order and semantics.'),
        ),
        'cards'=>array(
            'bordered'=>array('label'=>'Bordered','description'=>'Current bounded card surface with line and subtle depth.'),
            'lifted'=>array('label'=>'Lifted','description'=>'Stronger depth and hover lift without changing card content or links.'),
            'quiet'=>array('label'=>'Quiet','description'=>'Low-chrome card treatment using borders and transparent surfaces.'),
        ),
        'collections'=>array(
            'editorial-grid'=>array('label'=>'Editorial Grid','description'=>'Current editorial multi-column research collection grid.'),
            'compact-grid'=>array('label'=>'Compact Grid','description'=>'Denser bounded grid for scanning larger research collections.'),
            'list'=>array('label'=>'Research List','description'=>'Single-column list presentation while retaining the same article/card markup.'),
        ),
    );
}

function eduardo_research_design_variant_sanitize_state($value): array {
    $contract = eduardo_research_design_variant_contract();
    $value = is_array($value) ? $value : array();
    $state = array();
    foreach (array('hero','sections','cards','collections') as $dimension) {
        $requested = sanitize_key((string) ($value[$dimension] ?? $contract['defaults'][$dimension]));
        $state[$dimension] = isset($contract[$dimension][$requested]) ? $requested : (string) $contract['defaults'][$dimension];
    }
    return $state;
}

function eduardo_research_design_variant_state(): array {
    $contract = eduardo_research_design_variant_contract();
    $stored = get_option((string) $contract['option'], $contract['defaults']);
    return eduardo_research_design_variant_sanitize_state($stored);
}

function eduardo_research_design_variant_body_classes(array $classes): array {
    $state = eduardo_research_design_variant_state();
    foreach ($state as $dimension => $variant) {
        $classes[] = 'research-variant-' . sanitize_html_class((string) $dimension) . '-' . sanitize_html_class((string) $variant);
    }
    return array_values(array_unique($classes));
}
add_filter('body_class', 'eduardo_research_design_variant_body_classes');

function eduardo_research_design_variant_css(): string {
    return implode('', array(
        /* Hero: semantic markup never changes; only layout/presentation does. */
        '.research-variant-hero-editorial .research-hero{grid-template-columns:minmax(0,1fr);align-items:start}',
        '.research-variant-hero-editorial .research-hero-copy{max-width:58rem}',
        '.research-variant-hero-editorial .research-hero-visual{width:min(100%,46rem);margin-top:clamp(1.5rem,4vw,3.5rem)}',
        '.research-variant-hero-compact .research-hero{grid-template-columns:minmax(0,1fr);min-height:auto}',
        '.research-variant-hero-compact .research-hero-copy{max-width:54rem}',
        '.research-variant-hero-compact .research-hero-visual{display:none}',
        '.research-variant-hero-compact .research-title{max-width:14ch}',

        /* Sections: only background/border rhythm changes. */
        '.research-variant-sections-plain .research-section-soft{background:transparent}',
        '.research-variant-sections-plain .research-section{border-top:1px solid var(--research-line)}',
        '.research-variant-sections-plain .research-section:first-of-type{border-top:0}',
        '.research-variant-sections-bands main>.research-section:nth-of-type(even){background:var(--research-soft)}',
        '.research-variant-sections-bands main>.research-section:nth-of-type(odd){background:var(--research-bg)}',

        /* Cards: markup, links and hierarchy remain identical. */
        '.research-variant-cards-lifted .research-card,.research-variant-cards-lifted .research-interior-record,.research-variant-cards-lifted .research-interior-link-card{box-shadow:0 18px 46px rgba(11,24,32,.12);transition:transform .2s ease,box-shadow .2s ease}',
        '.research-variant-cards-lifted .research-card:hover,.research-variant-cards-lifted .research-interior-link-card:hover{transform:translateY(-3px);box-shadow:0 24px 58px rgba(11,24,32,.16)}',
        '.research-variant-cards-quiet .research-card,.research-variant-cards-quiet .research-interior-record,.research-variant-cards-quiet .research-interior-link-card{background:transparent;box-shadow:none;border-color:var(--research-line)}',

        /* Collection density/layout only; each record remains an article/card. */
        '.research-variant-collections-compact-grid .research-collection-grid,.research-variant-collections-compact-grid .research-object-index .research-grid-editorial,.research-variant-collections-compact-grid .research-insight-index .research-grid-editorial{grid-template-columns:repeat(auto-fit,minmax(min(100%,13.5rem),1fr));gap:.75rem}',
        '.research-variant-collections-compact-grid .research-object-index .research-card,.research-variant-collections-compact-grid .research-insight-index .research-card{padding:clamp(1rem,2vw,1.35rem)}',
        '.research-variant-collections-list .research-collection-grid,.research-variant-collections-list .research-object-index .research-grid-editorial,.research-variant-collections-list .research-insight-index .research-grid-editorial{grid-template-columns:minmax(0,1fr)}',
        '.research-variant-collections-list .research-object-index .research-card,.research-variant-collections-list .research-insight-index .research-card{min-height:0}',

        '@media (max-width:760px){.research-variant-hero-editorial .research-hero-visual{width:100%}.research-variant-cards-lifted .research-card:hover,.research-variant-cards-lifted .research-interior-link-card:hover{transform:none}}',
        '@media (prefers-reduced-motion:reduce){.research-variant-cards-lifted .research-card,.research-variant-cards-lifted .research-interior-link-card{transition:none}}',
    ));
}

function eduardo_research_render_design_variants(): void {
    if (is_admin()) { return; }
    echo '<style id="eduardo-research-component-variants">' . eduardo_research_design_variant_css() . '</style>' . "\n";
}
add_action('wp_head', 'eduardo_research_render_design_variants', 100);

function eduardo_research_design_variant_public_contract(): array {
    $contract = eduardo_research_design_variant_contract();
    return array(
        'version'=>(int) $contract['version'],
        'state'=>eduardo_research_design_variant_state(),
        'presets'=>array(
            'hero'=>$contract['hero'],
            'sections'=>$contract['sections'],
            'cards'=>$contract['cards'],
            'collections'=>$contract['collections'],
        ),
        'body_classes'=>eduardo_research_design_variant_body_classes(array()),
        'constraints'=>array(
            'semantic_markup_unchanged'=>true,
            'content_order_unchanged'=>true,
            'arbitrary_html'=>false,
            'arbitrary_class'=>false,
            'arbitrary_css'=>false,
            'section_hiding'=>false,
            'reduced_motion_respected'=>true,
        ),
    );
}
