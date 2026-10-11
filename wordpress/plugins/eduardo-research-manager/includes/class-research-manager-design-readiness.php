<?php
/** Aggregate M7 design readiness for ChatGPT-governed Research Theme operation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Design_Readiness {
    private Eduardo_Research_Manager_Design $design;
    private Eduardo_Research_Manager_Design_Variants $variants;
    private Eduardo_Research_Manager_Rendered_Verifier $rendered;

    public function __construct(
        ?Eduardo_Research_Manager_Design $design = null,
        ?Eduardo_Research_Manager_Design_Variants $variants = null,
        ?Eduardo_Research_Manager_Rendered_Verifier $rendered = null
    ) {
        $this->design = $design ?: new Eduardo_Research_Manager_Design();
        $this->variants = $variants ?: new Eduardo_Research_Manager_Design_Variants();
        $this->rendered = $rendered ?: new Eduardo_Research_Manager_Rendered_Verifier();
    }

    /**
     * Read-only aggregate readiness report. Existing M7a/M7b gateways remain the only design mutation paths.
     */
    public function report(bool $include_rendered = true): array {
        $findings = array();
        $actions = array();

        $design = $this->design->inspect();
        if (is_wp_error($design)) {
            $findings[] = $this->finding('design-contract', 'blocking', $design->get_error_message());
            $design = array();
        }

        $variants = $this->variants->inspect();
        if (is_wp_error($variants)) {
            $findings[] = $this->finding('variant-contract', 'blocking', $variants->get_error_message());
            $variants = array();
        }

        $palette = (string) ($design['state']['palette'] ?? '');
        $palette_accessibility = is_array($design['presets']['palette'][$palette]['accessibility'] ?? null)
            ? $design['presets']['palette'][$palette]['accessibility']
            : array('accessible'=>false,'checks'=>array(),'minimum_ratio'=>0.0);
        if (empty($palette_accessibility['accessible'])) {
            $findings[] = $this->finding('active-palette-accessibility', 'blocking', 'The active palette does not satisfy the Theme contrast contract.');
            $actions[] = $this->bounded_action('active-palette-accessibility', 'design', 'Choose one of the Theme-owned accessible palette presets.');
        }

        $token_css = function_exists('eduardo_research_design_css') ? (string) eduardo_research_design_css() : '';
        $variant_css = function_exists('eduardo_research_design_variant_css') ? (string) eduardo_research_design_variant_css() : '';
        $token_css_safe = $this->css_safe($token_css);
        $variant_css_safe = $this->css_safe($variant_css);
        if (! $token_css_safe || ! $variant_css_safe) {
            $findings[] = $this->finding('safe-theme-css', 'blocking', 'The bounded Theme design CSS contains a forbidden remote/dynamic surface.');
            $actions[] = $this->manual_action('safe-theme-css', 'Review the Theme-owned CSS compiler; remote arbitrary CSS must remain unavailable.');
        }

        $classes = is_array($variants['body_classes'] ?? null) ? array_values($variants['body_classes']) : array();
        $classes_safe = $this->classes_safe($classes);
        if (! $classes_safe) {
            $findings[] = $this->finding('bounded-body-classes', 'blocking', 'Current design body classes do not match the Theme-owned bounded contract.');
            $actions[] = $this->manual_action('bounded-body-classes', 'Review Theme variant sanitation/body_class generation.');
        }

        $constraints = is_array($variants['constraints'] ?? null) ? $variants['constraints'] : array();
        $semantic_safe = ! empty($constraints['semantic_markup_unchanged'])
            && ! empty($constraints['content_order_unchanged'])
            && empty($constraints['arbitrary_html'])
            && empty($constraints['arbitrary_class'])
            && empty($constraints['arbitrary_css'])
            && empty($constraints['section_hiding']);
        if (! $semantic_safe) {
            $findings[] = $this->finding('semantic-boundary', 'blocking', 'The active variant contract does not preserve the required semantic/content boundary.');
            $actions[] = $this->manual_action('semantic-boundary', 'Restore the Theme-owned semantic and content-order safety contract.');
        }

        $responsive = array(
            'fluid_spacing'=>false !== strpos($token_css, 'clamp('),
            'mobile_variant_guard'=>false !== strpos($variant_css, '@media (max-width:760px)'),
            'mobile_hover_lift_disabled'=>false !== strpos($variant_css, 'transform:none'),
            'reduced_motion'=>false !== strpos($variant_css, '@media (prefers-reduced-motion:reduce)'),
            'reduced_motion_contract'=>! empty($constraints['reduced_motion_respected']),
        );
        $responsive['ready'] = ! in_array(false, $responsive, true);
        if (! $responsive['ready']) {
            $findings[] = $this->finding('responsive-accessibility-contract', 'blocking', 'Theme design CSS is missing a required responsive or reduced-motion guard.');
            $actions[] = $this->manual_action('responsive-accessibility-contract', 'Restore fixed Theme responsive/reduced-motion guards; do not expose arbitrary layout CSS.');
        }

        $rendered = array('requested'=>$include_rendered,'ready'=>! $include_rendered,'surfaces'=>array());
        if ($include_rendered) {
            $rendered = $this->rendered_report($classes);
            if (empty($rendered['ready'])) {
                $findings[] = $this->finding('rendered-design-state', 'blocking', 'Representative EN/ES Home rendering did not expose the exact bounded design state.');
                $actions[] = $this->manual_action('rendered-design-state', 'Inspect Theme rendering/deployment and rerun design readiness before live operation.');
            }
        }

        $blocking = array_values(array_filter($findings, static fn(array $row): bool => 'blocking' === (string) ($row['severity'] ?? '')));
        $ready = 0 === count($blocking)
            && ! empty($design)
            && ! empty($variants)
            && $token_css_safe
            && $variant_css_safe
            && $classes_safe
            && $semantic_safe
            && ! empty($responsive['ready'])
            && (! $include_rendered || ! empty($rendered['ready']));

        return array(
            'version'=>1,
            'milestone'=>'M7c',
            'ready'=>$ready,
            'repository_ci_complete'=>$ready && $include_rendered,
            'design'=>array(
                'contract_version'=>(int) ($design['version'] ?? 0),
                'state'=>is_array($design['state'] ?? null) ? $design['state'] : array(),
                'palette_accessibility'=>$palette_accessibility,
                'safe_css'=>$token_css_safe,
                'css_sha256'=>hash('sha256', $token_css),
            ),
            'variants'=>array(
                'contract_version'=>(int) ($variants['version'] ?? 0),
                'state'=>is_array($variants['state'] ?? null) ? $variants['state'] : array(),
                'body_classes'=>$classes,
                'classes_safe'=>$classes_safe,
                'semantic_boundary'=>$semantic_safe,
                'safe_css'=>$variant_css_safe,
                'css_sha256'=>hash('sha256', $variant_css),
            ),
            'responsive_accessibility'=>$responsive,
            'rendered'=>$rendered,
            'findings'=>$findings,
            'blocking_findings'=>$blocking,
            'blocking_count'=>count($blocking),
            'residual_actions'=>$this->classify_actions($actions),
            'mutation_model'=>array(
                'readiness_read_only'=>true,
                'design_gateway'=>'/design/plan',
                'variant_gateway'=>'/design/variants/plan',
                'auto_apply'=>false,
                'arbitrary_css'=>false,
                'arbitrary_html'=>false,
                'arbitrary_classes'=>false,
            ),
            'next_stage'=>$ready ? 'M8 real eduardoyauriluna.com operating acceptance' : 'Resolve bounded findings and rerun M7c readiness',
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function rendered_report(array $expected_classes): array {
        $surfaces = array();
        $ready = true;
        foreach (array('en','es') as $language) {
            $result = $this->rendered->verify_page('home', $language);
            if (is_wp_error($result)) {
                $ready = false;
                $surfaces[$language] = array('ready'=>false,'error_code'=>$result->get_error_code(),'error'=>$result->get_error_message());
                continue;
            }
            $body = (string) ($result['body'] ?? '');
            $style_tokens = false !== strpos($body, 'id="eduardo-research-design-tokens"');
            $style_variants = false !== strpos($body, 'id="eduardo-research-component-variants"');
            $class_checks = array();
            foreach ($expected_classes as $class) {
                $class_checks[$class] = 1 === preg_match('/<body\b[^>]*\bclass=["\'][^"\']*\b' . preg_quote((string) $class, '/') . '\b[^"\']*["\']/i', $body);
            }
            $surface_ready = ! empty($result['verified'])
                && $style_tokens
                && $style_variants
                && ! in_array(false, $class_checks, true);
            $ready = $ready && $surface_ready;
            $surfaces[$language] = array(
                'ready'=>$surface_ready,
                'url'=>(string) ($result['url'] ?? ''),
                'http_checks'=>is_array($result['checks'] ?? null) ? $result['checks'] : array(),
                'design_token_style'=>$style_tokens,
                'component_variant_style'=>$style_variants,
                'body_classes'=>$class_checks,
            );
        }
        return array('requested'=>true,'ready'=>$ready,'surfaces'=>$surfaces);
    }

    private function css_safe(string $css): bool {
        return '' !== $css
            && false === stripos($css, 'url(')
            && false === stripos($css, '@import')
            && false === stripos($css, '<script');
    }

    private function classes_safe(array $classes): bool {
        if (4 !== count($classes)) { return false; }
        $dimensions = array();
        foreach ($classes as $class) {
            if (1 !== preg_match('/^research-variant-(hero|sections|cards|collections)-[a-z0-9-]+$/', (string) $class, $match)) { return false; }
            $dimensions[] = (string) $match[1];
        }
        sort($dimensions);
        return array('cards','collections','hero','sections') === array_values(array_unique($dimensions));
    }

    private function finding(string $id, string $severity, string $message): array {
        return array('check_id'=>$id,'severity'=>$severity,'message'=>$message);
    }

    private function bounded_action(string $check_id, string $gateway, string $reason): array {
        return array(
            'check_id'=>$check_id,'class'=>'bounded_change','reason'=>$reason,
            'action'=>array('gateway'=>$gateway,'auto_apply'=>false,'confirmation_required'=>true),
        );
    }

    private function manual_action(string $check_id, string $reason): array {
        return array('check_id'=>$check_id,'class'=>'manual_review','reason'=>$reason,'action'=>array('auto_apply'=>false));
    }

    private function classify_actions(array $actions): array {
        $groups = array('bounded_change'=>array(),'manual_review'=>array());
        foreach ($actions as $action) {
            $class = (string) ($action['class'] ?? 'manual_review');
            $class = isset($groups[$class]) ? $class : 'manual_review';
            $groups[$class][] = $action;
        }
        $groups['summary'] = array(
            'bounded_change'=>count($groups['bounded_change']),
            'manual_review'=>count($groups['manual_review']),
            'total'=>count($actions),
        );
        return $groups;
    }
}
