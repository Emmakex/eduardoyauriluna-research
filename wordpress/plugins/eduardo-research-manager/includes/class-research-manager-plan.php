<?php
/** Normalizes intent into bounded Research Manager actions. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Plan {
    private const MAX_TITLE_BYTES = 1024;
    private const MAX_EXCERPT_BYTES = 8192;
    private const MAX_CONTENT_BYTES = 1048576;

    public static function create(string $intent, array $actions, array $context = array()): array|WP_Error {
        $intent = sanitize_text_field($intent);
        if ('' === $intent) { return new WP_Error('research_manager_intent_required', 'A non-empty Research Manager intent is required.'); }
        if (! $actions) { return new WP_Error('research_manager_actions_required', 'At least one Research Manager action is required.'); }
        $normalized = array();
        foreach ($actions as $action) {
            if (! is_array($action)) { return new WP_Error('research_manager_invalid_action', 'Every Research Manager action must be an object-like array.'); }
            $type = sanitize_key((string) ($action['type'] ?? ''));
            switch ($type) {
                case 'create_output': $next = self::normalize_output_creation($action); break;
                case 'create_project': $next = self::normalize_project_creation($action); break;
                case 'create_software': $next = self::normalize_software_creation($action); break;
                case 'create_dataset': $next = self::normalize_dataset_creation($action); break;
                case 'create_line': $next = self::normalize_line_creation($action); break;
                case 'post_field': $next = self::normalize_post_field($action); break;
                case 'post_meta': $next = self::normalize_post_meta($action); break;
                default: return new WP_Error('research_manager_action_not_allowed', 'Research Manager action type is outside the bounded contract.');
            }
            if (is_wp_error($next)) { return $next; }
            $normalized[] = $next;
        }
        return array(
            'intent'=>$intent,
            'actions'=>$normalized,
            'context'=>self::normalize_context($context),
            'created_at'=>gmdate(DATE_W3C),
        );
    }

    private static function normalize_context(array $context): array {
        $normalized = array();
        foreach (array('evidence_confirmed','approval_confirmed') as $flag) {
            if (array_key_exists($flag, $context)) { $normalized[$flag] = ! empty($context[$flag]); }
        }
        foreach (array('evidence_reference','approval_reference') as $field) {
            if (isset($context[$field]) && is_scalar($context[$field])) { $normalized[$field] = sanitize_text_field((string) $context[$field]); }
        }
        return $normalized;
    }

    private static function normalize_post_field(array $action): array|WP_Error {
        $post_id = absint($action['post_id'] ?? 0);
        $field = sanitize_key((string) ($action['field'] ?? ''));
        $allowed = array('post_status','post_name','post_title','post_excerpt','post_content');
        if ($post_id <= 0 || ! in_array($field, $allowed, true)) { return new WP_Error('research_manager_invalid_post_field_action', 'Research Manager post field action is invalid.'); }
        $value = $action['value'] ?? '';
        if (! is_scalar($value)) { return new WP_Error('research_manager_invalid_post_field_value', 'Research Manager post field value must be scalar.'); }
        $value = (string) $value;
        if ('post_name' === $field) { $value = sanitize_title($value); }
        elseif ('post_title' === $field) { $value = sanitize_text_field($value); }
        elseif ('post_excerpt' === $field) { $value = sanitize_textarea_field($value); }
        elseif ('post_content' === $field) { $value = wp_kses_post($value); }
        else { $value = sanitize_key($value); }
        return array('type'=>'post_field','post_id'=>$post_id,'field'=>$field,'value'=>$value);
    }

    private static function normalize_post_meta(array $action): array|WP_Error {
        $post_id = absint($action['post_id'] ?? 0);
        $key = sanitize_key((string) ($action['key'] ?? ''));
        if ($post_id <= 0 || '' === $key) { return new WP_Error('research_manager_invalid_post_meta_action', 'Research Manager post meta action is invalid.'); }
        $value = $action['value'] ?? null;
        return array('type'=>'post_meta','post_id'=>$post_id,'key'=>$key,'value'=>$value);
    }

    private static function normalize_line_creation(array $action): array|WP_Error {
        $theme_contract = new Eduardo_Research_Manager_Contract();
        if (! $theme_contract->theme_available()) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme line contract is required.'); }
        $languages = $theme_contract->languages();
        $default_language = $theme_contract->default_language();
        $catalogs = $theme_contract->line_catalogs($default_language);
        if (is_wp_error($catalogs)) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme line contract is required.'); }
        $statuses = is_array($catalogs['statuses'] ?? null) ? $catalogs['statuses'] : array();
        $evidence_statuses = is_array($catalogs['evidence_statuses'] ?? null) ? $catalogs['evidence_statuses'] : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? $default_language));
        $status = sanitize_key((string) ($action['status'] ?? 'draft'));
        $evidence_status = sanitize_key((string) ($action['evidence_status'] ?? 'unverified'));
        $research_status = sanitize_key((string) ($action['research_status'] ?? 'planned'));
        $central_question = sanitize_textarea_field((string) ($action['central_question'] ?? ''));
        $order = sanitize_text_field((string) ($action['order'] ?? ''));
        $topics = self::sanitize_string_list($action['topics'] ?? array(), 'research_manager_invalid_line_topics', 'Research Line topics must be an array of text values.');
        if (is_wp_error($topics)) { return $topics; }
        $methods = self::sanitize_string_list($action['methods'] ?? array(), 'research_manager_invalid_line_methods', 'Research Line methods must be an array of text values.');
        if (is_wp_error($methods)) { return $methods; }
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        if ('' === $title || '' === $slug || ! self::valid_creation_token($creation_token)) { return new WP_Error('research_manager_invalid_line_creation', 'Research Line creation requires a title, slug and provenance token.'); }
        if (strlen($title) > self::MAX_TITLE_BYTES || strlen($excerpt) > self::MAX_EXCERPT_BYTES || strlen($content) > self::MAX_CONTENT_BYTES) { return new WP_Error('research_manager_line_content_too_large', 'Research Line title, excerpt or body exceeds the bounded contract.'); }
        if (! in_array($language, $languages, true)) { return new WP_Error('research_manager_unknown_language', 'Research Line language is outside the active Research preset.'); }
        if (! in_array($status, array('draft','publish'), true)) { return new WP_Error('research_manager_invalid_line_post_status', 'Research Line creation only permits draft or publish status.'); }
        if (! array_key_exists($evidence_status, $evidence_statuses)) { return new WP_Error('research_manager_unknown_line_evidence_status', 'Research Line evidence status is outside the active Theme contract.'); }
        if (! array_key_exists($research_status, $statuses)) { return new WP_Error('research_manager_unknown_line_status', 'Research Line status is outside the active Theme contract.'); }
        return array(
            'type'=>'create_line','title'=>$title,'slug'=>$slug,'excerpt'=>$excerpt,'content'=>$content,'language'=>$language,'status'=>$status,
            'evidence_status'=>$evidence_status,'research_status'=>$research_status,'central_question'=>$central_question,'order'=>$order,
            'topics'=>$topics,'methods'=>$methods,'creation_token'=>$creation_token,
        );
    }

    private static function normalize_output_creation(array $action): array|WP_Error {
        $theme_contract = new Eduardo_Research_Manager_Contract();
        if (! $theme_contract->theme_available()) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme publication contract is required.');
        }
        $languages = $theme_contract->languages();
        $default_language = $theme_contract->default_language();
        $options = $theme_contract->collection_catalogs('publications', $default_language);
        if (is_wp_error($options)) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme publication contract is required.');
        }
        $output_types = is_array($options['output_type'] ?? null) ? $options['output_type'] : array();
        $review_statuses = is_array($options['review_status'] ?? null) ? $options['review_status'] : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? $default_language));
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
        $theme_contract = new Eduardo_Research_Manager_Contract();
        if (! $theme_contract->theme_available()) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme project contract is required.');
        }
        $languages = $theme_contract->languages();
        $default_language = $theme_contract->default_language();
        $options = $theme_contract->collection_catalogs('projects', $default_language);
        if (is_wp_error($options)) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme project contract is required.');
        }
        $project_statuses = is_array($options['project_status'] ?? null) ? $options['project_status'] : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? $default_language));
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
        $theme_contract = new Eduardo_Research_Manager_Contract();
        if (! $theme_contract->theme_available()) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme software contract is required.');
        }
        $languages = $theme_contract->languages();
        $default_language = $theme_contract->default_language();
        $options = $theme_contract->collection_catalogs('software', $default_language);
        if (is_wp_error($options)) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme software contract is required.');
        }
        $software_statuses = is_array($options['software_status'] ?? null) ? $options['software_status'] : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? $default_language));
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
        $theme_contract = new Eduardo_Research_Manager_Contract();
        if (! $theme_contract->theme_available()) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme dataset contract is required.');
        }
        $languages = $theme_contract->languages();
        $default_language = $theme_contract->default_language();
        $options = $theme_contract->collection_catalogs('datasets', $default_language);
        if (is_wp_error($options)) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme dataset contract is required.');
        }
        $access_levels = is_array($options['access_level'] ?? null) ? $options['access_level'] : array();
        $title = sanitize_text_field((string) ($action['title'] ?? ''));
        $slug = sanitize_title((string) ($action['slug'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($action['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($action['content'] ?? ''));
        $language = sanitize_key((string) ($action['language'] ?? $default_language));
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
        $theme_contract = new Eduardo_Research_Manager_Contract();
        foreach ($ids as $id) {
            if (! $theme_contract->line_is_verified_public($id, $language)) {
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
        if (1 === preg_match('/^(\d{4})-(0[1-9]|1[0-2])-([0-2]\d|3[01])$/', $value)) {
            list($year,$month,$day) = array_map('intval', explode('-', $value));
            return checkdate($month, $day, $year) ? $value : '';
        }
        return '';
    }

    private static function normalize_doi(string $value): string {
        $value = trim($value);
        if ('' === $value) { return ''; }
        $value = preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', $value) ?? '';
        return 1 === preg_match('/^10\.\d{4,9}\/\S+$/i', $value) ? $value : '';
    }

    private static function normalize_http_url(string $value): string {
        $value = trim($value);
        if ('' === $value) { return ''; }
        $url = esc_url_raw($value, array('http','https'));
        return is_string($url) ? $url : '';
    }

    private static function valid_creation_token(string $token): bool {
        return '' !== $token && strlen($token) <= 255;
    }
}
