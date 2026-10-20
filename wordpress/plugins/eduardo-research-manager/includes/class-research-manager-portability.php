<?php
/** Portable generic-WordPress surface layered over the accepted Research Manager core. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Portability {
    private const NAMESPACE = 'research-manager/v1';

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'), 20);
        add_filter('rest_endpoints', array($this, 'filter_generic_endpoints'), 999);
        add_filter('rest_request_after_callbacks', array($this, 'normalise_generic_response'), 1000, 3);
    }

    public function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/audit/baseline', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'baseline'),
            'permission_callback' => fn(WP_REST_Request $request) => Eduardo_Research_Manager::remote_rest()->permission($request, 'site.diagnostics'),
        ));
    }

    public function baseline(WP_REST_Request $request): WP_REST_Response {
        return $this->response($request, (new Eduardo_Research_Manager_Site_Baseline())->run());
    }

    public function filter_generic_endpoints(array $endpoints): array {
        if ($this->research_available()) { return $endpoints; }

        $allowed = array(
            '/' . self::NAMESPACE . '/status',
            '/' . self::NAMESPACE . '/versions',
            '/' . self::NAMESPACE . '/capabilities',
            '/' . self::NAMESPACE . '/readiness',
            '/' . self::NAMESPACE . '/diagnostics',
            '/' . self::NAMESPACE . '/audit/baseline',
        );
        $allowed_map = array_fill_keys($allowed, true);
        $prefix = '/' . self::NAMESPACE . '/';

        foreach (array_keys($endpoints) as $route) {
            if (0 !== strpos((string) $route, $prefix)) { continue; }
            if (isset($allowed_map[$route])) { continue; }
            unset($endpoints[$route]);
        }

        return $endpoints;
    }

    public function normalise_generic_response(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ($this->research_available() || is_wp_error($response) || ! $response instanceof WP_REST_Response) {
            return $response;
        }

        $route = $request->get_route();
        $prefix = '/' . self::NAMESPACE;
        if (0 !== strpos($route, $prefix)) { return $response; }

        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }
        $data = $payload['data'];
        $profile = (new Eduardo_Research_Manager_Site_Profile())->snapshot();
        $diagnostics = $this->generic_diagnostics();

        if ($prefix . '/status' === $route) {
            $data['product_profile'] = $profile;
            $data['theme_version'] = (string) ($profile['active_theme']['version'] ?? '');
            $data['preset'] = array('id'=>'','version'=>0,'compatible'=>false);
            $data['languages'] = array((string) get_bloginfo('language'));
            $data['canonical_domain'] = home_url('/');
            $data['readiness'] = array(
                'ready' => (bool) $diagnostics['ready'],
                'summary' => $diagnostics['summary'],
                'generated_at' => $diagnostics['generated_at'],
            );
        } elseif ($prefix . '/versions' === $route) {
            $data['product_profile'] = $profile['id'];
            $data['theme'] = (string) ($profile['active_theme']['version'] ?? '');
            $data['theme_stylesheet'] = (string) ($profile['active_theme']['stylesheet'] ?? '');
            $data['preset'] = array('id'=>'','version'=>0,'minimum_supported_version'=>0,'compatible'=>false);
        } elseif ($prefix . '/capabilities' === $route) {
            $connection = Eduardo_Research_Manager::remote_credentials()->state();
            $data = array(
                'namespace' => self::NAMESPACE,
                'connection_id' => (string) ($connection['connection_id'] ?? ''),
                'product_profile' => $profile,
                'portable' => true,
                'granted_scopes' => is_array($connection['scopes'] ?? null) ? array_values($connection['scopes']) : array(),
                'effective_supported_scopes' => array('site.read','site.diagnostics'),
                'writes_enabled' => false,
                'read_endpoints' => array(
                    'status'=>'site.read',
                    'versions'=>'site.read',
                    'capabilities'=>'site.read',
                    'readiness'=>'site.diagnostics',
                    'diagnostics'=>'site.diagnostics',
                    'audit_baseline'=>'site.diagnostics',
                ),
                'available_surfaces' => array('status','versions','capabilities','diagnostics','site-baseline-audit','remote-audit'),
                'unavailable_surfaces' => array('research-pages','navigation','insights','research-lines','research-objects','translations','evidence','research-seo-geo','research-design','research-mutations'),
                'mutation_transport' => array(
                    'available'=>false,
                    'reason'=>'Generic WordPress profile is read-only in Manager 0.11 portability acceptance.',
                ),
            );
        } elseif ($prefix . '/readiness' === $route) {
            $data = array(
                'ready'=>$diagnostics['ready'],
                'summary'=>$diagnostics['summary'],
                'next_actions'=>$diagnostics['next_actions'],
                'generated_at'=>$diagnostics['generated_at'],
                'product_profile'=>$profile['id'],
            );
        } elseif ($prefix . '/diagnostics' === $route) {
            $data = $diagnostics;
        }

        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function generic_diagnostics(): array {
        $checks = array();
        $home = home_url('/');
        $site = site_url('/');
        $https = 0 === strpos($home, 'https://') && 0 === strpos($site, 'https://');
        $permalinks = '' !== trim((string) get_option('permalink_structure', ''));
        $public = '1' === (string) get_option('blog_public', '1');
        $remote = Eduardo_Research_Manager::remote_credentials()->state();

        $checks[] = $this->check('product-profile','Portable Manager profile','pass','Generic WordPress profile is active; Research-only surfaces are disabled.');
        $checks[] = $this->check('https-urls','HTTPS site URLs',$https ? 'pass' : 'warning',$https ? 'WordPress home/site URLs use HTTPS.' : 'WordPress home or site URL is not HTTPS.','external-review','site:urls');
        $checks[] = $this->check('permalinks','Pretty permalinks',$permalinks ? 'pass' : 'warning',$permalinks ? 'A custom permalink structure is configured.' : 'WordPress is using plain permalinks.','configuration-review','site:permalinks');
        $checks[] = $this->check('search-indexability','Search visibility',$public ? 'pass' : 'warning',$public ? 'WordPress permits search-engine indexing.' : 'WordPress is configured to discourage search engines.','configuration-review','site:indexability');
        $checks[] = $this->check('remote-identity','Remote Manager identity',! empty($remote['enabled']) ? 'pass' : 'warning',! empty($remote['enabled']) ? 'A scoped Remote Manager credential is enabled.' : 'Remote Manager has no enabled production credential yet.','connect-manager','remote');

        $summary = array('pass'=>0,'warning'=>0,'fail'=>0);
        $next_actions = array();
        foreach ($checks as $check) {
            $summary[$check['status']]++;
            if ('' !== $check['next_action']) {
                $next_actions[] = array('check_id'=>$check['id'],'action'=>$check['next_action'],'resource'=>$check['resource'],'label'=>$check['label']);
            }
        }
        return array(
            'ready'=>0 === $summary['fail'],
            'summary'=>$summary,
            'checks'=>$checks,
            'next_actions'=>$next_actions,
            'product_profile'=>Eduardo_Research_Manager_Site_Profile::GENERIC,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function check(string $id, string $label, string $status, string $message, string $next_action = '', string $resource = ''): array {
        return compact('id','label','status','message','next_action','resource');
    }

    private function research_available(): bool {
        return (new Eduardo_Research_Manager_Site_Profile())->research_available();
    }

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        $request_id = sanitize_text_field((string) $request->get_header('X-Research-Manager-Request-Id'));
        return new WP_REST_Response(array(
            'ok'=>true,
            'data'=>$data,
            'request_id'=>$request_id,
        ));
    }
}
