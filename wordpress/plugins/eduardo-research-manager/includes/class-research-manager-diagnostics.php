<?php
/** Actionable Research Manager diagnostics. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Diagnostics {
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    private function check(string $id, string $label, string $status, string $message, string $next_action = '', string $resource = ''): array {
        return compact('id','label','status','message','next_action','resource');
    }

    public function run(): array {
        $checks = array();

        if (! $this->contract->theme_available()) {
            $checks[] = $this->check(
                'theme-contract','Research Theme contract','fail',
                'The Research Theme contract is not active. The Manager remains installed but cannot mutate Research resources safely.',
                'configuration-required','theme'
            );
            return $this->report($checks);
        }

        $preset = $this->contract->preset();
        $checks[] = $this->check(
            'theme-contract','Research Theme contract',$this->contract->compatible() ? 'pass' : 'fail',
            $this->contract->compatible()
                ? sprintf('Research preset v%d is active and the Theme owns frontend rendering.', (int) ($preset['version'] ?? 0))
                : 'The active Theme contract is not compatible with this Manager foundation.',
            $this->contract->compatible() ? '' : 'configuration-required','theme'
        );

        foreach ($this->contract->pages() as $key => $page_contract) {
            $state = $this->contract->page_state((string) $key);
            $expected_role = (string) ($page_contract['role'] ?? '');
            $expected_model = (string) ($page_contract['model'] ?? '');
            if (! $state['exists']) {
                $checks[] = $this->check(
                    'page-' . sanitize_key((string) $key),
                    (string) ($page_contract['label'] ?? ucfirst((string) $key)),
                    'fail','Required Theme-controlled resource is missing.','create-resource','page:' . (string) $key
                );
                continue;
            }

            if ('publish' !== (string) ($state['status'] ?? '')) {
                $checks[] = $this->check(
                    'page-' . sanitize_key((string) $key),
                    (string) ($page_contract['label'] ?? ucfirst((string) $key)),
                    'warning',
                    'Theme-controlled Page exists but is not published. Publication state requires explicit human review.',
                    'manual-review','page:' . (string) $key
                );
                continue;
            }

            $aligned = $expected_role === (string) ($state['role'] ?? '')
                && $expected_model === (string) ($state['model'] ?? '');
            $checks[] = $this->check(
                'page-' . sanitize_key((string) $key),
                (string) ($page_contract['label'] ?? ucfirst((string) $key)),
                $aligned ? 'pass' : 'warning',
                $aligned
                    ? 'Theme-controlled page exists with the expected role and model.'
                    : 'Page is published but its role or model does not match the Research preset contract.',
                $aligned ? '' : 'auto-fix-candidate','page:' . (string) $key
            );
        }

        foreach ($this->contract->research_post_types() as $post_type) {
            $exists = post_type_exists($post_type);
            $checks[] = $this->check(
                'post-type-' . sanitize_key($post_type),ucwords(str_replace('_', ' ', $post_type)),
                $exists ? 'pass' : 'fail',
                $exists ? 'Structured Research resource type is registered.' : 'Structured Research resource type is unavailable.',
                $exists ? '' : 'configuration-required','post-type:' . $post_type
            );
        }

        $native_languages = get_option('eduardo_research_native_languages', array());
        $enabled = is_array($native_languages) && is_array($native_languages['enabled'] ?? null)
            ? array_values(array_map('sanitize_key', $native_languages['enabled']))
            : array();
        $expected_languages = $this->contract->languages();
        $expected_default = sanitize_key((string) ($preset['default_language'] ?? ($expected_languages[0] ?? 'en')));
        $stored_default = is_array($native_languages) ? sanitize_key((string) ($native_languages['default'] ?? '')) : '';
        $language_ok = ! array_diff($expected_languages, $enabled)
            && ! array_diff($enabled, $expected_languages)
            && $expected_default === $stored_default;
        $checks[] = $this->check(
            'language-contract','Language contract',$language_ok ? 'pass' : 'warning',
            $language_ok
                ? 'Configured languages match the Research preset.'
                : 'Native language configuration differs from the Research preset and can be restored deterministically.',
            $language_ok ? '' : 'auto-fix-candidate','languages'
        );

        $home_id = $this->contract->page_id('home');
        $front_ok = $home_id > 0 && 'page' === get_option('show_on_front') && $home_id === (int) get_option('page_on_front');
        $checks[] = $this->check(
            'front-page','Research Home routing',$front_ok ? 'pass' : 'warning',
            $front_ok
                ? 'Research Home is assigned as the static front page.'
                : 'The Research Home resource is not the active static front page. Core routing is not changed automatically by the remediation batch.',
            $front_ok ? '' : 'manual-review','page:home'
        );

        $evidence = get_option('eduardo_research_evidence', array());
        $evidence_ok = is_array($evidence);
        $checks[] = $this->check(
            'evidence-store','Research evidence store',$evidence_ok ? 'pass' : 'fail',
            $evidence_ok ? 'Evidence store is readable and structured.' : 'Evidence store has an invalid shape.',
            $evidence_ok ? '' : 'manual-review','evidence'
        );

        return $this->report($checks);
    }

    private function report(array $checks): array {
        $summary = array('pass'=>0,'warning'=>0,'fail'=>0);
        $next_actions = array();
        foreach ($checks as $check) {
            $status = isset($summary[$check['status']]) ? $check['status'] : 'warning';
            $summary[$status]++;
            if ('' !== (string) ($check['next_action'] ?? '')) {
                $next_actions[] = array(
                    'check_id'=>$check['id'],'action'=>$check['next_action'],'resource'=>$check['resource'],'label'=>$check['label'],
                );
            }
        }
        return array(
            'ready'=>0 === $summary['fail'],
            'summary'=>$summary,'checks'=>$checks,'next_actions'=>$next_actions,'generated_at'=>gmdate(DATE_W3C),
        );
    }
}
