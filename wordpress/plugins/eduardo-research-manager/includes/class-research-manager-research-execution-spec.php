<?php
/** Research creation execution descriptors are vertical-owned, never universal grants. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-resource-registry.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-resource-fields.php';

final class Eduardo_Research_Manager_Research_Execution_Spec {
    /** Exact action-to-post-type map. Registry alone never enables a new write path. */
    private const POST_TYPES = array(
        'create_line'=>'research_line',
        'create_output'=>'research_output',
        'create_project'=>'research_project',
        'create_software'=>'research_software',
        'create_dataset'=>'research_dataset',
    );

    /** Insight is a normal post. These six action identities are Research-owned. */
    public static function creation_actions(): array {
        return array('create_insight','create_line','create_output','create_project','create_software','create_dataset');
    }

    /** Preserve accepted resource meta/defaults/rollback shapes after exact type checks. */
    public static function for_action(string $action_type): array {
        $expected_post_type = self::POST_TYPES[$action_type] ?? '';
        if ('' === $expected_post_type) { return array(); }
        $registered = Eduardo_Research_Manager_Resource_Registry::by_creation_action($action_type);
        if (! $registered || (string) ($registered['post_type'] ?? '') !== $expected_post_type) { return array(); }

        $post_type = sanitize_key((string) ($registered['post_type'] ?? ''));
        $creation_conflict = sanitize_key((string) ($registered['creation_conflict'] ?? ''));
        $rollback_conflict = sanitize_key((string) ($registered['rollback_conflict'] ?? ''));
        $meta = is_array($registered['meta'] ?? null) ? $registered['meta'] : array();
        $array_fields = is_array($registered['array_fields'] ?? null) ? array_values($registered['array_fields']) : array();
        $defaults = is_array($registered['defaults'] ?? null) ? $registered['defaults'] : array();

        if ('' === $post_type || '' === $creation_conflict || '' === $rollback_conflict
            || ! Eduardo_Research_Manager_Research_Resource_Fields::creation_meta_matches($post_type, $meta)) {
            // The Registry describes resources; it cannot extend executable
            // field ownership without a separately reviewed vertical contract.
            return array();
        }

        return array(
            'post_type'=>$post_type,
            'creation_conflict'=>$creation_conflict,
            'rollback_conflict'=>$rollback_conflict,
            'meta'=>$meta,
            'array_fields'=>$array_fields,
            'defaults'=>$defaults,
        );
    }
}
