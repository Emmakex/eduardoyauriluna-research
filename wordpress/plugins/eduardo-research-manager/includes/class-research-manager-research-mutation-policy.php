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
}
