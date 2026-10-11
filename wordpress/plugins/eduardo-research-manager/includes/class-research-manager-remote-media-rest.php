<?php
/** Authenticated Remote Manager Media inventory, diagnostics and bounded remediation planning. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once __DIR__ . '/class-research-manager-media-diagnostics.php';

final class Eduardo_Research_Manager_Remote_Media_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Media_Resource $media;
    private Eduardo_Research_Manager_Media_Diagnostics $diagnostics;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Media_Resource $media = null,
        ?Eduardo_Research_Manager_Media_Diagnostics $diagnostics = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->media = $media ?: new Eduardo_Research_Manager_Media_Resource();
        $this->diagnostics = $diagnostics ?: new Eduardo_Research_Manager_Media_Diagnostics($this->media);
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        $namespace = Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($namespace, '/media', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'inventory'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->auth->permission($request, 'media.read'),
        ));
        register_rest_route($namespace, '/media/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'inspect'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->auth->permission($request, 'media.read'),
        ));
        register_rest_route($namespace, '/media/diagnostics', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'diagnostics'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->auth->permission($request, 'media.read'),
        ));
        register_rest_route($namespace, '/media/diagnostics/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'diagnostic_item'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->auth->permission($request, 'media.read'),
        ));
        register_rest_route($namespace, '/media/diagnostics/remediation/plan', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'plan_diagnostic_remediation'),
            'permission_callback' => fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'media.write'),
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

    public function diagnostics(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->diagnostics->report(array(
            'limit'=>(int) ($request->get_param('limit') ?: 500),
        ));
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function diagnostic_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->diagnostics->inspect((int) $request['id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function plan_diagnostic_remediation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        $attachment_id = absint($params['attachment_id'] ?? 0);
        $changes = is_array($params['changes'] ?? null) ? $params['changes'] : array();
        $prepared = $this->diagnostics->prepare_metadata_remediation($attachment_id, $changes);
        if (is_wp_error($prepared)) { return $prepared; }

        return $this->idem($request, function () use ($prepared, $request) {
            $operations = new Eduardo_Research_Manager_Remote_Media_Metadata_Operations();
            return $operations->create_plan(
                'media-metadata-update',
                array(
                    'attachment_id'=>(int) $prepared['attachment_id'],
                    'changes'=>(array) $prepared['changes'],
                ),
                $this->actor($request)
            );
        });
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
        $read['media_diagnostics'] = 'media.read';
        $read['media_diagnostic_item'] = 'media.read';
        $data['read_endpoints'] = $read;
        $data['media_control'] = array(
            'inventory' => true,
            'inspection' => true,
            'diagnostics' => array(
                'available'=>true,
                'base'=>'/research-manager/v1/media/diagnostics',
                'read_scope'=>'media.read',
                'checks'=>array('missing-alt','weak-alt','unused-attachment','duplicate-candidate','large-image-dimensions'),
                'aggregate_readiness'=>true,
                'duplicate_detection'=>'bounded-metadata-fingerprint-only',
                'content_hashing'=>false,
                'destructive_delete'=>false,
                'binary_replacement'=>false,
                'max_scan'=>500,
            ),
            'diagnostic_remediation'=>array(
                'plan'=>'/research-manager/v1/media/diagnostics/remediation/plan',
                'delegates_to'=>'/research-manager/v1/media/metadata',
                'required_plan_scopes'=>array('operations.apply','media.write'),
                'supported_fields'=>array('alt_text'),
                'operator_supplied_alt_required'=>true,
                'automatic_alt_generation'=>false,
                'auto_apply'=>false,
            ),
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

    private function permission(WP_REST_Request $request, string $primary, string $secondary): bool|WP_Error {
        $ok = $this->auth->permission($request, $primary);
        if (is_wp_error($ok) || true !== $ok) { return $ok; }
        $credentials = Eduardo_Research_Manager::remote_credentials();
        $state = $credentials->state();
        if ($credentials->has_scope($state, $secondary)) { return true; }
        return new WP_Error(
            'scope_denied',
            sprintf('Remote Manager connection does not grant %s.', $secondary),
            array('status'=>403)
        );
    }

    private function idem(WP_REST_Request $request, callable $callback): WP_REST_Response|WP_Error {
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        $connection_id = (string) ($connection['connection_id'] ?? '');
        $request_id = trim((string) $request->get_header('x-research-manager-request-id'));
        $guard = Eduardo_Research_Manager::remote_guard();
        $fingerprint = $guard->request_fingerprint($request);
        $previous = $guard->validate_idempotency($connection_id, $request_id, $fingerprint);
        if (is_wp_error($previous)) { return $previous; }
        if (is_array($previous)) {
            return new WP_REST_Response(array(
                'ok'=>true,
                'request_id'=>$request_id,
                'idempotent_replay'=>true,
                'data'=>(array) ($previous['result'] ?? array()),
            ), 200);
        }
        $result = $callback();
        if (is_wp_error($result)) { return $result; }
        $result = is_array($result) ? $result : array('result'=>$result);
        $guard->remember_request($connection_id, $request_id, $fingerprint, $result);
        return new WP_REST_Response(array(
            'ok'=>true,
            'request_id'=>$request_id,
            'idempotent_replay'=>false,
            'data'=>$result,
        ), 200);
    }

    private function actor(WP_REST_Request $request): array {
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        return array(
            'connection_id'=>(string) ($connection['connection_id'] ?? ''),
            'wordpress_user_id'=>get_current_user_id(),
            'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
        );
    }

    private function json(WP_REST_Request $request): array {
        $params = $request->get_json_params();
        if (! is_array($params)) { $params = $request->get_body_params(); }
        return is_array($params) ? $params : array();
    }

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        return new WP_REST_Response(array(
            'ok' => true,
            'request_id' => (string) $request->get_header('x-research-manager-request-id'),
            'data' => $data,
        ), 200);
    }
}
