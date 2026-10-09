<?php
/** Contract-driven readiness remediation plans. */
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

    public function catalog(): array {
        $report = $this->diagnostics->run();
        $items = array();
        foreach ((array) ($report['checks'] ?? array()) as $check) {
            if (! is_array($check)) { continue; }
            $items[] = array_merge($check, $this->classification($check));
        }
        return array(
            'ready'=>(bool) ($report['ready'] ?? false),
            'summary'=>$report['summary'] ?? array(),
            'items'=>$items,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    public function build_plan_for_check(string $check_id, string $intent = ''): array|WP_Error {
        $check = $this->find_check($check_id);
        if (is_wp_error($check)) { return $check; }
        $classification = $this->classification($check);
        if (empty($classification['planable'])) {
            return new WP_Error(
                'research_manager_remediation_not_planable',
                (string) $classification['reason'],
                array('check_id'=>$check_id,'classification'=>$classification['class'] ?? 'manual-review')
            );
        }
        $actions = $this->actions_for_check($check);
        if (is_wp_error($actions)) { return $actions; }
        if (! $actions) { return new WP_Error('research_manager_no_change', 'This readiness check no longer requires remediation.'); }
        $intent = '' !== trim($intent) ? $intent : 'Remediate readiness check: ' . (string) ($check['label'] ?? $check_id);
        return Eduardo_Research_Manager_Plan::create($intent, $actions, $this->contract_evidence_context());
    }

    public function prepare_safe_batch(string $intent = 'Repair deterministic Research readiness issues'): array|WP_Error {
        $report = $this->diagnostics->run();
        $actions = array();
        $included = array();
        $skipped = array();
        $targets = array();

        foreach ((array) ($report['checks'] ?? array()) as $check) {
            if (! is_array($check) || 'pass' === (string) ($check['status'] ?? '')) { continue; }
            $classification = $this->classification($check);
            if (empty($classification['planable'])) {
                $skipped[] = array(
                    'check_id'=>(string) ($check['id'] ?? ''),
                    'label'=>(string) ($check['label'] ?? ''),
                    'class'=>(string) ($classification['class'] ?? 'manual-review'),
                    'reason'=>(string) ($classification['reason'] ?? ''),
                );
                continue;
            }
            $check_actions = $this->actions_for_check($check);
            if (is_wp_error($check_actions)) {
                $skipped[] = array(
                    'check_id'=>(string) ($check['id'] ?? ''),
                    'label'=>(string) ($check['label'] ?? ''),
                    'class'=>'manual-review',
                    'reason'=>$check_actions->get_error_message(),
                );
                continue;
            }
            foreach ($check_actions as $action) {
                $signature = $this->action_signature($action);
                if (isset($targets[$signature])) { continue; }
                $targets[$signature] = true;
                $actions[] = $action;
            }
            if ($check_actions) { $included[] = (string) ($check['id'] ?? ''); }
        }

        if (! $actions) {
            return array(
                'plan'=>null,
                'included'=>$included,
                'skipped'=>$skipped,
                'message'=>'No deterministic readiness mutations are currently required.',
            );
        }
        $plan = Eduardo_Research_Manager_Plan::create($intent, $actions, $this->contract_evidence_context());
        if (is_wp_error($plan)) { return $plan; }
        return array('plan'=>$plan,'included'=>$included,'skipped'=>$skipped,'message'=>'Safe readiness batch prepared.');
    }

    public function verify_after_apply(array $expected_check_ids = array()): array {
        $report = $this->diagnostics->run();
        $checks = array();
        $verified = true;
        foreach ((array) ($report['checks'] ?? array()) as $check) {
            if (! is_array($check)) { continue; }
            $id = (string) ($check['id'] ?? '');
            if ($expected_check_ids && ! in_array($id, $expected_check_ids, true)) { continue; }
            $pass = 'pass' === (string) ($check['status'] ?? '');
            $checks[$id] = array('pass'=>$pass,'status'=>$check['status'] ?? '','message'=>$check['message'] ?? '');
            $verified = $verified && $pass;
        }
        return array('verified'=>$verified,'checks'=>$checks,'report'=>$report,'verified_at'=>gmdate(DATE_W3C));
    }

    private function find_check(string $check_id): array|WP_Error {
        $check_id = sanitize_key($check_id);
        foreach ((array) ($this->diagnostics->run()['checks'] ?? array()) as $check) {
            if (is_array($check) && $check_id === (string) ($check['id'] ?? '')) { return $check; }
        }
        return new WP_Error('research_manager_unknown_readiness_check', 'The requested readiness check is not present in the current diagnostic report.');
    }

    private function classification(array $check): array {
        if ('pass' === (string) ($check['status'] ?? '')) {
            return array('class'=>'none','planable'=>false,'reason'=>'Check already passes.');
        }
        $id = (string) ($check['id'] ?? '');
        $resource = (string) ($check['resource'] ?? '');
        $next = (string) ($check['next_action'] ?? '');

        if ('front-page' === $id) {
            return array(
                'class'=>'manual-review','planable'=>false,
                'reason'=>'Static front-page routing is a WordPress core routing setting. This release reports the drift but does not open generic core-option mutation.'
            );
        }

        if (str_starts_with($resource, 'page:')) {
            $key = substr($resource, 5);
            $state = $this->contract->page_state($key);
            if (empty($state['exists'])) {
                return array('class'=>'create-resource','planable'=>true,'reason'=>'Missing Theme-owned Page can be recreated exactly from the active preset contract.');
            }
            if ('publish' !== (string) ($state['status'] ?? '')) {
                return array('class'=>'manual-review','planable'=>false,'reason'=>'The Page exists but is not published. The Manager will not change publication state automatically.');
            }
            if (str_starts_with($id, 'page-')) {
                return array('class'=>'auto-fix','planable'=>true,'reason'=>'Role/model drift can be repaired from the immutable preset contract.');
            }
        }
        if ('language-contract' === $id) {
            return array('class'=>'auto-fix','planable'=>true,'reason'=>'Native language configuration can be restored from the active preset language contract.');
        }
        if ('manual-review' === $next || 'evidence-store' === $id) {
            return array('class'=>'manual-review','planable'=>false,'reason'=>'This condition cannot be repaired safely without inspecting the underlying data.');
        }
        return array('class'=>'configuration-required','planable'=>false,'reason'=>'This condition requires a Theme/plugin/environment configuration change rather than a bounded content mutation.');
    }

    private function actions_for_check(array $check): array|WP_Error {
        $id = (string) ($check['id'] ?? '');
        $resource = (string) ($check['resource'] ?? '');
        if ('front-page' === $id) {
            return new WP_Error('research_manager_manual_review_required', 'Front-page routing is intentionally outside the generic remediation mutation contract.');
        }
        if (str_starts_with($resource, 'page:')) {
            $key = substr($resource, 5);
            $state = $this->contract->page_state($key);
            if (empty($state['exists'])) {
                $plan = $this->pages->build_creation_plan($key, 'Readiness remediation: create ' . $key);
                if (is_wp_error($plan)) { return $plan; }
                return (array) $plan['actions'];
            }
            if ('publish' !== (string) ($state['status'] ?? '')) {
                return new WP_Error('research_manager_manual_review_required', 'Existing unpublished Pages require manual review before publication.');
            }
            $contract = is_array($state['contract'] ?? null) ? $state['contract'] : array();
            $actions = array();
            if ((string) ($state['role'] ?? '') !== (string) ($contract['role'] ?? '')) {
                $actions[] = array('type'=>'post_meta','post_id'=>(int) $state['id'],'key'=>'_eduardo_research_role','value'=>(string) ($contract['role'] ?? ''));
            }
            if ((string) ($state['model'] ?? '') !== (string) ($contract['model'] ?? '')) {
                $actions[] = array('type'=>'post_meta','post_id'=>(int) $state['id'],'key'=>'_eduardo_research_model','value'=>(string) ($contract['model'] ?? ''));
            }
            return $actions;
        }
        if ('language-contract' === $id) {
            $languages = $this->contract->languages();
            $preset = $this->contract->preset();
            $default = sanitize_key((string) ($preset['default_language'] ?? ($languages[0] ?? 'en')));
            return array(array('type'=>'option','key'=>'eduardo_research_native_languages','value'=>array('default'=>$default,'enabled'=>$languages)));
        }
        return new WP_Error('research_manager_remediation_not_planable', 'This readiness condition does not have a safe mutation recipe.');
    }

    private function contract_evidence_context(): array {
        $preset = $this->contract->preset();
        $id = sanitize_key((string) ($preset['id'] ?? 'research'));
        $version = (int) ($preset['version'] ?? 0);
        return array(
            'evidence_confirmed'=>true,
            'evidence_reference'=>sprintf('Active Research Theme preset contract: %s v%d', $id, $version),
        );
    }

    private function action_signature(array $action): string {
        $type = (string) ($action['type'] ?? '');
        if ('option' === $type) { return 'option:' . (string) ($action['key'] ?? ''); }
        if ('post_meta' === $type) { return 'post_meta:' . (int) ($action['post_id'] ?? 0) . ':' . (string) ($action['key'] ?? ''); }
        if ('create_page' === $type) { return 'create_page:' . (string) ($action['wp_slug'] ?? ''); }
        return $type . ':' . md5((string) wp_json_encode($action));
    }
}
