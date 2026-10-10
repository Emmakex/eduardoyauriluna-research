<?php
/** Compiles validated Greenfield blueprints into explicit bounded operations. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Blueprint_Compiler {
    private Eduardo_Research_Manager_Blueprint $blueprints;

    public function __construct(?Eduardo_Research_Manager_Blueprint $blueprints = null) {
        $this->blueprints = $blueprints ?: new Eduardo_Research_Manager_Blueprint();
    }

    public function preview(array $blueprint): array|WP_Error {
        $normalized = $this->blueprints->validate($blueprint);
        if (is_wp_error($normalized)) { return $normalized; }

        $operations = array();
        foreach ($normalized['pages'] as $page) {
            if (! is_array($page) || empty($page['key'])) {
                return new WP_Error('research_manager_blueprint_page_invalid', 'Every blueprint Page must declare a Theme page key.');
            }
            $key = sanitize_key((string) $page['key']);
            $language = sanitize_key((string) ($page['language'] ?? $normalized['languages'][0]));
            $state = Eduardo_Research_Manager::pages()->inspect($key, $language);

            if (is_wp_error($state)) {
                if ('research_manager_page_missing' !== $state->get_error_code()) {
                    $operations[] = array('resource'=>'page','key'=>$key,'language'=>$language,'status'=>'blocked','reason'=>$state->get_error_code());
                    continue;
                }
                $plan = Eduardo_Research_Manager::pages()->build_creation_plan($key);
                if (is_wp_error($plan)) {
                    $operations[] = array('resource'=>'page','key'=>$key,'language'=>$language,'status'=>'blocked','reason'=>$plan->get_error_code());
                    continue;
                }
                $operations[] = array('resource'=>'page','key'=>$key,'language'=>$language,'status'=>'create','plan'=>$plan);
                continue;
            }

            $operations[] = array(
                'resource'=>'page',
                'key'=>$key,
                'language'=>$language,
                'status'=>! empty($state['contract_aligned']) ? 'already-matching' : 'blocked',
                'page_id'=>(int) ($state['page_id'] ?? 0),
                'reason'=>! empty($state['contract_aligned']) ? null : 'research_manager_page_contract_drift',
            );
        }

        return array(
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'operations'=>$operations,
            'writes'=>count(array_filter($operations, static fn(array $op): bool => in_array($op['status'], array('create','hydrate'), true))),
            'blocked'=>count(array_filter($operations, static fn(array $op): bool => 'blocked' === $op['status'])),
        );
    }
}
