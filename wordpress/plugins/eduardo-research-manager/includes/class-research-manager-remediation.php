<?php
/** Safe diagnostics-to-plan remediation for Research site readiness. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remediation {
    private Eduardo_Research_Manager_Contract $contract;
    private Eduardo_Research_Manager_Diagnostics $diagnostics;
    private Eduardo_Research_Manager_Page_Resource $pages;

    public function __construct(
        ?Eduardo_Research_Manager_Contract $contract = null,
        ?Eduardo_Research_Manager_Diagnostics $diagnostics = null,
        ?Eduardo_Research_Manager_Page_Resource $pages = null
    ) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
        $this->diagnostics = $diagnostics ?: new Eduardo_Research_Manager_Diagnostics($this->contract);
        $this->pages = $pages ?: new Eduardo_Research_Manager_Page_Resource($this->contract);
    }

    public function inspect(): array {
        $report = $this->diagnostics->run();
        $items = array();
        foreach ((array) ($report['checks'] ?? array()) as $check) {
            if (! is_array($check) || 'pass' === (string) ($check['status'] ?? '')) { continue; }
            $check_id = (string) ($check['id'] ?? '');
            $items[] = array(
                'check_id'=>$check_id,
                'label'=>(string) ($check['label'] ?? $check_id),
                'status'=>(string) ($check['status'] ?? 'warning'),
                'resource'=>(string) ($check['resource'] ?? ''),
                'next_action'=>(string) ($check['next_action'] ?? ''),
                'auto_remediable'=>$this->is_auto_remediable($check_id, (string) ($check['resource'] ?? '')),
            );
        }
        return array(
            'ready'=>! empty($report['ready']),
            'items'=>$items,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    public function build_plan(string $check_id, string $intent = ''): array|WP_Error {
        $check_id = sanitize_key($check_id);
        $check = $this->find_check($check_id);
        if (is_wp_error($check)) { return $check; }
        if ('pass' === (string) ($check['status'] ?? '')) {
            return new WP_Error('research_manager_remediation_not_needed', 'This readiness check already passes.');
        }

        $resource = (string) ($check['resource'] ?? '');
        if (str_starts_with($check_id, 'page-') && str_starts_with($resource, 'page:')) {
            $key = sanitize_key(substr($resource, strlen('page:')));
            return $this->build_page_plan($key, $intent);
        }
        if ('front-page' === $check_id) {
            return $this->build_front_page_plan($intent);
        }
        if ('language-contract' === $check_id) {
            return $this->build_language_plan($intent);
        }

        return new WP_Error(
            'research_manager_remediation_manual_only',
            'This readiness finding requires manual configuration or evidence review and cannot be auto-remediated safely.'
        );
    }

    public function verify(string $check_id): array|WP_Error {
        $check_id = sanitize_key($check_id);
        $report = $this->diagnostics->run();
        foreach ((array) ($report['checks'] ?? array()) as $check) {
            if (! is_array($check) || $check_id !== (string) ($check['id'] ?? '')) { continue; }
            return array(
                'verified'=>'pass' === (string) ($check['status'] ?? ''),
                'check_id'=>$check_id,
                'status'=>(string) ($check['status'] ?? 'warning'),
                'message'=>(string) ($check['message'] ?? ''),
                'verified_at'=>gmdate(DATE_W3C),
            );
        }
        return new WP_Error('research_manager_remediation_check_missing', 'The requested readiness check is not exposed by current diagnostics.');
    }

    private function build_page_plan(string $key, string $intent): array|WP_Error {
        if (! isset($this->contract->pages()[$key])) {
            return new WP_Error('research_manager_remediation_page_unknown', 'The readiness finding does not map to a Theme-controlled Page contract.');
        }
        $state = $this->contract->page_state($key);
        if (empty($state['exists'])) {
            return $this->pages->build_creation_plan(
                $key,
                '' !== trim($intent) ? $intent : sprintf('Restore missing Theme-owned %s Page', $key)
            );
        }

        $definition = is_array($state['contract'] ?? null) ? $state['contract'] : array();
        $page_id = (int) ($state['id'] ?? 0);
        if ($page_id <= 0) { return new WP_Error('research_manager_remediation_page_missing', 'The Theme-controlled Page cannot be resolved safely.'); }

        $actions = array();
        if ('publish' !== (string) ($state['status'] ?? '')) {
            $actions[] = array('type'=>'post_field','post_id'=>$page_id,'field'=>'post_status','value'=>'publish');
        }
        $expected_role = sanitize_key((string) ($definition['role'] ?? ''));
        if ($expected_role !== (string) ($state['role'] ?? '')) {
            $actions[] = array('type'=>'post_meta','post_id'=>$page_id,'key'=>'_eduardo_research_role','value'=>$expected_role);
        }
        $expected_model = sanitize_key((string) ($definition['model'] ?? ''));
        if ($expected_model !== (string) ($state['model'] ?? '')) {
            $actions[] = array('type'=>'post_meta','post_id'=>$page_id,'key'=>'_eduardo_research_model','value'=>$expected_model);
        }
        if (! $actions) {
            return new WP_Error('research_manager_remediation_not_needed', 'The Theme-controlled Page already matches the structural contract.');
        }

        return Eduardo_Research_Manager_Plan::create(
            '' !== trim($intent) ? $intent : sprintf('Repair Theme-owned %s Page contract', $key),
            $actions
        );
    }

    private function build_front_page_plan(string $intent): array|WP_Error {
        $home_id = $this->contract->page_id('home');
        if ($home_id <= 0) {
            return new WP_Error('research_manager_remediation_home_missing', 'Research Home must exist before static-front-page routing can be repaired.');
        }
        $actions = array();
        if ('page' !== (string) get_option('show_on_front', 'posts')) {
            $actions[] = array('type'=>'option','key'=>'show_on_front','value'=>'page');
        }
        if ($home_id !== (int) get_option('page_on_front', 0)) {
            $actions[] = array('type'=>'option','key'=>'page_on_front','value'=>$home_id);
        }
        if (! $actions) { return new WP_Error('research_manager_remediation_not_needed', 'Research Home is already the static front page.'); }
        return Eduardo_Research_Manager_Plan::create(
            '' !== trim($intent) ? $intent : 'Repair Research Home static-front-page routing',
            $actions
        );
    }

    private function build_language_plan(string $intent): array|WP_Error {
        $expected = array('default'=>'en','enabled'=>$this->contract->languages());
        $stored = get_option('eduardo_research_native_languages', array());
        if (maybe_serialize($stored) === maybe_serialize($expected)) {
            return new WP_Error('research_manager_remediation_not_needed', 'Native language configuration already matches the Research preset.');
        }
        return Eduardo_Research_Manager_Plan::create(
            '' !== trim($intent) ? $intent : 'Repair native Research language configuration',
            array(array('type'=>'option','key'=>'eduardo_research_native_languages','value'=>$expected))
        );
    }

    private function find_check(string $check_id): array|WP_Error {
        foreach ((array) ($this->diagnostics->run()['checks'] ?? array()) as $check) {
            if (is_array($check) && $check_id === (string) ($check['id'] ?? '')) { return $check; }
        }
        return new WP_Error('research_manager_remediation_check_missing', 'The requested readiness check is not exposed by current diagnostics.');
    }

    private function is_auto_remediable(string $check_id, string $resource): bool {
        if ('front-page' === $check_id || 'language-contract' === $check_id) { return true; }
        return str_starts_with($check_id, 'page-') && str_starts_with($resource, 'page:');
    }
}
