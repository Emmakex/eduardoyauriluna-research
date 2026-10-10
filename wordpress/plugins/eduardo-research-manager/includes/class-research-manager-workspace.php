<?php
/** Unified read-only navigation and readiness workspace for the Research Manager authoring surfaces. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Workspace {
    public function register(): void {
        add_action('admin_menu', array($this, 'menu'), 8);
    }

    public function menu(): void {
        add_menu_page(
            'Research Workspace',
            'Research',
            'manage_options',
            'eduardo-research-workspace',
            array($this, 'render'),
            'dashicons-welcome-learn-more',
            30
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access the Research Workspace.', 'eduardo-research-manager'));
        }

        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        $summary = is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : array('pass'=>0,'warning'=>0,'fail'=>0);
        $mode = Eduardo_Research_Manager::mode();
        $counts = $this->counts();
        $tools = $this->tools($counts);
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Workspace', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('One control plane for the Greenfield Research site. The Manager owns structured authoring and academic evidence; the Research Theme remains the public rendering authority.', 'eduardo-research-manager'); ?></p>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;max-width:1180px;margin:18px 0">
            <div class="card" style="margin:0;max-width:none;padding:16px">
              <strong><?php echo esc_html__('Manager mode', 'eduardo-research-manager'); ?></strong>
              <p style="font-size:20px;margin:6px 0 0"><code><?php echo esc_html(strtoupper((string) ($mode['mode'] ?? 'greenfield'))); ?></code></p>
            </div>
            <div class="card" style="margin:0;max-width:none;padding:16px">
              <strong><?php echo esc_html__('Research contract', 'eduardo-research-manager'); ?></strong>
              <p style="font-size:20px;margin:6px 0 0"><?php echo esc_html(! empty($diagnostics['ready']) ? 'READY' : 'ATTENTION'); ?></p>
            </div>
            <div class="card" style="margin:0;max-width:none;padding:16px">
              <strong><?php echo esc_html__('Diagnostics', 'eduardo-research-manager'); ?></strong>
              <p style="margin:6px 0 0"><?php echo esc_html(sprintf('%d pass · %d warning · %d fail', (int) ($summary['pass'] ?? 0), (int) ($summary['warning'] ?? 0), (int) ($summary['fail'] ?? 0))); ?></p>
            </div>
            <div class="card" style="margin:0;max-width:none;padding:16px">
              <strong><?php echo esc_html__('Languages', 'eduardo-research-manager'); ?></strong>
              <p style="font-size:20px;margin:6px 0 0"><?php echo esc_html(strtoupper(implode(' / ', Eduardo_Research_Manager::contract()->languages()))); ?></p>
            </div>
          </div>

          <h2><?php echo esc_html__('Authoring workspace', 'eduardo-research-manager'); ?></h2>
          <p><?php echo esc_html__('Open the dedicated structured editor for each Research resource. Every mutation surface keeps Preview → Apply → Verify → Rollback discipline.', 'eduardo-research-manager'); ?></p>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;max-width:1180px;margin:16px 0 26px">
            <?php foreach ($tools as $tool) : ?>
              <section class="card" style="margin:0;max-width:none;padding:18px;display:flex;flex-direction:column;min-height:175px">
                <h3 style="margin:0 0 8px"><?php echo esc_html((string) $tool['title']); ?></h3>
                <p style="margin:0 0 10px;flex:1"><?php echo esc_html((string) $tool['description']); ?></p>
                <p style="margin:0 0 12px"><strong><?php echo esc_html((string) $tool['metric']); ?></strong></p>
                <p style="margin:0"><a class="button button-primary" href="<?php echo esc_url((string) $tool['url']); ?>"><?php echo esc_html((string) $tool['cta']); ?></a></p>
              </section>
            <?php endforeach; ?>
          </div>

          <h2><?php echo esc_html__('Operating model', 'eduardo-research-manager'); ?></h2>
          <table class="widefat striped" style="max-width:1000px"><tbody>
            <tr><th><?php echo esc_html__('Frontend authority', 'eduardo-research-manager'); ?></th><td><?php echo esc_html__('Research Theme — layouts, routes, semantic HTML, responsive behavior and public rendering.', 'eduardo-research-manager'); ?></td></tr>
            <tr><th><?php echo esc_html__('Authoring authority', 'eduardo-research-manager'); ?></th><td><?php echo esc_html__('Research Manager — structured Pages, Insights, Lines, Research Objects, translations and verified evidence.', 'eduardo-research-manager'); ?></td></tr>
            <tr><th><?php echo esc_html__('Academic safety', 'eduardo-research-manager'); ?></th><td><?php echo esc_html__('Evidence-sensitive claims remain blocked until an explicit verification source is confirmed.', 'eduardo-research-manager'); ?></td></tr>
            <tr><th><?php echo esc_html__('Page composition', 'eduardo-research-manager'); ?></th><td><?php echo esc_html__('Theme-controlled structured slots; no Gutenberg layout composition is required.', 'eduardo-research-manager'); ?></td></tr>
          </tbody></table>

          <p style="margin-top:18px"><a class="button" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open public Research site', 'eduardo-research-manager'); ?></a></p>
        </div>
        <?php
    }

    private function tools(array $counts): array {
        return array(
            array(
                'title'=>'Greenfield Site & Pages',
                'description'=>'Run the canonical blueprint pipeline, inspect readiness and edit Theme-owned structured Page slots.',
                'metric'=>sprintf('%d contracted Pages', (int) ($counts['pages'] ?? 0)),
                'url'=>$this->tools_url('eduardo-research-manager'),
                'cta'=>'Open Research Manager',
            ),
            array(
                'title'=>'Academic Evidence',
                'description'=>'Manage canonical researcher identity and verified evidence for About, Research, CV, Contact and scholarly SEO.',
                'metric'=>sprintf('%d evidence records', (int) ($counts['evidence'] ?? 0)),
                'url'=>$this->tools_url('eduardo-research-evidence'),
                'cta'=>'Open Research Evidence',
            ),
            array(
                'title'=>'Research Lines',
                'description'=>'Author the evidence-backed research agenda, central questions, methods, topics and research status.',
                'metric'=>sprintf('%d EN/ES lines', (int) ($counts['lines'] ?? 0)),
                'url'=>$this->tools_url('eduardo-research-lines'),
                'cta'=>'Open Research Lines',
            ),
            array(
                'title'=>'Research Objects',
                'description'=>'Create and maintain Outputs, Projects, Research Software and Datasets with collection-specific metadata.',
                'metric'=>sprintf('%d EN/ES objects', (int) ($counts['objects'] ?? 0)),
                'url'=>$this->tools_url('eduardo-research-objects'),
                'cta'=>'Open Research Objects',
            ),
            array(
                'title'=>'Research Insights',
                'description'=>'Publish Theme-rendered research notes, explainers and working ideas as controlled editorial content.',
                'metric'=>sprintf('%d EN/ES insights', (int) ($counts['insights'] ?? 0)),
                'url'=>$this->tools_url('eduardo-research-insights'),
                'cta'=>'Open Research Insights',
            ),
            array(
                'title'=>'EN/ES Translations',
                'description'=>'Pair published English and Spanish records so alternate-language navigation and hreflang stay deterministic.',
                'metric'=>sprintf('%d bilateral pairs', (int) ($counts['translations'] ?? 0)),
                'url'=>$this->tools_url('eduardo-research-translations'),
                'cta'=>'Open Research Translations',
            ),
        );
    }

    private function counts(): array {
        $languages = Eduardo_Research_Manager::contract()->languages();
        $counts = array(
            'pages'=>count(Eduardo_Research_Manager::contract()->pages()),
            'evidence'=>0,
            'lines'=>0,
            'objects'=>0,
            'insights'=>0,
            'translations'=>0,
        );

        foreach ($languages as $language) {
            $insights = Eduardo_Research_Manager::insight_editor()->list((string) $language);
            if (is_array($insights)) { $counts['insights'] += count($insights); }
            $lines = Eduardo_Research_Manager::line_editor()->list((string) $language);
            if (is_array($lines)) { $counts['lines'] += count($lines); }
            foreach (array('output','project','software','dataset') as $kind) {
                $objects = Eduardo_Research_Manager::object_editor()->list($kind, (string) $language);
                if (is_array($objects)) { $counts['objects'] += count($objects); }
            }
        }

        foreach (array_keys(Eduardo_Research_Manager::evidence_editor()->groups()) as $group) {
            $records = Eduardo_Research_Manager::evidence_editor()->records((string) $group);
            if (is_array($records)) { $counts['evidence'] += count($records); }
        }

        foreach (array_keys(Eduardo_Research_Manager::translation_editor()->supported_types()) as $post_type) {
            $candidates = Eduardo_Research_Manager::translation_editor()->list_candidates((string) $post_type, 'en');
            if (! is_array($candidates)) { continue; }
            foreach ($candidates as $candidate) {
                if (! empty($candidate['counterpart_exists']) && absint($candidate['counterpart_id'] ?? 0) > 0) { $counts['translations']++; }
            }
        }

        return $counts;
    }

    private function tools_url(string $page): string {
        return add_query_arg('page', $page, admin_url('tools.php'));
    }
}
