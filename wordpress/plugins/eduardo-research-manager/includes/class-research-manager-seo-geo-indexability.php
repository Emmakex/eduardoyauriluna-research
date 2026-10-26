<?php
/** Indexability, robots, sitemap and internal-link diagnostics for managed Research surfaces. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_SEO_GEO_Indexability {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_indexability_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_indexability_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const PLAN_LIMIT = 40;
    private const OPERATION_LIMIT = 80;

    private Eduardo_Research_Manager_SEO_GEO $seo_geo;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        ?Eduardo_Research_Manager_SEO_GEO $seo_geo = null,
        ?Eduardo_Research_Manager_Remote_Audit $audit = null
    ) {
        $this->seo_geo = $seo_geo ?: new Eduardo_Research_Manager_SEO_GEO();
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function site(): array {
        $blog_public = '1' === (string) get_option('blog_public', '1');
        $sitemaps = function_exists('wp_sitemaps_get_server') ? wp_sitemaps_get_server() : null;
        $sitemaps_enabled = is_object($sitemaps) && method_exists($sitemaps, 'sitemaps_enabled')
            ? (bool) $sitemaps->sitemaps_enabled()
            : false;
        $exploration_filter = false !== has_filter('wp_robots', 'eduardo_research_exploration_robots');

        return array(
            'search_engine_visibility'=>$blog_public,
            'diagnostic_check'=>'search-indexability',
            'core_sitemaps_enabled'=>$sitemaps_enabled,
            'localized_page_sitemap_provider'=>class_exists('Eduardo_Research_Language_Sitemap_Provider'),
            'exploration_policy'=>array(
                'registered'=>$exploration_filter,
                'filtered_collection_states'=>'noindex,follow',
                'canonical_collection_surfaces'=>'indexable',
                'arbitrary_per_url_override'=>false,
            ),
            'remediation'=>array(
                'available'=>! $blog_public,
                'operation'=>'search-indexability',
                'bounded_target'=>'blog_public',
                'only_allowed_target_value'=>'1',
                'generic_option_editor'=>false,
            ),
            'expected_public_policy'=>'index,follow unless the Theme classifies the request as an exploration/filter state',
            'ready'=>$blog_public && $sitemaps_enabled && $exploration_filter,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    /** Exact bounded Preview for the single supported global indexability mutation: blog_public 0 -> 1. */
    public function create_plan(string $intent, array $actor): array|WP_Error {
        $baseline = (string) get_option('blog_public', '1');
        if ('1' === $baseline) {
            return $this->error('research_manager_remediation_not_needed', 'Search engine visibility is already enabled.', 409);
        }
        $source_revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($source_revision)) { return $source_revision; }
        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $request_id = sanitize_text_field((string) ($actor['request_id'] ?? ''));
        $intent = sanitize_text_field($intent ?: 'Enable public search-engine indexing for the Research site');
        $material = array(
            'operation'=>'search-indexability',
            'baseline'=>$baseline,
            'desired'=>'1',
            'source_revision'=>$source_revision,
            'connection_id'=>$connection_id,
            'intent'=>$intent,
        );
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-indexability-plan-' . substr($checksum, 0, 18) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,
            'operation'=>'search-indexability',
            'intent'=>$intent,
            'risk'=>'standard',
            'confirmation_required'=>true,
            'confirmation_class'=>'exact-remediation',
            'target'=>array('resource'=>'site:indexability','option'=>'blog_public'),
            'requested'=>array('from'=>$baseline,'to'=>'1'),
            'preview'=>array(
                'changed'=>true,
                'before'=>$baseline,
                'after'=>'1',
                'bounded'=>true,
                'generic_option_editor'=>false,
            ),
            'apply_allowed'=>true,
            'source_revision'=>$source_revision,
            'baseline'=>$baseline,
            'desired'=>'1',
            'connection_id'=>$connection_id,
            'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'created_request_id'=>$request_id,
            'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),
            'expires_unix'=>$now + self::PLAN_TTL,
            'status'=>'planned',
            'checksum'=>$checksum,
        );
        $this->put_plan($plan);
        $this->audit_record('remote-indexability-plan-created', 'success', $actor, $plan_id, '', $intent);
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Indexability plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
        }
        return $this->public_plan($plan);
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot apply indexability operations.', 403);
        }
        $plan = $this->find_plan($plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Indexability plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This indexability plan belongs to a different remote connection.', 403);
        }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) {
                return $this->get_operation((string) $plan['operation_id']);
            }
            return $this->error('validation_failed', 'Only a planned indexability operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan['status'] = 'expired';
            $this->put_plan($plan);
            return $this->error('plan_expired', 'Indexability plan has expired. Create a fresh Preview.', 409);
        }
        if (! $confirmed) {
            return $this->error('confirmation_required', 'Indexability Apply requires explicit confirmation.', 409, array('confirmation_class'=>'exact-remediation'));
        }
        if ('1' !== (string) ($plan['desired'] ?? '') || 'blog_public' !== (string) ($plan['target']['option'] ?? '')) {
            return $this->error('validation_failed', 'Stored indexability plan is outside the bounded mutation contract.', 409);
        }
        $current = (string) get_option('blog_public', '1');
        if (! hash_equals((string) ($plan['baseline'] ?? ''), $current)) {
            return $this->error('stale_revision', 'Search visibility changed after Preview. Create a fresh indexability plan.', 409);
        }
        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($revision)) { return $revision; }
        if (! hash_equals((string) ($plan['source_revision'] ?? ''), $revision)) {
            return $this->error('stale_revision', 'Managed WordPress state changed after Preview. Create a fresh indexability plan.', 409);
        }

        update_option('blog_public', '1', false);
        if ('1' !== (string) get_option('blog_public', '0')) {
            update_option('blog_public', (string) ($plan['baseline'] ?? '0'), false);
            return $this->error('research_manager_write_failed', 'WordPress did not persist the bounded search visibility value.', 409);
        }
        $post_revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($post_revision)) {
            update_option('blog_public', (string) ($plan['baseline'] ?? '0'), false);
            return $post_revision;
        }

        $operation_id = 'erm-indexability-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array(
            'operation_id'=>$operation_id,
            'plan_id'=>$plan_id,
            'operation'=>'search-indexability',
            'target'=>array('resource'=>'site:indexability','option'=>'blog_public'),
            'source_revision'=>(string) ($plan['source_revision'] ?? ''),
            'post_apply_revision'=>$post_revision,
            'baseline'=>(string) ($plan['baseline'] ?? '0'),
            'desired'=>'1',
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'apply_request_id'=>(string) ($actor['request_id'] ?? ''),
            'status'=>'applied',
            'result'=>array('verified'=>true,'stored_value'=>'1'),
            'stored_verification'=>array(),
            'rendered_verification'=>array(),
            'created_at'=>gmdate(DATE_W3C),
            'applied_at'=>gmdate(DATE_W3C),
            'verified_at'=>'',
            'rolled_back_at'=>'',
        );
        $this->put_operation($operation);
        $plan['status'] = 'applied';
        $plan['operation_id'] = $operation_id;
        $plan['applied_at'] = gmdate(DATE_W3C);
        $this->put_plan($plan);
        $this->audit_record('remote-indexability-operation-applied', 'success', $actor, $plan_id, $operation_id, (string) ($plan['intent'] ?? ''));
        return $this->public_operation($operation);
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        return $operation ? $this->public_operation($operation) : $this->error('operation_not_found', 'Indexability operation was not found.', 404);
    }

    public function verify(string $operation_id, bool $include_rendered, array $actor): array|WP_Error {
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Indexability operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This indexability operation belongs to a different remote connection.', 403);
        }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            return $this->error('validation_failed', 'Only applied indexability operations can be verified.', 409);
        }
        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($revision)) { return $revision; }
        $revision_matches = '' !== (string) ($operation['post_apply_revision'] ?? '')
            && hash_equals((string) $operation['post_apply_revision'], $revision);
        $value_matches = '1' === (string) get_option('blog_public', '0');
        $stored_verified = $revision_matches && $value_matches;
        $stored = array(
            'verified'=>$stored_verified,
            'revision_matches'=>$revision_matches,
            'value_matches'=>$value_matches,
            'stored_value'=>(string) get_option('blog_public', '0'),
            'verified_at'=>gmdate(DATE_W3C),
        );
        $rendered = array('requested'=>$include_rendered,'verified'=>null);
        if ($include_rendered) {
            $state = $this->site();
            $rendered = array(
                'requested'=>true,
                'verified'=>! empty($state['ready']),
                'search_engine_visibility'=>! empty($state['search_engine_visibility']),
                'core_sitemaps_enabled'=>! empty($state['core_sitemaps_enabled']),
                'exploration_policy_registered'=>! empty($state['exploration_policy']['registered']),
                'verified_at'=>gmdate(DATE_W3C),
            );
        }
        $verified = $stored_verified && (! $include_rendered || ! empty($rendered['verified']));
        $operation['stored_verification'] = $stored;
        $operation['rendered_verification'] = $rendered;
        $operation['verified_at'] = gmdate(DATE_W3C);
        $operation['status'] = $verified ? 'verified' : 'verification-failed';
        $this->put_operation($operation);
        $this->audit_record('remote-indexability-operation-verified', $verified ? 'success' : 'failed', $actor, (string) $operation['plan_id'], $operation_id, 'Verify search indexability');
        return $this->public_operation($operation);
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local WordPress execution identity cannot rollback indexability operations.', 403);
        }
        $operation = $this->find_operation($operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Indexability operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) {
            return $this->error('scope_denied', 'This indexability operation belongs to a different remote connection.', 403);
        }
        if (! $confirmed) {
            return $this->error('confirmation_required', 'Indexability rollback requires explicit confirmation.', 409, array('confirmation_class'=>'explicit'));
        }
        if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $this->public_operation($operation); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            return $this->error('rollback_unavailable', 'This indexability operation is not rollback-capable.', 409);
        }
        if ('1' !== (string) get_option('blog_public', '0')) {
            return $this->error('stale_revision', 'Search visibility changed after Apply. Rollback requires a fresh operator decision.', 409);
        }
        $revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        if (is_wp_error($revision)) { return $revision; }
        if (! hash_equals((string) ($operation['post_apply_revision'] ?? ''), $revision)) {
            return $this->error('stale_revision', 'Managed WordPress state changed after Apply. Automatic indexability rollback is blocked.', 409);
        }

        $baseline = (string) ($operation['baseline'] ?? '0');
        update_option('blog_public', $baseline, false);
        $restored = $baseline === (string) get_option('blog_public', '');
        $post_rollback_revision = Eduardo_Research_Manager::remote_operations()->revision_fingerprint();
        $source_restored = ! is_wp_error($post_rollback_revision)
            && hash_equals((string) ($operation['source_revision'] ?? ''), $post_rollback_revision);
        if (! $restored) {
            return $this->error('research_manager_write_failed', 'WordPress did not restore the previous search visibility value.', 409);
        }
        $operation['status'] = 'rolled-back';
        $operation['rolled_back_at'] = gmdate(DATE_W3C);
        $operation['post_rollback_revision'] = is_wp_error($post_rollback_revision) ? '' : $post_rollback_revision;
        $operation['rollback_restored_baseline'] = true;
        $operation['rollback_restored_source_revision'] = $source_restored;
        $operation['rollback_result'] = array('restored_value'=>$baseline);
        $this->put_operation($operation);
        $this->audit_record('remote-indexability-operation-rolled-back', 'success', $actor, (string) $operation['plan_id'], $operation_id, 'Rollback search indexability');
        return $this->public_operation($operation);
    }

    public function resource(string $type, string|int $identifier, string $language = ''): array|WP_Error {
        $inspection = $this->seo_geo->inspect_resource($type, $identifier, $language);
        if (is_wp_error($inspection)) { return $inspection; }

        $status = 'page' === sanitize_key($type)
            ? (string) (($inspection['stored']['status'] ?? '') ?: 'publish')
            : (string) ($inspection['status'] ?? '');
        $public = 'publish' === $status;
        $url = $this->resource_url($type, $identifier, $language, $inspection);
        if (! $public || '' === $url) {
            return array(
                'type'=>sanitize_key($type),
                'target'=>$this->target($type, $identifier, $language),
                'public'=>$public,
                'expected_indexable'=>false,
                'rendered_checked'=>false,
                'indexable'=>false,
                'reason'=>'draft-or-non-public',
                'generated_at'=>gmdate(DATE_W3C),
            );
        }

        $response = wp_remote_get($url, array(
            'timeout'=>12,
            'redirection'=>3,
            'user-agent'=>'ResearchManager/' . (defined('EDUARDO_RESEARCH_MANAGER_VERSION') ? EDUARDO_RESEARCH_MANAGER_VERSION : 'dev'),
            'headers'=>array('Accept'=>'text/html,application/xhtml+xml'),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('research_manager_indexability_request_failed', $response->get_error_message(), array('url'=>$url));
        }
        $http_status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $meta_robots = $this->extract_meta_robots($body);
        $x_robots = strtolower(trim((string) wp_remote_retrieve_header($response, 'x-robots-tag')));
        $robots = strtolower(trim($meta_robots . ',' . $x_robots, ','));
        $noindex = false !== strpos($robots, 'noindex');
        $global_public = '1' === (string) get_option('blog_public', '1');
        $indexable = 200 === $http_status && $global_public && ! $noindex;

        return array(
            'type'=>sanitize_key($type),
            'target'=>$this->target($type, $identifier, $language),
            'url'=>$url,
            'public'=>true,
            'expected_indexable'=>true,
            'rendered_checked'=>true,
            'http_status'=>$http_status,
            'meta_robots'=>$meta_robots,
            'x_robots_tag'=>$x_robots,
            'noindex_detected'=>$noindex,
            'global_search_visibility'=>$global_public,
            'indexable'=>$indexable,
            'verified'=>$indexable,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function internal_links(int $limit = 200): array {
        $limit = max(1, min(500, $limit));
        $rows = array();
        $groups = array('insight'=>0,'output'=>0,'project'=>0,'software'=>0,'dataset'=>0);
        $connected = 0;
        $unlinked = 0;

        $specs = array(
            'insight'=>array('post_type'=>'post','meta_key'=>'_research_insight_type'),
            'output'=>array('post_type'=>'research_output'),
            'project'=>array('post_type'=>'research_project'),
            'software'=>array('post_type'=>'research_software'),
            'dataset'=>array('post_type'=>'research_dataset'),
        );
        foreach ($specs as $kind => $spec) {
            $args = array(
                'post_type'=>$spec['post_type'],
                'post_status'=>'publish',
                'posts_per_page'=>$limit,
                'orderby'=>'ID',
                'order'=>'ASC',
                'suppress_filters'=>true,
            );
            if (isset($spec['meta_key'])) {
                $args['meta_query'] = array(array('key'=>$spec['meta_key'],'compare'=>'EXISTS'));
            }
            $posts = get_posts($args);
            foreach ($posts as $post) {
                if (! $post instanceof WP_Post) { continue; }
                $post_id = (int) $post->ID;
                $line_ids = function_exists('eduardo_research_post_line_ids') ? eduardo_research_post_line_ids($post_id) : array();
                $has_graph_link = ! empty($line_ids);
                $groups[$kind]++;
                if ($has_graph_link) { $connected++; } else { $unlinked++; }
                $rows[] = array(
                    'kind'=>$kind,
                    'post_id'=>$post_id,
                    'language'=>function_exists('eduardo_research_post_language') ? (string) eduardo_research_post_language($post_id) : (string) get_post_meta($post_id, '_research_language', true),
                    'title'=>(string) $post->post_title,
                    'url'=>(string) get_permalink($post_id),
                    'verified_line_ids'=>array_values(array_map('absint', $line_ids)),
                    'research_graph_connected'=>$has_graph_link,
                    'status'=>$has_graph_link ? 'pass' : 'advisory',
                    'action'=>$has_graph_link ? array() : $this->relation_action($kind, $post_id),
                );
            }
        }

        $lines = array();
        $line_posts = get_posts(array(
            'post_type'=>'research_line',
            'post_status'=>'publish',
            'posts_per_page'=>$limit,
            'meta_key'=>'_research_evidence_status',
            'meta_value'=>'verified',
            'orderby'=>'ID',
            'order'=>'ASC',
            'suppress_filters'=>true,
        ));
        foreach ($line_posts as $line) {
            if (! $line instanceof WP_Post) { continue; }
            $count = 0;
            if (function_exists('eduardo_research_related_objects_query_for_line')) {
                $query = eduardo_research_related_objects_query_for_line((int) $line->ID, 200);
                $count = (int) $query->post_count;
                wp_reset_postdata();
            }
            $lines[] = array(
                'post_id'=>(int) $line->ID,
                'title'=>(string) $line->post_title,
                'language'=>function_exists('eduardo_research_post_language') ? (string) eduardo_research_post_language((int) $line->ID) : (string) get_post_meta((int) $line->ID, '_research_language', true),
                'related_object_count'=>$count,
                'status'=>$count > 0 ? 'pass' : 'advisory',
            );
        }

        return array(
            'summary'=>array(
                'managed_public_records'=>count($rows),
                'research_graph_connected'=>$connected,
                'research_graph_unlinked'=>$unlinked,
                'verified_public_lines'=>count($lines),
                'groups'=>$groups,
            ),
            'records'=>$rows,
            'lines'=>$lines,
            'remediation_model'=>'delegate-to-existing-manager-gateways',
            'direct_html_link_mutation'=>false,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function relation_action(string $kind, int $post_id): array {
        if ('insight' === $kind) {
            return array(
                'gateway'=>'insights',
                'operation'=>'insight-update',
                'target'=>array('post_id'=>$post_id,'field'=>'line_ids'),
                'required_input'=>array('value'),
                'requires_evidence'=>false,
                'auto_apply'=>false,
            );
        }
        return array(
            'gateway'=>'research-objects',
            'operation'=>'object-update',
            'target'=>array('kind'=>$kind,'post_id'=>$post_id,'field'=>'line_ids'),
            'required_input'=>array('value','evidence_reference'),
            'requires_evidence'=>true,
            'auto_apply'=>false,
        );
    }

    private function resource_url(string $type, string|int $identifier, string $language, array $inspection): string {
        if ('page' === sanitize_key($type)) {
            $key = sanitize_key((string) $identifier);
            $language = sanitize_key($language ?: (string) ($inspection['language'] ?? Eduardo_Research_Manager::contract()->default_language()));
            return function_exists('eduardo_research_page_url') ? (string) eduardo_research_page_url($key, $language) : '';
        }
        $post_id = absint($inspection['post_id'] ?? $identifier);
        return $post_id > 0 ? (string) get_permalink($post_id) : '';
    }

    private function target(string $type, string|int $identifier, string $language): array {
        if ('page' === sanitize_key($type)) {
            return array('key'=>sanitize_key((string) $identifier),'language'=>sanitize_key($language ?: Eduardo_Research_Manager::contract()->default_language()));
        }
        return array('post_id'=>absint($identifier));
    }

    private function extract_meta_robots(string $body): string {
        $patterns = array(
            '/<meta\b[^>]*\bname=["\']robots["\'][^>]*\bcontent=["\']([^"\']*)["\'][^>]*>/i',
            '/<meta\b[^>]*\bcontent=["\']([^"\']*)["\'][^>]*\bname=["\']robots["\'][^>]*>/i',
        );
        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $body, $match)) {
                return strtolower(trim(html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }
        }
        return '';
    }

    private function put_plan(array $plan): void {
        $plans = get_option(self::PLANS_OPTION, array());
        $plans = is_array($plans) ? $plans : array();
        $plans[(string) $plan['plan_id']] = $plan;
        if (count($plans) > self::PLAN_LIMIT) { $plans = array_slice($plans, -self::PLAN_LIMIT, null, true); }
        update_option(self::PLANS_OPTION, $plans, false);
    }

    private function find_plan(string $plan_id): array {
        $plans = get_option(self::PLANS_OPTION, array());
        return is_array($plans) && is_array($plans[$plan_id] ?? null) ? $plans[$plan_id] : array();
    }

    private function put_operation(array $operation): void {
        $operations = get_option(self::OPERATIONS_OPTION, array());
        $operations = is_array($operations) ? $operations : array();
        $operations[(string) $operation['operation_id']] = $operation;
        if (count($operations) > self::OPERATION_LIMIT) { $operations = array_slice($operations, -self::OPERATION_LIMIT, null, true); }
        update_option(self::OPERATIONS_OPTION, $operations, false);
    }

    private function find_operation(string $operation_id): array {
        $operations = get_option(self::OPERATIONS_OPTION, array());
        return is_array($operations) && is_array($operations[$operation_id] ?? null) ? $operations[$operation_id] : array();
    }

    private function public_plan(array $plan): array {
        unset($plan['expires_unix']);
        return $plan;
    }

    private function public_operation(array $operation): array {
        return $operation;
    }

    private function audit_record(string $event, string $outcome, array $actor, string $plan_id, string $operation_id, string $reason): void {
        $this->audit->record(array(
            'request_id'=>(string) ($actor['request_id'] ?? ''),
            'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),
            'event'=>$event,
            'outcome'=>$outcome,
            'scope'=>'seo.write',
            'plan_id'=>$plan_id,
            'operation_id'=>$operation_id,
            'reason'=>$reason,
        ));
    }

    private function error(string $code, string $message, int $status, array $extra = array()): WP_Error {
        return new WP_Error($code, $message, array_merge(array('status'=>$status), $extra));
    }
}