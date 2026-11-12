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
}
