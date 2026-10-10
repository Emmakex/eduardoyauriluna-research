<?php
/** Authenticated read surface for bounded Theme-owned Page control. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Pages_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;

    public function __construct(?Eduardo_Research_Manager_Remote_REST $auth = null) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/pages', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inventory'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'site.read'),
        ));
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/pages/(?P<key>[a-z0-9-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inspect'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'site.read'),
        ));
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/pages/(?P<key>[a-z0-9-]+)/seo-geo', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'seo_geo'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'site.diagnostics'),
        ));
    }

    public function inventory(WP_REST_Request $request): WP_REST_Response {
        return $this->response($request, Eduardo_Research_Manager::page_editor()->inventory());
    }

    public function inspect(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $key = sanitize_key((string) $request['key']);
        $language = sanitize_key((string) ($request->get_param('language') ?: 'en'));
        $result = Eduardo_Research_Manager::page_editor()->inspect($key, $language);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function seo_geo(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $key = sanitize_key((string) $request['key']);
        if (! isset(Eduardo_Research_Manager::contract()->pages()[$key])) {
            return new WP_Error('validation_failed', 'The requested Page is outside the active Research preset.', array('status'=>400));
        }

        $requested_language = sanitize_key((string) ($request->get_param('language') ?: ''));
        $languages = '' !== $requested_language
            ? array($requested_language)
            : Eduardo_Research_Manager::contract()->languages();
        foreach ($languages as $language) {
            if (! in_array($language, Eduardo_Research_Manager::contract()->languages(), true)) {
                return new WP_Error('validation_failed', 'The requested Page language is outside the active Research preset.', array('status'=>400));
            }
        }

        $stored = array();
        $rendered = array();
        foreach ($languages as $language) {
            $inspection = Eduardo_Research_Manager::page_editor()->inspect($key, (string) $language);
            $stored[(string) $language] = is_wp_error($inspection)
                ? array('available'=>false,'error_code'=>$inspection->get_error_code(),'error'=>$inspection->get_error_message())
                : $inspection;
            $verification = Eduardo_Research_Manager::rendered()->verify_page($key, (string) $language);
            if (is_wp_error($verification)) {
                $rendered[(string) $language] = array(
                    'verified'=>false,
                    'error_code'=>$verification->get_error_code(),
                    'error'=>$verification->get_error_message(),
                );
            } else {
                unset($verification['body']);
                $rendered[(string) $language] = $verification;
            }
        }

        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        $findings = array_values(array_filter((array) ($diagnostics['checks'] ?? array()), static function ($check) use ($key): bool {
            if (! is_array($check)) { return false; }
            $resource = (string) ($check['resource'] ?? '');
            $id = (string) ($check['id'] ?? '');
            return 'page:' . $key === $resource || str_contains($id, 'page-' . $key) || str_contains($id, $key . '-page');
        }));

        return $this->response($request, array(
            'key'=>$key,
            'languages'=>$languages,
            'stored'=>$stored,
            'rendered'=>$rendered,
            'diagnostics'=>$findings,
            'ready'=>! in_array(false, array_map(static fn(array $row): bool => ! empty($row['verified']), $rendered), true),
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
        $read['pages'] = 'site.read';
        $read['page'] = 'site.read';
        $read['page_seo_geo'] = 'site.diagnostics';
        $data['read_endpoints'] = $read;
        $transport = is_array($data['mutation_transport'] ?? null) ? $data['mutation_transport'] : array();
        $transport['available'] = true;
        $transport['milestone'] = 'M3';
        $transport['supported_operations'] = array('greenfield-canonical','page-slots-update','page-create');
        $transport['lifecycle'] = array('plan','apply','status','verify','rollback');
        $transport['exact_plan_required'] = true;
        $transport['stale_revision_protection'] = true;
        $transport['idempotency'] = true;
        $data['mutation_transport'] = $transport;
        $data['page_control'] = array(
            'inventory'=>true,
            'inspection'=>true,
            'languages'=>Eduardo_Research_Manager::contract()->languages(),
            'structured_slots_only'=>true,
            'create_missing_contract_page'=>true,
            'seo_geo_rendered_inspection'=>true,
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
