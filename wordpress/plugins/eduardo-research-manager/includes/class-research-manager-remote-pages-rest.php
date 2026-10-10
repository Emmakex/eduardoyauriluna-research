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

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        return new WP_REST_Response(array(
            'ok'=>true,
            'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
            'data'=>$data,
        ), 200);
    }
}
