<?php
/** Offline regression: explicit scoped mutation types/options without WordPress writes. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('EDUARDO_RESEARCH_MANAGER_DIR', dirname(__DIR__) . '/wordpress/plugins/eduardo-research-manager/');
define('OBJECT', 'OBJECT');

final class WP_Error {
    public function __construct(public string $code, public string $message) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
final class WP_Post {
    public function __construct(public int $ID, public string $post_type = 'page') {}
}
final class Eduardo_Research_Manager_Snapshots {}
/** Read-only DB stand-in: Executor Preview must never write during this offline test. */
final class SiteArchitectPreviewReadOnlyDb {
    public string $postmeta = 'wp_postmeta';
    public function prepare(string $sql, mixed ...$values): string { return $sql; }
    public function get_var(string $sql): mixed { return null; }
}
$GLOBALS['wpdb'] = new SiteArchitectPreviewReadOnlyDb();
function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
function sanitize_key(string $v): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($v)) ?? ''; }
function sanitize_text_field(string $v): string { return trim(strip_tags($v)); }
function absint(mixed $v): int { return abs((int) $v); }
function sanitize_title(string $v): string { return trim(strtolower(preg_replace('/[^a-z0-9-]/i', '-', $v) ?? ''), '-'); }
function sanitize_textarea_field(string $v): string { return trim(strip_tags($v)); }
function wp_kses_post(string $v): string { return $v; }
function eduardo_research_insight_types(string $language): array { return ['research_note'=>'Research note']; }

function wp_json_encode(mixed $v, int $opts = 0): string|false { return json_encode($v, $opts); }
function get_option(string $key, mixed $default = false): mixed { return $default; }
function get_page_by_path(string $path, string $output = OBJECT, string $type = 'page'): ?WP_Post {
    if ('home' === $path) { return new WP_Post(17); }
    return 'landing' === $path ? new WP_Post(31) : null;
}
function maybe_serialize(mixed $v): string { return serialize($v); }
function get_post(int $post_id): ?WP_Post {
    if (17 === $post_id) { return new WP_Post(17); }
    if (23 === $post_id) { return new WP_Post(23, 'research_line'); }
    if (in_array($post_id, [31, 32], true)) { return new WP_Post($post_id); }
    return 45 === $post_id ? new WP_Post(45, 'product') : null;
}
function eduardo_research_preset(): array {
    return ['id'=>'research','version'=>6,'frontend_authority'=>'theme','languages'=>['en','es'],
        'default_language'=>'en','pages'=>['home'=>['role'=>'front-page'],'about'=>['role'=>'profile']]];
}
function expect(bool $yes, string $message): void {
    if (! $yes) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
function error_code(mixed $v, string $code): bool {
    return $v instanceof WP_Error && $v->get_error_code() === $code;
}
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-contract.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-plan.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-executor.php';

$research = new Eduardo_Research_Manager_Research_Mutation_Policy();
expect($research->allowed_action_types() === [
    'option','post_meta','post_field','create_page','create_insight',
    'create_line','create_output','create_project','create_software','create_dataset',
], 'all 0.11.1 Research action types unchanged');
expect($research->allowed_option_keys() === [
    'eduardo_research_evidence','eduardo_research_model','eduardo_research_model_es',
    'eduardo_research_identity','eduardo_research_native_languages','show_on_front','page_on_front',
], 'all 0.11.1 fixed options and special guarded options unchanged');

// Registry contains descriptive Research fields, not an implicit write grant.
// The Research mutation policy must explicitly authorize the exact historical union.
$registered_meta = [];
foreach (Eduardo_Research_Manager_Resource_Registry::all() as $resource) {
    foreach ((array) ($resource['meta'] ?? []) as $key) { $registered_meta[] = (string) $key; }
}
$shared_meta = [
    '_eduardo_research_role','_eduardo_research_model',
    '_eduardo_research_manager_creation_token','_eduardo_research_translation_evidence',
    '_research_language','_research_insight_type','_research_insight_line_relations',
    '_research_translation_en','_research_translation_es',
];
$expected_meta = array_values(array_unique(array_merge($shared_meta, $registered_meta)));
$actual_meta = $research->allowed_meta_keys();
expect(count($actual_meta) === count(array_unique($actual_meta)), 'Research meta policy has no duplicate entries');
expect(count($actual_meta) === count($expected_meta), 'Research meta policy exactly preserves registered historical metadata');
expect([] === array_diff($actual_meta, $expected_meta) && [] === array_diff($expected_meta, $actual_meta),
    'Research metadata is an explicitly reviewed exact set, not an extensible prefix');
foreach ($expected_meta as $key) {
    $p = Eduardo_Research_Manager_Plan::create('existing Research meta', [
        ['type'=>'post_meta','post_id'=>17,'key'=>$key,'value'=>'sample'],
    ]);
    expect(is_array($p) && Eduardo_Research_Manager_Plan::validate($p) === true, "legacy $key remains authorized");
}
foreach (['_research_invented','_eduardo_research_new_secret','_wp_capabilities','_research_doi_rogue'] as $key) {
    expect(error_code(Eduardo_Research_Manager_Plan::create('deny unknown Research meta', [
        ['type'=>'post_meta','post_id'=>17,'key'=>$key,'value'=>'sample'],
    ]), 'research_manager_meta_not_allowed'), "metadata $key cannot inherit authorization by prefix");
}

foreach (array_slice($research->allowed_option_keys(), 0, 5) as $key) {
    $plan = Eduardo_Research_Manager_Plan::create('legacy option', [
        ['type'=>'option','key'=>$key,'value'=>['sample'=>1]],
    ]);
    expect(is_array($plan) && Eduardo_Research_Manager_Plan::validate($plan) === true, "legacy $key checksum");
}
$page = Eduardo_Research_Manager_Plan::create('legacy ES surface', [
    ['type'=>'option','key'=>'eduardo_research_surface_about_es','value'=>['headline'=>'Prueba']],
]);
expect(is_array($page), 'registered Research bilingual page is allowed');
expect(error_code(Eduardo_Research_Manager_Plan::create('unknown WP option', [
    ['type'=>'option','key'=>'siteurl','value'=>'https://bad.example'],
]), 'research_manager_option_not_allowed'), 'arbitrary WordPress option blocked');
expect(error_code(Eduardo_Research_Manager_Plan::create('unknown page', [
    ['type'=>'option','key'=>'eduardo_research_surface_unregistered_es','value'=>'x'],
]), 'research_manager_option_not_allowed'), 'unregistered Research surface blocked');
expect(error_code(Eduardo_Research_Manager_Plan::create('unknown action', [
    ['type'=>'update_siteurl','key'=>'siteurl','value'=>'x'],
]), 'research_manager_action_not_allowed'), 'unknown operation blocked');
$front = Eduardo_Research_Manager_Plan::create('front-page guarded', [
    ['type'=>'option','key'=>'show_on_front','value'=>'page'],
    ['type'=>'option','key'=>'page_on_front','value'=>17],
]);
expect(is_array($front) && Eduardo_Research_Manager_Plan::validate($front) === true, 'exact Research Home guards maintained');
expect(error_code(Eduardo_Research_Manager_Plan::create('wrong Home', [
    ['type'=>'option','key'=>'page_on_front','value'=>18],
]), 'research_manager_front_page_option_not_allowed'), 'front page cannot point elsewhere');
expect(error_code(Eduardo_Research_Manager_Plan::create('latest posts', [
    ['type'=>'option','key'=>'show_on_front','value'=>'posts'],
]), 'research_manager_front_page_option_not_allowed'), 'show_on_front remains limited');

final class OtherThemePolicy implements Site_Architect_Mutation_Policy {
    public function allowed_action_types(): array { return ['option']; }
    public function allowed_option_keys(): array { return ['sa_sample_preferences']; }
    public function allowed_meta_keys(): array { return []; }
    public function risk_for_action(array $action, callable $is_theme_owned_page): string { return 'standard'; }
    public function evidence_blocker_message(string $reason): string { return 'Alternate Theme evidence required'; }
}
final class OtherThemeProfile implements Site_Architect_Theme_Profile {
    public function available(): bool { return true; }
    public function preset(): array {
        return ['id'=>'sample','version'=>1,'frontend_authority'=>'theme','default_language'=>'fr',
            'languages'=>['fr','de'],'pages'=>['landing'=>['role'=>'front-page'],'about'=>['role'=>'profile']]];
    }
    public function preset_id(): string { return 'sample'; }
    public function minimum_preset_version(): int { return 1; }
    public function stylesheet(): string { return 'sample-theme'; }
    public function language_storage(): array {
        return ['fr'=>['suffix'=>'_fr','identity_headline_field'=>'headline_fr'],
            'de'=>['suffix'=>'_de','identity_headline_field'=>'headline_de']];
    }
    public function page_option_key(string $key, string $suffix): string { return 'sa_sample_' . $key . $suffix; }
}
$other_policy = new OtherThemePolicy();
$other_contract = new Eduardo_Research_Manager_Contract(new OtherThemeProfile());
expect($other_contract->compatible(), 'explicit alternate Theme contract valid');
$other = Eduardo_Research_Manager_Plan::create('alternate keys', [
    ['type'=>'option','key'=>'sa_sample_preferences','value'=>['layout'=>'wide']],
    ['type'=>'option','key'=>'sa_sample_about_de','value'=>['text'=>'Hallo']],
], [], $other_policy, $other_contract);
expect(is_array($other), 'custom policy + named page options allowed');
expect(Eduardo_Research_Manager_Plan::validate($other, $other_policy, $other_contract) === true, 'alternate plan checksummed under matching policy');
expect(error_code(Eduardo_Research_Manager_Plan::validate($other), 'research_manager_option_not_allowed'), 'legacy default does not validate foreign plan');
$executor = new Eduardo_Research_Manager_Executor(new Eduardo_Research_Manager_Snapshots(), $other_policy, $other_contract);
$preview = $executor->preview($other);
expect(is_array($preview) && count($preview['actions']) === 2 && $preview['apply_allowed'], 'Executor preview passes explicit policy to Plan and gate');
expect(error_code((new Eduardo_Research_Manager_Executor(new Eduardo_Research_Manager_Snapshots()))->preview($other), 'research_manager_option_not_allowed'), 'legacy executor rejects foreign plan');
foreach (['siteurl','eduardo_research_model','sa_sample_unknown_de','sa_sample_about_es','show_on_front'] as $key) {
    expect(error_code(Eduardo_Research_Manager_Plan::create('unauthorized foreign option', [
        ['type'=>'option','key'=>$key,'value'=>'x'],
    ], [], $other_policy, $other_contract), 'research_manager_option_not_allowed'), "foreign option $key rejected");
}
// A distinct Theme may explicitly opt in to one metadata field without inheriting Research.
$foreign_meta = new class implements Site_Architect_Mutation_Policy, Site_Architect_Resource_Ownership_Policy {
    public function allowed_action_types(): array { return ['post_meta']; }
    public function allowed_option_keys(): array { return []; }
    public function allowed_meta_keys(): array { return ['sa_sample_flag']; }
    public function owns_resource(string $theme_id, string $post_type, bool $is_theme_owned_page): bool {
        return 'sample' === $theme_id && 'page' === $post_type && $is_theme_owned_page;
    }
    public function allowed_resource_meta_keys(string $post_type): array { return 'page' === $post_type ? ['sa_sample_flag'] : []; }
    public function allowed_resource_post_fields(string $post_type): array { return []; }
    public function risk_for_action(array $action, callable $is_theme_owned_page): string { return 'evidence-required'; }
    public function evidence_blocker_message(string $reason): string { return 'Alternate Theme evidence required'; }
};
$foreign_plan = Eduardo_Research_Manager_Plan::create('foreign meta', [
    ['type'=>'post_meta','post_id'=>31,'key'=>'sa_sample_flag','value'=>'enabled'],
], [], $foreign_meta, $other_contract);
expect(is_array($foreign_plan) &&
    Eduardo_Research_Manager_Plan::validate($foreign_plan, $foreign_meta, $other_contract) === true,
    'other Theme allows one exact own metadata field');
expect(error_code(Eduardo_Research_Manager_Plan::validate($foreign_plan), 'research_manager_meta_not_allowed'),
    'Research default cannot validate foreign metadata');
foreach (['_research_doi','_eduardo_research_model','sa_sample_flag_extra','_wp_capabilities'] as $key) {
    expect(error_code(Eduardo_Research_Manager_Plan::create('forbid unrelated meta', [
        ['type'=>'post_meta','post_id'=>17,'key'=>$key,'value'=>'x'],
    ], [], $foreign_meta, $other_contract), 'research_manager_meta_not_allowed'),
        "foreign Theme cannot write $key");
}
expect(error_code(Eduardo_Research_Manager_Plan::create('Research operation leakage', [
    ['type'=>'create_insight','slug'=>'a'],
], [], $other_policy, $other_contract), 'research_manager_action_not_allowed'), 'Research creation cannot be inherited by other contract');
expect(error_code(Eduardo_Research_Manager_Plan::create('post field leakage', [
    ['type'=>'post_field','post_id'=>1,'field'=>'post_content','value'=>'x'],
], [], $other_policy, $other_contract), 'research_manager_action_not_allowed'), 'post field mutations not automatically inherited');
$unknown = new class implements Site_Architect_Mutation_Policy {
    public function allowed_action_types(): array { return ['run_php']; }
    public function allowed_option_keys(): array { return ['*']; }
    public function allowed_meta_keys(): array { return ['*']; }
    public function risk_for_action(array $action, callable $is_theme_owned_page): string { return 'untrusted-risk'; }
    public function evidence_blocker_message(string $reason): string { return ''; }
};
expect(error_code(Eduardo_Research_Manager_Plan::create('unsupported executor type', [
    ['type'=>'run_php','code'=>'phpinfo();'],
], [], $unknown, $other_contract), 'research_manager_action_not_allowed'), 'policy declarations cannot invent unimplemented executor types');

// The generic Plan now delegates academic heuristics without reducing risk.
$risk_samples = [
    [['type'=>'create_page'], 'standard'],
    [['type'=>'create_insight'], 'editorial-review'],
    [['type'=>'create_line'], 'evidence-required'],
    [['type'=>'create_output'], 'evidence-required'],
    [['type'=>'create_project'], 'evidence-required'],
    [['type'=>'create_software'], 'evidence-required'],
    [['type'=>'create_dataset'], 'evidence-required'],
    [['type'=>'post_field','field'=>'post_title','post_id'=>23], 'evidence-required'],
    [['type'=>'post_field','field'=>'post_title','post_id'=>17], 'editorial-review'],
    [['type'=>'post_field','field'=>'post_status','post_id'=>17], 'standard'],
    [['type'=>'post_meta','key'=>'_eduardo_research_role','post_id'=>17], 'standard'],
    [['type'=>'post_meta','key'=>'_research_doi','post_id'=>23], 'evidence-required'],
    [['type'=>'post_meta','key'=>'_research_review_status','post_id'=>23], 'evidence-required'],
    [['type'=>'post_meta','key'=>'_research_insight_type','post_id'=>17], 'editorial-review'],
    [['type'=>'option','key'=>'eduardo_research_surface_about_es'], 'editorial-review'],
    [['type'=>'option','key'=>'eduardo_research_model_es'], 'editorial-review'],
    [['type'=>'option','key'=>'show_on_front'], 'standard'],
];
foreach ($risk_samples as [$action, $expected]) {
    expect(Eduardo_Research_Manager_Plan::action_risk($action) === $expected,
        'Research risk parity for ' . ($action['type'] ?? '') . ':' . ($action['key'] ?? $action['field'] ?? ''));
}
expect(Eduardo_Research_Manager_Plan::action_risk(['type'=>'execute_php']) === 'evidence-required',
    'unknown operation risk is always most restrictive');
expect(Eduardo_Research_Manager_Plan::action_risk(['type'=>'option','key'=>'sa_sample_preferences'], $other_policy, $other_contract) === 'standard',
    'other Theme policy controls only its explicitly provided risk classification');
expect(Eduardo_Research_Manager_Plan::action_risk(['type'=>'option','key'=>'eduardo_research_model'], $other_policy, $other_contract) === 'standard',
    'risk classification alone never authorizes a write; allowlist remains separate');
expect(Eduardo_Research_Manager_Plan::action_risk(['type'=>'run_php'], $unknown, $other_contract) === 'evidence-required',
    'invalid policy risk labels are promoted to strongest level');

$legacy_evidence = Eduardo_Research_Manager_Plan::create('Evidence-sensitive Research metadata', [
    ['type'=>'post_meta','post_id'=>17,'key'=>'_research_doi','value'=>'10.1000/test'],
]);
expect(is_array($legacy_evidence) && $legacy_evidence['risk'] === 'evidence-required',
    'Research evidence-sensitive Plan risk remains unchanged');
expect(error_code(Eduardo_Research_Manager_Plan::apply_gate($legacy_evidence), 'research_manager_evidence_required'),
    'Core still blocks Apply without evidence confirmation');
$missing_evidence_message = Eduardo_Research_Manager_Plan::apply_gate($legacy_evidence);
expect($missing_evidence_message->get_error_message() ===
    'This mutation changes an evidence-sensitive academic claim. Confirm evidence and rebuild the plan before Apply.',
    'Research 0.11.1 evidence blocker copy remains identical');
$confirmed_only = Eduardo_Research_Manager_Plan::create('Missing evidence reference', [
    ['type'=>'post_meta','post_id'=>17,'key'=>'_research_doi','value'=>'10.1000/test'],
], ['evidence_confirmed'=>true]);
expect(error_code(Eduardo_Research_Manager_Plan::apply_gate($confirmed_only), 'research_manager_evidence_reference_required'),
    'Core requires nonempty source even if confirmation was provided');
expect(Eduardo_Research_Manager_Plan::apply_gate($confirmed_only)->get_error_message() ===
    'Evidence-sensitive mutations require a source or verification reference before Apply.',
    'Research reference blocker copy remains identical');
$verified_evidence = Eduardo_Research_Manager_Plan::create('Verified Research metadata', [
    ['type'=>'post_meta','post_id'=>17,'key'=>'_research_doi','value'=>'10.1000/test'],
], ['evidence_confirmed'=>true,'evidence_reference'=>'https://doi.org/10.1000/test']);
expect(Eduardo_Research_Manager_Plan::apply_gate($verified_evidence) === true,
    'Core accepts a checksum-valid, explicitly evidenced Research Plan');
$changed_risk = $verified_evidence;
$changed_risk['risk'] = 'standard';
expect(error_code(Eduardo_Research_Manager_Plan::apply_gate($changed_risk), 'research_manager_plan_changed'),
    'Risk may not be downgraded in a serialized Plan after creation');

$foreign_evidence = Eduardo_Research_Manager_Plan::create('Other Theme strict meta', [
    ['type'=>'post_meta','post_id'=>31,'key'=>'sa_sample_flag','value'=>'enabled'],
], [], $foreign_meta, $other_contract);
expect(is_array($foreign_evidence) && $foreign_evidence['risk'] === 'evidence-required',
    'A distinct Theme can opt into strict evidence policy without Research semantics');
expect(error_code(Eduardo_Research_Manager_Plan::apply_gate($foreign_evidence, $foreign_meta, $other_contract),
    'research_manager_evidence_required'), 'Evidence cannot be bypassed by alternate Theme');
expect(Eduardo_Research_Manager_Plan::apply_gate($foreign_evidence, $foreign_meta, $other_contract)->get_error_message()
    === 'Alternate Theme evidence required', 'Other Theme blocker text is contract-owned');
$foreign_preview = (new Eduardo_Research_Manager_Executor(
    new Eduardo_Research_Manager_Snapshots(), $foreign_meta, $other_contract
))->preview($foreign_evidence);
expect(is_array($foreign_preview) && $foreign_preview['risk'] === 'evidence-required'
    && $foreign_preview['actions'][0]['risk'] === 'evidence-required'
    && $foreign_preview['apply_allowed'] === false,
    'Executor preview classifies and blocks the same explicit evidence-sensitive action');
expect(error_code(Eduardo_Research_Manager_Plan::apply_gate($foreign_evidence),
    'research_manager_meta_not_allowed'), 'Default Research cannot validate foreign evidence Plan');


/** #475.6: Research typed schemas live in the vertical, and remain fail-closed. */
$insight = Eduardo_Research_Manager_Plan::create('Research Insight normalizer parity', [
    ['type'=>'create_insight','title'=>'Academic note','slug'=>'academic-note','excerpt'=>'Summary',
     'content'=>'<p>Evidence coming</p>','language'=>'es','status'=>'draft',
     'insight_type'=>'research_note','creation_token'=>str_repeat('a', 32)],
]);
expect(is_array($insight) && $insight['risk'] === 'editorial-review'
    && $insight['actions'][0]['language'] === 'es'
    && $insight['actions'][0]['slug'] === 'academic-note'
    && Eduardo_Research_Manager_Plan::validate($insight) === true,
    'Research Insight creation retains normalized fields, checksum and risk');
expect(error_code(Eduardo_Research_Manager_Plan::create('Invalid Research provenance', [
    ['type'=>'create_insight','title'=>'Academic note','slug'=>'academic-note',
     'creation_token'=>'invalid-token'],
]), 'research_manager_invalid_insight_creation'), 'Research Insight creation token remains bounded');
expect(error_code(Eduardo_Research_Manager_Plan::create('Long Research content', [
    ['type'=>'create_insight','title'=>str_repeat('A', 201),'slug'=>'academic-note',
     'creation_token'=>str_repeat('a', 32)],
]), 'research_manager_insight_content_too_large'), 'Research vertical preserves content byte bounds');

$forged_research_policy = new class implements Site_Architect_Mutation_Policy {
    public function allowed_action_types(): array { return ['create_insight']; }
    public function allowed_option_keys(): array { return []; }
    public function allowed_meta_keys(): array { return []; }
    public function risk_for_action(array $action, callable $is_theme_owned_page): string { return 'standard'; }
    public function evidence_blocker_message(string $reason): string { return ''; }
};
expect(error_code(Eduardo_Research_Manager_Plan::create('Foreign policy cannot claim Research normalizer', [
    ['type'=>'create_insight','title'=>'Borrowed','slug'=>'borrowed',
     'creation_token'=>str_repeat('a', 32)],
], [], $forged_research_policy, $other_contract), 'research_manager_action_not_allowed'),
    'merely declaring Research creation does not authorize another Theme');
expect(error_code(Eduardo_Research_Manager_Plan::create('Research policy on foreign Theme', [
    ['type'=>'create_insight','title'=>'Cross-Theme','slug'=>'cross-theme',
     'creation_token'=>str_repeat('a', 32)],
], [], $research, $other_contract), 'research_manager_action_not_allowed'),
    'foreign Theme cannot borrow Research creation even with Research policy injection');
expect(error_code(Eduardo_Research_Manager_Research_Creation_Normalizer::normalize('create_arbitrary', []),
    'research_manager_action_not_allowed'), 'Research normalizer has no dynamic Registry fallback');


/** #475.7: Research execution identity and rollback cannot bleed across Themes. */
$expected_research_post_types = [
    'create_line'=>'research_line',
    'create_output'=>'research_output',
    'create_project'=>'research_project',
    'create_software'=>'research_software',
    'create_dataset'=>'research_dataset',
];
expect(Eduardo_Research_Manager_Research_Execution_Spec::creation_actions() === [
    'create_insight','create_line','create_output','create_project','create_software','create_dataset',
], 'Research execution has a fixed six-action identity set');
$spec_reflection = new ReflectionMethod(Eduardo_Research_Manager_Executor::class, 'research_object_spec');
$read_reflection = new ReflectionMethod(Eduardo_Research_Manager_Executor::class, 'read_state');
$write_reflection = new ReflectionMethod(Eduardo_Research_Manager_Executor::class, 'write_action');
$restore_reflection = new ReflectionMethod(Eduardo_Research_Manager_Executor::class, 'restore_records');
$research_executor = new Eduardo_Research_Manager_Executor(new Eduardo_Research_Manager_Snapshots(), $research, new Eduardo_Research_Manager_Contract());
$foreign_executor = new Eduardo_Research_Manager_Executor(new Eduardo_Research_Manager_Snapshots(), $other_policy, $other_contract);
$mixed_executor = new Eduardo_Research_Manager_Executor(new Eduardo_Research_Manager_Snapshots(), $research, $other_contract);
$borrowed_executor = new Eduardo_Research_Manager_Executor(new Eduardo_Research_Manager_Snapshots(), $forged_research_policy, new Eduardo_Research_Manager_Contract());
foreach ($expected_research_post_types as $action_type => $post_type) {
    $spec = Eduardo_Research_Manager_Research_Execution_Spec::for_action($action_type);
    $registry = Eduardo_Research_Manager_Resource_Registry::by_creation_action($action_type);
    expect(is_array($spec) && $spec['post_type'] === $post_type
        && $spec['meta'] === $registry['meta']
        && $spec['array_fields'] === $registry['array_fields']
        && $spec['defaults'] === $registry['defaults']
        && $spec['creation_conflict'] === $registry['creation_conflict']
        && $spec['rollback_conflict'] === $registry['rollback_conflict']
        && $spec_reflection->invoke($research_executor, $action_type) === $spec,
        'Research execution retains exactly the accepted schema for ' . $action_type);
    expect([] === $spec_reflection->invoke($foreign_executor, $action_type)
        && [] === $spec_reflection->invoke($mixed_executor, $action_type)
        && [] === $spec_reflection->invoke($borrowed_executor, $action_type),
        'foreign Theme or mutation policy cannot resolve Research spec for ' . $action_type);
}
foreach (['create_arbitrary','create_insight','option','post_meta'] as $action_type) {
    expect([] === Eduardo_Research_Manager_Research_Execution_Spec::for_action($action_type),
        'Resource Registry cannot supply an unreviewed action spec for ' . $action_type);
}
$foreign_create = ['type'=>'create_insight','creation_token'=>str_repeat('b', 32),'slug'=>'cross-theme'];
foreach ([$foreign_executor, $mixed_executor, $borrowed_executor] as $outsider) {
    expect(error_code($read_reflection->invoke($outsider, $foreign_create), 'research_manager_action_not_allowed'),
        'Research Insight read-state is not inherited by another Theme');
    expect(error_code($write_reflection->invoke($outsider, $foreign_create), 'research_manager_action_not_allowed'),
        'Research Insight write is not inherited by another Theme');
    expect(error_code($restore_reflection->invoke($outsider, [
        ['action'=>$foreign_create,'state'=>['exists'=>false,'value'=>null]],
    ]), 'research_manager_action_not_allowed'), 'Research Insight rollback is not inherited by another Theme');
}


/** #475.8: no Theme can inherit post fields from a global action/key allowlist. */
expect($research instanceof Site_Architect_Resource_Ownership_Policy, 'Research declares resource ownership explicitly');
expect($research->owns_resource('research','page',true), 'Research owns only contracted Theme Pages');
expect(! $research->owns_resource('research','page',false), 'unowned WordPress pages fail closed');
expect($research->owns_resource('research','research_line',false), 'known Research CPT belongs to Research');
expect(! $research->owns_resource('sample','research_line',false), 'Research CPT cannot be borrowed by sample Theme');
expect(! $research->owns_resource('research','product',false), 'unknown CPT cannot inherit metadata rights');
expect($research->allowed_resource_meta_keys('research_line') === $research->allowed_meta_keys(),
    'the historical finite metadata set is unchanged on owned resources');
expect($research->allowed_resource_post_fields('research_line') === [
    'post_title','post_excerpt','post_content','menu_order','post_status',
], 'the five bounded post fields are unchanged');
expect([] === $research->allowed_resource_meta_keys('product')
    && [] === $research->allowed_resource_post_fields('product'), 'unknown CPT has no Research mutations');

foreach ([
    ['post_meta', ['key'=>'_research_doi','value'=>'10.1000/example']],
    ['post_field', ['field'=>'post_title','value'=>'New title']],
] as [$type, $fields]) {
    expect(error_code(Eduardo_Research_Manager_Plan::create('Foreign Theme cannot borrow Research', [
        array_merge(['type'=>$type,'post_id'=>31], $fields),
    ], [], $research, $other_contract), 'research_manager_resource_not_allowed'),
        'Research policy + foreign Theme rejected for ' . $type);
    expect(error_code(Eduardo_Research_Manager_Plan::create('Unowned WordPress Page', [
        array_merge(['type'=>$type,'post_id'=>32], $fields),
    ]), 'research_manager_resource_not_allowed'), 'unowned Page denied for ' . $type);
    expect(error_code(Eduardo_Research_Manager_Plan::create('Unknown WordPress CPT', [
        array_merge(['type'=>$type,'post_id'=>45], $fields),
    ]), 'research_manager_resource_not_allowed'), 'unknown CPT denied for ' . $type);
}
expect(is_array(Eduardo_Research_Manager_Plan::create('Owned Research CPT', [
    ['type'=>'post_field','post_id'=>23,'field'=>'post_title','value'=>'Research title'],
])), 'owned Research line retains bounded post_field authorization');
expect(error_code(Eduardo_Research_Manager_Plan::create('Foreign Theme targets Research page', [
    ['type'=>'post_meta','post_id'=>17,'key'=>'sa_sample_flag','value'=>'1'],
], [], $foreign_meta, $other_contract), 'research_manager_resource_not_allowed'),
    'sample Theme cannot mutate Eduardo Research Home');
expect(error_code(Eduardo_Research_Manager_Plan::create('Research policy on sample Theme', [
    ['type'=>'post_meta','post_id'=>31,'key'=>'_research_doi','value'=>'x'],
], [], $research, $other_contract), 'research_manager_resource_not_allowed'), 'Research meta grant is Theme-bound');

foreach ([
    ['type'=>'post_meta','post_id'=>32,'key'=>'_research_doi','value'=>'x'],
    ['type'=>'post_field','post_id'=>45,'field'=>'post_title','value'=>'x'],
] as $foreign_resource) {
    expect(error_code($read_reflection->invoke($research_executor, $foreign_resource), 'research_manager_resource_not_allowed'),
        'Executor private read rejects unowned post mutation');
    expect(error_code($write_reflection->invoke($research_executor, $foreign_resource), 'research_manager_resource_not_allowed'),
        'Executor private write rejects unowned post mutation before WP writes');
    expect(error_code($restore_reflection->invoke($research_executor, [
        ['action'=>$foreign_resource,'state'=>['exists'=>false,'value'=>null]],
    ]), 'research_manager_resource_not_allowed'), 'Executor private rollback rejects unowned post mutation');
}
expect(error_code($read_reflection->invoke($mixed_executor, [
    'type'=>'post_meta','post_id'=>31,'key'=>'_research_doi',
]), 'research_manager_resource_not_allowed'), 'foreign Theme cannot read Research metadata via Research policy');

echo "PASS: Research risk/evidence parity, resource ownership and alternate Theme policy\n";
