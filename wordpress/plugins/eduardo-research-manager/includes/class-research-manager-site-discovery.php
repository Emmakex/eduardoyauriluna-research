<?php
/** Adaptive local site discovery and governance map for Research Manager. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Site_Discovery {
    private const OPTION = 'eduardo_research_manager_site_discovery';
    private const DIRTY_OPTION = 'eduardo_research_manager_site_discovery_dirty';
    private const SCHEMA_VERSION = 1;
    private const ADMIN_SLUG = 'site-manager';

    /** Run a bounded local discovery immediately when the plugin is activated. */
    public static function activate(): void {
        (new self())->refresh('activation');
    }

    public function register(): void {
        add_action('activated_plugin', array($this, 'plugin_changed'), 10, 2);
        add_action('deactivated_plugin', array($this, 'plugin_changed'), 10, 2);
        add_action('switch_theme', array($this, 'mark_dirty'), 10, 2);
        add_action('upgrader_process_complete', array($this, 'mark_dirty'), 10, 2);

        foreach (array('permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'active_plugins') as $option) {
            add_action('update_option_' . $option, array($this, 'mark_dirty'), 10, 3);
        }
        if (is_multisite()) {
            add_action('update_site_option_active_sitewide_plugins', array($this, 'mark_dirty'), 10, 3);
        }

        add_action('admin_init', array($this, 'ensure_current'), 5);
        add_action('rest_api_init', array($this, 'ensure_current'), 5);
        add_action('admin_menu', array($this, 'admin_menu'), 1100);
        add_action('admin_post_eduardo_research_manager_refresh_discovery', array($this, 'handle_refresh'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_diagnostics'), 1002, 3);
    }

    public function plugin_changed(string $plugin = '', bool $network_wide = false): void {
        if (defined('EDUARDO_RESEARCH_MANAGER_FILE') && plugin_basename(EDUARDO_RESEARCH_MANAGER_FILE) === $plugin) {
            return;
        }
        $this->mark_dirty();
    }

    public function mark_dirty(mixed ...$ignored): void {
        update_option(self::DIRTY_OPTION, '1', false);
    }

    public function ensure_current(): array {
        $stored = get_option(self::OPTION, array());
        $dirty = '1' === (string) get_option(self::DIRTY_OPTION, '1');
        $fingerprint = $this->fingerprint();

        if (! is_array($stored)
            || self::SCHEMA_VERSION !== (int) ($stored['schema_version'] ?? 0)
            || $dirty
            || $fingerprint !== (string) ($stored['fingerprint'] ?? '')
        ) {
            return $this->refresh($dirty ? 'structure-changed' : 'fingerprint-changed');
        }

        return $stored;
    }

    public function snapshot(): array {
        return $this->ensure_current();
    }

    public function refresh(string $reason = 'manual'): array {
        $profile = (new Eduardo_Research_Manager_Site_Profile())->snapshot();
        $baseline = (new Eduardo_Research_Manager_Site_Baseline())->run();
        $facts = $this->facts($baseline);
        $governance = $this->governance($profile, $facts);
        $gaps = $this->gaps($profile, $facts);

        $snapshot = array(
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate(DATE_W3C),
            'reason' => sanitize_key($reason),
            'fingerprint' => $this->fingerprint(),
            'profile' => $profile,
            'facts' => $facts,
            'governance' => $governance,
            'gaps' => $gaps,
            'summary' => array(
                'content_types' => count((array) ($facts['content_types'] ?? array())),
                'taxonomies' => count((array) ($facts['taxonomies'] ?? array())),
                'active_plugins' => (int) ($facts['plugins']['active_count'] ?? 0),
                'integrations' => count((array) ($facts['integrations'] ?? array())),
                'governable_now' => count((array) ($governance['available'] ?? array())),
                'gaps' => count($gaps),
            ),
        );

        update_option(self::OPTION, $snapshot, false);
        delete_option(self::DIRTY_OPTION);
        return $snapshot;
    }

    /** Site-read payload: discovery summary without diagnostic-only baseline details. */
    public function capability_payload(): array {
        $snapshot = $this->snapshot();
        return array(
            'schema_version' => (int) ($snapshot['schema_version'] ?? self::SCHEMA_VERSION),
            'generated_at' => (string) ($snapshot['generated_at'] ?? ''),
            'fingerprint' => (string) ($snapshot['fingerprint'] ?? ''),
            'profile' => (string) ($snapshot['profile']['id'] ?? Eduardo_Research_Manager_Site_Profile::GENERIC),
            'summary' => is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : array(),
            'theme' => is_array($snapshot['facts']['theme'] ?? null) ? $snapshot['facts']['theme'] : array(),
            'content_types' => is_array($snapshot['facts']['content_types'] ?? null) ? $snapshot['facts']['content_types'] : array(),
            'integrations' => is_array($snapshot['facts']['integrations'] ?? null) ? $snapshot['facts']['integrations'] : array(),
            'governance' => is_array($snapshot['governance'] ?? null) ? $snapshot['governance'] : array(),
            'gaps' => is_array($snapshot['gaps'] ?? null) ? $snapshot['gaps'] : array(),
        );
    }

    public function augment_diagnostics(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/diagnostics' !== $request->get_route()
            || is_wp_error($response)
            || ! $response instanceof WP_REST_Response
        ) {
            return $response;
        }

        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            return $response;
        }

        $payload['data']['site_discovery'] = $this->snapshot();
        $response->set_data($payload);
        return $response;
    }

    /** Research Theme keeps its deep tools addressable but presents one adaptive Manager entry point. */
    public function admin_menu(): void {
        if (! (new Eduardo_Research_Manager_Site_Profile())->research_available()) {
            return; // Generic WordPress already gets the portable Site Manager from Portability.
        }

        global $submenu;
        if (isset($submenu['tools.php']) && is_array($submenu['tools.php'])) {
            foreach ($submenu['tools.php'] as $index => $item) {
                $slug = is_array($item) ? (string) ($item[2] ?? '') : '';
                if (0 === strpos($slug, 'eduardo-research-manager')) {
                    unset($submenu['tools.php'][$index]);
                }
            }
        }
        remove_menu_page('eduardo-research-workspace');

        add_management_page(
            __('Site Manager', 'eduardo-research-manager'),
            __('Site Manager', 'eduardo-research-manager'),
            'manage_options',
            self::ADMIN_SLUG,
            array($this, 'render_admin')
        );
    }

    public function handle_refresh(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to refresh Site Manager discovery.', 'eduardo-research-manager'));
        }
        check_admin_referer('erm_refresh_site_discovery');
        $this->refresh('manual');
        wp_safe_redirect(admin_url('tools.php?page=' . self::ADMIN_SLUG . '&discovery_refreshed=1'));
        exit;
    }

    public function render_admin(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Site Manager.', 'eduardo-research-manager'));
        }

        $snapshot = $this->snapshot();
        $facts = is_array($snapshot['facts'] ?? null) ? $snapshot['facts'] : array();
        $available = is_array($snapshot['governance']['available'] ?? null) ? $snapshot['governance']['available'] : array();
        $gaps = is_array($snapshot['gaps'] ?? null) ? $snapshot['gaps'] : array();
        $content_types = is_array($facts['content_types'] ?? null) ? $facts['content_types'] : array();
        $integrations = is_array($facts['integrations'] ?? null) ? $facts['integrations'] : array();
        $theme = is_array($facts['theme'] ?? null) ? $facts['theme'] : array();
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : array();
        ?>
        <div class="wrap erm-discovery">
          <style>
            .erm-discovery{max-width:1180px}.erm-discovery .erm-hero,.erm-discovery .erm-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px;margin-top:16px}.erm-discovery .erm-hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.erm-discovery .erm-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px}.erm-discovery .erm-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}.erm-discovery .erm-card span{display:block;color:#646970;font-size:12px}.erm-discovery .erm-card strong{display:block;font-size:20px;margin-top:5px}.erm-discovery .erm-list{display:grid;gap:8px;margin:12px 0 0}.erm-discovery .erm-row{padding:12px;border:1px solid #e2e4e7;border-radius:7px}.erm-discovery .erm-row strong{display:block}.erm-discovery .erm-row code{white-space:normal}.erm-discovery .erm-meta{color:#646970}.erm-discovery .erm-actions{display:flex;gap:8px;flex-wrap:wrap}.erm-discovery .erm-ok{border-left:4px solid #00a32a}.erm-discovery .erm-gap{border-left:4px solid #dba617}@media(max-width:900px){.erm-discovery .erm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.erm-discovery .erm-hero{display:block}}@media(max-width:600px){.erm-discovery .erm-grid{grid-template-columns:1fr}}
          </style>
          <div class="erm-hero">
            <div>
              <h1><?php echo esc_html__('Site Manager', 'eduardo-research-manager'); ?></h1>
              <p><?php echo esc_html__('Research Manager has inspected this WordPress installation and adapts its control surface to what is actually present. Detection does not grant write access by itself.', 'eduardo-research-manager'); ?></p>
              <p class="erm-meta"><?php echo esc_html(sprintf(__('Last discovery: %s · reason: %s', 'eduardo-research-manager'), (string) ($snapshot['generated_at'] ?? ''), (string) ($snapshot['reason'] ?? ''))); ?></p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="eduardo_research_manager_refresh_discovery">
              <?php wp_nonce_field('erm_refresh_site_discovery'); ?>
              <?php submit_button(__('Re-detect site', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
            </form>
          </div>

          <div class="erm-grid">
            <div class="erm-card"><span><?php echo esc_html__('Profile', 'eduardo-research-manager'); ?></span><strong><?php echo esc_html((string) ($snapshot['profile']['id'] ?? '')); ?></strong></div>
            <div class="erm-card"><span><?php echo esc_html__('Content types', 'eduardo-research-manager'); ?></span><strong><?php echo esc_html((string) ((int) ($summary['content_types'] ?? 0))); ?></strong></div>
            <div class="erm-card"><span><?php echo esc_html__('Detected integrations', 'eduardo-research-manager'); ?></span><strong><?php echo esc_html((string) ((int) ($summary['integrations'] ?? 0))); ?></strong></div>
            <div class="erm-card"><span><?php echo esc_html__('Governable now', 'eduardo-research-manager'); ?></span><strong><?php echo esc_html((string) ((int) ($summary['governable_now'] ?? 0))); ?></strong></div>
          </div>

          <section class="erm-panel">
            <h2><?php echo esc_html__('1. Discovered', 'eduardo-research-manager'); ?></h2>
            <p class="erm-meta"><?php echo esc_html__('Structure detected locally at activation and refreshed whenever the theme, plugins or routing configuration changes.', 'eduardo-research-manager'); ?></p>
            <div class="erm-list">
              <div class="erm-row"><strong><?php echo esc_html__('Active theme', 'eduardo-research-manager'); ?></strong><?php echo esc_html((string) ($theme['name'] ?? '') . ' ' . (string) ($theme['version'] ?? '')); ?> <code><?php echo esc_html((string) ($theme['stylesheet'] ?? '')); ?></code></div>
              <div class="erm-row"><strong><?php echo esc_html__('Public content', 'eduardo-research-manager'); ?></strong><?php echo esc_html(implode(', ', array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $content_types))); ?></div>
              <div class="erm-row"><strong><?php echo esc_html__('Integrations', 'eduardo-research-manager'); ?></strong><?php echo $integrations ? esc_html(implode(', ', array_map(static fn(array $row): string => (string) ($row['label'] ?? $row['id'] ?? ''), $integrations))) : esc_html__('No known third-party integration detected.', 'eduardo-research-manager'); ?></div>
            </div>
          </section>

          <section class="erm-panel">
            <h2><?php echo esc_html__('2. Governable now', 'eduardo-research-manager'); ?></h2>
            <p class="erm-meta"><?php echo esc_html__('Only surfaces with an explicit Manager provider are listed here. Remote use still depends on the scopes granted to the active connection.', 'eduardo-research-manager'); ?></p>
            <div class="erm-list">
              <?php foreach ($available as $surface) : ?>
                <div class="erm-row erm-ok"><strong><code><?php echo esc_html((string) ($surface['id'] ?? '')); ?></code></strong><?php echo esc_html((string) ($surface['description'] ?? '')); ?><?php if (! empty($surface['safety'])) : ?><div class="erm-meta"><?php echo esc_html((string) $surface['safety']); ?></div><?php endif; ?></div>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="erm-panel">
            <h2><?php echo esc_html__('3. Missing to govern', 'eduardo-research-manager'); ?></h2>
            <p class="erm-meta"><?php echo esc_html__('These are detected needs, not permissions. We can add adapters one by one without pretending unsupported writes are safe.', 'eduardo-research-manager'); ?></p>
            <div class="erm-list">
              <?php if (! $gaps) : ?><div class="erm-row erm-ok"><?php echo esc_html__('No structural governance gaps were detected for the current profile.', 'eduardo-research-manager'); ?></div><?php endif; ?>
              <?php foreach ($gaps as $gap) : ?>
                <div class="erm-row erm-gap"><strong><?php echo esc_html((string) ($gap['label'] ?? $gap['id'] ?? '')); ?></strong><?php echo esc_html((string) ($gap['reason'] ?? '')); ?><div class="erm-meta"><code><?php echo esc_html((string) ($gap['requirement'] ?? '')); ?></code></div></div>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="erm-panel">
            <h2><?php echo esc_html__('Connections and advanced controls', 'eduardo-research-manager'); ?></h2>
            <p class="erm-meta"><?php echo esc_html__('The normal operating surface is Site Manager. Existing Research Theme tools remain available as advanced screens, but no longer need to fill the WordPress menu.', 'eduardo-research-manager'); ?></p>
            <div class="erm-actions">
              <a class="button button-primary" href="<?php echo esc_url(admin_url('tools.php?page=eduardo-research-manager-remote')); ?>"><?php echo esc_html__('ChatGPT / MCP connection', 'eduardo-research-manager'); ?></a>
              <a class="button" href="<?php echo esc_url(admin_url('tools.php?page=eduardo-research-manager')); ?>"><?php echo esc_html__('Advanced Research Manager', 'eduardo-research-manager'); ?></a>
              <a class="button" href="<?php echo esc_url(admin_url('tools.php?page=eduardo-research-manager-v1-controls')); ?>"><?php echo esc_html__('Advanced contract controls', 'eduardo-research-manager'); ?></a>
            </div>
          </section>
        </div>
        <?php
    }

    private function facts(array $baseline): array {
        $theme = wp_get_theme();
        $post_types = array();
        foreach (get_post_types(array('public' => true), 'objects') as $name => $object) {
            $post_types[] = array(
                'name' => (string) $name,
                'label' => (string) ($object->labels->name ?? $name),
                'builtin' => ! empty($object->_builtin),
                'hierarchical' => ! empty($object->hierarchical),
                'show_in_rest' => ! empty($object->show_in_rest),
                'rest_base' => (string) ($object->rest_base ?: $name),
                'supports' => array_keys((array) get_all_post_type_supports((string) $name)),
            );
        }

        $taxonomies = array();
        foreach (get_taxonomies(array('public' => true), 'objects') as $name => $object) {
            $taxonomies[] = array(
                'name' => (string) $name,
                'label' => (string) ($object->labels->name ?? $name),
                'hierarchical' => ! empty($object->hierarchical),
                'show_in_rest' => ! empty($object->show_in_rest),
                'object_types' => array_values((array) ($object->object_type ?? array())),
            );
        }

        $active_plugins = $this->active_plugins();
        return array(
            'runtime' => is_array($baseline['runtime'] ?? null) ? $baseline['runtime'] : array(),
            'routing' => is_array($baseline['routing'] ?? null) ? $baseline['routing'] : array(),
            'indexability' => is_array($baseline['indexability'] ?? null) ? $baseline['indexability'] : array(),
            'theme' => array(
                'name' => (string) $theme->get('Name'),
                'stylesheet' => (string) $theme->get_stylesheet(),
                'template' => (string) $theme->get_template(),
                'version' => (string) $theme->get('Version'),
                'child_theme' => (bool) $theme->parent(),
                'block_theme' => function_exists('wp_is_block_theme') ? (bool) wp_is_block_theme() : false,
                'supports' => array(
                    'menus' => current_theme_supports('menus'),
                    'custom_logo' => current_theme_supports('custom-logo'),
                    'title_tag' => current_theme_supports('title-tag'),
                    'editor_styles' => current_theme_supports('editor-styles'),
                    'block_templates' => current_theme_supports('block-templates'),
                ),
            ),
            'content_types' => $post_types,
            'taxonomies' => $taxonomies,
            'plugins' => array(
                'active_count' => count($active_plugins),
                'active_files' => array_values(array_keys($active_plugins)),
            ),
            'integrations' => $this->integrations($active_plugins),
        );
    }

    private function active_plugins(): array {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = function_exists('get_plugins') ? get_plugins() : array();
        $active_files = array_fill_keys(array_map('strval', (array) get_option('active_plugins', array())), true);
        if (is_multisite()) {
            foreach (array_keys((array) get_site_option('active_sitewide_plugins', array())) as $file) {
                $active_files[(string) $file] = true;
            }
        }

        $result = array();
        foreach ($active_files as $file => $_enabled) {
            $data = is_array($installed[$file] ?? null) ? $installed[$file] : array();
            $result[$file] = array(
                'name' => (string) ($data['Name'] ?? $file),
                'version' => (string) ($data['Version'] ?? ''),
            );
        }
        ksort($result);
        return $result;
    }

    private function integrations(array $active_plugins): array {
        $definitions = array(
            'woocommerce' => array('label'=>'WooCommerce','files'=>array('woocommerce/woocommerce.php')),
            'elementor' => array('label'=>'Elementor','files'=>array('elementor/elementor.php','elementor-pro/elementor-pro.php')),
            'acf' => array('label'=>'Advanced Custom Fields','files'=>array('advanced-custom-fields/acf.php','advanced-custom-fields-pro/acf.php')),
            'yoast-seo' => array('label'=>'Yoast SEO','files'=>array('wordpress-seo/wp-seo.php')),
            'rank-math' => array('label'=>'Rank Math SEO','files'=>array('seo-by-rank-math/rank-math.php')),
            'aioseo' => array('label'=>'All in One SEO','files'=>array('all-in-one-seo-pack/all_in_one_seo_pack.php')),
            'wpml' => array('label'=>'WPML','files'=>array('sitepress-multilingual-cms/sitepress.php')),
            'polylang' => array('label'=>'Polylang','files'=>array('polylang/polylang.php','polylang-pro/polylang.php')),
            'gutenberg' => array('label'=>'Gutenberg','files'=>array('gutenberg/gutenberg.php')),
            'classic-editor' => array('label'=>'Classic Editor','files'=>array('classic-editor/classic-editor.php')),
        );

        $detected = array();
        foreach ($definitions as $id => $definition) {
            $files = array_values(array_intersect((array) $definition['files'], array_keys($active_plugins)));
            if (! $files) { continue; }
            $detected[] = array(
                'id' => $id,
                'label' => (string) $definition['label'],
                'status' => 'detected',
                'files' => $files,
                'adapter' => 'required',
            );
        }
        return $detected;
    }

    private function governance(array $profile, array $facts): array {
        $available = array(
            array('id'=>'site.read','provider'=>'wordpress-core','description'=>'Read discovered WordPress structure and capability metadata.','remote_grant'=>'scope-required'),
            array('id'=>'site.diagnostics','provider'=>'wordpress-core','description'=>'Run the bounded local baseline and Manager diagnostics.','remote_grant'=>'scope-required'),
        );
        $blocked = array();

        if (! empty($profile['research_available'])) {
            $available[] = array('id'=>'research.contract','provider'=>'research-theme','description'=>'Use the detected Research Theme contract and its registered resources.','remote_grant'=>'scope-dependent');
            $available[] = array('id'=>'research.mutations','provider'=>'research-manager','description'=>'Use existing bounded Research Theme mutation services.','remote_grant'=>'write-scope-required','safety'=>'Preview → explicit confirmation → apply → verification → rollback.');
        } else {
            foreach (array('content.write','navigation.write','seo.write','design.write') as $surface) {
                $blocked[] = array('id'=>$surface,'status'=>'blocked','reason'=>'No explicit safe adapter is registered for this WordPress profile.');
            }
        }

        foreach ((array) ($facts['integrations'] ?? array()) as $integration) {
            $blocked[] = array(
                'id' => 'integration.' . (string) ($integration['id'] ?? '') . '.write',
                'status' => 'detected-not-governable',
                'reason' => 'Integration detected; a dedicated adapter is required before Manager writes are exposed.',
            );
        }

        return array('available'=>$available,'blocked'=>$blocked);
    }

    private function gaps(array $profile, array $facts): array {
        $gaps = array();
        if (empty($profile['research_available'])) {
            $gaps[] = array('id'=>'generic-content-write','label'=>'WordPress content writes','reason'=>'Pages/posts are detected, but Manager does not infer write safety from their existence.','requirement'=>'safe-content-adapter + validation + snapshot + rollback');
            $gaps[] = array('id'=>'generic-navigation-write','label'=>'Navigation writes','reason'=>'Menus/navigation require a structure-aware adapter for this site.','requirement'=>'navigation-adapter + preview/apply/rollback');
            $gaps[] = array('id'=>'generic-seo-write','label'=>'SEO metadata writes','reason'=>'SEO ownership varies by plugin/theme and must be detected explicitly.','requirement'=>'seo-provider-adapter + field ownership map');
        }

        foreach ((array) ($facts['integrations'] ?? array()) as $integration) {
            $id = (string) ($integration['id'] ?? 'integration');
            $label = (string) ($integration['label'] ?? $id);
            $gaps[] = array(
                'id' => $id . '-adapter',
                'label' => $label . ' adapter',
                'reason' => $label . ' is active, but no generic write capability is granted just because the plugin is installed.',
                'requirement' => $id . '-adapter + capability tests + guarded mutations',
            );
        }

        if (! empty($facts['runtime']['multisite'])) {
            $gaps[] = array('id'=>'multisite-scope','label'=>'Multisite governance scope','reason'=>'Network/site boundaries must be explicit before cross-site control is offered.','requirement'=>'site-bound tenant scope + network authorization policy');
        }
        return $gaps;
    }

    private function fingerprint(): string {
        $theme = wp_get_theme();
        $active_plugins = $this->active_plugins();
        $source = array(
            'wordpress' => (string) get_bloginfo('version'),
            'theme' => array((string) $theme->get_stylesheet(), (string) $theme->get_template(), (string) $theme->get('Version')),
            'plugins' => $active_plugins,
            'routing' => array(
                (string) get_option('permalink_structure', ''),
                (string) get_option('show_on_front', 'posts'),
                (int) get_option('page_on_front', 0),
                (int) get_option('page_for_posts', 0),
            ),
        );
        return hash('sha256', (string) wp_json_encode($source));
    }
}

if (defined('EDUARDO_RESEARCH_MANAGER_FILE')) {
    register_activation_hook(EDUARDO_RESEARCH_MANAGER_FILE, array('Eduardo_Research_Manager_Site_Discovery', 'activate'));
}
