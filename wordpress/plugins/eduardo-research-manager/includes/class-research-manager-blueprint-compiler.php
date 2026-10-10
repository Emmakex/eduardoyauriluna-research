<?php
/** Compile a validated Greenfield blueprint into native Manager mutation plans. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Blueprint_Compiler {
    private Eduardo_Research_Manager_Blueprint $blueprints;
    private Eduardo_Research_Manager_Greenfield $greenfield;

    public function __construct(
        ?Eduardo_Research_Manager_Blueprint $blueprints = null,
        ?Eduardo_Research_Manager_Greenfield $greenfield = null
    ) {
        $this->blueprints = $blueprints ?: new Eduardo_Research_Manager_Blueprint();
        $this->greenfield = $greenfield ?: new Eduardo_Research_Manager_Greenfield();
    }

    public function compile(array $blueprint): array|WP_Error {
        $ready = $this->greenfield->ready();
        if (is_wp_error($ready)) { return $ready; }

        $normalized = $this->blueprints->validate($blueprint);
        if (is_wp_error($normalized)) { return $normalized; }

        $resources = $this->greenfield->resources();
        if (is_wp_error($resources)) { return $resources; }

        $plans = array();
        $skipped = array();

        foreach ($normalized['pages'] as $index => $record) {
            if (! is_array($record)) {
                return $this->record_error('pages', $index, 'Each Page blueprint record must be an object-like array.');
            }
            $key = sanitize_key((string) ($record['key'] ?? ''));
            if ('' === $key) { return $this->record_error('pages', $index, 'Page blueprint records require a key.'); }

            $state = Eduardo_Research_Manager::contract()->page_state($key);
            if (! empty($state['exists'])) {
                $skipped[] = array('resource'=>'pages','index'=>$index,'key'=>$key,'reason'=>'already_exists');
                continue;
            }

            $plan = $resources['pages']->build_creation_plan($key, sprintf('Greenfield blueprint: create Theme-owned %s Page', $key));
            if (is_wp_error($plan)) { return $plan; }
            $plans[] = array('resource'=>'pages','index'=>$index,'key'=>$key,'plan'=>$plan);
        }

        foreach (array('insights','lines','outputs','projects','software','datasets') as $resource_key) {
            foreach ($normalized[$resource_key] as $index => $record) {
                if (! is_array($record)) {
                    return $this->record_error($resource_key, $index, 'Blueprint resource records must be object-like arrays.');
                }

                $payload = $record;
                $context = $this->evidence_context($payload);
                if (is_wp_error($context)) { return $this->record_error($resource_key, $index, $context->get_error_message()); }
                unset($payload['evidence']);

                if ('lines' === $resource_key) {
                    $slug = sanitize_title((string) ($payload['slug'] ?? $payload['title'] ?? ''));
                    $language = sanitize_key((string) ($payload['language'] ?? 'en'));
                    if ('' !== $slug) {
                        $existing = $resources['lines']->find_by_slug($slug, $language);
                        if ($existing > 0) {
                            $skipped[] = array('resource'=>'lines','index'=>$index,'slug'=>$slug,'language'=>$language,'reason'=>'already_exists','post_id'=>$existing);
                            continue;
                        }
                    }
                }

                $singular = array(
                    'insights'=>'insight','lines'=>'research line','outputs'=>'research output','projects'=>'research project','software'=>'research software','datasets'=>'research dataset',
                )[$resource_key] ?? rtrim($resource_key, 's');
                $intent = sprintf('Greenfield blueprint: create %s record %d', $singular, $index + 1);
                $plan = $resources[$resource_key]->build_creation_plan($payload, $intent, $context);
                if (is_wp_error($plan)) { return $plan; }
                $plans[] = array('resource'=>$resource_key,'index'=>$index,'plan'=>$plan);
            }
        }

        return array(
            'version'=>(int) $normalized['version'],
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'languages'=>$normalized['languages'],
            'plans'=>$plans,
            'plan_count'=>count($plans),
            'skipped'=>$skipped,
            'skipped_count'=>count($skipped),
            'requires_legacy_discovery'=>false,
            'requires_legacy_mapping'=>false,
        );
    }

    private function evidence_context(array $record): array|WP_Error {
        if (! array_key_exists('evidence', $record)) { return array(); }
        if (! is_array($record['evidence'])) {
            return new WP_Error('research_manager_blueprint_evidence_invalid', 'Blueprint evidence must be an object-like array.');
        }
        $confirmed = ! empty($record['evidence']['confirmed']);
        $reference = sanitize_text_field((string) ($record['evidence']['reference'] ?? ''));
        if ($confirmed && '' === $reference) {
            return new WP_Error('research_manager_blueprint_evidence_reference_required', 'Confirmed blueprint evidence requires a source or verification reference.');
        }
        return array('evidence_confirmed'=>$confirmed,'evidence_reference'=>$reference);
    }

    private function record_error(string $resource, int $index, string $message): WP_Error {
        return new WP_Error(
            'research_manager_blueprint_record_invalid',
            $message,
            array('resource'=>$resource,'index'=>$index)
        );
    }
}
