<?php
/** Reviewed, code-owned Research resource metadata; Registry is descriptive only. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

/**
 * Exact field-to-postmeta maps accepted by the 0.11.1 Research creation paths.
 * This is an EXECUTABLE contract: a Registry change must be reviewed here
 * before it can affect creation, Preview, Apply, Verify or Rollback.
 *
 * IMPORTANT: Existing generic post_meta mutations retain their separate
 * 49-key legacy allowlist in Research_Mutation_Policy. Native creation fields
 * MUST NOT be reused to silently narrow or expand that accepted write surface.
 */
final class Eduardo_Research_Manager_Research_Resource_Fields {
    private const CREATION_META = array(
        'research_line'=>array(
            'evidence_status'=>'_research_evidence_status',
            'research_status'=>'_research_status',
            'central_question'=>'_research_central_question',
            'order'=>'_research_order',
            'topics'=>'_research_topics',
            'methods'=>'_research_methods',
        ),
        'research_output'=>array(
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
        'research_project'=>array(
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
        'research_software'=>array(
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
        'research_dataset'=>array(
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
    );

    /** Legacy WP page/post + five reviewed Research CPTs; never infer from Registry. */
    public static function supports_post_type(string $post_type): bool {
        return in_array($post_type, array('page','post'), true)
            || array_key_exists($post_type, self::CREATION_META);
    }

    /** Closed reviewed native creation fields (field => physical meta key). */
    public static function creation_meta(string $post_type): array {
        return self::CREATION_META[$post_type] ?? array();
    }

    /** No added, missing, reordered or remapped field can reach execution. */
    public static function creation_meta_matches(string $post_type, array $candidate): bool {
        $reviewed = self::creation_meta($post_type);
        return ! empty($reviewed) && $candidate === $reviewed;
    }

    /** Preserve the finite legacy 49-key post_meta surface on known types only. */
    public static function legacy_meta_keys(string $post_type, array $reviewed_keys): array {
        return self::supports_post_type($post_type) ? $reviewed_keys : array();
    }

    /** Preserve the existing five bounded post fields; Plan controls their values. */
    public static function legacy_post_fields(string $post_type): array {
        return self::supports_post_type($post_type)
            ? array('post_title','post_excerpt','post_content','menu_order','post_status')
            : array();
    }
}
