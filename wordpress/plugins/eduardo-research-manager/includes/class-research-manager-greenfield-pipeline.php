<?php
/** Atomic Greenfield site pipeline across creation, pairing, hydration and relations. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Greenfield_Pipeline {
    private Eduardo_Research_Manager_Bootstrap $bootstrap;
    private Eduardo_Research_Manager_Blueprint_Pairing $pairing;
    private Eduardo_Research_Manager_Blueprint_Hydrator $hydrator;
    private Eduardo_Research_Manager_Blueprint_Relations $relations;

    public function __construct(
        ?Eduardo_Research_Manager_Bootstrap $bootstrap = null,
        ?Eduardo_Research_Manager_Blueprint_Pairing $pairing = null,
        ?Eduardo_Research_Manager_Blueprint_Hydrator $hydrator = null,
        ?Eduardo_Research_Manager_Blueprint_Relations $relations = null
    ) {
        $this->bootstrap = $bootstrap ?: Eduardo_Research_Manager::bootstrap();
        $this->pairing = $pairing ?: Eduardo_Research_Manager::blueprint_pairing();
        $this->hydrator = $hydrator ?: Eduardo_Research_Manager::hydrator();
        $this->relations = $relations ?: Eduardo_Research_Manager::blueprint_relations();
    }

    public function preview(array $blueprint): array|WP_Error {
        if (! Eduardo_Research_Manager_Mode::is_greenfield()) {
            return new WP_Error('research_manager_greenfield_mode_required', 'The Greenfield site pipeline requires Greenfield mode.');
        }

        $bootstrap = $this->bootstrap->preview($blueprint);
        if (is_wp_error($bootstrap)) { return $bootstrap; }
        $hydration = $this->hydrator->preview($blueprint);
        if (is_wp_error($hydration)) { return $hydration; }

        $has_bootstrap_changes = (int) ($bootstrap['operation_count'] ?? 0) > 0;
        if ($has_bootstrap_changes) {
            $pairing = $this->deferred('bootstrap-required', 'Research Line pairing is previewed after bootstrap creates missing resources.');
            $relations = $this->deferred('bootstrap-required', 'Research Line relations are previewed after bootstrap creates missing resources.');
        } else {
            $pairing = $this->pairing->preview($blueprint);
            if (is_wp_error($pairing)) { return $pairing; }
            $relations = $this->relations->preview($blueprint);
            if (is_wp_error($relations)) { return $relations; }
        }

        $apply_allowed = ! empty($bootstrap['apply_allowed'])
            && ! empty($hydration['apply_allowed'])
            && ('deferred' === (string) ($pairing['status'] ?? '') || ! empty($pairing['apply_allowed']))
            && ('deferred' === (string) ($relations['status'] ?? '') || ! empty($relations['apply_allowed']));

        return array(
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'apply_allowed'=>$apply_allowed,
            'phases'=>array(
                'bootstrap'=>$bootstrap,
                'pairing'=>$pairing,
                'hydration'=>$hydration,
                'relations'=>$relations,
            ),
            'requires_legacy_discovery'=>false,
            'requires_legacy_mapping'=>false,
            'requires_gutenberg_layout'=>false,
        );
    }

    public function apply(array $blueprint): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply the Greenfield site pipeline.');
        }
        if (! Eduardo_Research_Manager_Mode::is_greenfield()) {
            return new WP_Error('research_manager_greenfield_mode_required', 'The Greenfield site pipeline requires Greenfield mode.');
        }

        $completed = array();
        $bootstrap = $this->bootstrap->apply($blueprint);
        if (is_wp_error($bootstrap)) { return $this->failure('bootstrap', $bootstrap, $completed); }
        $completed['bootstrap'] = $bootstrap;

        $pairing = $this->pairing->apply($blueprint);
        if (is_wp_error($pairing)) { return $this->failure('pairing', $pairing, $completed); }
        $completed['pairing'] = $pairing;

        $hydration = $this->hydrator->apply($blueprint);
        if (is_wp_error($hydration)) { return $this->failure('hydration', $hydration, $completed); }
        $completed['hydration'] = $hydration;

        $relations = $this->relations->apply($blueprint);
        if (is_wp_error($relations)) { return $this->failure('relations', $relations, $completed); }
        $completed['relations'] = $relations;

        $snapshots = $this->snapshots($completed);
        return array(
            'status'=>'applied',
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'verified'=>true,
            'phases'=>$completed,
            'snapshots'=>$snapshots,
            'snapshot_count'=>array_sum(array_map('count', $snapshots)),
            'applied_at'=>gmdate(DATE_W3C),
        );
    }

    public function rollback(array $snapshots): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback the Greenfield site pipeline.');
        }
        $results = array();
        foreach (array('relations','hydration','pairing','bootstrap') as $phase) {
            $ids = is_array($snapshots[$phase] ?? null) ? $snapshots[$phase] : array();
            if (! $ids) {
                $results[$phase] = array('status'=>'noop','snapshot_count'=>0);
                continue;
            }
            $service = $this->phase_service($phase);
            $result = $service->rollback($ids);
            if (is_wp_error($result)) {
                return new WP_Error('research_manager_pipeline_rollback_failed', $result->get_error_message(), array('phase'=>$phase,'results'=>$results));
            }
            $results[$phase] = $result;
        }
        return array(
            'status'=>'rolled-back',
            'phases'=>$results,
            'snapshot_count'=>array_sum(array_map(static fn(array $row): int => (int) ($row['snapshot_count'] ?? 0), $results)),
            'rolled_back_at'=>gmdate(DATE_W3C),
        );
    }

    private function failure(string $phase, WP_Error $error, array $completed): WP_Error {
        $snapshots = $this->snapshots($completed);
        $rollback = $this->rollback_completed($snapshots);
        return new WP_Error(
            'research_manager_greenfield_pipeline_failed',
            $error->get_error_message(),
            array(
                'failed_phase'=>$phase,
                'source_code'=>$error->get_error_code(),
                'rollback'=>$rollback,
                'snapshots'=>$snapshots,
            )
        );
    }

    private function rollback_completed(array $snapshots): array {
        $results = array();
        foreach (array('relations','hydration','pairing','bootstrap') as $phase) {
            $ids = is_array($snapshots[$phase] ?? null) ? $snapshots[$phase] : array();
            if (! $ids) { continue; }
            $service = $this->phase_service($phase);
            $result = $service->rollback($ids);
            $results[$phase] = is_wp_error($result)
                ? array('rolled_back'=>false,'error'=>$result->get_error_message())
                : array('rolled_back'=>true,'snapshot_count'=>(int) ($result['snapshot_count'] ?? 0));
        }
        return $results;
    }

    private function snapshots(array $phases): array {
        $snapshots = array();
        foreach (array('bootstrap','pairing','hydration','relations') as $phase) {
            $snapshots[$phase] = array_values(array_filter(array_map('strval', is_array($phases[$phase]['snapshot_ids'] ?? null) ? $phases[$phase]['snapshot_ids'] : array())));
        }
        return $snapshots;
    }

    private function phase_service(string $phase): object {
        return match ($phase) {
            'bootstrap'=>$this->bootstrap,
            'pairing'=>$this->pairing,
            'hydration'=>$this->hydrator,
            'relations'=>$this->relations,
            default=>throw new LogicException('Unsupported Greenfield pipeline phase.'),
        };
    }

    private function deferred(string $reason, string $message): array {
        return array('status'=>'deferred','reason'=>$reason,'message'=>$message,'apply_allowed'=>true,'operation_count'=>0,'operations'=>array());
    }
}
