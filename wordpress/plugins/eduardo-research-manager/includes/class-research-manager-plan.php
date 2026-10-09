<?php
/** Mutation-plan contract for Preview -> Apply -> Verify -> Rollback. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Plan {
    private const ALLOWED_OPTIONS = array(
        'eduardo_research_evidence','eduardo_research_model','eduardo_research_model_es',
        'eduardo_research_identity','eduardo_research_native_languages',
    );
    private const ALLOWED_SURFACE_KEYS = array(
        'about','research','publications','projects','software','datasets','cv','insights','contact','privacy-policy','legal-notice',
    );
    private const ALLOWED_POST_FIELDS = array('post_title','post_excerpt','post_content','menu_order');
    private const STRUCTURED_RESEARCH_POST_TYPES = array('research_output','research_project','research_software','research_dataset');
    private const MAX_TITLE_BYTES = 200;
    private const MAX_EXCERPT_BYTES = 1000;
    private const MAX_CONTENT_BYTES = 60000;

    public static function create(string $intent, array $actions, array $context = array()): array|WP_Error {
        $intent = sanitize_text_field($intent);
        if ('' === $intent) { return new WP_Error('research_manager_missing_intent', 'A human-readable mutation intent is required.'); }
        if (! $actions) { return new WP_Error('research_manager_empty_plan', 'At least one mutation action is required.'); }

        $clean = array();
        $targets = array();
        $risk = 'standard';
        foreach ($actions as $index => $action) {
            if (! is_array($action)) { return new WP_Error('research_manager_invalid_action', sprintf('Action %d must be an array.', $index)); }
            $normalized = self::normalize_action($action);
            if (is_wp_error($normalized)) { return $normalized; }
            $signature = self::target_signature($normalized);
            if (isset($targets[$signature])) { return new WP_Error('research_manager_duplicate_target', 'A mutation plan may change each target only once.'); }
            $targets[$signature] = true;
            $action_risk = self::action_risk($normalized);
            if ('evidence-required' === $action_risk) { $risk = 'evidence-required'; }
            elseif ('standard' === $risk && 'editorial-review' === $action_risk) { $risk = 'editorial-review'; }
            $clean[] = $normalized;
        }

        $evidence_confirmed = ! empty($context['evidence_confirmed']);
        $evidence_reference = sanitize_text_field((string) ($context['evidence_reference'] ?? ''));
        $payload = array(
            'version'=>1,'intent'=>$intent,'actions'=>$clean,'risk'=>$risk,
            'evidence_confirmed'=>$evidence_confirmed,'evidence_reference'=>$evidence_reference,
        );
        $checksum = self::checksum($payload);
        return array(
            'id'=>'erm-plan-' . substr($checksum, 0, 16),'version'=>1,'intent'=>$intent,'actions'=>$clean,'risk'=>$risk,
            'evidence_confirmed'=>$evidence_confirmed,'evidence_reference'=>$evidence_reference,
            'checksum'=>$checksum,'created_at'=>gmdate(DATE_W3C),
        );
    }

    public static function validate(array $plan): bool|WP_Error {
        $required = array('id','version','intent','actions','risk','evidence_confirmed','evidence_reference','checksum');
        foreach ($required as $key) {
            if (! array_key_exists($key, $plan)) { return new WP_Error('research_manager_invalid_plan', 'Mutation plan is missing a required field: ' . $key); }
        }
        $rebuilt = self::create((string) $plan['intent'], is_array($plan['actions']) ? $plan['actions'] : array(), array(
            'evidence_confirmed'=>! empty($plan['evidence_confirmed']),
            'evidence_reference'=>(string) $plan['evidence_reference'],
        ));
        if (is_wp_error($rebuilt)) { return $rebuilt; }
        if (! hash_equals((string) $rebuilt['checksum'], (string) $plan['checksum'])) {
            return new WP_Error('research_manager_plan_changed', 'Mutation plan checksum does not match its contents. Re-preview the change.');
        }
        return true;
    }

    public static function apply_gate(array $plan): bool|WP_Error {
        $valid = self::validate($plan);
        if (is_wp_error($valid)) { return $valid; }
        if ('evidence-required' === (string) $plan['risk']) {
            if (empty($plan['evidence_confirmed'])) {
                return new WP_Error('research_manager_evidence_required', 'This mutation changes an evidence-sensitive academic claim. Confirm evidence and rebuild the plan before Apply.');
            }
            if ('' === trim((string) $plan['evidence_reference'])) {
                return new WP_Error('research_manager_evidence_reference_required', 'Evidence-sensitive mutations require a source or verification reference before Apply.');
            }
        }
        return true;
    }

    public static function action_risk(array $action): string {
        $type = (string) ($action['type'] ?? '');
        if ('create_page' === $type) { return 'standard'; }
        if ('create_insight' === $type) { return 'editorial-review'; }
        if (in_array($type, array('create_output','create_project','create_software','create_dataset'), true)) { return 'evidence-required'; }

        $key = strtolower((string) ($action['key'] ?? $action['field'] ?? ''));
        if ('post_field' === $type && 'post_title' === $key) {
            $post = get_post(absint($action['post_id'] ?? 0));
            if ($post instanceof WP_Post && in_array((string) $post->post_type, self::STRUCTURED_RESEARCH_POST_TYPES, true)) {
                return 'evidence-required';
            }
        }

        foreach (array(
            'doi','review_status','output_type','identifier','evidence','affiliation','award','grant',
            'publication_date','venue','publisher','authors','line_ids','project_status','question','role',
            'start_date','end_date','partner','funding','methods','project_url','software_status','software_version',
            'release_date','repository_url','archive_url','documentation_url','programming_languages','license',
            'dataset_version','repository','access_level','formats','methodology','provenance','ethics_notes','size',
        ) as $needle) {
            if (str_contains($key, $needle)) { return 'evidence-required'; }
        }
        if (in_array($key, array('post_title','post_excerpt','post_content','_research_language','_research_insight_type'), true)
            || str_starts_with($key, 'eduardo_research_model') || str_starts_with($key, 'eduardo_research_surface_')) {
            return 'editorial-review';
        }
        return 'standard';
    }

    private static function normalize_action(array $action): array|WP_Error {
        $type = sanitize_key((string) ($action['type'] ?? ''));
        if ('option' === $type) {
            $key = sanitize_key((string) ($action['key'] ?? ''));
            if (! self::allowed_option_key($key)) { return new WP_Error('research_manager_option_not_allowed', 'This WordPress option is outside the Research Manager mutation contract.'); }
            return array('type'=>'option','key'=>$key,'value'=>$action['value'] ?? null);
        }
        if ('post_meta' === $type) {
            $post_id = absint($action['post_id'] ?? 0);
            $key = sanitize_key((string) ($action['key'] ?? ''));
            if ($post_id <= 0 || ! get_post($post_id)) { return new WP_Error('research_manager_invalid_post', 'Post metadata mutation requires an existing WordPress resource.'); }
            if (! self::allowed_meta_key($key)) { return new WP_Error('research_manager_meta_not_allowed', 'This metadata key is outside the Research Theme contract.'); }
            return array('type'=>'post_meta','post_id'=>$post_id,'key'=>$key,'value'=>$action['value'] ?? null);
        }
        if ('post_field' === $type) {
            $post_id = absint($action['post_id'] ?? 0);
            $field = sanitize_key((string) ($action['field'] ?? ''));
            if ($post_id <= 0 || ! get_post($post_id)) { return new WP_Error('research_manager_invalid_post', 'Post-field mutation requires an existing WordPress resource.'); }
            if (! in_array($field, self::ALLOWED_POST_FIELDS, true)) { return new WP_Error('research_manager_post_field_not_allowed', 'This post field cannot be mutated by the Manager foundation.'); }
            $value = self::sanitize_post_field_value($field, $action['value'] ?? '');
            $bounded = self::validate_post_field_length($field, $value);
            if (is_wp_error($bounded)) { return $bounded; }
            return array('type'=>'post_field','post_id'=>$post_id,'field'=>$field,'value'=>$value);
        }
        if ('create_page' === $type) { return self::normalize_page_creation($action); }
        if ('create_insight' === $type) { return self::normalize_insight_creation($action); }
        if ('create_output' === $type) { return self::normalize_output_creation($action); }
        if ('create_project' === $type) { return self::normalize_project_creation($action); }
        if ('create_software' === $type) { return self::normalize_software_creation($action); }
        if ('create_dataset' === $type) { return self::normalize_dataset_creation($action); }
        return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
    }

    private static function normalize_page_creation(array $action): array|WP_Error {
        if (! function_exists('eduardo_research_preset')) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme contract is required for Page creation.'); }
        $preset = eduardo_research_preset();
        $pages = is_array($preset['pages'] ?? null) ? $preset['pages'] : array();
        $page_key = sanitize_key((string) ($action['page_key'] ?? ''));
        $contract = is_array($pages[$page_key] ?? null) ? $pages[$page_key] : array();
        if (! $contract) { return new WP_Error('research_manager_page_key_not_allowed', 'This Page key is outside the active Research preset creation contract.'); }
        $wp_slug = sanitize_title((string) ($action['wp_slug'] ?? ''));
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $role = sanitize_key((string) ($action['role'] ?? ''));
        $model = sanitize_key((string) ($action['model'] ?? ''));
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        $expected_slug = sanitize_title((string) ($contract['wp_slug'] ?? $contract['slug'] ?? $page_key));
        $expected_role = sanitize_key((string) ($contract['role'] ?? ''));
        $expected_model = sanitize_key((string) ($contract['model'] ?? ''));
        $front_page = 'front-page' === $expected_role;
        if ('' === $wp_slug || '' === $title || '' === $role || '' === $model || ! self::valid_creation_token($creation_token)) {
            return new WP_Error('research_manager_invalid_page_creation', 'Page creation requires a valid key, slug, title, role, model and provenance token.');
        }
        if ($wp_slug !== $expected_slug || $role !== $expected_role || $model !== $expected_model) { return new WP_Error('research_manager_page_contract_mismatch', 'Page creation must match the active Research Theme contract exactly.'); }
        if ($front_page !== ! empty($action['front_page'])) { return new WP_Error('research_manager_front_page_contract_mismatch', 'Front-page creation state must match the active Research Theme role.'); }
        return array('type'=>'create_page','page_key'=>$page_key,'wp_slug'=>$wp_slug,'title'=>$title,'role'=>$role,'model'=>$model,'creation_token'=>$creation_token,'front_page'=>$front_page);
    }

    private static function normalize_insight_creation(array $action): array|WP_Error {
        if (! function_exists('eduardo_research_preset') || ! function_exists('eduardo_research_insight_types')) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme editorial contract is required for Insight creation.'); }
        $preset = eduardo_research_preset();
        $languages = is_array($preset['languages'] ?? null) ? array_map('sanitize_key', $preset['languages']) : array();
        $types = eduardo_research_insight_types('en');
        $types = is_array($types) ? $types : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? 'en'));
        $insight_type = sanitize_key((string) ($action['insight_type'] ?? 'research_note'));
        $status = sanitize_key((string) ($action['status'] ?? 'draft'));
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        if ('' === $title || '' === $slug || ! self::valid_creation_token($creation_token)) { return new WP_Error('research_manager_invalid_insight_creation', 'Insight creation requires a title, slug and provenance token.'); }
        if (strlen($title) > self::MAX_TITLE_BYTES || strlen($excerpt) > self::MAX_EXCERPT_BYTES || strlen($content) > self::MAX_CONTENT_BYTES) { return new WP_Error('research_manager_insight_content_too_large', 'Insight title, excerpt or body exceeds the bounded editorial contract.'); }
        if (! in_array($language, $languages, true)) { return new WP_Error('research_manager_unknown_language', 'Insight language is outside the active Research preset.'); }
        if (! array_key_exists($insight_type, $types)) { return new WP_Error('research_manager_unknown_insight_type', 'Insight type is outside the active Research Theme editorial contract.'); }
        if (! in_array($status, array('draft','publish'), true)) { return new WP_Error('research_manager_invalid_insight_status', 'Insight creation only permits draft or publish status.'); }
        return array('type'=>'create_insight','title'=>$title,'slug'=>$slug,'excerpt'=>$excerpt,'content'=>$content,'language'=>$language,'insight_type'=>$insight_type,'status'=>$status,'creation_token'=>$creation_token);
    }

    private static function normalize_output_creation(array $action): array|WP_Error {
        if (! function_exists('eduardo_research_preset') || ! function_exists('eduardo_research_collection_options')) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme publication contract is required.'); }
        $preset = eduardo_research_preset();
        $languages = is_array($preset['languages'] ?? null) ? array_map('sanitize_key', $preset['languages']) : array();
        $options = eduardo_research_collection_options('publications', 'en');
        $output_types = is_array($options['output_type'] ?? null) ? $options['output_type'] : array();
        $review_statuses = is_array($options['review_status'] ?? null) ? $options['review_status'] : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? 'en'));
        $status = sanitize_key((string) ($action['status'] ?? 'draft'));
        $output_type = sanitize_key((string) ($action['output_type'] ?? ''));
        $review_status = sanitize_key((string) ($action['review_status'] ?? ''));
        $publication_date = self::normalize_date((string) ($action['publication_date'] ?? ''));
        $venue = sanitize_text_field((string) ($action['venue'] ?? ''));
        $doi = self::normalize_doi((string) ($action['doi'] ?? ''));
        $authors = self::sanitize_output_authors($action['authors'] ?? array());
        if (is_wp_error($authors)) { return $authors; }
        $line_ids = self::sanitize_verified_line_ids($action['line_ids'] ?? array(), $language, 'Research Outputs');
        if (is_wp_error($line_ids)) { return $line_ids; }
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        if ('' === $title || '' === $slug || ! self::valid_creation_token($creation_token)) { return new WP_Error('research_manager_invalid_output_creation', 'Research Output creation requires a title, slug and provenance token.'); }
        if (strlen($title) > self::MAX_TITLE_BYTES || strlen($excerpt) > self::MAX_EXCERPT_BYTES || strlen($content) > self::MAX_CONTENT_BYTES) { return new WP_Error('research_manager_output_content_too_large', 'Research Output title, excerpt or body exceeds the bounded contract.'); }
        if (! in_array($language, $languages, true)) { return new WP_Error('research_manager_unknown_language', 'Research Output language is outside the active Research preset.'); }
        if (! in_array($status, array('draft','publish'), true)) { return new WP_Error('research_manager_invalid_output_status', 'Research Output creation only permits draft or publish status.'); }
        if ('' !== $output_type && ! array_key_exists($output_type, $output_types)) { return new WP_Error('research_manager_unknown_output_type', 'Research Output type is outside the active Theme contract.'); }
        if ('' !== $review_status && ! array_key_exists($review_status, $review_statuses)) { return new WP_Error('research_manager_unknown_review_status', 'Review status is outside the active Theme contract.'); }
        if ('' !== (string) ($action['publication_date'] ?? '') && '' === $publication_date) { return new WP_Error('research_manager_invalid_publication_date', 'Publication date must use YYYY, YYYY-MM or YYYY-MM-DD.'); }
        if ('' !== (string) ($action['doi'] ?? '') && '' === $doi) { return new WP_Error('research_manager_invalid_doi', 'DOI must use a valid 10.xxxx/... identifier.'); }
        return array(
            'type'=>'create_output','title'=>$title,'slug'=>$slug,'excerpt'=>$excerpt,'content'=>$content,'language'=>$language,'status'=>$status,
            'output_type'=>$output_type,'review_status'=>$review_status,'publication_date'=>$publication_date,'venue'=>$venue,'doi'=>$doi,
            'authors'=>$authors,'line_ids'=>$line_ids,'output_type_verified'=>'' !== $output_type ? '1' : '0',
            'review_status_verified'=>'' !== $review_status ? '1' : '0','doi_verified'=>'' !== $doi ? '1' : '0','creation_token'=>$creation_token,
        );
    }

    private static function normalize_project_creation(array $action): array|WP_Error {
        if (! function_exists('eduardo_research_preset') || ! function_exists('eduardo_research_collection_options')) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme project contract is required.'); }
        $preset = eduardo_research_preset();
        $languages = is_array($preset['languages'] ?? null) ? array_map('sanitize_key', $preset['languages']) : array();
        $options = eduardo_research_collection_options('projects', 'en');
        $project_statuses = is_array($options['project_status'] ?? null) ? $options['project_status'] : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? 'en'));
        $status = sanitize_key((string) ($action['status'] ?? 'draft'));
        $project_status = sanitize_key((string) ($action['project_status'] ?? ''));
        $question = sanitize_textarea_field((string) ($action['question'] ?? ''));
        $role = sanitize_text_field((string) ($action['role'] ?? ''));
        $start_date = self::normalize_date((string) ($action['start_date'] ?? ''));
        $end_date = self::normalize_date((string) ($action['end_date'] ?? ''));
        $partner = sanitize_text_field((string) ($action['partner'] ?? ''));
        $funding = sanitize_text_field((string) ($action['funding'] ?? ''));
        $project_url = self::normalize_http_url((string) ($action['project_url'] ?? ''));
        $methods = self::sanitize_string_list($action['methods'] ?? array(), 'research_manager_invalid_project_methods', 'Project methods must be an array of text values.');
        if (is_wp_error($methods)) { return $methods; }
        $line_ids = self::sanitize_verified_line_ids($action['line_ids'] ?? array(), $language, 'Research Projects');
        if (is_wp_error($line_ids)) { return $line_ids; }
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        if ('' === $title || '' === $slug || ! self::valid_creation_token($creation_token)) { return new WP_Error('research_manager_invalid_project_creation', 'Research Project creation requires a title, slug and provenance token.'); }
        if (strlen($title) > self::MAX_TITLE_BYTES || strlen($excerpt) > self::MAX_EXCERPT_BYTES || strlen($content) > self::MAX_CONTENT_BYTES) { return new WP_Error('research_manager_project_content_too_large', 'Research Project title, excerpt or body exceeds the bounded contract.'); }
        if (! in_array($language, $languages, true)) { return new WP_Error('research_manager_unknown_language', 'Research Project language is outside the active Research preset.'); }
        if (! in_array($status, array('draft','publish'), true)) { return new WP_Error('research_manager_invalid_project_post_status', 'Research Project creation only permits draft or publish status.'); }
        if ('' !== $project_status && ! array_key_exists($project_status, $project_statuses)) { return new WP_Error('research_manager_unknown_project_status', 'Research Project status is outside the active Theme contract.'); }
        if ('' !== (string) ($action['start_date'] ?? '') && '' === $start_date) { return new WP_Error('research_manager_invalid_project_start_date', 'Project start date must use a real YYYY, YYYY-MM or YYYY-MM-DD date.'); }
        if ('' !== (string) ($action['end_date'] ?? '') && '' === $end_date) { return new WP_Error('research_manager_invalid_project_end_date', 'Project end date must use a real YYYY, YYYY-MM or YYYY-MM-DD date.'); }
        if ('' !== (string) ($action['project_url'] ?? '') && '' === $project_url) { return new WP_Error('research_manager_invalid_project_url', 'Project URL must be a valid http or https URL.'); }
        return array(
            'type'=>'create_project','title'=>$title,'slug'=>$slug,'excerpt'=>$excerpt,'content'=>$content,'language'=>$language,'status'=>$status,
            'project_status'=>$project_status,'question'=>$question,'role'=>$role,'start_date'=>$start_date,'end_date'=>$end_date,
            'partner'=>$partner,'funding'=>$funding,'project_url'=>$project_url,'methods'=>$methods,'line_ids'=>$line_ids,'creation_token'=>$creation_token,
        );
    }

    private static function normalize_software_creation(array $action): array|WP_Error {
        if (! function_exists('eduardo_research_preset') || ! function_exists('eduardo_research_collection_options')) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme software contract is required.'); }
        $preset = eduardo_research_preset();
        $languages = is_array($preset['languages'] ?? null) ? array_map('sanitize_key', $preset['languages']) : array();
        $options = eduardo_research_collection_options('software', 'en');
        $software_statuses = is_array($options['software_status'] ?? null) ? $options['software_status'] : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? 'en'));
        $status = sanitize_key((string) ($action['status'] ?? 'draft'));
        $software_status = sanitize_key((string) ($action['software_status'] ?? ''));
        $version = sanitize_text_field((string) ($action['version'] ?? ''));
        $release_date = self::normalize_date((string) ($action['release_date'] ?? ''));
        $repository_url = self::normalize_http_url((string) ($action['repository_url'] ?? ''));
        $archive_url = self::normalize_http_url((string) ($action['archive_url'] ?? ''));
        $documentation_url = self::normalize_http_url((string) ($action['documentation_url'] ?? ''));
        $license = sanitize_text_field((string) ($action['license'] ?? ''));
        $doi = self::normalize_doi((string) ($action['doi'] ?? ''));
        $programming_languages = self::sanitize_string_list($action['programming_languages'] ?? array(), 'research_manager_invalid_programming_languages', 'Programming languages must be an array of text values.');
        if (is_wp_error($programming_languages)) { return $programming_languages; }
        $line_ids = self::sanitize_verified_line_ids($action['line_ids'] ?? array(), $language, 'Research Software');
        if (is_wp_error($line_ids)) { return $line_ids; }
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        if ('' === $title || '' === $slug || ! self::valid_creation_token($creation_token)) { return new WP_Error('research_manager_invalid_software_creation', 'Research Software creation requires a title, slug and provenance token.'); }
        if (strlen($title) > self::MAX_TITLE_BYTES || strlen($excerpt) > self::MAX_EXCERPT_BYTES || strlen($content) > self::MAX_CONTENT_BYTES) { return new WP_Error('research_manager_software_content_too_large', 'Research Software title, excerpt or body exceeds the bounded contract.'); }
        if (! in_array($language, $languages, true)) { return new WP_Error('research_manager_unknown_language', 'Research Software language is outside the active Research preset.'); }
        if (! in_array($status, array('draft','publish'), true)) { return new WP_Error('research_manager_invalid_software_post_status', 'Research Software creation only permits draft or publish status.'); }
        if ('' !== $software_status && ! array_key_exists($software_status, $software_statuses)) { return new WP_Error('research_manager_unknown_software_status', 'Research Software status is outside the active Theme contract.'); }
        if ('' !== (string) ($action['release_date'] ?? '') && '' === $release_date) { return new WP_Error('research_manager_invalid_software_release_date', 'Software release date must use a real YYYY, YYYY-MM or YYYY-MM-DD date.'); }
        foreach (array('repository_url'=>$repository_url,'archive_url'=>$archive_url,'documentation_url'=>$documentation_url) as $field => $url) {
            if ('' !== (string) ($action[$field] ?? '') && '' === $url) { return new WP_Error('research_manager_invalid_software_url', 'Software repository, archive and documentation URLs must use http or https.'); }
        }
        if ('' !== (string) ($action['doi'] ?? '') && '' === $doi) { return new WP_Error('research_manager_invalid_doi', 'DOI must use a valid 10.xxxx/... identifier.'); }
        return array(
            'type'=>'create_software','title'=>$title,'slug'=>$slug,'excerpt'=>$excerpt,'content'=>$content,'language'=>$language,'status'=>$status,
            'software_status'=>$software_status,'version'=>$version,'release_date'=>$release_date,'repository_url'=>$repository_url,
            'archive_url'=>$archive_url,'license'=>$license,'documentation_url'=>$documentation_url,'doi'=>$doi,'doi_verified'=>'' !== $doi ? '1' : '0',
            'programming_languages'=>$programming_languages,'line_ids'=>$line_ids,'creation_token'=>$creation_token,
        );
    }

    private static function normalize_dataset_creation(array $action): array|WP_Error {
        if (! function_exists('eduardo_research_preset') || ! function_exists('eduardo_research_collection_options')) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme dataset contract is required.'); }
        $preset = eduardo_research_preset();
        $languages = is_array($preset['languages'] ?? null) ? array_map('sanitize_key', $preset['languages']) : array();
        $options = eduardo_research_collection_options('datasets', 'en');
        $access_levels = is_array($options['access_level'] ?? null) ? $options['access_level'] : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? 'en'));
        $status = sanitize_key((string) ($action['status'] ?? 'draft'));
        $version = sanitize_text_field((string) ($action['version'] ?? ''));
        $publication_date = self::normalize_date((string) ($action['publication_date'] ?? ''));
        $repository = self::normalize_http_url((string) ($action['repository'] ?? ''));
        $doi = self::normalize_doi((string) ($action['doi'] ?? ''));
        $license = sanitize_text_field((string) ($action['license'] ?? ''));
        $access_level = sanitize_key((string) ($action['access_level'] ?? ''));
        $methodology = sanitize_textarea_field((string) ($action['methodology'] ?? ''));
        $provenance = sanitize_textarea_field((string) ($action['provenance'] ?? ''));
        $size = sanitize_text_field((string) ($action['size'] ?? ''));
        $documentation_url = self::normalize_http_url((string) ($action['documentation_url'] ?? ''));
        $ethics_notes = sanitize_textarea_field((string) ($action['ethics_notes'] ?? ''));
        $formats = self::sanitize_string_list($action['formats'] ?? array(), 'research_manager_invalid_dataset_formats', 'Dataset formats must be an array of text values.');
        if (is_wp_error($formats)) { return $formats; }
        $line_ids = self::sanitize_verified_line_ids($action['line_ids'] ?? array(), $language, 'Research Datasets');
        if (is_wp_error($line_ids)) { return $line_ids; }
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        if ('' === $title || '' === $slug || ! self::valid_creation_token($creation_token)) { return new WP_Error('research_manager_invalid_dataset_creation', 'Research Dataset creation requires a title, slug and provenance token.'); }
        if (strlen($title) > self::MAX_TITLE_BYTES || strlen($excerpt) > self::MAX_EXCERPT_BYTES || strlen($content) > self::MAX_CONTENT_BYTES) { return new WP_Error('research_manager_dataset_content_too_large', 'Research Dataset title, excerpt or body exceeds the bounded contract.'); }
        if (! in_array($language, $languages, true)) { return new WP_Error('research_manager_unknown_language', 'Research Dataset language is outside the active Research preset.'); }
        if (! in_array($status, array('draft','publish'), true)) { return new WP_Error('research_manager_invalid_dataset_post_status', 'Research Dataset creation only permits draft or publish status.'); }
        if ('' !== $access_level && ! array_key_exists($access_level, $access_levels)) { return new WP_Error('research_manager_unknown_access_level', 'Dataset access level is outside the active Theme contract.'); }
        if ('' !== (string) ($action['publication_date'] ?? '') && '' === $publication_date) { return new WP_Error('research_manager_invalid_dataset_publication_date', 'Dataset publication date must use a real YYYY, YYYY-MM or YYYY-MM-DD date.'); }
        if ('' !== (string) ($action['repository'] ?? '') && '' === $repository) { return new WP_Error('research_manager_invalid_dataset_repository', 'Dataset repository must be a valid http or https URL.'); }
        if ('' !== (string) ($action['documentation_url'] ?? '') && '' === $documentation_url) { return new WP_Error('research_manager_invalid_dataset_documentation_url', 'Dataset documentation URL must use http or https.'); }
        if ('' !== (string) ($action['doi'] ?? '') && '' === $doi) { return new WP_Error('research_manager_invalid_doi', 'DOI must use a valid 10.xxxx/... identifier.'); }
        return array(
            'type'=>'create_dataset','title'=>$title,'slug'=>$slug,'excerpt'=>$excerpt,'content'=>$content,'language'=>$language,'status'=>$status,
            'version'=>$version,'publication_date'=>$publication_date,'repository'=>$repository,'doi'=>$doi,'doi_verified'=>'' !== $doi ? '1' : '0',
            'license'=>$license,'access_level'=>$access_level,'methodology'=>$methodology,'provenance'=>$provenance,'size'=>$size,
            'documentation_url'=>$documentation_url,'ethics_notes'=>$ethics_notes,'formats'=>$formats,'line_ids'=>$line_ids,'creation_token'=>$creation_token,
        );
    }

    private static function sanitize_output_authors($value): array|WP_Error {
        if (null === $value || array() === $value) { return array(); }
        if (! is_array($value)) { return new WP_Error('research_manager_invalid_authors', 'Research Output authors must be an array.'); }
        $authors = array();
        foreach ($value as $author) {
            if (is_string($author)) { $author = array('display_name'=>$author); }
            if (! is_array($author)) { return new WP_Error('research_manager_invalid_authors', 'Every author must be a name or structured author object.'); }
            $name = sanitize_text_field((string) ($author['display_name'] ?? ''));
            if ('' === $name) { return new WP_Error('research_manager_invalid_authors', 'Every structured author requires display_name.'); }
            $record = array('display_name'=>$name);
            foreach (array('given_name','family_name','affiliation') as $field) {
                $text = sanitize_text_field((string) ($author[$field] ?? ''));
                if ('' !== $text) { $record[$field] = $text; }
            }
            $orcid = trim((string) ($author['orcid'] ?? ''));
            if ('' !== $orcid) {
                $orcid = preg_replace('#^https?://orcid\.org/#i', '', $orcid) ?? '';
                if (1 !== preg_match('/^\d{4}-\d{4}-\d{4}-[\dX]{4}$/i', $orcid)) { return new WP_Error('research_manager_invalid_orcid', 'Author ORCID must use the 0000-0000-0000-0000 format.'); }
                $record['orcid'] = strtoupper($orcid);
            }
            $record['is_site_researcher'] = ! empty($author['is_site_researcher']);
            $authors[] = $record;
        }
        return $authors;
    }

    private static function sanitize_verified_line_ids($value, string $language, string $resource_label): array|WP_Error {
        if (null === $value || array() === $value) { return array(); }
        if (! is_array($value)) { return new WP_Error('research_manager_invalid_line_relations', 'Research line relations must be an array of IDs.'); }
        $ids = array_values(array_unique(array_filter(array_map('absint', $value))));
        foreach ($ids as $id) {
            if (! function_exists('eduardo_research_line_is_verified_public') || ! eduardo_research_line_is_verified_public($id, $language)) {
                return new WP_Error('research_manager_unverified_line_relation', $resource_label . ' may only relate to verified Research Lines in the same language.');
            }
        }
        return $ids;
    }

    private static function sanitize_string_list($value, string $error_code, string $error_message): array|WP_Error {
        if (null === $value || array() === $value) { return array(); }
        if (! is_array($value)) { return new WP_Error($error_code, $error_message); }
        $clean = array();
        foreach ($value as $item) {
            if (! is_scalar($item)) { return new WP_Error($error_code, $error_message); }
            $item = sanitize_text_field((string) $item);
            if ('' !== $item) { $clean[] = $item; }
        }
        return array_values(array_unique($clean));
    }

    private static function normalize_date(string $value): string {
        $value = trim($value);
        if ('' === $value) { return ''; }
        if (1 === preg_match('/^\d{4}$/', $value)) { return $value; }
        if (1 === preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value)) { return $value; }
        if (1 !== preg_match('/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value, $parts)) { return ''; }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : '';
    }

    private static function normalize_doi(string $value): string {
        $value = trim($value);
        if ('' === $value) { return ''; }
        $value = preg_replace('#^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)#i', '', $value) ?? '';
        $value = trim($value);
        return 1 === preg_match('/^10\.\d{4,9}\/\S+$/i', $value) ? $value : '';
    }

    private static function normalize_http_url(string $value): string {
        $value = trim($value);
        if ('' === $value) { return ''; }
        $url = esc_url_raw($value, array('http','https'));
        if ('' === $url) { return ''; }
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, array('http','https'), true) ? $url : '';
    }

    private static function allowed_option_key(string $key): bool {
        if (in_array($key, self::ALLOWED_OPTIONS, true)) { return true; }
        foreach (self::ALLOWED_SURFACE_KEYS as $surface) {
            if ('eduardo_research_surface_' . $surface === $key || 'eduardo_research_surface_' . $surface . '_es' === $key) { return true; }
        }
        return false;
    }

    private static function allowed_meta_key(string $key): bool {
        return str_starts_with($key, '_eduardo_research_') || str_starts_with($key, '_research_');
    }

    private static function sanitize_post_field_value(string $field, mixed $value): mixed {
        if ('menu_order' === $field) { return (int) $value; }
        if ('post_content' === $field) { return wp_kses_post((string) $value); }
        if ('post_excerpt' === $field) { return sanitize_textarea_field((string) $value); }
        return sanitize_text_field((string) $value);
    }

    private static function validate_post_field_length(string $field, mixed $value): bool|WP_Error {
        if (! is_string($value)) { return true; }
        $limit = 'post_title' === $field ? self::MAX_TITLE_BYTES : ('post_excerpt' === $field ? self::MAX_EXCERPT_BYTES : ('post_content' === $field ? self::MAX_CONTENT_BYTES : 0));
        if ($limit > 0 && strlen($value) > $limit) { return new WP_Error('research_manager_post_field_too_large', sprintf('The planned %s value exceeds the bounded Manager contract.', $field)); }
        return true;
    }

    private static function valid_creation_token(string $token): bool {
        return 1 === preg_match('/^[a-f0-9-]{32,64}$/i', $token);
    }

    private static function target_signature(array $action): string {
        if ('option' === $action['type']) { return 'option:' . $action['key']; }
        if ('post_meta' === $action['type']) { return 'post_meta:' . $action['post_id'] . ':' . $action['key']; }
        if ('post_field' === $action['type']) { return 'post_field:' . $action['post_id'] . ':' . $action['field']; }
        if ('create_page' === $action['type']) { return 'create_page:' . $action['wp_slug']; }
        if ('create_insight' === $action['type']) { return 'create_insight:' . $action['slug']; }
        if ('create_output' === $action['type']) { return 'create_output:' . $action['slug']; }
        if ('create_project' === $action['type']) { return 'create_project:' . $action['slug']; }
        if ('create_software' === $action['type']) { return 'create_software:' . $action['slug']; }
        if ('create_dataset' === $action['type']) { return 'create_dataset:' . $action['slug']; }
        return 'unknown:' . md5((string) wp_json_encode($action));
    }

    private static function checksum(array $payload): string {
        return hash('sha256', (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
