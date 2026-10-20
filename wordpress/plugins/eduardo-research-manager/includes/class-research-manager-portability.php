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
                $notice['message'] = 'Secure read-only access was generated. Copy the access key now; WordPress stores only its protected hash.';
            }
        } elseif ('revoke' === $action) {
            $credentials->revoke();
            $notice['message'] = 'The Site Manager connection was disconnected.';
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
        $diagnostics = $this->generic_diagnostics();
        $summary = is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : array('pass'=>0,'warning'=>0,'fail'=>0);
        $checks = is_array($diagnostics['checks'] ?? null) ? $diagnostics['checks'] : array();
        $connection = Eduardo_Research_Manager::remote_credentials()->primary_state();
        $connected = ! empty($connection['enabled']) && ! empty($connection['credential_present']);
        $health_status = ((int) ($summary['fail'] ?? 0)) > 0 ? 'critical' : (((int) ($summary['warning'] ?? 0)) > 0 ? 'attention' : 'healthy');
        $health_label = 'critical' === $health_status ? __('Needs action', 'eduardo-research-manager') : ('attention' === $health_status ? __('Review recommended', 'eduardo-research-manager') : __('Healthy', 'eduardo-research-manager'));
        $last_used = trim((string) ($connection['last_used_at'] ?? ''));
        $last_used_label = '' !== $last_used ? $last_used : __('Not used yet', 'eduardo-research-manager');

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
        <div class="wrap sm-shell">
          <style>
            .sm-shell{max-width:1180px;margin-top:24px}.sm-shell *{box-sizing:border-box}.sm-shell h1{font-size:28px;margin:0}.sm-shell h2{font-size:18px;margin:0 0 6px}.sm-shell p{font-size:14px}.sm-hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;padding:24px;border:1px solid #dcdcde;background:#fff;border-radius:10px}.sm-hero__copy{max-width:720px}.sm-eyebrow{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#646970;margin-bottom:8px}.sm-lead{font-size:15px!important;line-height:1.6;color:#50575e;margin-bottom:0}.sm-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border:1px solid #c3c4c7;border-radius:999px;background:#f6f7f7;font-size:12px;font-weight:600;white-space:nowrap}.sm-badge--good{border-color:#68de7c;background:#edfaef}.sm-badge--warn{border-color:#dba617;background:#fcf9e8}.sm-badge--bad{border-color:#d63638;background:#fcf0f1}.sm-dot{width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block}.sm-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}.sm-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;min-width:0}.sm-card__label{display:block;font-size:12px;font-weight:600;color:#646970;margin-bottom:8px}.sm-card__value{display:block;font-size:18px;font-weight:700;line-height:1.25;color:#1d2327;overflow-wrap:anywhere}.sm-card__meta{display:block;font-size:12px;color:#646970;margin-top:6px;line-height:1.45}.sm-section{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px;margin-top:16px}.sm-section__head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.sm-section__head p{margin:4px 0 0;color:#646970}.sm-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:18px 0}.sm-step{border:1px solid #dcdcde;border-radius:8px;padding:14px;display:flex;gap:10px;align-items:flex-start}.sm-step__num{width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#f0f0f1;font-weight:700;flex:0 0 auto}.sm-step--done .sm-step__num{background:#edfaef}.sm-step--active{border-color:#72aee6;background:#f0f6fc}.sm-step strong{display:block;margin:2px 0 4px}.sm-step p{margin:0;color:#646970;font-size:12px;line-height:1.45}.sm-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.sm-actions form{margin:0}.sm-danger{color:#b32d2e!important;border-color:#d63638!important}.sm-token{border:1px solid #dba617;background:#fcf9e8;border-radius:8px;padding:18px;margin:16px 0}.sm-token h3{margin:0 0 6px}.sm-token__row{display:flex;gap:8px;align-items:stretch;margin-top:12px}.sm-token code{display:block;flex:1;padding:10px 12px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;word-break:break-all;user-select:all;line-height:1.5}.sm-live{min-height:20px;font-size:12px;font-weight:600;margin-top:8px}.sm-checks{display:grid;gap:8px}.sm-check{display:grid;grid-template-columns:140px minmax(0,1fr);gap:14px;padding:13px 0;border-top:1px solid #f0f0f1}.sm-check:first-child{border-top:0;padding-top:0}.sm-check__status{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}.sm-check__status--pass{color:#008a20}.sm-check__status--warning{color:#996800}.sm-check__status--fail{color:#b32d2e}.sm-check strong{display:block;margin-bottom:3px}.sm-check p{margin:0;color:#646970}.sm-baseline{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.sm-mini{border:1px solid #dcdcde;border-radius:7px;padding:13px;min-width:0}.sm-mini span{display:block;color:#646970;font-size:12px;margin-bottom:5px}.sm-mini strong,.sm-mini code{display:block;overflow-wrap:anywhere}.sm-callout{padding:14px 16px;border-left:4px solid #72aee6;background:#f0f6fc;margin-top:16px}.sm-callout p{margin:4px 0 0}.sm-details{margin-top:14px;border-top:1px solid #f0f0f1;padding-top:14px}.sm-details summary{cursor:pointer;font-weight:600}.sm-details table{margin-top:12px}.sm-details th{width:190px}.sm-shell .button:focus-visible,.sm-details summary:focus-visible{outline:2px solid #2271b1;outline-offset:2px}.sm-muted{color:#646970}.sm-kicker{font-size:12px;color:#646970}.sm-next{font-weight:600;margin-top:12px}.sm-next span{font-weight:400;color:#646970}@media(max-width:960px){.sm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sm-steps,.sm-baseline{grid-template-columns:1fr}.sm-hero{flex-direction:column}.sm-section__head{flex-direction:column}.sm-check{grid-template-columns:110px minmax(0,1fr)}}@media(max-width:600px){.sm-grid{grid-template-columns:1fr}.sm-token__row{flex-direction:column}.sm-shell{margin-right:10px}.sm-hero,.sm-section{padding:16px}}
          </style>

          <section class="sm-hero" aria-labelledby="site-manager-title">
            <div class="sm-hero__copy">
              <div class="sm-eyebrow"><?php echo esc_html__('WordPress control', 'eduardo-research-manager'); ?></div>
              <h1 id="site-manager-title"><?php echo esc_html__('Site Manager', 'eduardo-research-manager'); ?></h1>
              <p class="sm-lead"><?php echo esc_html__('See the health of this site and connect it safely to your ChatGPT project. This profile is intentionally read-only: it can inspect and diagnose, but it cannot change content or settings.', 'eduardo-research-manager'); ?></p>
            </div>
            <span class="sm-badge sm-badge--good"><span class="sm-dot" aria-hidden="true"></span><?php echo esc_html__('Read-only protection active', 'eduardo-research-manager'); ?></span>
          </section>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) ($notice['type'] ?? 'info')); ?> inline" style="margin:16px 0 0"><p><?php echo esc_html((string) ($notice['message'] ?? '')); ?></p></div>
          <?php endif; ?>

          <?php if ('' !== $token) : ?>
            <section class="sm-token" aria-labelledby="sm-token-title">
              <h3 id="sm-token-title"><?php echo esc_html__('Your secure access key is ready', 'eduardo-research-manager'); ?></h3>
              <p><?php echo esc_html__('Copy it now and add it to the project that will manage this site. For security, this key is shown only once.', 'eduardo-research-manager'); ?></p>
              <div class="sm-token__row">
                <code id="site-manager-access-key"><?php echo esc_html($token); ?></code>
                <button type="button" class="button button-primary" data-site-manager-copy="#site-manager-access-key"><?php echo esc_html__('Copy access key', 'eduardo-research-manager'); ?></button>
              </div>
              <div class="sm-live" aria-live="polite" data-site-manager-copy-status></div>
              <p class="sm-next"><?php echo esc_html__('Next:', 'eduardo-research-manager'); ?> <span><?php echo esc_html__('use this key when connecting the site from your ChatGPT project.', 'eduardo-research-manager'); ?></span></p>
            </section>
          <?php endif; ?>

          <div class="sm-grid" aria-label="<?php echo esc_attr__('Site Manager overview', 'eduardo-research-manager'); ?>">
            <div class="sm-card">
              <span class="sm-card__label"><?php echo esc_html__('Connection', 'eduardo-research-manager'); ?></span>
              <span class="sm-card__value"><?php echo $connected ? esc_html__('Connected', 'eduardo-research-manager') : esc_html__('Not connected', 'eduardo-research-manager'); ?></span>
              <span class="sm-card__meta"><?php echo $connected ? esc_html__('Secure read-only credential is active.', 'eduardo-research-manager') : esc_html__('Generate an access key to start.', 'eduardo-research-manager'); ?></span>
            </div>
            <div class="sm-card">
              <span class="sm-card__label"><?php echo esc_html__('Site checks', 'eduardo-research-manager'); ?></span>
              <span class="sm-card__value"><?php echo esc_html($health_label); ?></span>
              <span class="sm-card__meta"><?php echo esc_html(sprintf('%d passed · %d to review · %d failed', (int) ($summary['pass'] ?? 0), (int) ($summary['warning'] ?? 0), (int) ($summary['fail'] ?? 0))); ?></span>
            </div>
            <div class="sm-card">
              <span class="sm-card__label"><?php echo esc_html__('Protection', 'eduardo-research-manager'); ?></span>
              <span class="sm-card__value"><?php echo esc_html__('No write access', 'eduardo-research-manager'); ?></span>
              <span class="sm-card__meta"><?php echo esc_html__('Content and settings cannot be modified from this profile.', 'eduardo-research-manager'); ?></span>
            </div>
            <div class="sm-card">
              <span class="sm-card__label"><?php echo esc_html__('Last remote use', 'eduardo-research-manager'); ?></span>
              <span class="sm-card__value" style="font-size:14px"><?php echo esc_html($last_used_label); ?></span>
              <span class="sm-card__meta"><?php echo esc_html__('Useful for spotting unexpected access.', 'eduardo-research-manager'); ?></span>
            </div>
          </div>

          <section class="sm-section" aria-labelledby="sm-connect-title">
            <div class="sm-section__head">
              <div>
                <div class="sm-eyebrow"><?php echo esc_html__('Setup', 'eduardo-research-manager'); ?></div>
                <h2 id="sm-connect-title"><?php echo esc_html__('Connect this site to ChatGPT', 'eduardo-research-manager'); ?></h2>
                <p><?php echo esc_html__('Three clear steps. Technical identifiers stay hidden unless you need them.', 'eduardo-research-manager'); ?></p>
              </div>
              <span class="sm-badge <?php echo $connected ? 'sm-badge--good' : 'sm-badge--warn'; ?>"><span class="sm-dot" aria-hidden="true"></span><?php echo $connected ? esc_html__('Site access ready', 'eduardo-research-manager') : esc_html__('Connection required', 'eduardo-research-manager'); ?></span>
            </div>

            <div class="sm-steps">
              <div class="sm-step sm-step--done">
                <span class="sm-step__num" aria-hidden="true">✓</span>
                <div><strong><?php echo esc_html__('Site detected', 'eduardo-research-manager'); ?></strong><p><?php echo esc_html__('WordPress and the active theme have been identified.', 'eduardo-research-manager'); ?></p></div>
              </div>
              <div class="sm-step <?php echo $connected ? 'sm-step--done' : 'sm-step--active'; ?>">
                <span class="sm-step__num" aria-hidden="true"><?php echo $connected ? '✓' : '2'; ?></span>
                <div><strong><?php echo esc_html__('Create secure access', 'eduardo-research-manager'); ?></strong><p><?php echo $connected ? esc_html__('A read-only access key is active.', 'eduardo-research-manager') : esc_html__('Generate a one-time access key for the project.', 'eduardo-research-manager'); ?></p></div>
              </div>
              <div class="sm-step <?php echo $connected ? 'sm-step--active' : ''; ?>">
                <span class="sm-step__num" aria-hidden="true">3</span>
                <div><strong><?php echo esc_html__('Connect your project', 'eduardo-research-manager'); ?></strong><p><?php echo $connected ? esc_html__('Use the access key in the ChatGPT project that will govern this site.', 'eduardo-research-manager') : esc_html__('This step becomes available after secure access is generated.', 'eduardo-research-manager'); ?></p></div>
              </div>
            </div>

            <div class="sm-actions">
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="site_manager_remote">
                <input type="hidden" name="manager_action" value="<?php echo ! empty($connection['credential_present']) ? 'rotate' : 'connect'; ?>">
                <?php wp_nonce_field('erm_site_manager_remote'); ?>
                <?php submit_button(! empty($connection['credential_present']) ? __('Generate a new access key', 'eduardo-research-manager') : __('Generate secure access', 'eduardo-research-manager'), 'primary', 'submit', false); ?>
              </form>
              <?php if (! empty($connection['credential_present'])) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return window.confirm('<?php echo esc_js(__('Disconnect this site? Existing access will stop working immediately.', 'eduardo-research-manager')); ?>');">
                  <input type="hidden" name="action" value="site_manager_remote">
                  <input type="hidden" name="manager_action" value="revoke">
                  <?php wp_nonce_field('erm_site_manager_remote'); ?>
                  <?php submit_button(__('Disconnect site', 'eduardo-research-manager'), 'secondary sm-danger', 'submit', false); ?>
                </form>
              <?php endif; ?>
            </div>

            <div class="sm-callout">
              <strong><?php echo esc_html__('Safe by default', 'eduardo-research-manager'); ?></strong>
              <p><?php echo esc_html__('This connection can read site status and diagnostics only. It cannot publish, edit, delete, install or change WordPress settings.', 'eduardo-research-manager'); ?></p>
            </div>

            <details class="sm-details">
              <summary><?php echo esc_html__('Technical connection details', 'eduardo-research-manager'); ?></summary>
              <table class="widefat striped"><tbody>
                <tr><th><?php echo esc_html__('Profile', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($profile['id'] ?? '')); ?></code></td></tr>
                <tr><th><?php echo esc_html__('Connection ID', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($connection['connection_id'] ?? '')); ?></code></td></tr>
                <tr><th><?php echo esc_html__('Generation', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ((int) ($connection['generation'] ?? 0))); ?></td></tr>
                <tr><th><?php echo esc_html__('Permissions', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html(implode(', ', (array) ($connection['scopes'] ?? array()))); ?></code></td></tr>
                <tr><th><?php echo esc_html__('WordPress', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($profile['wordpress_version'] ?? '')); ?></td></tr>
                <tr><th><?php echo esc_html__('PHP', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($profile['php_version'] ?? '')); ?></td></tr>
                <tr><th><?php echo esc_html__('Active theme', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($profile['active_theme']['name'] ?? '')); ?> — <?php echo esc_html((string) ($profile['active_theme']['version'] ?? '')); ?></td></tr>
              </tbody></table>
            </details>
          </section>

          <section class="sm-section" aria-labelledby="sm-health-title">
            <div class="sm-section__head">
              <div>
                <div class="sm-eyebrow"><?php echo esc_html__('Health', 'eduardo-research-manager'); ?></div>
                <h2 id="sm-health-title"><?php echo esc_html__('What needs your attention?', 'eduardo-research-manager'); ?></h2>
                <p><?php echo esc_html__('Plain-language checks first; technical evidence remains available below.', 'eduardo-research-manager'); ?></p>
              </div>
              <span class="sm-badge <?php echo 'critical' === $health_status ? 'sm-badge--bad' : ('attention' === $health_status ? 'sm-badge--warn' : 'sm-badge--good'); ?>"><span class="sm-dot" aria-hidden="true"></span><?php echo esc_html($health_label); ?></span>
            </div>

            <div class="sm-checks">
              <?php foreach ($checks as $check) :
                  $check_status = sanitize_key((string) ($check['status'] ?? 'warning'));
                  $check_label = 'pass' === $check_status ? __('Passed', 'eduardo-research-manager') : ('fail' === $check_status ? __('Action required', 'eduardo-research-manager') : __('Review', 'eduardo-research-manager'));
              ?>
                <div class="sm-check">
                  <div class="sm-check__status sm-check__status--<?php echo esc_attr($check_status); ?>"><?php echo esc_html($check_label); ?></div>
                  <div>
                    <strong><?php echo esc_html((string) ($check['label'] ?? '')); ?></strong>
                    <p><?php echo esc_html((string) ($check['message'] ?? '')); ?></p>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="sm-section" aria-labelledby="sm-baseline-title">
            <div class="sm-section__head">
              <div>
                <div class="sm-eyebrow"><?php echo esc_html__('Baseline', 'eduardo-research-manager'); ?></div>
                <h2 id="sm-baseline-title"><?php echo esc_html__('Site snapshot', 'eduardo-research-manager'); ?></h2>
                <p><?php echo esc_html__('A read-only snapshot of the WordPress configuration used as the starting point for later audits and improvements.', 'eduardo-research-manager'); ?></p>
              </div>
              <span class="sm-badge"><span class="sm-dot" aria-hidden="true"></span><?php echo esc_html__('Read-only evidence', 'eduardo-research-manager'); ?></span>
            </div>

            <div class="sm-baseline">
              <div class="sm-mini"><span><?php echo esc_html__('Home URL', 'eduardo-research-manager'); ?></span><code><?php echo esc_html((string) ($baseline['urls']['home'] ?? '')); ?></code></div>
              <div class="sm-mini"><span><?php echo esc_html__('Search visibility', 'eduardo-research-manager'); ?></span><strong><?php echo esc_html((string) ($baseline['indexability']['wordpress_search_visibility'] ?? '')); ?></strong></div>
              <div class="sm-mini"><span><?php echo esc_html__('Permalink structure', 'eduardo-research-manager'); ?></span><code><?php echo esc_html((string) ($baseline['routing']['permalink_structure'] ?? '')); ?></code></div>
              <div class="sm-mini"><span><?php echo esc_html__('Installed plugins', 'eduardo-research-manager'); ?></span><strong><?php echo esc_html((string) ((int) ($baseline['plugins']['installed_count'] ?? 0))); ?></strong></div>
              <div class="sm-mini"><span><?php echo esc_html__('Native sitemap', 'eduardo-research-manager'); ?></span><strong><?php echo ! empty($baseline['discovery']['native_sitemaps_available']) ? esc_html__('Available', 'eduardo-research-manager') : esc_html__('Unavailable', 'eduardo-research-manager'); ?></strong></div>
              <div class="sm-mini"><span><?php echo esc_html__('Snapshot generated', 'eduardo-research-manager'); ?></span><strong><?php echo esc_html((string) ($baseline['generated_at'] ?? '')); ?></strong></div>
            </div>

            <div class="sm-callout">
              <strong><?php echo esc_html__('Measurements we do not fake', 'eduardo-research-manager'); ?></strong>
              <p><?php echo esc_html__('Core Web Vitals, rendered accessibility, Search Console, analytics and external search visibility stay marked as not measured until their real probes or connectors are available.', 'eduardo-research-manager'); ?></p>
            </div>
          </section>

          <script>
            (function(){
              var buttons=document.querySelectorAll('[data-site-manager-copy]');
              var status=document.querySelector('[data-site-manager-copy-status]');
              buttons.forEach(function(button){
                button.addEventListener('click',function(){
                  var selector=button.getAttribute('data-site-manager-copy');
                  var source=selector?document.querySelector(selector):null;
                  var text=source?source.textContent.trim():'';
                  if(!text){return;}
                  if(navigator.clipboard&&navigator.clipboard.writeText){
                    navigator.clipboard.writeText(text).then(function(){button.textContent='<?php echo esc_js(__('Copied', 'eduardo-research-manager')); ?>';if(status){status.textContent='<?php echo esc_js(__('Access key copied to clipboard.', 'eduardo-research-manager')); ?>';}}).catch(function(){if(status){status.textContent='<?php echo esc_js(__('Copy failed. Select the key and copy it manually.', 'eduardo-research-manager')); ?>';}});
                  }else{
                    if(status){status.textContent='<?php echo esc_js(__('Select the key and copy it manually.', 'eduardo-research-manager')); ?>';}
                  }
                });
              });
            }());
          </script>
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
