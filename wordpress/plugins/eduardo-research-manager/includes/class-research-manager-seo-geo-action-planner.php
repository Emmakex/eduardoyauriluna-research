<?php
/** Deterministic SEO/GEO action planner that routes findings to existing Manager contracts. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_SEO_GEO_Action_Planner {
    private Eduardo_Research_Manager_SEO_GEO $seo_geo;

    public function __construct(?Eduardo_Research_Manager_SEO_GEO $seo_geo = null) {
        $this->seo_geo = $seo_geo ?: new Eduardo_Research_Manager_SEO_GEO();
    }

    public function plan(string $type, string|int $identifier, string $language = ''): array|WP_Error {
        $type = sanitize_key($type);
        $inspection = $this->seo_geo->inspect_resource($type, $identifier, $language);
        if (is_wp_error($inspection)) { return $inspection; }

        if ('page' === $type) {
            return $this->page_plan($inspection);
        }
        return $this->record_plan($type, $inspection);
    }

    private function page_plan(array $inspection): array {
        $stored = is_array($inspection['stored'] ?? null) ? $inspection['stored'] : array();
        $slots = is_array($stored['effective_slots'] ?? null) ? $stored['effective_slots'] : array();
        $key = sanitize_key((string) ($inspection['key'] ?? $stored['key'] ?? ''));
        $language = sanitize_key((string) ($inspection['language'] ?? $stored['language'] ?? 'en'));
        $description_slot = 'home' === $key ? 'hero-lead' : 'lead';
        $description = trim(wp_strip_all_tags((string) ($slots[$description_slot] ?? '')));
        $checks = array();

        $checks[] = $this->check(
            'description-source',
            '' !== $description ? 'pass' : 'fail',
            'seo+geo',
            'The Theme derives meta description and public summary from a structured Page lead slot.',
            '' !== $description
                ? array()
                : $this->action('pages', 'page-slots-update', array('key'=>$key,'language'=>$language,'slot'=>$description_slot), array('value'), false)
        );

        $checks[] = $this->check(
            'rendered-contract',
            ! empty($inspection['ready']) ? 'pass' : 'fail',
            'seo',
            'Canonical, hreflang, language, Open Graph URL and JSON-LD must verify on the rendered Page.',
            ! empty($inspection['ready'])
                ? array()
                : $this->action('seo-geo', 'seo-remediate-or-source-fix', array('type'=>'page','key'=>$key,'language'=>$language), array('diagnostic_check_or_structured_source'), false)
        );

        return $this->result('page', array('key'=>$key,'language'=>$language), $inspection, $checks);
    }

    private function record_plan(string $type, array $inspection): array {
        $stored = is_array($inspection['stored'] ?? null) ? $inspection['stored'] : array();
        $post_id = absint($inspection['post_id'] ?? $stored['post_id'] ?? 0);
        $language = sanitize_key((string) ($inspection['language'] ?? $stored['language'] ?? 'en'));
        $checks = array();

        $checks[] = $this->check(
            'title',
            '' !== trim((string) ($stored['title'] ?? '')) ? 'pass' : 'fail',
            'seo+geo',
            'The resource title is the primary human and schema entity label.',
            '' !== trim((string) ($stored['title'] ?? '')) ? array() : $this->update_action($type, $post_id, 'title', true)
        );
        $checks[] = $this->check(
            'description-source',
            '' !== trim(wp_strip_all_tags((string) ($stored['excerpt'] ?? ''))) ? 'pass' : 'fail',
            'seo+geo',
            'The Theme derives meta description and schema description/abstract from the structured excerpt.',
            '' !== trim(wp_strip_all_tags((string) ($stored['excerpt'] ?? ''))) ? array() : $this->update_action($type, $post_id, 'excerpt', true)
        );
        $checks[] = $this->check(
            'rendered-contract',
            ! empty($inspection['ready']) ? 'pass' : ('publish' === (string) ($inspection['status'] ?? '') ? 'fail' : 'not-applicable'),
            'seo',
            'Published resources must verify canonical, language, hreflang, Open Graph URL, JSON-LD and expected schema type.',
            (! empty($inspection['ready']) || 'publish' !== (string) ($inspection['status'] ?? ''))
                ? array()
                : $this->action('seo-geo', 'inspect-source-contract', array('type'=>$type,'post_id'=>$post_id), array('failing_rendered_check'), false)
        );

        $translation = Eduardo_Research_Manager::translation_editor()->inspect($post_id);
        if (! is_wp_error($translation)) {
            $has_counterpart = absint($translation['counterpart_id'] ?? 0) > 0 && ! empty($translation['counterpart_exists']);
            $checks[] = $this->check(
                'bilingual-alternate',
                $has_counterpart ? 'pass' : 'advisory',
                'seo+geo',
                'A paired EN/ES counterpart enables reciprocal hreflang and bilingual entity discovery.',
                $has_counterpart ? array() : $this->translation_action($type, $post_id, $language)
            );
        }

        if ('insight' === $type) {
            $checks = array_merge($checks, $this->insight_checks($stored, $post_id));
        } elseif ('line' === $type) {
            $checks = array_merge($checks, $this->line_checks($stored, $post_id));
        } else {
            $checks = array_merge($checks, $this->object_checks($type, $stored, $post_id));
        }

        return $this->result($type, array('post_id'=>$post_id,'language'=>$language), $inspection, $checks);
    }

    private function insight_checks(array $stored, int $post_id): array {
        return array(
            $this->check(
                'research-entity-graph',
                ! empty($stored['line_ids']) ? 'pass' : 'advisory',
                'geo',
                'A verified Research Line relation connects the Insight to the site research entity graph and internal related-object graph.',
                ! empty($stored['line_ids']) ? array() : $this->update_action('insight', $post_id, 'line_ids', true)
            ),
        );
    }

    private function line_checks(array $stored, int $post_id): array {
        $verified = 'verified' === sanitize_key((string) ($stored['evidence_status'] ?? ''));
        $question = '' !== trim((string) ($stored['central_question'] ?? ''));
        $topics = ! empty((array) ($stored['topics'] ?? array()));
        $methods = ! empty((array) ($stored['methods'] ?? array()));
        return array(
            $this->check('provenance', $verified ? 'pass' : 'fail', 'geo+provenance', 'Research Lines are public entity authorities only when evidence-verified.', $verified ? array() : $this->action('research-lines','line-update',array('post_id'=>$post_id,'field'=>'evidence_status'),array('evidence_reference'),true)),
            $this->check('direct-answer-question', $question ? 'pass' : 'advisory', 'geo', 'A central research question gives answer engines a concise statement of the Line intent and feeds schema abstract.', $question ? array() : $this->update_action('line',$post_id,'central_question',true)),
            $this->check('topic-entities', $topics ? 'pass' : 'advisory', 'geo', 'Structured topics feed Research Line schema keywords and improve entity/topic disambiguation.', $topics ? array() : $this->update_action('line',$post_id,'topics',true)),
            $this->check('method-context', $methods ? 'pass' : 'advisory', 'geo', 'Structured methods provide machine-readable research context and support evidence-oriented answers.', $methods ? array() : $this->update_action('line',$post_id,'methods',true)),
        );
    }

    private function object_checks(string $type, array $stored, int $post_id): array {
        $checks = array();
        $checks[] = $this->check(
            'research-entity-graph',
            ! empty((array) ($stored['line_ids'] ?? array())) ? 'pass' : 'advisory',
            'geo',
            'Verified Research Line relations feed public `about` links and the reverse `subjectOf` research graph.',
            ! empty((array) ($stored['line_ids'] ?? array())) ? array() : $this->update_action($type,$post_id,'line_ids',true)
        );

        if ('output' === $type) {
            $precise_type = '' !== trim((string) ($stored['output_type'] ?? '')) && '1' === (string) ($stored['output_type_verified'] ?? '0');
            $authors = ! empty((array) ($stored['authors'] ?? array()));
            $date = '' !== trim((string) ($stored['publication_date'] ?? ''));
            $checks[] = $this->check('schema-classification', $precise_type ? 'pass' : 'advisory', 'seo+geo+provenance', 'A verified output type permits a precise scholarly schema type instead of generic CreativeWork.', $precise_type ? array() : $this->evidence_object_action('output',$post_id,'output_type',(string)($stored['output_type'] ?? '')));
            $checks[] = $this->check('authorship', $authors ? 'pass' : 'advisory', 'geo+provenance', 'Structured authorship feeds Person/Organization nodes in scholarly JSON-LD.', $authors ? array() : $this->evidence_object_action('output',$post_id,'authors',array()));
            $checks[] = $this->check('publication-date', $date ? 'pass' : 'advisory', 'seo+geo', 'A publication date feeds datePublished and improves scholarly discovery context.', $date ? array() : $this->evidence_object_action('output',$post_id,'publication_date',''));
            if ('' !== trim((string) ($stored['doi'] ?? '')) && '1' !== (string) ($stored['doi_verified'] ?? '0')) {
                $checks[] = $this->check('doi-provenance','fail','geo+provenance','A stored DOI is excluded from verified scholarly schema until evidence-confirmed.',$this->evidence_object_action('output',$post_id,'doi',(string)$stored['doi']));
            }
        } elseif ('project' === $type) {
            $checks[] = $this->check('direct-answer-question', '' !== trim((string)($stored['question'] ?? '')) ? 'pass' : 'advisory', 'geo', 'The structured project question becomes schema abstract and provides a concise answer-oriented project intent.', '' !== trim((string)($stored['question'] ?? '')) ? array() : $this->evidence_object_action('project',$post_id,'question',''));
            $checks[] = $this->check('method-context', ! empty((array)($stored['methods'] ?? array())) ? 'pass' : 'advisory', 'geo', 'Structured methods improve machine-readable project context.', ! empty((array)($stored['methods'] ?? array())) ? array() : $this->evidence_object_action('project',$post_id,'methods',array()));
        } elseif ('software' === $type) {
            $repo = '' !== trim((string) ($stored['repository_url'] ?? ''));
            $languages = ! empty((array) ($stored['programming_languages'] ?? array()));
            $checks[] = $this->check('code-repository', $repo ? 'pass' : 'advisory', 'geo+provenance', 'A verified repository URL feeds SoftwareSourceCode.codeRepository and ties the entity to its source artefact.', $repo ? array() : $this->evidence_object_action('software',$post_id,'repository_url',''));
            $checks[] = $this->check('programming-language', $languages ? 'pass' : 'advisory', 'geo', 'Programming-language metadata improves SoftwareSourceCode entity specificity.', $languages ? array() : $this->evidence_object_action('software',$post_id,'programming_languages',array()));
            if ('' !== trim((string) ($stored['doi'] ?? '')) && '1' !== (string) ($stored['doi_verified'] ?? '0')) {
                $checks[] = $this->check('doi-provenance','fail','geo+provenance','A stored software DOI must be evidence-verified before it is treated as trusted provenance.',$this->evidence_object_action('software',$post_id,'doi',(string)$stored['doi']));
            }
        } elseif ('dataset' === $type) {
            $repo = '' !== trim((string) ($stored['repository'] ?? ''));
            $formats = ! empty((array) ($stored['formats'] ?? array()));
            $provenance = '' !== trim((string) ($stored['provenance'] ?? ''));
            $checks[] = $this->check('dataset-repository', $repo ? 'pass' : 'advisory', 'geo+provenance', 'A repository or landing URL gives the Dataset entity a verifiable external/source context.', $repo ? array() : $this->evidence_object_action('dataset',$post_id,'repository',''));
            $checks[] = $this->check('dataset-formats', $formats ? 'pass' : 'advisory', 'geo', 'Structured formats improve dataset reuse context and machine interpretation.', $formats ? array() : $this->evidence_object_action('dataset',$post_id,'formats',array()));
            $checks[] = $this->check('dataset-provenance', $provenance ? 'pass' : 'advisory', 'geo+provenance', 'Explicit dataset provenance supports trustworthy answer-engine attribution and reuse context.', $provenance ? array() : $this->evidence_object_action('dataset',$post_id,'provenance',''));
            if ('' !== trim((string) ($stored['doi'] ?? '')) && '1' !== (string) ($stored['doi_verified'] ?? '0')) {
                $checks[] = $this->check('doi-provenance','fail','geo+provenance','A stored dataset DOI must be evidence-verified before it is treated as trusted provenance.',$this->evidence_object_action('dataset',$post_id,'doi',(string)$stored['doi']));
            }
        }
        return $checks;
    }

    private function update_action(string $type, int $post_id, string $field, bool $requires_input): array {
        if ('insight' === $type) {
            return $this->action('insights','insight-update',array('post_id'=>$post_id,'field'=>$field),$requires_input?array('value'):array(),false);
        }
        if ('line' === $type) {
            return $this->action('research-lines','line-update',array('post_id'=>$post_id,'field'=>$field),$requires_input?array('value','evidence_reference'):array('evidence_reference'),true);
        }
        return $this->evidence_object_action($type,$post_id,$field,null);
    }

    private function evidence_object_action(string $type, int $post_id, string $field, mixed $current): array {
        return $this->action('research-objects','object-update',array('kind'=>$type,'post_id'=>$post_id,'field'=>$field,'current_value'=>$current),array('value','evidence_reference'),true);
    }

    private function translation_action(string $type, int $post_id, string $language): array {
        if ('insight' === $type) {
            return $this->action('insight-translations','insight-pair',array('post_id'=>$post_id,'language'=>$language),array('counterpart_id'),false);
        }
        return $this->action('research-translations','translation-pair',array('post_id'=>$post_id,'language'=>$language),array('counterpart_id','evidence_reference'),true);
    }

    private function action(string $gateway, string $operation, array $target, array $required_input, bool $requires_evidence): array {
        return array(
            'gateway'=>$gateway,
            'operation'=>$operation,
            'target'=>$target,
            'required_input'=>$required_input,
            'requires_evidence'=>$requires_evidence,
            'auto_apply'=>false,
            'reason'=>'Use the existing bounded Manager gateway; do not write SEO metadata directly.',
        );
    }

    private function check(string $id, string $status, string $dimension, string $reason, array $action): array {
        return array('check_id'=>$id,'status'=>$status,'dimension'=>$dimension,'reason'=>$reason,'action'=>$action);
    }

    private function result(string $type, array $target, array $inspection, array $checks): array {
        $summary = array('pass'=>0,'fail'=>0,'advisory'=>0,'not_applicable'=>0);
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? 'advisory');
            if (isset($summary[$status])) { $summary[$status]++; }
        }
        return array(
            'type'=>$type,
            'target'=>$target,
            'inspection_ready'=>! empty($inspection['ready']),
            'summary'=>$summary,
            'checks'=>$checks,
            'action_count'=>$summary['fail'] + $summary['advisory'],
            'mutation_model'=>'delegate-to-existing-manager-gateways',
            'direct_meta_mutation'=>false,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }
}
