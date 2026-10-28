<?php
/** Exact descriptive registry for Site Architect Research vertical resources. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Resource_Registry {
    /**
     * Descriptive vertical contract only.
     *
     * This Registry does not authorize mutations. Eduardo_Research_Manager_Plan
     * remains the fail-closed validation and security boundary.
     */
    public static function all(): array {
        return array(
            'line'=>array(
                'inventory_key'=>'lines',
                'post_type'=>'research_line',
                'creation_action'=>'create_line',
                'read_scope'=>'research.read',
                'identifier'=>'post_id',
                'creation_conflict'=>'research_manager_line_creation_conflict',
                'rollback_conflict'=>'research_manager_line_rollback_conflict',
                'meta'=>array(
                    'evidence_status'=>'_research_evidence_status',
                    'research_status'=>'_research_status',
                    'central_question'=>'_research_central_question',
                    'order'=>'_research_order',
                    'topics'=>'_research_topics',
                    'methods'=>'_research_methods',
                ),
                'array_fields'=>array('topics','methods'),
                'defaults'=>array('evidence_status'=>'unverified','research_status'=>'planned'),
            ),
            'output'=>array(
                'inventory_key'=>'outputs',
                'post_type'=>'research_output',
                'creation_action'=>'create_output',
                'read_scope'=>'research.read',
                'identifier'=>'post_id',
                'creation_conflict'=>'research_manager_output_creation_conflict',
                'rollback_conflict'=>'research_manager_output_rollback_conflict',
                'meta'=>array(
                    'output_type'=>'_research_output_type',
                    'output_type_verified'=>'_research_output_type_verified',
                    'review_status'=>'_research_review_status',
                    'review_status_verified'=>'_research_review_status_verified',
                    'publication_date'=>'_research_publication_date',
                    'venue'=>'_research_venue',
                    'doi'=>'_research_doi',
                    'doi_verified'=>'_research_doi_verified',
                    'authors'=>'_research_authors',
                    'line_ids'=>'_research_line_ids',
                ),
                'array_fields'=>array('authors','line_ids'),
                'defaults'=>array('output_type_verified'=>'0','review_status_verified'=>'0','doi_verified'=>'0'),
            ),
            'project'=>array(
                'inventory_key'=>'projects',
                'post_type'=>'research_project',
                'creation_action'=>'create_project',
                'read_scope'=>'research.read',
                'identifier'=>'post_id',
                'creation_conflict'=>'research_manager_project_creation_conflict',
                'rollback_conflict'=>'research_manager_project_rollback_conflict',
                'meta'=>array(
                    'project_status'=>'_research_project_status',
                    'question'=>'_research_question',
                    'role'=>'_research_role',
                    'start_date'=>'_research_start_date',
                    'end_date'=>'_research_end_date',
                    'partner'=>'_research_partner',
                    'funding'=>'_research_funding',
                    'project_url'=>'_research_project_url',
                    'methods'=>'_research_methods',
                    'line_ids'=>'_research_line_ids',
                ),
                'array_fields'=>array('methods','line_ids'),
                'defaults'=>array(),
            ),
            'software'=>array(
                'inventory_key'=>'software',
                'post_type'=>'research_software',
                'creation_action'=>'create_software',
                'read_scope'=>'research.read',
                'identifier'=>'post_id',
                'creation_conflict'=>'research_manager_software_creation_conflict',
                'rollback_conflict'=>'research_manager_software_rollback_conflict',
                'meta'=>array(
                    'software_status'=>'_research_software_status',
                    'version'=>'_research_software_version',
                    'release_date'=>'_research_release_date',
                    'repository_url'=>'_research_repository_url',
                    'archive_url'=>'_research_archive_url',
                    'license'=>'_research_license',
                    'documentation_url'=>'_research_documentation_url',
                    'doi'=>'_research_doi',
                    'doi_verified'=>'_research_doi_verified',
                    'programming_languages'=>'_research_programming_languages',
                    'line_ids'=>'_research_line_ids',
                ),
                'array_fields'=>array('programming_languages','line_ids'),
                'defaults'=>array('doi_verified'=>'0'),
            ),
            'dataset'=>array(
                'inventory_key'=>'datasets',
                'post_type'=>'research_dataset',
                'creation_action'=>'create_dataset',
                'read_scope'=>'research.read',
                'identifier'=>'post_id',
                'creation_conflict'=>'research_manager_dataset_creation_conflict',
                'rollback_conflict'=>'research_manager_dataset_rollback_conflict',
                'meta'=>array(
                    'version'=>'_research_dataset_version',
                    'publication_date'=>'_research_publication_date',
                    'repository'=>'_research_repository',
                    'doi'=>'_research_doi',
                    'doi_verified'=>'_research_doi_verified',
                    'license'=>'_research_license',
                    'access_level'=>'_research_access_level',
                    'methodology'=>'_research_methodology',
                    'provenance'=>'_research_provenance',
                    'size'=>'_research_size',
                    'documentation_url'=>'_research_documentation_url',
                    'ethics_notes'=>'_research_ethics_notes',
                    'formats'=>'_research_formats',
                    'line_ids'=>'_research_line_ids',
                ),
                'array_fields'=>array('formats','line_ids'),
                'defaults'=>array('doi_verified'=>'0'),
            ),
        );
    }

    public static function get(string $resource_key): array {
        $resource_key = sanitize_key($resource_key);
        $registry = self::all();
        return is_array($registry[$resource_key] ?? null) ? $registry[$resource_key] : array();
    }

    public static function by_inventory_key(string $inventory_key): array {
        $inventory_key = sanitize_key($inventory_key);
        foreach (self::all() as $resource_key => $spec) {
            if ($inventory_key === (string) ($spec['inventory_key'] ?? '')) {
                return array_merge(array('resource_key'=>$resource_key), $spec);
            }
        }
        return array();
    }

    public static function by_creation_action(string $action_type): array {
        $action_type = sanitize_key($action_type);
        foreach (self::all() as $resource_key => $spec) {
            if ($action_type === (string) ($spec['creation_action'] ?? '')) {
                return array_merge(array('resource_key'=>$resource_key), $spec);
            }
        }
        return array();
    }

    /** Descriptive editable/readback field vocabulary; this does not authorize mutation. */
    public static function resource_fields(string $resource_key): array {
        $spec = self::get($resource_key);
        if (! $spec) { return array(); }
        $meta = is_array($spec['meta'] ?? null) ? $spec['meta'] : array();
        return array_merge(
            array('status','slug','title','excerpt','content','language'),
            array_keys($meta)
        );
    }

    /** Descriptive field-to-storage map; Plan remains the fail-closed mutation authority. */
    public static function resource_meta_fields(string $resource_key): array {
        $spec = self::get($resource_key);
        if (! $spec) { return array(); }
        $meta = is_array($spec['meta'] ?? null) ? $spec['meta'] : array();
        return array_merge(array('language'=>'_research_language'), $meta);
    }

    public static function array_fields(string $resource_key): array {
        $spec = self::get($resource_key);
        return is_array($spec['array_fields'] ?? null) ? array_values($spec['array_fields']) : array();
    }

    public static function defaults(string $resource_key): array {
        $spec = self::get($resource_key);
        return is_array($spec['defaults'] ?? null) ? $spec['defaults'] : array();
    }

    public static function post_types(): array {
        return array_values(array_map(
            static fn(array $spec): string => (string) ($spec['post_type'] ?? ''),
            self::all()
        ));
    }

    public static function vertical_blueprint_resource_keys(): array {
        return array_keys(self::vertical_inventory_specs());
    }

    public static function vertical_inventory_specs(): array {
        return array_merge(
            array(
                'insights'=>array(
                    'scope'=>'insights.read',
                    'identifier'=>'post_id',
                    'post_type'=>'post',
                ),
            ),
            self::inventory_specs()
        );
    }

    public static function relation_blueprint_specs(): array {
        $specs = array();
        foreach (self::all() as $spec) {
            if (! isset($spec['meta']['line_ids'])) { continue; }
            $inventory_key = sanitize_key((string) ($spec['inventory_key'] ?? ''));
            if ('' === $inventory_key) { continue; }
            $specs[$inventory_key] = array(
                'post_type'=>(string) ($spec['post_type'] ?? ''),
                'service'=>$inventory_key,
            );
        }
        return $specs;
    }

    public static function inventory_specs(): array {
        $specs = array();
        foreach (self::all() as $spec) {
            $inventory_key = sanitize_key((string) ($spec['inventory_key'] ?? ''));
            if ('' === $inventory_key) { continue; }
            $specs[$inventory_key] = array(
                'scope'=>(string) ($spec['read_scope'] ?? ''),
                'identifier'=>(string) ($spec['identifier'] ?? 'post_id'),
                'post_type'=>(string) ($spec['post_type'] ?? ''),
            );
        }
        return $specs;
    }
}
