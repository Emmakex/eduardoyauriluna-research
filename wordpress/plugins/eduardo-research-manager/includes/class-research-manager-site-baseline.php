<?php
/** Read-only portable Site Baseline Audit for any supported WordPress site. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Site_Baseline {
    private Eduardo_Research_Manager_Site_Profile $profile;
    private Eduardo_Research_Manager_Remote_Credentials $credentials;

    public function __construct(
        ?Eduardo_Research_Manager_Site_Profile $profile = null,
        ?Eduardo_Research_Manager_Remote_Credentials $credentials = null
    ) {
        $this->profile = $profile ?: new Eduardo_Research_Manager_Site_Profile();
        $this->credentials = $credentials ?: new Eduardo_Research_Manager_Remote_Credentials();
    }

    public function run(): array {
        return array(
            'schema_version' => 1,
            'generated_at' => gmdate(DATE_W3C),
            'profile' => $this->profile->snapshot(),
            'runtime' => $this->runtime(),
            'urls' => $this->urls(),
            'indexability' => $this->indexability(),
            'routing' => $this->routing(),
            'content' => $this->content_inventory(),
            'themes' => $this->theme_inventory(),
            'plugins' => $this->plugin_inventory(),
            'discovery' => $this->discovery(),
            'remote' => $this->remote_state(),
            'external_observability' => $this->external_observability(),
        );
    }

    private function runtime(): array {
        return array(
            'wordpress' => (string) get_bloginfo('version'),
            'php' => PHP_VERSION,
            'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            'multisite' => is_multisite(),
            'locale' => (string) get_locale(),
            'timezone' => (string) wp_timezone_string(),
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
        );
    }

    private function urls(): array {
        $home = home_url('/');
        $site = site_url('/');
        return array(
            'home' => $home,
            'site' => $site,
            'rest' => rest_url(),
            'home_https' => 0 === strpos($home, 'https://'),
            'site_https' => 0 === strpos($site, 'https://'),
            'request_is_ssl' => is_ssl(),
        );
    }

    private function indexability(): array {
        $public = '1' === (string) get_option('blog_public', '1');
        return array(
            'wordpress_search_visibility' => $public ? 'index' : 'discourage',
            'indexable_by_wordpress_setting' => $public,
            'robots_runtime_filter_present' => has_filter('wp_robots'),
            'note' => 'This is WordPress-state evidence only; external robots/header/crawler verification is measured separately.',
        );
    }

    private function routing(): array {
        $front_page_id = (int) get_option('page_on_front', 0);
        $posts_page_id = (int) get_option('page_for_posts', 0);
        return array(
            'show_on_front' => (string) get_option('show_on_front', 'posts'),
            'front_page_id' => $front_page_id,
            'front_page_title' => $front_page_id > 0 ? (string) get_the_title($front_page_id) : '',
            'posts_page_id' => $posts_page_id,
            'posts_page_title' => $posts_page_id > 0 ? (string) get_the_title($posts_page_id) : '',
            'permalink_structure' => (string) get_option('permalink_structure', ''),
        );
    }

    private function content_inventory(): array {
        $types = get_post_types(array('public' => true), 'objects');
        $inventory = array();
        $limit = 50;
        foreach (array_slice($types, 0, $limit, true) as $name => $object) {
            $counts = wp_count_posts((string) $name);
            $row = array(
                'name' => (string) $name,
                'label' => is_object($object) ? (string) ($object->labels->name ?? $name) : (string) $name,
                'builtin' => is_object($object) ? ! empty($object->_builtin) : false,
                'counts' => array(),
            );
            foreach (array('publish','draft','pending','future','private','trash') as $status) {
                $row['counts'][$status] = is_object($counts) ? (int) ($counts->{$status} ?? 0) : 0;
            }
            $inventory[] = $row;
        }
        return array(
            'public_post_types' => $inventory,
            'truncated' => count($types) > $limit,
            'limit' => $limit,
        );
    }

    private function theme_inventory(): array {
        $theme = wp_get_theme();
        return array(
            'active' => array(
                'stylesheet' => (string) $theme->get_stylesheet(),
                'template' => (string) $theme->get_template(),
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'parent' => $theme->parent() ? (string) $theme->parent()->get('Name') : '',
            ),
        );
    }

    private function plugin_inventory(): array {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = function_exists('get_plugins') ? get_plugins() : array();
        $active = array_fill_keys((array) get_option('active_plugins', array()), true);
        $items = array();
        $limit = 100;
        foreach (array_slice($plugins, 0, $limit, true) as $file => $data) {
            $items[] = array(
                'file' => (string) $file,
                'name' => (string) ($data['Name'] ?? ''),
                'version' => (string) ($data['Version'] ?? ''),
                'active' => isset($active[$file]) || (is_multisite() && is_plugin_active_for_network((string) $file)),
            );
        }
        return array(
            'installed_count' => count($plugins),
            'items' => $items,
            'truncated' => count($plugins) > $limit,
            'limit' => $limit,
        );
    }

    private function discovery(): array {
        $sitemaps_enabled = function_exists('wp_sitemaps_get_server');
        return array(
            'site_title' => (string) get_bloginfo('name'),
            'site_description' => (string) get_bloginfo('description'),
            'language' => (string) get_bloginfo('language'),
            'native_rest_url' => rest_url(),
            'native_sitemaps_available' => $sitemaps_enabled,
            'native_sitemap_url' => $sitemaps_enabled ? home_url('/wp-sitemap.xml') : '',
        );
    }

    private function remote_state(): array {
        $state = $this->credentials->state();
        return array(
            'connection_id' => (string) ($state['connection_id'] ?? ''),
            'enabled' => ! empty($state['enabled']),
            'writes_enabled' => ! empty($state['writes_enabled']),
            'generation' => (int) ($state['generation'] ?? 0),
            'scopes' => is_array($state['scopes'] ?? null) ? array_values($state['scopes']) : array(),
            'last_used_at' => (string) ($state['last_used_at'] ?? ''),
        );
    }

    private function external_observability(): array {
        $not_measured = static fn(string $reason): array => array('status' => 'not_measured', 'reason' => $reason);
        return array(
            'dns_tls_http_headers' => $not_measured('Requires an external network probe.'),
            'robots_and_sitemap_crawl' => $not_measured('Requires an external crawler to verify public responses.'),
            'lighthouse_core_web_vitals' => $not_measured('Requires Lighthouse/CrUX or equivalent external measurement.'),
            'accessibility_axe_wcag' => $not_measured('Requires rendered-page accessibility instrumentation.'),
            'search_console' => $not_measured('Requires a connected Search Console property.'),
            'analytics' => $not_measured('Requires an analytics connector or runtime tag inspection.'),
            'search_visibility' => $not_measured('Requires external index/search-provider evidence.'),
        );
    }
}
