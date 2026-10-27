<?php
/** Aggregate SEO/GEO readiness and residual-action report for ChatGPT-governed operation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_SEO_GEO_Readiness {
    private Eduardo_Research_Manager_SEO_GEO $seo_geo;
    private Eduardo_Research_Manager_SEO_GEO_Action_Planner $planner;
    private Eduardo_Research_Manager_SEO_GEO_Indexability $indexability;

    public function __construct(
        ?Eduardo_Research_Manager_SEO_GEO $seo_geo = null,
        ?Eduardo_Research_Manager_SEO_GEO_Action_Planner $planner = null,
        ?Eduardo_Research_Manager_SEO_GEO_Indexability $indexability = null
    ) {
        $this->seo_geo = $seo_geo ?: new Eduardo_Research_Manager_SEO_GEO();
        $this->planner = $planner ?: new Eduardo_Research_Manager_SEO_GEO_Action_Planner($this->seo_geo);
        $this->indexability = $indexability ?: new Eduardo_Research_Manager_SEO_GEO_Indexability($this->seo_geo);
    }

    /**
     * Build one bounded operating report from the existing M6 services.
     * This service is read-only: residual actions are descriptors for existing gateways only.
     */
    public function report(int $per_type_limit = 1): array {
        $per_type_limit = max(1, min(5, $per_type_limit));
        $site = $this->seo_geo->site();
        $indexability = $this->indexability->site();
        $internal_links = $this->indexability->internal_links(200);
        $targets = $this->representative_targets($per_type_limit);

        $resources = array();
        $resource_summary = array('pass'=>0,'fail'=>0,'advisory'=>0,'not_applicable'=>0,'errors'=>0);
        $actions = array();

        foreach ($targets as $target) {
            $plan = $this->planner->plan(
                (string) $target['type'],
                $target['identifier'],
                (string) ($target['language'] ?? '')
            );
            if (is_wp_error($plan)) {
                $resource_summary['errors']++;
                $resources[] = array(
                    'target'=>$target,
                    'ready'=>false,
                    'error_code'=>$plan->get_error_code(),
                    'error'=>$plan->get_error_message(),
                );
                continue;
            }

            $resources[] = array(
                'target'=>$target,
                'ready'=>! empty($plan['inspection_ready']),
                'summary'=>is_array($plan['summary'] ?? null) ? $plan['summary'] : array(),
                'checks'=>is_array($plan['checks'] ?? null) ? $plan['checks'] : array(),
            );
            foreach ((array) ($plan['summary'] ?? array()) as $status => $count) {
                if (isset($resource_summary[$status])) { $resource_summary[$status] += (int) $count; }
            }
            foreach ((array) ($plan['checks'] ?? array()) as $check) {
                if (! is_array($check) || 'pass' === (string) ($check['status'] ?? '')) { continue; }
                $action = is_array($check['action'] ?? null) ? $check['action'] : array();
                $actions[] = array(
                    'source'=>'resource',
                    'status'=>(string) ($check['status'] ?? 'advisory'),
                    'check_id'=>(string) ($check['check_id'] ?? ''),
                    'dimension'=>(string) ($check['dimension'] ?? ''),
                    'target'=>$target,
                    'reason'=>(string) ($check['reason'] ?? ''),
                    'action'=>$action,
                );
            }
        }

        foreach ((array) ($site['remediation']['auto_remediable'] ?? array()) as $item) {
            if (! is_array($item)) { continue; }
            $actions[] = array(
                'source'=>'site-diagnostics',
                'status'=>(string) ($item['status'] ?? 'fail'),
                'check_id'=>(string) ($item['check_id'] ?? ''),
                'dimension'=>'seo',
                'target'=>array('resource'=>(string) ($item['resource'] ?? 'site')),
                'reason'=>(string) ($item['label'] ?? ''),
                'action'=>array(
                    'gateway'=>'seo-geo',
                    'operation'=>'search-indexability' === (string) ($item['check_id'] ?? '') ? 'search-indexability' : 'seo-remediate',
                    'target'=>array('check_id'=>(string) ($item['check_id'] ?? '')),
                    'required_input'=>array(),
                    'requires_evidence'=>false,
                    'auto_apply'=>false,
                ),
            );
        }
        foreach ((array) ($site['remediation']['manual_or_evidence'] ?? array()) as $item) {
            if (! is_array($item)) { continue; }
            $actions[] = array(
                'source'=>'site-diagnostics',
                'status'=>(string) ($item['status'] ?? 'warning'),
                'check_id'=>(string) ($item['check_id'] ?? ''),
                'dimension'=>'seo',
                'target'=>array('resource'=>(string) ($item['resource'] ?? 'site')),
                'reason'=>(string) ($item['label'] ?? ''),
                'action'=>array(),
            );
        }

        foreach ((array) ($internal_links['records'] ?? array()) as $record) {
            if (! is_array($record) || 'advisory' !== (string) ($record['status'] ?? '')) { continue; }
            $actions[] = array(
                'source'=>'internal-links',
                'status'=>'advisory',
                'check_id'=>'research-entity-graph',
                'dimension'=>'geo',
                'target'=>array(
                    'kind'=>(string) ($record['kind'] ?? ''),
                    'post_id'=>absint($record['post_id'] ?? 0),
                ),
                'reason'=>'Managed public Research resource has no verified Research Line relationship.',
                'action'=>is_array($record['action'] ?? null) ? $record['action'] : array(),
            );
        }

        $actions = $this->deduplicate_actions($actions);
        $residual = $this->classify_actions($actions);
        $blocking = $this->blocking_findings($site, $indexability, $resources);
        $advisory_count = $resource_summary['advisory'] + (int) ($internal_links['summary']['research_graph_unlinked'] ?? 0);
        $ready = ! empty($site['ready'])
            && ! empty($indexability['ready'])
            && 0 === $resource_summary['fail']
            && 0 === $resource_summary['errors'];

        return array(
            'version'=>1,
            'ready'=>$ready,
            'optimization_complete'=>$ready && 0 === $advisory_count && 0 === (int) ($residual['summary']['total'] ?? 0),
            'site'=>array(
                'ready'=>! empty($site['ready']),
                'summary'=>is_array($site['summary'] ?? null) ? $site['summary'] : array(),
                'resource_counts'=>is_array($site['resource_counts'] ?? null) ? $site['resource_counts'] : array(),
            ),
            'indexability'=>$indexability,
            'internal_links'=>array(
                'summary'=>is_array($internal_links['summary'] ?? null) ? $internal_links['summary'] : array(),
                'remediation_model'=>(string) ($internal_links['remediation_model'] ?? ''),
                'direct_html_link_mutation'=>! empty($internal_links['direct_html_link_mutation']),
            ),
            'representative'=>array(
                'selection'=>$targets,
                'summary'=>$resource_summary,
                'resources'=>$resources,
                'per_type_limit'=>$per_type_limit,
            ),
            'blocking_findings'=>$blocking,
            'blocking_count'=>count($blocking),
            'advisory_count'=>$advisory_count,
            'residual_actions'=>$residual,
            'operating_rule'=>'Fix through the recommended bounded Manager gateway, then rerun this report. Never write SEO metadata, robots directives or HTML links directly.',
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function representative_targets(int $per_type_limit): array {
        $targets = array();
        if (isset(Eduardo_Research_Manager::contract()->pages()['home'])) {
            foreach (Eduardo_Research_Manager::contract()->languages() as $language) {
                $targets[] = array('type'=>'page','identifier'=>'home','language'=>(string) $language);
            }
        }

        $specs = array(
            'insight'=>array('post_type'=>'post','meta_key'=>'_research_insight_type'),
        );
        foreach (Eduardo_Research_Manager_Resource_Registry::all() as $type => $spec) {
            $specs[$type] = array('post_type'=>(string) ($spec['post_type'] ?? ''));
        }
        foreach ($specs as $type => $spec) {
            $args = array(
                'post_type'=>$spec['post_type'],
                'post_status'=>array('publish','draft','future','private','pending'),
                'posts_per_page'=>$per_type_limit,
                'orderby'=>'modified',
                'order'=>'DESC',
                'fields'=>'ids',
                'suppress_filters'=>true,
            );
            if (isset($spec['meta_key'])) {
                $args['meta_query'] = array(array('key'=>$spec['meta_key'],'compare'=>'EXISTS'));
            }
            foreach (get_posts($args) as $post_id) {
                $post_id = absint($post_id);
                if ($post_id <= 0) { continue; }
                $targets[] = array(
                    'type'=>$type,
                    'identifier'=>$post_id,
                    'language'=>function_exists('eduardo_research_post_language') ? (string) eduardo_research_post_language($post_id) : '',
                );
            }
        }
        return $targets;
    }

    private function blocking_findings(array $site, array $indexability, array $resources): array {
        $findings = array();
        foreach ((array) ($site['findings'] ?? array()) as $finding) {
            if (! is_array($finding) || 'fail' !== (string) ($finding['status'] ?? '')) { continue; }
            $findings[] = array(
                'source'=>'site-diagnostics',
                'check_id'=>(string) ($finding['id'] ?? ''),
                'message'=>(string) ($finding['message'] ?? ''),
                'resource'=>(string) ($finding['resource'] ?? ''),
            );
        }
        if (empty($indexability['ready'])) {
            $findings[] = array(
                'source'=>'indexability',
                'check_id'=>'indexability-policy',
                'message'=>'Global search visibility, sitemap availability or Theme robots policy is not ready.',
                'resource'=>'site:indexability',
            );
        }
        foreach ($resources as $resource) {
            if (! is_array($resource)) { continue; }
            if (! empty($resource['error_code'])) {
                $findings[] = array(
                    'source'=>'representative-resource',
                    'check_id'=>'inspection-error',
                    'message'=>(string) ($resource['error'] ?? ''),
                    'resource'=>wp_json_encode($resource['target'] ?? array()),
                );
                continue;
            }
            foreach ((array) ($resource['checks'] ?? array()) as $check) {
                if (! is_array($check) || 'fail' !== (string) ($check['status'] ?? '')) { continue; }
                $findings[] = array(
                    'source'=>'representative-resource',
                    'check_id'=>(string) ($check['check_id'] ?? ''),
                    'message'=>(string) ($check['reason'] ?? ''),
                    'resource'=>wp_json_encode($resource['target'] ?? array()),
                );
            }
        }
        return $findings;
    }

    private function classify_actions(array $actions): array {
        $groups = array('safe_remediation'=>array(),'structured_input'=>array(),'evidence_required'=>array(),'manual_review'=>array());
        foreach ($actions as $item) {
            $action = is_array($item['action'] ?? null) ? $item['action'] : array();
            if (! $action) {
                $groups['manual_review'][] = $item;
                continue;
            }
            if (! empty($action['requires_evidence'])) {
                $groups['evidence_required'][] = $item;
                continue;
            }
            $required = is_array($action['required_input'] ?? null) ? array_values($action['required_input']) : array();
            if ($required) {
                $groups['structured_input'][] = $item;
                continue;
            }
            $groups['safe_remediation'][] = $item;
        }
        $groups['summary'] = array(
            'safe_remediation'=>count($groups['safe_remediation']),
            'structured_input'=>count($groups['structured_input']),
            'evidence_required'=>count($groups['evidence_required']),
            'manual_review'=>count($groups['manual_review']),
            'total'=>count($actions),
        );
        return $groups;
    }

    private function deduplicate_actions(array $actions): array {
        $seen = array();
        $result = array();
        foreach ($actions as $item) {
            if (! is_array($item)) { continue; }
            $action = is_array($item['action'] ?? null) ? $item['action'] : array();
            $signature = hash('sha256', (string) wp_json_encode(array(
                'source'=>(string) ($item['source'] ?? ''),
                'check_id'=>(string) ($item['check_id'] ?? ''),
                'target'=>$item['target'] ?? array(),
                'gateway'=>$action['gateway'] ?? '',
                'operation'=>$action['operation'] ?? '',
            )));
            if (isset($seen[$signature])) { continue; }
            $seen[$signature] = true;
            $result[] = $item;
        }
        return $result;
    }
}
