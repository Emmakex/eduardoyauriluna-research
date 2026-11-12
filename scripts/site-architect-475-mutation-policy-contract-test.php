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
function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
function sanitize_key(string $v): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($v)) ?? ''; }
function sanitize_text_field(string $v): string { return trim(strip_tags($v)); }
function absint(mixed $v): int { return abs((int) $v); }
function wp_json_encode(mixed $v, int $opts = 0): string|false { return json_encode($v, $opts); }
function get_option(string $key, mixed $default = false): mixed { return $default; }
function get_page_by_path(string $path, string $output = OBJECT, string $type = 'page'): ?WP_Post {
    return $path === 'home' ? new WP_Post(17) : null;
}
function maybe_serialize(mixed $v): string { return serialize($v); }
function get_post(int $post_id): ?WP_Post { return $post_id === 17 ? new WP_Post(17) : null; }
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
$foreign_meta = new class implements Site_Architect_Mutation_Policy {
    public function allowed_action_types(): array { return ['post_meta']; }
    public function allowed_option_keys(): array { return []; }
    public function allowed_meta_keys(): array { return ['sa_sample_flag']; }
};
$foreign_plan = Eduardo_Research_Manager_Plan::create('foreign meta', [
    ['type'=>'post_meta','post_id'=>17,'key'=>'sa_sample_flag','value'=>'enabled'],
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
};
expect(error_code(Eduardo_Research_Manager_Plan::create('unsupported executor type', [
    ['type'=>'run_php','code'=>'phpinfo();'],
], [], $unknown, $other_contract), 'research_manager_action_not_allowed'), 'policy declarations cannot invent unimplemented executor types');

echo "PASS: Research 0.11.1 allowlists exact; alternate policy and Executor are explicit/fail-closed\n";
