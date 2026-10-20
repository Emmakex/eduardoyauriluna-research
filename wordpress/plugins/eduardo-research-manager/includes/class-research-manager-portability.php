<?php
/** Portable generic-WordPress surface layered over the accepted Research Manager core. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Portability {
    private const NAMESPACE = 'research-manager/v1';
    private const ADMIN_SLUG = 'site-manager';
    private const TOKEN_TRANSIENT = 'erm_site_manager_token_';
    private const NOTICE_TRANSIENT = 'erm_site_manager_notice_';

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'), 20);
        add_filter('rest_endpoints', array($this, 'filter_generic_endpoints'), 999);
        add_filter('rest_request_after_callbacks', array($this, 'normalise_generic_response'), 1000, 3);
        add_action('admin_menu', array($this, 'admin_menu'), 999);
        add_action('admin_post_site_manager_remote', array($this, 'handle_admin_action'));
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
            $granted = is_array($connection['scopes'] ?? null) ? array_values($connection['scopes']) : array();
            $data = array(
                'namespace' => self::NAMESPACE,
                'connection_id' => (string) ($connection['connection_id'] ?? ''),
                'product_profile' => $profile,
                'portable' => true,
                'granted_scopes' => $granted,
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
            if (in_array('site.diagnostics', $granted, true)) {
                $data['site_baseline'] = (new Eduardo_Research_Manager_Site_Baseline())->run();
            }
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

    public function admin_menu(): void {
        if ($this->research_available()) { return; }

        global $submenu;
        if (isset($submenu['tools.php']) && is_array($submenu['tools.php'])) {
            foreach ($submenu['tools.php'] as $index => $item) {
                $slug = is_array($item) ? (string) ($item[2] ?? '') : '';
                if (0 === strpos($slug, 'eduardo-research-manager')) {
                    unset($submenu['tools.php'][$index]);
                }
            }
        }

        add_management_page(
            'Site Manager',
            'Site Manager',
            'manage_options',
            self::ADMIN_SLUG,
            array($this, 'render_admin')
        );
    }

    public function handle_admin_action(): void {
        if ($this->research_available() || ! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage Site Manager.', 'eduardo-research-manager'));
        }
        check_admin_referer('erm_site_manager_remote');

        $action = sanitize_key((string) ($_POST['manager_action'] ?? ''));
        $credentials = Eduardo_Research_Manager::remote_credentials();
        $notice = array('type'=>'success','message'=>'');

        if ('connect' === $action || 'rotate' === $action) {
            $issued = $credentials->issue_token(
                array('site.read','site.diagnostics'),
                get_current_user_id(),
                'Site Manager Bridge'
            );
            if (is_wp_error($issued)) {
                $notice = array('type'=>'error','message'=>$issued->get_error_message());
            } else {
                $token = (string) ($issued['token'] ?? '');
                if ('' !== $token) {
                    set_transient($this->token_key(), $token, 5 * MINUTE_IN_SECONDS);
                }
                $notice['message'] = 'A least-privilege Site Manager credential was generated. Copy the token now; only its hash is stored.';
            }
        } elseif ('revoke' === $action) {
            $credentials->revoke();
            $notice['message'] = 'The Site Manager production credential was revoked.';
        } else {
            $notice = array('type'=>'error','message'=>'Unsupported Site Manager action.');
        }

        set_transient($this->notice_key(), $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('tools.php?page=' . self::ADMIN_SLUG));
        exit;
    }

    public function render_admin(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Site Manager.', 'eduardo-research-manager'));
        }

        $profile = (new Eduardo_Research_Manager_Site_Profile())->snapshot();
        $baseline = (new Eduardo_Research_Manager_Site_Baseline())->run();
        $connection = Eduardo_Research_Manager::remote_credentials()->primary_state();
        $token = get_transient($this->token_key());
        if (is_string($token) && '' !== $token) {
            delete_transient($this->token_key());
        } else {
            $token = '';
        }
        $notice = get_transient($this->notice_key());
        if (is_array($notice)) {
            delete_transient($this->notice_key());
        } else {
            $notice = array();
        }
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Site Manager', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Portable read-only control plane for this WordPress site. Research-only surfaces are disabled because the Research Theme contract is not active.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) ($notice['type'] ?? 'info')); ?> inline"><p><?php echo esc_html((string) ($notice['message'] ?? '')); ?></p></div>
          <?php endif; ?>

          <?php if ('' !== $token) : ?>
            <div class="notice notice-warning inline">
              <p><strong><?php echo esc_html__('Copy this token now. It is shown once.', 'eduardo-research-manager'); ?></strong></p>
              <p><code style="word-break:break-all;user-select:all"><?php echo esc_html($token); ?></code></p>
            </div>
          <?php endif; ?>

          <h2><?php echo esc_html__('Detected profile', 'eduardo-research-manager'); ?></h2>
          <table class="widefat striped" style="max-width:900px"><tbody>
            <tr><th>Profile</th><td><code><?php echo esc_html((string) ($profile['id'] ?? '')); ?></code></td></tr>
            <tr><th>WordPress</th><td><?php echo esc_html((string) ($profile['wordpress_version'] ?? '')); ?></td></tr>
            <tr><th>PHP</th><td><?php echo esc_html((string) ($profile['php_version'] ?? '')); ?></td></tr>
            <tr><th>Active theme</th><td><?php echo esc_html((string) ($profile['active_theme']['name'] ?? '')); ?> — <?php echo esc_html((string) ($profile['active_theme']['version'] ?? '')); ?></td></tr>
          </tbody></table>

          <h2><?php echo esc_html__('ChatGPT / MCP connection', 'eduardo-research-manager'); ?></h2>
          <p><?php echo esc_html__('Generic sites use exactly site.read + site.diagnostics. Write scopes are not exposed in this profile.', 'eduardo-research-manager'); ?></p>
          <table class="widefat striped" style="max-width:900px"><tbody>
            <tr><th>Connection ID</th><td><code><?php echo esc_html((string) ($connection['connection_id'] ?? '')); ?></code></td></tr>
            <tr><th>Enabled</th><td><?php echo ! empty($connection['enabled']) ? 'YES' : 'NO'; ?></td></tr>
            <tr><th>Generation</th><td><?php echo esc_html((string) ((int) ($connection['generation'] ?? 0))); ?></td></tr>
            <tr><th>Scopes</th><td><code><?php echo esc_html(implode(', ', (array) ($connection['scopes'] ?? array()))); ?></code></td></tr>
            <tr><th>Last used</th><td><?php echo esc_html((string) ($connection['last_used_at'] ?? '')); ?></td></tr>
          </tbody></table>

          <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="site_manager_remote">
              <input type="hidden" name="manager_action" value="<?php echo ! empty($connection['credential_present']) ? 'rotate' : 'connect'; ?>">
              <?php wp_nonce_field('erm_site_manager_remote'); ?>
              <?php submit_button(! empty($connection['credential_present']) ? __('Rotate connection token', 'eduardo-research-manager') : __('Generate connection token', 'eduardo-research-manager'), 'primary', 'submit', false); ?>
            </form>
            <?php if (! empty($connection['credential_present'])) : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="site_manager_remote">
                <input type="hidden" name="manager_action" value="revoke">
                <?php wp_nonce_field('erm_site_manager_remote'); ?>
                <?php submit_button(__('Revoke connection', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
              </form>
            <?php endif; ?>
          </div>

          <h2><?php echo esc_html__('Site Baseline Audit', 'eduardo-research-manager'); ?></h2>
          <p><?php echo esc_html__('The baseline below is read-only WordPress evidence. External DNS/TLS, Lighthouse/CWV, accessibility, Search Console and analytics stay explicitly not_measured until their probes are connected.', 'eduardo-research-manager'); ?></p>
          <table class="widefat striped" style="max-width:900px"><tbody>
            <tr><th>Home URL</th><td><code><?php echo esc_html((string) ($baseline['urls']['home'] ?? '')); ?></code></td></tr>
            <tr><th>Search visibility</th><td><?php echo esc_html((string) ($baseline['indexability']['wordpress_search_visibility'] ?? '')); ?></td></tr>
            <tr><th>Permalink structure</th><td><code><?php echo esc_html((string) ($baseline['routing']['permalink_structure'] ?? '')); ?></code></td></tr>
            <tr><th>Installed plugins</th><td><?php echo esc_html((string) ((int) ($baseline['plugins']['installed_count'] ?? 0))); ?></td></tr>
            <tr><th>Native sitemap</th><td><?php echo ! empty($baseline['discovery']['native_sitemaps_available']) ? esc_html((string) ($baseline['discovery']['native_sitemap_url'] ?? '')) : 'Unavailable'; ?></td></tr>
            <tr><th>Generated</th><td><?php echo esc_html((string) ($baseline['generated_at'] ?? '')); ?></td></tr>
          </tbody></table>
        </div>
        <?php
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

    private function token_key(): string {
        return self::TOKEN_TRANSIENT . get_current_user_id();
    }

    private function notice_key(): string {
        return self::NOTICE_TRANSIENT . get_current_user_id();
    }
}
