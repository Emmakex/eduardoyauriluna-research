<?php
/** Authenticated read-only Remote Manager Media surface. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Media_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Media_Resource $media;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Media_Resource $media = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->media = $media ?: new Eduardo_Research_Manager_Media_Resource();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/media', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'inventory'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->auth->permission($request, 'media.read'),
        ));
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/media/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'inspect'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->auth->permission($request, 'media.read'),
        ));
    }

    public function inventory(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->media->inventory(array(
            'page' => (int) ($request->get_param('page') ?: 1),
            'per_page' => (int) ($request->get_param('per_page') ?: 20),
            'search' => (string) ($request->get_param('search') ?: ''),
            'mime_type' => (string) ($request->get_param('mime_type') ?: ''),
        ));
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function inspect(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->media->inspect((int) $request['id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route()) {
            return $response;
        }
        if (is_wp_error($response) || ! $response instanceof WP_REST_Response) {
            return $response;
        }
        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            return $response;
        }

        $data = $payload['data'];
        $read = is_array($data['read_endpoints'] ?? null) ? $data['read_endpoints'] : array();
        $read['media'] = 'media.read';
        $read['media_item'] = 'media.read';
        $data['read_endpoints'] = $read;
        $data['media_control'] = array(
            'inventory' => true,
            'inspection' => true,
            'read_scope' => 'media.read',
            'write_available' => false,
            'supported_filters' => array('page', 'per_page', 'search', 'mime_type'),
            'mime_filters' => array('image', 'audio', 'video', 'pdf', 'application/pdf'),
            'safe_fields' => array(
                'id', 'title', 'alt_text', 'caption', 'description', 'mime_type', 'media_type',
                'source_url', 'parent_id', 'status', 'uploaded_at', 'modified_at', 'width', 'height', 'readiness',
            ),
            'filesystem_paths_exposed' => false,
            'arbitrary_upload_proxy' => false,
            'arbitrary_wordpress_proxy' => false,
        );

        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        return new WP_REST_Response(array(
            'ok' => true,
            'request_id' => (string) $request->get_header('x-research-manager-request-id'),
            'data' => $data,
        ), 200);
    }
}
