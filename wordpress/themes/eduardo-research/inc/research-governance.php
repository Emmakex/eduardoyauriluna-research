<?php
/** Explicit Theme-native governance contract for Research Manager / Gateway control. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_governance_contract(): array {
    $preset = function_exists('eduardo_research_preset') ? eduardo_research_preset() : array();
    $theme = wp_get_theme('eduardo-research');
    $pages = is_array($preset['pages'] ?? null) ? $preset['pages'] : array();
    $languages = is_array($preset['languages'] ?? null) ? array_values($preset['languages']) : array('en','es');

    $page_models = array();
    foreach ($pages as $key => $definition) {
        $models = array();
        foreach ($languages as $language) {
            $language = sanitize_key((string) $language);
            if ('home' === (string) $key && function_exists('eduardo_research_default_model')) {
                $model = eduardo_research_default_model($language);
                if (is_array($model)) {
                    unset($model['researcher-name'], $model['researcher-headline']);
                    $models[$language] = array_keys($model);
                }
            } elseif (function_exists('eduardo_research_surface_models')) {
                $surface_models = eduardo_research_surface_models($language);
                $model = is_array($surface_models[$key] ?? null) ? $surface_models[$key] : array();
                $models[$language] = array_keys($model);
            }
        }
        $page_models[(string) $key] = array(
            'role'=>(string) ($definition['role'] ?? ''),
            'model'=>(string) ($definition['model'] ?? ''),
            'languages'=>$models,
        );
    }

    return array(
        'version'=>1,
        'theme'=>array(
            'stylesheet'=>'eduardo-research',
            'version'=>$theme->exists() ? (string) $theme->get('Version') : '',
            'preset'=>(string) ($preset['id'] ?? ''),
            'preset_version'=>(int) ($preset['version'] ?? 0),
            'frontend_authority'=>(string) ($preset['frontend_authority'] ?? ''),
            'languages'=>$languages,
        ),
        'surfaces'=>array(
            'page_content'=>array(
                'available'=>true,
                'mode'=>'theme-owned-slots',
                'pages'=>$page_models,
                'identity_slots_locked'=>array('researcher-name','researcher-headline'),
                'arbitrary_html'=>false,
                'gutenberg_layout_authority'=>false,
            ),
            'navigation'=>array(
                'available'=>function_exists('eduardo_research_navigation_contract'),
                'contract'=>function_exists('eduardo_research_navigation_contract') ? eduardo_research_navigation_contract() : array(),
                'state'=>function_exists('eduardo_research_navigation_state') ? eduardo_research_navigation_state() : array(),
                'arbitrary_urls'=>false,
            ),
            'design'=>array(
                'available'=>function_exists('eduardo_research_design_public_contract'),
                'contract'=>function_exists('eduardo_research_design_public_contract') ? eduardo_research_design_public_contract() : array(),
                'arbitrary_css'=>false,
                'arbitrary_font_url'=>false,
            ),
            'variants'=>array(
                'available'=>function_exists('eduardo_research_design_variant_public_contract'),
                'contract'=>function_exists('eduardo_research_design_variant_public_contract') ? eduardo_research_design_variant_public_contract() : array(),
                'arbitrary_class'=>false,
                'section_hiding'=>false,
            ),
            'media'=>array(
                'available'=>function_exists('eduardo_research_render_primary_media'),
                'theme_owned_slots'=>true,
            ),
            'seo_geo'=>array(
                'available'=>function_exists('eduardo_research_seo_graph') || function_exists('eduardo_research_render_structured_data'),
                'theme_owned'=>true,
            ),
        ),
        'constraints'=>array(
            'frontend_generated_by_theme'=>true,
            'wordpress_editor_not_layout_authority'=>true,
            'registered_targets_only'=>true,
            'verified_identity_not_free_editorial_copy'=>true,
            'arbitrary_php'=>false,
            'arbitrary_javascript'=>false,
            'arbitrary_rest_route'=>false,
            'arbitrary_sql'=>false,
            'arbitrary_filesystem'=>false,
        ),
    );
}

function eduardo_research_governance_snapshot(): array {
    $contract = eduardo_research_governance_contract();
    return array(
        'theme'=>$contract['theme'],
        'page_content'=>array(
            'available'=>! empty($contract['surfaces']['page_content']['available']),
            'page_keys'=>array_keys((array) ($contract['surfaces']['page_content']['pages'] ?? array())),
        ),
        'navigation'=>(array) ($contract['surfaces']['navigation']['state'] ?? array()),
        'design'=>function_exists('eduardo_research_design_state') ? eduardo_research_design_state() : array(),
        'variants'=>function_exists('eduardo_research_design_variant_state') ? eduardo_research_design_variant_state() : array(),
        'generated_at'=>gmdate(DATE_W3C),
    );
}
