<?php
/** Canonical academic content-type contract and registration for the Research Theme. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_content_type_contracts(): array {
    $base_supports = array('title', 'editor', 'excerpt', 'thumbnail', 'custom-fields');

    return array(
        'research_line' => array(
            'name'=>'Research Lines',
            'singular_name'=>'Research Line',
            'menu_icon'=>'dashicons-networking',
            'rewrite'=>false,
            'supports'=>array_merge($base_supports, array('page-attributes')),
        ),
        'research_output' => array(
            'name'=>'Outputs',
            'singular_name'=>'Output',
            'menu_icon'=>'dashicons-media-document',
            'rewrite'=>array('slug'=>'publications', 'with_front'=>false),
            'surface'=>'publications',
            'supports'=>$base_supports,
        ),
        'research_project' => array(
            'name'=>'Projects',
            'singular_name'=>'Project',
            'menu_icon'=>'dashicons-portfolio',
            'rewrite'=>array('slug'=>'projects', 'with_front'=>false),
            'surface'=>'projects',
            'supports'=>$base_supports,
        ),
        'research_software' => array(
            'name'=>'Software',
            'singular_name'=>'Software',
            'menu_icon'=>'dashicons-editor-code',
            'rewrite'=>array('slug'=>'software', 'with_front'=>false),
            'surface'=>'software',
            'supports'=>$base_supports,
        ),
        'research_dataset' => array(
            'name'=>'Datasets',
            'singular_name'=>'Dataset',
            'menu_icon'=>'dashicons-database',
            'rewrite'=>array('slug'=>'datasets', 'with_front'=>false),
            'surface'=>'datasets',
            'supports'=>$base_supports,
        ),
    );
}

function eduardo_research_content_type_surface(string $post_type): string {
    $contract = eduardo_research_content_type_contracts()[$post_type] ?? array();
    return is_array($contract) ? (string) ($contract['surface'] ?? '') : '';
}

function eduardo_research_surface_content_type(string $surface): string {
    foreach (eduardo_research_content_type_contracts() as $post_type => $contract) {
        if ($surface === (string) ($contract['surface'] ?? '')) { return (string) $post_type; }
    }
    return '';
}

function eduardo_research_surface_content_types(): array {
    return array_values(array_filter(
        array_keys(eduardo_research_content_type_contracts()),
        static fn(string $post_type): bool => '' !== eduardo_research_content_type_surface($post_type)
    ));
}

function eduardo_research_surface_keys(): array {
    return array_values(array_map('eduardo_research_content_type_surface', eduardo_research_surface_content_types()));
}

function eduardo_research_register_content_types(): void {
    foreach (eduardo_research_content_type_contracts() as $type => $contract) {
        register_post_type($type, array(
            'labels'=>array(
                'name'=>(string) ($contract['name'] ?? ''),
                'singular_name'=>(string) ($contract['singular_name'] ?? ''),
            ),
            'public'=>true,
            'show_in_rest'=>true,
            'has_archive'=>false,
            'rewrite'=>$contract['rewrite'] ?? false,
            'menu_icon'=>(string) ($contract['menu_icon'] ?? ''),
            'supports'=>array_values((array) ($contract['supports'] ?? array())),
        ));
    }
}
add_action('init', 'eduardo_research_register_content_types');
