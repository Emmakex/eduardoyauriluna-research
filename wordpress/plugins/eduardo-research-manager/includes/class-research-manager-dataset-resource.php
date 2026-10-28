<?php
/** Evidence-aware service for Theme-owned Research Datasets. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Dataset_Resource {
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    public function inspect(int $post_id): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        $post = get_post($post_id);
        $post_type = $this->post_type();
        if (! $post instanceof WP_Post || $post_type !== $post->post_type) {
            return new WP_Error('research_manager_dataset_missing', 'The requested Research Dataset does not exist.');
        }
        $record = array(
            'post_id'=>$post_id,'post_type'=>$post_type,'status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,'title'=>(string) $post->post_title,
            'excerpt'=>(string) $post->post_excerpt,'content'=>(string) $post->post_content,
        );
        foreach ($this->meta_fields() as $field => $meta_key) {
            $default = $this->default_for_field($field);
            $record[$field] = in_array($field, $this->array_fields(), true)
                ? $this->private_meta_value($post_id, $meta_key, $default)
                : $this->private_meta_string($post_id, $meta_key, (string) $default);
        }
        $record['creation_token'] = $this->private_meta_string($post_id, '_eduardo_research_manager_creation_token');
        $record['url'] = (string) get_permalink($post);
        return $record;
    }

    public function build_creation_plan(array $data, string $intent = '', array $context = array()): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        if (array_key_exists('formats', $data) && ! is_array($data['formats'])) {
            return new WP_Error('research_manager_invalid_dataset_formats', 'Dataset formats must be supplied as an array.');
        }
        if (array_key_exists('line_ids', $data) && ! is_array($data['line_ids'])) {
            return new WP_Error('research_manager_invalid_line_relations', 'Research Line relations must be supplied as an array of IDs.');
        }
        $title = is_scalar($data['title'] ?? null) ? (string) $data['title'] : '';
        $action = array(
            'type'=>'create_dataset','title'=>$title,
            'slug'=>is_scalar($data['slug'] ?? null) ? (string) $data['slug'] : sanitize_title($title),
            'excerpt'=>is_scalar($data['excerpt'] ?? null) ? (string) $data['excerpt'] : '',
            'content'=>is_scalar($data['content'] ?? null) ? (string) $data['content'] : '',
            'language'=>is_scalar($data['language'] ?? null) ? (string) $data['language'] : $this->contract->default_language(),
            'status'=>is_scalar($data['status'] ?? null) ? (string) $data['status'] : 'draft',
            'version'=>is_scalar($data['version'] ?? null) ? (string) $data['version'] : '',
            'publication_date'=>is_scalar($data['publication_date'] ?? null) ? (string) $data['publication_date'] : '',
            'repository'=>is_scalar($data['repository'] ?? null) ? (string) $data['repository'] : '',
            'doi'=>is_scalar($data['doi'] ?? null) ? (string) $data['doi'] : '',
            'license'=>is_scalar($data['license'] ?? null) ? (string) $data['license'] : '',
            'access_level'=>is_scalar($data['access_level'] ?? null) ? (string) $data['access_level'] : '',
            'methodology'=>is_scalar($data['methodology'] ?? null) ? (string) $data['methodology'] : '',
            'provenance'=>is_scalar($data['provenance'] ?? null) ? (string) $data['provenance'] : '',
            'size'=>is_scalar($data['size'] ?? null) ? (string) $data['size'] : '',
            'documentation_url'=>is_scalar($data['documentation_url'] ?? null) ? (string) $data['documentation_url'] : '',
            'ethics_notes'=>is_scalar($data['ethics_notes'] ?? null) ? (string) $data['ethics_notes'] : '',
            'formats'=>$data['formats'] ?? array(),
            'line_ids'=>$data['line_ids'] ?? array(),
            'creation_token'=>wp_generate_uuid4(),
        );
        $intent = '' !== trim($intent) ? $intent : sprintf('Create verified Research Dataset: %s', sanitize_text_field($title));
        return Eduardo_Research_Manager_Plan::create($intent, array($action), $context);
    }

    public function build_update_plan(int $post_id, array $changes, string $intent = '', array $context = array()): array|WP_Error {
        $current = $this->inspect($post_id);
        if (is_wp_error($current)) { return $current; }
        if (! $changes) { return new WP_Error('research_manager_empty_update', 'At least one Research Dataset field must be supplied.'); }
        $allowed = array_values(array_diff(
            Eduardo_Research_Manager_Resource_Registry::editable_fields('dataset'),
            array('status','slug')
        ));
        $unknown = array_diff(array_keys($changes), $allowed);
        if ($unknown) {
            return new WP_Error('research_manager_dataset_field_not_allowed', 'Research Dataset update contains fields outside the bounded contract: ' . implode(', ', array_map('sanitize_key', $unknown)));
        }
        if (array_key_exists('formats', $changes) && ! is_array($changes['formats'])) {
            return new WP_Error('research_manager_invalid_dataset_formats', 'Dataset formats must be supplied as an array.');
        }
        if (array_key_exists('line_ids', $changes) && ! is_array($changes['line_ids'])) {
            return new WP_Error('research_manager_invalid_line_relations', 'Research Line relations must be supplied as an array of IDs.');
        }

        $synthetic = array(
            'type'=>'create_dataset','title'=>$current['title'],'slug'=>$current['slug'],'excerpt'=>$current['excerpt'],'content'=>$current['content'],
            'language'=>$current['language'],'status'=>$current['status'],'version'=>$current['version'],'publication_date'=>$current['publication_date'],
            'repository'=>$current['repository'],'doi'=>$current['doi'],'license'=>$current['license'],'access_level'=>$current['access_level'],
            'methodology'=>$current['methodology'],'provenance'=>$current['provenance'],'size'=>$current['size'],
            'documentation_url'=>$current['documentation_url'],'ethics_notes'=>$current['ethics_notes'],'formats'=>$current['formats'],
            'line_ids'=>$current['line_ids'],'creation_token'=>wp_generate_uuid4(),
        );
        foreach ($changes as $field => $value) { $synthetic[$field] = $value; }
        $normalization = Eduardo_Research_Manager_Plan::create('Normalize Research Dataset update', array($synthetic), $context);
        if (is_wp_error($normalization)) { return $normalization; }
        $next = $normalization['actions'][0];
        $actions = array();
        foreach (array('title'=>'post_title','excerpt'=>'post_excerpt','content'=>'post_content') as $field => $wp_field) {
            if (array_key_exists($field, $changes) && (string) $current[$field] !== (string) $next[$field]) {
                $actions[] = array('type'=>'post_field','post_id'=>$post_id,'field'=>$wp_field,'value'=>$next[$field]);
            }
        }
        $meta_fields = $this->meta_fields();
        foreach ($meta_fields as $field => $meta_key) {
            if (! array_key_exists($field, $changes)) { continue; }
            if (maybe_serialize($current[$field]) !== maybe_serialize($next[$field])) {
                $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>$meta_key,'value'=>$next[$field]);
            }
        }
        if (array_key_exists('doi', $changes) && (string) $current['doi_verified'] !== (string) $next['doi_verified']) {
            $meta_key = (string) ($meta_fields['doi_verified'] ?? '');
            if ('' === $meta_key) {
                return new WP_Error('research_manager_dataset_storage_contract_invalid', 'Research Dataset storage descriptor is incomplete.');
            }
            $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>$meta_key,'value'=>$next['doi_verified']);
        }
        if (! $actions) { return new WP_Error('research_manager_no_change', 'The requested Research Dataset update already matches stored state.'); }
        $intent = '' !== trim($intent) ? $intent : sprintf('Update Research Dataset #%d', $post_id);
        return Eduardo_Research_Manager_Plan::create($intent, $actions, $context);
    }

    public function verify(int $post_id, array $expected, ?string $creation_token = null): array|WP_Error {
        $record = $this->inspect($post_id);
        if (is_wp_error($record)) { return $record; }
        $allowed = Eduardo_Research_Manager_Resource_Registry::editable_fields('dataset');
        $unknown = array_diff(array_keys($expected), $allowed);
        if ($unknown) { return new WP_Error('research_manager_dataset_field_not_allowed', 'Research Dataset verification requested unsupported fields.'); }
        $synthetic = array(
            'type'=>'create_dataset','title'=>$record['title'],'slug'=>$record['slug'],'excerpt'=>$record['excerpt'],'content'=>$record['content'],
            'language'=>$record['language'],'status'=>$record['status'],'version'=>$record['version'],'publication_date'=>$record['publication_date'],
            'repository'=>$record['repository'],'doi'=>$record['doi'],'license'=>$record['license'],'access_level'=>$record['access_level'],
            'methodology'=>$record['methodology'],'provenance'=>$record['provenance'],'size'=>$record['size'],
            'documentation_url'=>$record['documentation_url'],'ethics_notes'=>$record['ethics_notes'],'formats'=>$record['formats'],
            'line_ids'=>$record['line_ids'],'creation_token'=>wp_generate_uuid4(),
        );
        foreach ($expected as $field => $value) { $synthetic[$field] = $value; }
        $normalized = Eduardo_Research_Manager_Plan::create('Normalize Research Dataset verification', array($synthetic), array('evidence_confirmed'=>true,'evidence_reference'=>'verification-only'));
        if (is_wp_error($normalized)) { return $normalized; }
        $next = $normalized['actions'][0];
        $checks = array('route'=>'' !== (string) $record['url']);
        foreach ($expected as $field => $_) {
            $checks[$field] = maybe_serialize($record[$field] ?? null) === maybe_serialize($next[$field] ?? null);
        }
        if (array_key_exists('doi', $expected)) { $checks['doi_verified'] = (string) $record['doi_verified'] === (string) $next['doi_verified']; }
        if (null !== $creation_token) { $checks['provenance'] = '' !== $creation_token && hash_equals($creation_token, (string) $record['creation_token']); }
        return array('verified'=>! in_array(false, $checks, true),'post_id'=>$post_id,'url'=>$record['url'],'checks'=>$checks,'verified_at'=>gmdate(DATE_W3C));
    }

    public function find_created_by_token(string $token): int {
        if ('' === trim($token)) { return 0; }
        $posts = get_posts(array(
            'post_type'=>$this->post_type(),'post_status'=>'any','posts_per_page'=>2,'orderby'=>'ID','order'=>'ASC',
            'meta_key'=>'_eduardo_research_manager_creation_token','meta_value'=>$token,'suppress_filters'=>true,
        ));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? (int) $posts[0]->ID : 0;
    }

    private function validate_contract(): bool|WP_Error {
        if (! $this->contract->compatible() || ! post_type_exists($this->post_type())) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme dataset contract is required.');
        }
        $catalogs = $this->contract->collection_catalogs('datasets');
        if (is_wp_error($catalogs)) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme dataset contract is required.');
        }
        return true;
    }

    private function post_type(): string {
        $registry = Eduardo_Research_Manager_Resource_Registry::get('dataset');
        return (string) ($registry['post_type'] ?? '');
    }

    private function meta_fields(): array {
        return Eduardo_Research_Manager_Resource_Registry::resource_meta_fields('dataset');
    }

    private function array_fields(): array {
        return Eduardo_Research_Manager_Resource_Registry::array_fields('dataset');
    }

    private function defaults(): array {
        return Eduardo_Research_Manager_Resource_Registry::defaults('dataset');
    }

    private function default_for_field(string $field): mixed {
        if ('language' === $field) { return $this->contract->default_language(); }
        if (in_array($field, $this->array_fields(), true)) { return array(); }
        $defaults = $this->defaults();
        return $defaults[$field] ?? '';
    }

    private function private_meta_value(int $post_id, string $key, mixed $default = null): mixed {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $post_id,
            $key
        ));
        return null === $raw ? $default : maybe_unserialize($raw);
    }

    private function private_meta_string(int $post_id, string $key, string $default = ''): string {
        $value = $this->private_meta_value($post_id, $key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }
}
