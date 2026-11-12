<?php
/** Legacy-exact authorization declarations for the Eduardo Research vertical. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-site-architect-mutation-policy.php';

final class Eduardo_Research_Manager_Research_Mutation_Policy implements Site_Architect_Mutation_Policy {
    public function allowed_action_types(): array {
        return array(
            'option','post_meta','post_field',
            'create_page','create_insight',
            'create_line','create_output','create_project','create_software','create_dataset',
        );
    }

    public function allowed_option_keys(): array {
        return array(
            'eduardo_research_evidence','eduardo_research_model','eduardo_research_model_es',
            'eduardo_research_identity','eduardo_research_native_languages',
            // Front-page options still require their existing Home/value guards.
            'show_on_front','page_on_front',
        );
    }

    public function allowed_meta_keys(): array {
        // Security authority: these are the exact previously accepted shared
        // and Research Resource keys. Registry entries are descriptive only;
        // adding a Registry field must not silently grant write access.
        return array(
            // Shared Theme / provenance / translation metadata.
            '_eduardo_research_role',
            '_eduardo_research_model',
            '_eduardo_research_manager_creation_token',
            '_eduardo_research_translation_evidence',
            '_research_language',
            '_research_insight_type',
            '_research_insight_line_relations',
            '_research_translation_en',
            '_research_translation_es',
            // Research resource fields explicitly reviewed for mutation.
            '_research_access_level',
            '_research_archive_url',
            '_research_authors',
            '_research_central_question',
            '_research_dataset_version',
            '_research_documentation_url',
            '_research_doi',
            '_research_doi_verified',
            '_research_end_date',
            '_research_ethics_notes',
            '_research_evidence_status',
            '_research_formats',
            '_research_funding',
            '_research_license',
            '_research_line_ids',
            '_research_methodology',
            '_research_methods',
            '_research_order',
            '_research_output_type',
            '_research_output_type_verified',
            '_research_partner',
            '_research_programming_languages',
            '_research_project_status',
            '_research_project_url',
            '_research_provenance',
            '_research_publication_date',
            '_research_question',
            '_research_release_date',
            '_research_repository',
            '_research_repository_url',
            '_research_review_status',
            '_research_review_status_verified',
            '_research_role',
            '_research_size',
            '_research_software_status',
            '_research_software_version',
            '_research_start_date',
            '_research_status',
            '_research_topics',
            '_research_venue',
        );
    }

    /**
     * Exact Research 0.11.1 action-risk semantics, transferred out of generic Plan.
     * The Resource Registry supplies descriptive facts only, never write authority.
     */
    public function risk_for_action(array $action, callable $is_theme_owned_page): string {
        $type = (string) ($action['type'] ?? '');
        if ('create_page' === $type) { return 'standard'; }
        if ('create_insight' === $type) { return 'editorial-review'; }
        if (Eduardo_Research_Manager_Resource_Registry::by_creation_action($type)) { return 'evidence-required'; }

        $key = strtolower((string) ($action['key'] ?? $action['field'] ?? ''));
        $post_id = absint($action['post_id'] ?? 0);
        if ('post_field' === $type && 'post_title' === $key) {
            $post = get_post($post_id);
            if ($post instanceof WP_Post && in_array((string) $post->post_type, Eduardo_Research_Manager_Resource_Registry::post_types(), true)) {
                return 'evidence-required';
            }
        }
        if ('post_field' === $type && 'post_status' === $key && $is_theme_owned_page($post_id)) {
            return 'standard';
        }
        if ('post_meta' === $type && in_array($key, array('_eduardo_research_role','_eduardo_research_model'), true) && $is_theme_owned_page($post_id)) {
            return 'standard';
        }

        foreach (array(
            'doi','review_status','output_type','identifier','evidence','affiliation','award','grant',
            'publication_date','venue','publisher','authors','line_ids','project_status','question','role',
            'start_date','end_date','partner','funding','methods','project_url','software_status','software_version',
            'release_date','repository_url','archive_url','documentation_url','programming_languages','license',
            'dataset_version','repository','access_level','formats','methodology','provenance','ethics_notes','size',
            'research_status','central_question','topics','research_order',
        ) as $needle) {
            if (str_contains($key, $needle)) { return 'evidence-required'; }
        }
        if (in_array($key, array('post_title','post_excerpt','post_content','_research_language','_research_insight_type'), true)
            || str_starts_with($key, 'eduardo_research_model') || str_starts_with($key, 'eduardo_research_surface_')) {
            return 'editorial-review';
        }
        return 'standard';
    }

    public function evidence_blocker_message(string $reason): string {
        if ('confirmation' === $reason) {
            return 'This mutation changes an evidence-sensitive academic claim. Confirm evidence and rebuild the plan before Apply.';
        }
        if ('reference' === $reason) {
            return 'Evidence-sensitive mutations require a source or verification reference before Apply.';
        }
        return '';
    }
}
