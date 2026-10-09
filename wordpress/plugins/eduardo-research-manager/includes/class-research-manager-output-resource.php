<?php
/** Evidence-aware service for Theme-owned Research Outputs / Publications. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Output_Resource {
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    public function inspect(int $post_id): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        $post = get_post($post_id);
        if (! $post instanceof WP_Post || 'research_output' !== $post->post_type) {
            return new WP_Error('research_manager_output_missing', 'The requested Research Output does not exist.');
        }
        return array(
            'post_id'=>$post_id,'post_type'=>'research_output','status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,'title'=>(string) $post->post_title,'excerpt'=>(string) $post->post_excerpt,'content'=>(string) $post->post_content,
            'language'=>$this->private_meta_string($post_id, '_research_language', 'en'),
            'output_type'=>$this->private_meta_string($post_id, '_research_output_type'),
            'output_type_verified'=>$this->private_meta_string($post_id, '_research_output_type_verified', '0'),
            'review_status'=>$this->private_meta_string($post_id, '_research_review_status'),
            'review_status_verified'=>$this->private_meta_string($post_id, '_research_review_status_verified', '0'),
            'publication_date'=>$this->private_meta_string($post_id, '_research_publication_date'),
            'venue'=>$this->private_meta_string($post_id, '_research_venue'),
            'doi'=>$this->private_meta_string($post_id, '_research_doi'),
            'doi_verified'=>$this->private_meta_string($post_id, '_research_doi_verified', '0'),
            'authors'=>$this->private_meta_value($post_id, '_research_authors', array()),
            'line_ids'=>$this->private_meta_value($post_id, '_research_line_ids', array()),
            'creation_token'=>$this->private_meta_string($post_id, '_eduardo_research_manager_creation_token'),
            'url'=>(string) get_permalink($post),
        );
    }

    public function build_creation_plan(array $data, string $intent = '', array $context = array()): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        $title = is_scalar($data['title'] ?? null) ? (string) $data['title'] : '';
        $action = array(
            'type'=>'create_output','title'=>$title,
            'slug'=>is_scalar($data['slug'] ?? null) ? (string) $data['slug'] : sanitize_title($title),
            'excerpt'=>is_scalar($data['excerpt'] ?? null) ? (string) $data['excerpt'] : '',
            'content'=>is_scalar($data['content'] ?? null) ? (string) $data['content'] : '',
            'language'=>is_scalar($data['language'] ?? null) ? (string) $data['language'] : 'en',
            'status'=>is_scalar($data['status'] ?? null) ? (string) $data['status'] : 'draft',
            'output_type'=>is_scalar($data['output_type'] ?? null) ? (string) $data['output_type'] : '',
            'review_status'=>is_scalar($data['review_status'] ?? null) ? (string) $data['review_status'] : '',
            'publication_date'=>is_scalar($data['publication_date'] ?? null) ? (string) $data['publication_date'] : '',
            'venue'=>is_scalar($data['venue'] ?? null) ? (string) $data['venue'] : '',
            'doi'=>is_scalar($data['doi'] ?? null) ? (string) $data['doi'] : '',
            'authors'=>is_array($data['authors'] ?? null) ? $data['authors'] : array(),
            'line_ids'=>is_array($data['line_ids'] ?? null) ? $data['line_ids'] : array(),
            'creation_token'=>wp_generate_uuid4(),
        );
        $intent = '' !== trim($intent) ? $intent : sprintf('Create verified Research Output: %s', sanitize_text_field($title));
        return Eduardo_Research_Manager_Plan::create($intent, array($action), $context);
    }

    public function build_update_plan(int $post_id, array $changes, string $intent = '', array $context = array()): array|WP_Error {
        $current = $this->inspect($post_id);
        if (is_wp_error($current)) { return $current; }
        if (! $changes) { return new WP_Error('research_manager_empty_update', 'At least one Research Output field must be supplied.'); }
        $allowed = array('title','excerpt','content','language','output_type','review_status','publication_date','venue','doi','authors','line_ids');
        $unknown = array_diff(array_keys($changes), $allowed);
        if ($unknown) {
            return new WP_Error('research_manager_output_field_not_allowed', 'Research Output update contains fields outside the bounded contract: ' . implode(', ', array_map('sanitize_key', $unknown)));
        }

        $synthetic = array(
            'type'=>'create_output','title'=>$current['title'],'slug'=>$current['slug'],'excerpt'=>$current['excerpt'],'content'=>$current['content'],
            'language'=>$current['language'],'status'=>$current['status'],'output_type'=>$current['output_type'],'review_status'=>$current['review_status'],
            'publication_date'=>$current['publication_date'],'venue'=>$current['venue'],'doi'=>$current['doi'],'authors'=>$current['authors'],'line_ids'=>$current['line_ids'],
            'creation_token'=>wp_generate_uuid4(),
        );
        foreach ($changes as $field => $value) { $synthetic[$field] = $value; }
        $normalization = Eduardo_Research_Manager_Plan::create('Normalize Research Output update', array($synthetic), $context);
        if (is_wp_error($normalization)) { return $normalization; }
        $next = $normalization['actions'][0];
        $actions = array();

        foreach (array('title'=>'post_title','excerpt'=>'post_excerpt','content'=>'post_content') as $field => $wp_field) {
            if (array_key_exists($field, $changes) && (string) $current[$field] !== (string) $next[$field]) {
                $actions[] = array('type'=>'post_field','post_id'=>$post_id,'field'=>$wp_field,'value'=>$next[$field]);
            }
        }
        if (array_key_exists('language', $changes) && (string) $current['language'] !== (string) $next['language']) {
            $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>'_research_language','value'=>$next['language']);
        }
        foreach (array(
            'output_type'=>'_research_output_type','review_status'=>'_research_review_status','publication_date'=>'_research_publication_date',
            'venue'=>'_research_venue','doi'=>'_research_doi','authors'=>'_research_authors','line_ids'=>'_research_line_ids',
        ) as $field => $meta_key) {
            if (! array_key_exists($field, $changes)) { continue; }
            if (maybe_serialize($current[$field]) !== maybe_serialize($next[$field])) {
                $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>$meta_key,'value'=>$next[$field]);
            }
        }

        foreach (array(
            'output_type'=>array('flag'=>'_research_output_type_verified','expected'=>'output_type_verified'),
            'review_status'=>array('flag'=>'_research_review_status_verified','expected'=>'review_status_verified'),
            'doi'=>array('flag'=>'_research_doi_verified','expected'=>'doi_verified'),
        ) as $field => $verification) {
            if (! array_key_exists($field, $changes)) { continue; }
            $expected_flag = (string) $next[$verification['expected']];
            if ((string) $current[$verification['expected']] !== $expected_flag) {
                $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>$verification['flag'],'value'=>$expected_flag);
            }
        }

        if (! $actions) { return new WP_Error('research_manager_no_change', 'The requested Research Output update already matches stored state.'); }
        $intent = '' !== trim($intent) ? $intent : sprintf('Update Research Output #%d', $post_id);
        return Eduardo_Research_Manager_Plan::create($intent, $actions, $context);
    }

    public function verify(int $post_id, array $expected, ?string $creation_token = null): array|WP_Error {
        $record = $this->inspect($post_id);
        if (is_wp_error($record)) { return $record; }
        $allowed = array('status','slug','title','excerpt','content','language','output_type','review_status','publication_date','venue','doi','authors','line_ids');
        $unknown = array_diff(array_keys($expected), $allowed);
        if ($unknown) { return new WP_Error('research_manager_output_field_not_allowed', 'Research Output verification requested unsupported fields.'); }

        $synthetic = array(
            'type'=>'create_output','title'=>$record['title'],'slug'=>$record['slug'],'excerpt'=>$record['excerpt'],'content'=>$record['content'],
            'language'=>$record['language'],'status'=>$record['status'],'output_type'=>$record['output_type'],'review_status'=>$record['review_status'],
            'publication_date'=>$record['publication_date'],'venue'=>$record['venue'],'doi'=>$record['doi'],'authors'=>$record['authors'],'line_ids'=>$record['line_ids'],
            'creation_token'=>wp_generate_uuid4(),
        );
        foreach ($expected as $field => $value) { $synthetic[$field] = $value; }
        $normalized = Eduardo_Research_Manager_Plan::create('Normalize Research Output verification', array($synthetic), array('evidence_confirmed'=>true,'evidence_reference'=>'verification-only'));
        if (is_wp_error($normalized)) { return $normalized; }
        $next = $normalized['actions'][0];

        $checks = array('route'=>'' !== (string) $record['url']);
        foreach ($expected as $field => $_) {
            $checks[$field] = maybe_serialize($record[$field] ?? null) === maybe_serialize($next[$field] ?? null);
        }
        foreach (array('output_type'=>'output_type_verified','review_status'=>'review_status_verified','doi'=>'doi_verified') as $field => $flag) {
            if (array_key_exists($field, $expected) && '' !== (string) ($next[$field] ?? '')) { $checks[$flag] = '1' === (string) $record[$flag]; }
        }
        if (null !== $creation_token) {
            $checks['provenance'] = '' !== $creation_token && hash_equals($creation_token, (string) $record['creation_token']);
        }
        return array('verified'=>! in_array(false, $checks, true),'post_id'=>$post_id,'url'=>$record['url'],'checks'=>$checks,'verified_at'=>gmdate(DATE_W3C));
    }

    public function find_created_by_token(string $token): int {
        if ('' === trim($token)) { return 0; }
        $posts = get_posts(array('post_type'=>'research_output','post_status'=>'any','posts_per_page'=>2,'orderby'=>'ID','order'=>'ASC','meta_key'=>'_eduardo_research_manager_creation_token','meta_value'=>$token,'suppress_filters'=>true));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? (int) $posts[0]->ID : 0;
    }

    private function validate_contract(): bool|WP_Error {
        if (! $this->contract->compatible() || ! post_type_exists('research_output') || ! function_exists('eduardo_research_collection_options')) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme publication contract is required.');
        }
        return true;
    }

    private function private_meta_value(int $post_id, string $key, mixed $default = null): mixed {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key));
        return null === $raw ? $default : maybe_unserialize($raw);
    }
    private function private_meta_string(int $post_id, string $key, string $default = ''): string {
        $value = $this->private_meta_value($post_id, $key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }
}
