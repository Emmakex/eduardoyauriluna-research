<?php
/** Authenticated read surface for Theme-owned Research Insights. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Insights_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;

    public function __construct(?Eduardo_Research_Manager_Remote_REST $auth = null) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/insights', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inventory'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'site.read'),
        ));
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/insights/(?P<post_id>\d+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inspect'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'site.read'),
        ));
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/insights/(?P<post_id>\d+)/seo-geo', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'seo_geo'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'site.diagnostics'),
        ));
    }

    public function inventory(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $language = sanitize_key((string) ($request->get_param('language') ?: ''));
        $result = Eduardo_Research_Manager::insight_editor()->list($language);
        return is_wp_error($result) ? $result : $this->response($request, array(
            'language'=>$language,
            'count'=>count($result),
            'items'=>$result,
        ));
    }

    public function inspect(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $post_id = absint($request['post_id']);
        $result = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function seo_geo(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $post_id = absint($request['post_id']);
        $record = Eduardo_Research_Manager::insight_editor()->inspect($post_id);
        if (is_wp_error($record)) { return $record; }

        $translation = Eduardo_Research_Manager::translation_editor()->inspect($post_id);
        $translation_state = is_wp_error($translation)
            ? array('available'=>false,'error_code'=>$translation->get_error_code(),'error'=>$translation->get_error_message())
            : $translation;

        $rendered = array(
            'available'=>false,
            'verified'=>false,
            'reason'=>'draft-or-non-public',
        );
        if ('publish' === (string) ($record['status'] ?? '')) {
            $verification = Eduardo_Research_Manager::rendered()->verify_record($post_id);
            if (is_wp_error($verification)) {
                $rendered = array(
                    'available'=>true,
                    'verified'=>false,
                    'error_code'=>$verification->get_error_code(),
                    'error'=>$verification->get_error_message(),
                );
            } else {
                $rendered = $verification;
                $rendered['available'] = true;
            }
        }

        $excerpt = trim((string) ($record['excerpt'] ?? ''));
        $content = trim(wp_strip_all_tags((string) ($record['content'] ?? '')));
        $title = trim((string) ($record['title'] ?? ''));
        $line_ids = array_values(array_map('absint', (array) ($record['line_ids'] ?? array())));
        $stored_checks = array(
            'title'=>'' !== $title,
            'excerpt'=>'' !== $excerpt,
            'content'=>'' !== $content,
            'language'=>in_array((string) ($record['language'] ?? ''), Eduardo_Research_Manager::contract()->languages(), true),
            'insight_type'=>array_key_exists((string) ($record['insight_type'] ?? ''), Eduardo_Research_Manager::insights()->types()),
            'relations'=>count($line_ids) === count(array_filter($line_ids, static fn(int $line_id): bool => function_exists('eduardo_research_line_is_verified_public') && eduardo_research_line_is_verified_public($line_id, (string) ($record['language'] ?? 'en')))),
            'url'=>'' !== (string) ($record['url'] ?? ''),
        );

        return $this->response($request, array(
            'post_id'=>$post_id,
            'stored'=>$record,
            'stored_checks'=>$stored_checks,
            'stored_ready'=>! in_array(false, $stored_checks, true),
            'relations'=>array(
                'line_ids'=>$line_ids,
                'count'=>count($line_ids),
                'verified_same_language'=>! empty($stored_checks['relations']),
                'theme_internal_links'=>true,
            ),
            'translation'=>$translation_state,
            'rendered'=>$rendered,
            'advanced_optimisation_milestone'=>'M6',
            'verified_at'=>gmdate(DATE_W3C),
        ));
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route()) {
            return $response;
        }
        if (is_wp_error($response) || ! $response instanceof WP_REST_Response) { return $response; }
        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }

        $data = $payload['data'];
        $read = is_array($data['read_endpoints'] ?? null) ? $data['read_endpoints'] : array();
        $read['insights'] = 'site.read';
        $read['insight'] = 'site.read';
        $read['insight_seo_geo'] = 'site.diagnostics';
        $data['read_endpoints'] = $read;

        $transport = is_array($data['mutation_transport'] ?? null) ? $data['mutation_transport'] : array();
        $supported = is_array($transport['supported_operations'] ?? null) ? $transport['supported_operations'] : array();
        foreach (array('insight-create','insight-update') as $operation) {
            if (! in_array($operation, $supported, true)) { $supported[] = $operation; }
        }
        $transport['available'] = true;
        $transport['milestone'] = 'M4';
        $transport['supported_operations'] = $supported;
        $transport['lifecycle'] = array('plan','apply','status','verify','rollback');
        $transport['exact_plan_required'] = true;
        $transport['stale_revision_protection'] = true;
        $transport['idempotency'] = true;
        $data['mutation_transport'] = $transport;

        $data['insight_control'] = array(
            'milestone'=>'M4',
            'inventory'=>true,
            'inspection'=>true,
            'languages'=>Eduardo_Research_Manager::contract()->languages(),
            'editorial_types'=>array_keys(Eduardo_Research_Manager::insights()->types()),
            'creation_statuses'=>array('draft','publish'),
            'update_fields'=>array('title','excerpt','content','language','insight_type','status','line_ids'),
            'research_relations'=>array(
                'available'=>true,
                'field'=>'line_ids',
                'target'=>'verified Research Lines in the same language',
                'storage'=>'_research_insight_line_relations',
                'operation'=>'insight-update',
                'theme_renders_line_links'=>true,
                'theme_derives_related_object_links'=>true,
                'arbitrary_urls'=>false,
            ),
            'status_transition'=>array(
                'available'=>true,
                'allowed'=>array('draft','publish'),
                'separate_preview_required'=>true,
                'generic_post_status_contract_unchanged'=>true,
            ),
            'translation_pairing'=>array(
                'available'=>true,
                'operations'=>array('insight-pair','insight-unpair'),
                'languages'=>array('en','es'),
                'published_only'=>true,
                'managed_insights_only'=>true,
                'collision_protection'=>true,
                'lifecycle'=>array('plan','apply','status','verify','rollback'),
                'transport_base'=>'/research-manager/v1/insights/pairing',
                'exact_plan_required'=>true,
                'stale_preview_protection'=>true,
                'idempotency'=>true,
            ),
            'rendered_seo_geo_inspection'=>true,
            'translation_inspection'=>true,
            'remote_mutations'=>array('insight-create','insight-update'),
            'advanced_seo_geo_optimisation'=>'M6',
            'arbitrary_wordpress_proxy'=>false,
        );
        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        return new WP_REST_Response(array(
            'ok'=>true,
            'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
            'data'=>$data,
        ), 200);
    }
}
