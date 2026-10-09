<?php
/** Evidence-aware service for Theme-owned Research Software. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Software_Resource {
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    public function inspect(int $post_id): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        $post = get_post($post_id);
        if (! $post instanceof WP_Post || 'research_software' !== $post->post_type) {
            return new WP_Error('research_manager_software_missing', 'The requested Research Software record does not exist.');
        }
        return array(
            'post_id'=>$post_id,'post_type'=>'research_software','status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,'title'=>(string) $post->post_title,
            'excerpt'=>(string) $post->post_excerpt,'content'=>(string) $post->post_content,
            'language'=>$this->private_meta_string($post_id, '_research_language', 'en'),
            'software_status'=>$this->private_meta_string($post_id, '_research_software_status'),
            'version'=>$this->private_meta_string($post_id, '_research_software_version'),
            'release_date'=>$this->private_meta_string($post_id, '_research_release_date'),
            'repository_url'=>$this->private_meta_string($post_id, '_research_repository_url'),
            'archive_url'=>$this->private_meta_string($post_id, '_research_archive_url'),
            'license'=>$this->private_meta_string($post_id, '_research_license'),
            'documentation_url'=>$this->private_meta_string($post_id, '_research_documentation_url'),
            'doi'=>$this->private_meta_string($post_id, '_research_doi'),
            'doi_verified'=>$this->private_meta_string($post_id, '_research_doi_verified', '0'),
            'programming_languages'=>$this->private_meta_value($post_id, '_research_programming_languages', array()),
            'line_ids'=>$this->private_meta_value($post_id, '_research_line_ids', array()),
            'creation_token'=>$this->private_meta_string($post_id, '_eduardo_research_manager_creation_token'),
            'url'=>(string) get_permalink($post),
        );
    }

    public function build_creation_plan(array $data, string $intent = '', array $context = array()): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        if (array_key_exists('programming_languages', $data) && ! is_array($data['programming_languages'])) {
            return new WP_Error('research_manager_invalid_programming_languages', 'Programming languages must be supplied as an array.');
        }
        if (array_key_exists('line_ids', $data) && ! is_array($data['line_ids'])) {
            return new WP_Error('research_manager_invalid_line_relations', 'Research Line relations must be supplied as an array of IDs.');
        }
        $title = is_scalar($data['title'] ?? null) ? (string) $data['title'] : '';
        $action = array(
            'type'=>'create_software','title'=>$title,
            'slug'=>is_scalar($data['slug'] ?? null) ? (string) $data['slug'] : sanitize_title($title),
            'excerpt'=>is_scalar($data['excerpt'] ?? null) ? (string) $data['excerpt'] : '',
            'content'=>is_scalar($data['content'] ?? null) ? (string) $data['content'] : '',
            'language'=>is_scalar($data['language'] ?? null) ? (string) $data['language'] : 'en',
            'status'=>is_scalar($data['status'] ?? null) ? (string) $data['status'] : 'draft',
            'software_status'=>is_scalar($data['software_status'] ?? null) ? (string) $data['software_status'] : '',
            'version'=>is_scalar($data['version'] ?? null) ? (string) $data['version'] : '',
            'release_date'=>is_scalar($data['release_date'] ?? null) ? (string) $data['release_date'] : '',
            'repository_url'=>is_scalar($data['repository_url'] ?? null) ? (string) $data['repository_url'] : '',
            'archive_url'=>is_scalar($data['archive_url'] ?? null) ? (string) $data['archive_url'] : '',
            'license'=>is_scalar($data['license'] ?? null) ? (string) $data['license'] : '',
            'documentation_url'=>is_scalar($data['documentation_url'] ?? null) ? (string) $data['documentation_url'] : '',
            'doi'=>is_scalar($data['doi'] ?? null) ? (string) $data['doi'] : '',
            'programming_languages'=>$data['programming_languages'] ?? array(),
            'line_ids'=>$data['line_ids'] ?? array(),
            'creation_token'=>wp_generate_uuid4(),
        );
        $intent = '' !== trim($intent) ? $intent : sprintf('Create verified Research Software: %s', sanitize_text_field($title));
        return Eduardo_Research_Manager_Plan::create($intent, array($action), $context);
    }

    public function build_update_plan(int $post_id, array $changes, string $intent = '', array $context = array()): array|WP_Error {
        $current = $this->inspect($post_id);
        if (is_wp_error($current)) { return $current; }
        if (! $changes) { return new WP_Error('research_manager_empty_update', 'At least one Research Software field must be supplied.'); }
        $allowed = array(
            'title','excerpt','content','language','software_status','version','release_date','repository_url','archive_url',
            'license','documentation_url','doi','programming_languages','line_ids',
        );
        $unknown = array_diff(array_keys($changes), $allowed);
        if ($unknown) { return new WP_Error('research_manager_software_field_not_allowed', 'Research Software update contains fields outside the bounded contract: ' . implode(', ', array_map('sanitize_key', $unknown))); }
        if (array_key_exists('programming_languages', $changes) && ! is_array($changes['programming_languages'])) { return new WP_Error('research_manager_invalid_programming_languages', 'Programming languages must be supplied as an array.'); }
        if (array_key_exists('line_ids', $changes) && ! is_array($changes['line_ids'])) { return new WP_Error('research_manager_invalid_line_relations', 'Research Line relations must be supplied as an array of IDs.'); }

        $synthetic = array(
            'type'=>'create_software','title'=>$current['title'],'slug'=>$current['slug'],'excerpt'=>$current['excerpt'],'content'=>$current['content'],
            'language'=>$current['language'],'status'=>$current['status'],'software_status'=>$current['software_status'],'version'=>$current['version'],
            'release_date'=>$current['release_date'],'repository_url'=>$current['repository_url'],'archive_url'=>$current['archive_url'],
            'license'=>$current['license'],'documentation_url'=>$current['documentation_url'],'doi'=>$current['doi'],
            'programming_languages'=>$current['programming_languages'],'line_ids'=>$current['line_ids'],'creation_token'=>wp_generate_uuid4(),
        );
        foreach ($changes as $field => $value) { $synthetic[$field] = $value; }
        $normalization = Eduardo_Research_Manager_Plan::create('Normalize Research Software update', array($synthetic), $context);
        if (is_wp_error($normalization)) { return $normalization; }
        $next = $normalization['actions'][0];
        $actions = array();
        foreach (array('title'=>'post_title','excerpt'=>'post_excerpt','content'=>'post_content') as $field => $wp_field) {
            if (array_key_exists($field, $changes) && (string) $current[$field] !== (string) $next[$field]) { $actions[] = array('type'=>'post_field','post_id'=>$post_id,'field'=>$wp_field,'value'=>$next[$field]); }
        }
        $meta = array(
            'language'=>'_research_language','software_status'=>'_research_software_status','version'=>'_research_software_version',
            'release_date'=>'_research_release_date','repository_url'=>'_research_repository_url','archive_url'=>'_research_archive_url',
            'license'=>'_research_license','documentation_url'=>'_research_documentation_url','doi'=>'_research_doi',
            'programming_languages'=>'_research_programming_languages','line_ids'=>'_research_line_ids',
        );
        foreach ($meta as $field => $meta_key) {
            if (! array_key_exists($field, $changes)) { continue; }
            if (maybe_serialize($current[$field]) !== maybe_serialize($next[$field])) { $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>$meta_key,'value'=>$next[$field]); }
        }
        if (array_key_exists('doi', $changes) && (string) $current['doi_verified'] !== (string) $next['doi_verified']) {
            $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>'_research_doi_verified','value'=>$next['doi_verified']);
        }
        if (! $actions) { return new WP_Error('research_manager_no_change', 'The requested Research Software update already matches stored state.'); }
        $intent = '' !== trim($intent) ? $intent : sprintf('Update Research Software #%d', $post_id);
        return Eduardo_Research_Manager_Plan::create($intent, $actions, $context);
    }

    public function verify(int $post_id, array $expected, ?string $creation_token = null): array|WP_Error {
        $record = $this->inspect($post_id);
        if (is_wp_error($record)) { return $record; }
        $allowed = array('status','slug','title','excerpt','content','language','software_status','version','release_date','repository_url','archive_url','license','documentation_url','doi','programming_languages','line_ids');
        $unknown = array_diff(array_keys($expected), $allowed);
        if ($unknown) { return new WP_Error('research_manager_software_field_not_allowed', 'Research Software verification requested unsupported fields.'); }
        $synthetic = array(
            'type'=>'create_software','title'=>$record['title'],'slug'=>$record['slug'],'excerpt'=>$record['excerpt'],'content'=>$record['content'],
            'language'=>$record['language'],'status'=>$record['status'],'software_status'=>$record['software_status'],'version'=>$record['version'],
            'release_date'=>$record['release_date'],'repository_url'=>$record['repository_url'],'archive_url'=>$record['archive_url'],'license'=>$record['license'],
            'documentation_url'=>$record['documentation_url'],'doi'=>$record['doi'],'programming_languages'=>$record['programming_languages'],
            'line_ids'=>$record['line_ids'],'creation_token'=>wp_generate_uuid4(),
        );
        foreach ($expected as $field => $value) { $synthetic[$field] = $value; }
        $normalized = Eduardo_Research_Manager_Plan::create('Normalize Research Software verification', array($synthetic), array('evidence_confirmed'=>true,'evidence_reference'=>'verification-only'));
        if (is_wp_error($normalized)) { return $normalized; }
        $next = $normalized['actions'][0];
        $checks = array('route'=>'' !== (string) $record['url']);
        foreach ($expected as $field => $_) { $checks[$field] = maybe_serialize($record[$field] ?? null) === maybe_serialize($next[$field] ?? null); }
        if (array_key_exists('doi', $expected)) { $checks['doi_verified'] = (string) $record['doi_verified'] === (string) $next['doi_verified']; }
        if (null !== $creation_token) { $checks['provenance'] = '' !== $creation_token && hash_equals($creation_token, (string) $record['creation_token']); }
        return array('verified'=>! in_array(false, $checks, true),'post_id'=>$post_id,'url'=>$record['url'],'checks'=>$checks,'verified_at'=>gmdate(DATE_W3C));
    }

    public function find_created_by_token(string $token): int {
        if ('' === trim($token)) { return 0; }
        $posts = get_posts(array('post_type'=>'research_software','post_status'=>'any','posts_per_page'=>2,'orderby'=>'ID','order'=>'ASC','meta_key'=>'_eduardo_research_manager_creation_token','meta_value'=>$token,'suppress_filters'=>true));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? (int) $posts[0]->ID : 0;
    }

    private function validate_contract(): bool|WP_Error {
        if (! $this->contract->compatible() || ! post_type_exists('research_software') || ! function_exists('eduardo_research_collection_options')) { return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme software contract is required.'); }
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
