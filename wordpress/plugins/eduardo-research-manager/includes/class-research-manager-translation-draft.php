<?php
/** Create a minimal opposite-language draft without auto-translating research claims. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Translation_Draft {
    private const SUPPORTED_TYPES = array('research_output','research_project','research_software','research_dataset','post');
    private const STRUCTURED_TYPES = array('research_output','research_project','research_software','research_dataset');

    private Eduardo_Research_Manager_Contract $contract;
    private Eduardo_Research_Manager_Output_Resource $outputs;
    private Eduardo_Research_Manager_Project_Resource $projects;
    private Eduardo_Research_Manager_Software_Resource $software;
    private Eduardo_Research_Manager_Dataset_Resource $datasets;
    private Eduardo_Research_Manager_Insight_Resource $insights;

    public function __construct(
        ?Eduardo_Research_Manager_Contract $contract = null,
        ?Eduardo_Research_Manager_Output_Resource $outputs = null,
        ?Eduardo_Research_Manager_Project_Resource $projects = null,
        ?Eduardo_Research_Manager_Software_Resource $software = null,
        ?Eduardo_Research_Manager_Dataset_Resource $datasets = null,
        ?Eduardo_Research_Manager_Insight_Resource $insights = null
    ) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
        $this->outputs = $outputs ?: new Eduardo_Research_Manager_Output_Resource($this->contract);
        $this->projects = $projects ?: new Eduardo_Research_Manager_Project_Resource($this->contract);
        $this->software = $software ?: new Eduardo_Research_Manager_Software_Resource($this->contract);
        $this->datasets = $datasets ?: new Eduardo_Research_Manager_Dataset_Resource($this->contract);
        $this->insights = $insights ?: new Eduardo_Research_Manager_Insight_Resource($this->contract);
    }

    public function inspect(int $source_id): array|WP_Error {
        $source = $this->source_record($source_id, false);
        if (is_wp_error($source)) { return $source; }
        $target_language = $this->opposite_language($source['language']);
        $pending_key = $this->pending_key($target_language);
        $token = (string) get_post_meta($source_id, $pending_key, true);
        $target = '' !== $token ? $this->find_by_creation_token($token, $source['post_type']) : null;
        $paired_id = (int) get_post_meta($source_id, '_research_translation_' . $target_language, true);

        return array(
            'source_id'=>$source_id,
            'post_type'=>$source['post_type'],
            'source_status'=>$source['status'],
            'source_language'=>$source['language'],
            'target_language'=>$target_language,
            'paired_id'=>$paired_id,
            'pending_token'=>$token,
            'pending_exists'=>$target instanceof WP_Post,
            'pending_id'=>$target instanceof WP_Post ? (int) $target->ID : 0,
            'pending_status'=>$target instanceof WP_Post ? (string) $target->post_status : '',
            'pending_title'=>$target instanceof WP_Post ? (string) $target->post_title : '',
            'pending_slug'=>$target instanceof WP_Post ? (string) $target->post_name : '',
            'pending_url'=>$target instanceof WP_Post ? (string) get_permalink($target) : '',
        );
    }

    public function build_creation_plan(
        int $source_id,
        string $target_title,
        string $target_slug = '',
        string $intent = '',
        array $context = array()
    ): array|WP_Error {
        $source = $this->source_record($source_id, true);
        if (is_wp_error($source)) { return $source; }

        $target_language = $this->opposite_language($source['language']);
        $paired_id = (int) get_post_meta($source_id, '_research_translation_' . $target_language, true);
        if ($paired_id > 0) {
            return new WP_Error('research_manager_translation_already_paired', 'This source record already stores a translation counterpart. Remove or review the existing pairing before creating another draft.');
        }

        $pending_key = $this->pending_key($target_language);
        $existing_token = trim((string) get_post_meta($source_id, $pending_key, true));
        if ('' !== $existing_token && $this->find_by_creation_token($existing_token, $source['post_type']) instanceof WP_Post) {
            return new WP_Error('research_manager_translation_draft_exists', 'A pending opposite-language draft already exists for this source record.');
        }

        $target_title = sanitize_text_field($target_title);
        $target_slug = sanitize_title('' !== trim($target_slug) ? $target_slug : $target_title);
        if ('' === $target_title || '' === $target_slug) {
            return new WP_Error('research_manager_translation_draft_identity_required', 'Translation draft creation requires an explicit target-language title and a valid slug.');
        }
        if ($this->find_by_slug($target_slug, $source['post_type']) instanceof WP_Post) {
            return new WP_Error('research_manager_translation_draft_slug_conflict', 'The requested target-language slug is already occupied by the same Research resource type.');
        }

        $creation = $this->resource_creation_plan($source, $target_language, $target_title, $target_slug, $context);
        if (is_wp_error($creation)) { return $creation; }
        $action = is_array($creation['actions'][0] ?? null) ? $creation['actions'][0] : array();
        $token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        if ('' === $token) {
            return new WP_Error('research_manager_translation_draft_token_missing', 'The underlying resource service did not produce a creation provenance token.');
        }

        $actions = array(
            $action,
            array('type'=>'post_meta','post_id'=>$source_id,'key'=>$pending_key,'value'=>$token),
        );
        $intent = '' !== trim($intent)
            ? $intent
            : sprintf('Create %s translation draft for %s #%d', strtoupper($target_language), $source['post_type'], $source_id);
        $plan_context = in_array($source['post_type'], self::STRUCTURED_TYPES, true) ? $context : array();
        return Eduardo_Research_Manager_Plan::create($intent, $actions, $plan_context);
    }

    public function verify(int $source_id, ?string $expected_token = null): array|WP_Error {
        $source = $this->source_record($source_id, false);
        if (is_wp_error($source)) { return $source; }
        $target_language = $this->opposite_language($source['language']);
        $token = (string) get_post_meta($source_id, $this->pending_key($target_language), true);
        if (null !== $expected_token && ('' === $expected_token || ! hash_equals($expected_token, $token))) {
            return array(
                'verified'=>false,
                'source_id'=>$source_id,
                'target_id'=>0,
                'target_language'=>$target_language,
                'checks'=>array('provenance'=>false),
                'verified_at'=>gmdate(DATE_W3C),
            );
        }
        $target = '' !== $token ? $this->find_by_creation_token($token, $source['post_type']) : null;
        $checks = array(
            'pending_token'=>'' !== $token,
            'target_exists'=>$target instanceof WP_Post,
            'target_type'=>$target instanceof WP_Post && (string) $target->post_type === $source['post_type'],
            'target_status'=>$target instanceof WP_Post && 'draft' === (string) $target->post_status,
            'target_language'=>$target instanceof WP_Post && $target_language === $this->record_language((int) $target->ID),
            'not_publicly_paired'=>(int) get_post_meta($source_id, '_research_translation_' . $target_language, true) <= 0,
        );
        if (null !== $expected_token) { $checks['provenance'] = hash_equals($expected_token, $token); }

        if ($target instanceof WP_Post && in_array($source['post_type'], self::STRUCTURED_TYPES, true)) {
            foreach ($this->sensitive_meta_keys($source['post_type']) as $key) {
                $checks['empty:' . $key] = $this->is_empty_meta_value(get_post_meta((int) $target->ID, $key, true));
            }
            $checks['empty_excerpt'] = '' === trim((string) $target->post_excerpt);
            $checks['empty_content'] = '' === trim((string) $target->post_content);
        }

        return array(
            'verified'=>! in_array(false, $checks, true),
            'source_id'=>$source_id,
            'source_language'=>$source['language'],
            'target_language'=>$target_language,
            'pending_token'=>$token,
            'target_id'=>$target instanceof WP_Post ? (int) $target->ID : 0,
            'target_title'=>$target instanceof WP_Post ? (string) $target->post_title : '',
            'target_slug'=>$target instanceof WP_Post ? (string) $target->post_name : '',
            'checks'=>$checks,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    private function resource_creation_plan(array $source, string $language, string $title, string $slug, array $context): array|WP_Error {
        $base = array('title'=>$title,'slug'=>$slug,'language'=>$language,'status'=>'draft');
        return match ($source['post_type']) {
            'research_output' => $this->outputs->build_creation_plan($base, 'Normalize Research Output translation draft', $context),
            'research_project' => $this->projects->build_creation_plan($base, 'Normalize Research Project translation draft', $context),
            'research_software' => $this->software->build_creation_plan($base, 'Normalize Research Software translation draft', $context),
            'research_dataset' => $this->datasets->build_creation_plan($base, 'Normalize Research Dataset translation draft', $context),
            'post' => $this->insights->build_creation_plan(array_merge($base, array(
                'insight_type'=>sanitize_key((string) get_post_meta((int) $source['post_id'], '_research_insight_type', true)) ?: 'research_note',
                'excerpt'=>'',
                'content'=>'',
            )), 'Normalize Research Insight translation draft'),
            default => new WP_Error('research_manager_translation_resource_unsupported', 'This source type is outside the translation-draft contract.'),
        };
    }

    private function source_record(int $source_id, bool $require_publish): array|WP_Error {
        $post = get_post($source_id);
        if (! $post instanceof WP_Post || ! in_array((string) $post->post_type, self::SUPPORTED_TYPES, true)) {
            return new WP_Error('research_manager_translation_resource_unsupported', 'Translation drafts support Insights, Publications, Projects, Software and Datasets only.');
        }
        if ($require_publish && 'publish' !== (string) $post->post_status) {
            return new WP_Error('research_manager_translation_source_not_public', 'The source record must be published before an opposite-language draft can be created.');
        }
        $language = $this->record_language($source_id);
        if (! in_array($language, array('en','es'), true)) {
            return new WP_Error('research_manager_translation_language_invalid', 'The source record must use the Research EN/ES language contract.');
        }
        return array(
            'post_id'=>$source_id,
            'post_type'=>(string) $post->post_type,
            'status'=>(string) $post->post_status,
            'language'=>$language,
        );
    }

    private function record_language(int $post_id): string {
        if (function_exists('eduardo_research_post_language')) {
            return sanitize_key((string) eduardo_research_post_language($post_id));
        }
        $language = sanitize_key((string) get_post_meta($post_id, '_research_language', true));
        return '' !== $language ? $language : 'en';
    }

    private function opposite_language(string $language): string {
        return 'es' === $language ? 'en' : 'es';
    }

    private function pending_key(string $target_language): string {
        return '_eduardo_research_translation_draft_' . $target_language;
    }

    private function find_by_creation_token(string $token, string $post_type): ?WP_Post {
        if ('' === trim($token)) { return null; }
        $posts = get_posts(array(
            'post_type'=>$post_type,
            'post_status'=>'any',
            'posts_per_page'=>2,
            'orderby'=>'ID',
            'order'=>'ASC',
            'meta_key'=>'_eduardo_research_manager_creation_token',
            'meta_value'=>$token,
            'suppress_filters'=>true,
        ));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? $posts[0] : null;
    }

    private function find_by_slug(string $slug, string $post_type): ?WP_Post {
        $posts = get_posts(array(
            'post_type'=>$post_type,
            'post_status'=>'any',
            'name'=>$slug,
            'posts_per_page'=>1,
            'suppress_filters'=>true,
        ));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? $posts[0] : null;
    }

    private function sensitive_meta_keys(string $post_type): array {
        return match ($post_type) {
            'research_output' => array('_research_output_type','_research_review_status','_research_publication_date','_research_venue','_research_doi','_research_authors','_research_line_ids'),
            'research_project' => array('_research_project_status','_research_question','_research_role','_research_start_date','_research_end_date','_research_partner','_research_funding','_research_project_url','_research_methods','_research_line_ids'),
            'research_software' => array('_research_software_status','_research_software_version','_research_release_date','_research_repository_url','_research_archive_url','_research_license','_research_documentation_url','_research_doi','_research_programming_languages','_research_line_ids'),
            'research_dataset' => array('_research_dataset_version','_research_publication_date','_research_repository','_research_doi','_research_license','_research_access_level','_research_formats','_research_methodology','_research_provenance','_research_size','_research_documentation_url','_research_ethics_notes','_research_line_ids'),
            default => array(),
        };
    }

    private function is_empty_meta_value(mixed $value): bool {
        if (is_array($value)) { return array() === $value; }
        if (null === $value || false === $value) { return true; }
        if (! is_scalar($value)) { return false; }
        $string = trim((string) $value);
        return '' === $string || '0' === $string;
    }
}
