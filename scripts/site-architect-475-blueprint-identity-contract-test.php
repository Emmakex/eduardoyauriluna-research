<?php
/** #475.14 offline regression: Research identity compatibility and Theme ownership. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('EDUARDO_RESEARCH_MANAGER_DIR', dirname(__DIR__) . '/wordpress/plugins/eduardo-research-manager/');

final class WP_Error {
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? ''; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function current_user_can(string $capability): bool { return 'manage_options' === $capability; }
function expect(bool $test, string $message): void {
    if (! $test) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
$GLOBALS['stored_identity'] = [];
function get_option(string $key, mixed $default): mixed {
    return 'eduardo_research_identity' === $key ? $GLOBALS['stored_identity'] : $default;
}

final class Eduardo_Research_Manager_Mode {
    public static function current(): string { return 'greenfield'; }
}
final class Eduardo_Research_Manager_Contract {
    public function languages(): array { return ['en','es']; }
    public function identity_headline_field(string $language): string|WP_Error {
        return in_array($language, ['en','es'], true)
            ? 'headline_' . $language
            : new WP_Error('invalid_language', 'Unsupported language');
    }
}
final class Eduardo_Research_Manager {
    public static function contract(): Eduardo_Research_Manager_Contract {
        return new Eduardo_Research_Manager_Contract();
    }
}
final class Eduardo_Research_Manager_Identity_Profile {
    public int $preview_calls = 0;
    public int $apply_calls = 0;
    public static function option_key(): string { return 'eduardo_research_identity'; }
    public function preview_update(array $seed, bool $evidence, string $ref): array|WP_Error {
        $this->preview_calls++;
        return [
            'status'=>'change', 'apply_allowed'=>true, 'plan_id'=>'identity-p1',
            'preview'=>$seed, 'reference'=>$ref, 'evidence'=>$evidence,
        ];
    }
    public function apply_preview(array $prepared): array|WP_Error {
        $this->apply_calls++;
        return ['status'=>'applied', 'snapshot_id'=>'identity-s1', 'verification'=>['verified'=>true]];
    }
}
final class Eduardo_Research_Manager_Blueprint {
    public function __construct(private string $theme = 'research') {}
    public function theme_id(): string { return $this->theme; }
    public function validate(array $blueprint): array|WP_Error {
        return array_merge(['version'=>1,'site'=>[],'languages'=>['en','es'],'pages'=>[]], $blueprint);
    }
}
final class Eduardo_Research_Manager_Page_Resource {
    public int $plans = 0;
    public function build_hydration_plan(string $key, string $lang, array $slots, string $intent): array|WP_Error {
        $this->plans++;
        return ['id'=>'page-p1','key'=>$key,'language'=>$lang,'slots'=>$slots];
    }
    public function verify_hydration(string $key, string $language, array $slots): array|WP_Error {
        return ['verified'=>true];
    }
}
final class Eduardo_Research_Manager_Executor {
    public int $preview_calls = 0;
    public int $apply_calls = 0;
    public int $rollback_calls = 0;
    public function preview(array $plan): array|WP_Error {
        $this->preview_calls++;
        return ['apply_allowed'=>true];
    }
    public function apply(array $plan): array|WP_Error {
        $this->apply_calls++;
        return ['status'=>'applied','snapshot_id'=>'page-s1'];
    }
    public function verify(array $plan): array|WP_Error { return ['verified'=>true]; }
    public function rollback(string $id): array|WP_Error {
        $this->rollback_calls++;
        return ['status'=>'rolled-back','id'=>$id];
    }
}
$includes=EDUARDO_RESEARCH_MANAGER_DIR.'includes/';
require_once $includes.'class-site-architect-blueprint-identity-resolver.php';
require_once $includes.'class-research-manager-research-blueprint-identity.php';
require_once $includes.'class-research-manager-blueprint-hydrator.php';

$resolver = new Eduardo_Research_Manager_Research_Blueprint_Identity();
expect($resolver->theme_id() === 'research', 'Research resolver exact Theme');
$canonical = [
    'site'=>['identity'=>['name'=>' Eduardo <b>Yauri</b> ', 'headlines'=>['en'=>'Researcher EN','es'=>'Investigador ES']]],
    'languages'=>['en','es'], 'pages'=>[
        ['key'=>'home','slots'=>['en'=>['researcher-name'=>'Legacy name EN'], 'es'=>['researcher-name'=>'Legacy name ES']]],
    ],
];
$seed=$resolver->seed($canonical);
expect($seed === ['name'=>'Eduardo Yauri','headline_en'=>'Researcher EN','headline_es'=>'Investigador ES'],
    'Canonical identity takes precedence; sanitizes and maps headlines as before');
expect($resolver->has_seed_data($canonical), 'Canonical identity seed presence');
$GLOBALS['stored_identity']=['name'=>'Already seeded'];
expect($resolver->seed($canonical) === [], 'Existing identity is never reseeded');
$GLOBALS['stored_identity']=[];

$legacy = ['site'=>[], 'languages'=>['en','es'], 'pages'=>[
    ['key'=>'home','slots'=>[
        'en'=>['researcher-name'=>'Eduardo Yauri','researcher-headline'=>'EN headline'],
        'es'=>['researcher-name'=>'Eduardo Yauri','researcher-headline'=>'ES titular'],
    ]],
]];
expect($resolver->seed($legacy) === ['headline_en'=>'EN headline','headline_es'=>'ES titular','name'=>'Eduardo Yauri'],
    'Legacy Home fallback retains historic seed keys and order');
expect($resolver->has_seed_data($legacy), 'Legacy Home alias presence detected');
$legacy['pages'][0]['slots']['es']['researcher-name']='Other person';
$mismatch=$resolver->seed($legacy);
expect(is_wp_error($mismatch) && $mismatch->get_error_code()==='research_manager_blueprint_identity_mismatch',
    'Legacy EN/ES researcher identity mismatch retains exact error code');
expect($resolver->filter_page_slots('home',['researcher-name'=>'x','researcher-headline'=>'y','heading'=>'H']) === ['heading'=>'H'],
    'Only Research Home legacy identity aliases excluded from page hydration');
expect($resolver->filter_page_slots('about',['researcher-name'=>'x','heading'=>'H']) === ['researcher-name'=>'x','heading'=>'H'],
    'Other Page slots are not silently rewritten');

$bp = new Eduardo_Research_Manager_Blueprint('research');
$pages = new Eduardo_Research_Manager_Page_Resource();
$executor = new Eduardo_Research_Manager_Executor();
$profile = new Eduardo_Research_Manager_Identity_Profile();
$hydrator = new Eduardo_Research_Manager_Blueprint_Hydrator($bp,$pages,$executor,$profile,$resolver);
$payload=$canonical;
$payload['pages'][0]['slots']['en']['heading']='Welcome';
$preview=$hydrator->preview($payload);
expect(is_array($preview) && $preview['apply_allowed'] && $preview['operation_count']===2,
    'Research canonical identity and filtered Home Page preview ordering preserved');
expect($preview['operations'][0]['key']==='researcher-identity'
    && $preview['operations'][1]['resource']==='pages', 'Identity remains before Page operation');
$applied=$hydrator->apply($payload);
expect(is_array($applied) && $applied['snapshot_ids']===['identity-s1','page-s1']
    && $profile->apply_calls===1, 'Apply preserves identity + Page snapshots and order');
$rollback=$hydrator->rollback($applied['snapshot_ids']);
expect(is_array($rollback) && $rollback['snapshot_count']===2 && $executor->rollback_calls===2,
    'Snapshot rollback remains supported');

final class AlternateIdentity implements Site_Architect_Blueprint_Identity_Resolver {
    public int $calls=0;
    public function __construct(private string $id) {}
    public function theme_id(): string { return $this->id; }
    public function seed(array $normalized): array|WP_Error { $this->calls++; return []; }
    public function has_seed_data(array $normalized): bool { $this->calls++; return false; }
    public function filter_page_slots(string $page_key, array $values): array { $this->calls++; return $values; }
}
$alt=new AlternateIdentity('second-theme');
$altPages=new Eduardo_Research_Manager_Page_Resource();
$altExecutor=new Eduardo_Research_Manager_Executor();
$altProfile=new Eduardo_Research_Manager_Identity_Profile();
$independent=new Eduardo_Research_Manager_Blueprint_Hydrator(
    new Eduardo_Research_Manager_Blueprint('second-theme'), $altPages, $altExecutor, $altProfile, $alt
);
$empty=['languages'=>['fr'], 'pages'=>[]];
expect(is_array($independent->preview($empty)), 'Explicit second Theme resolver can preview its own Blueprint');
expect(is_array($independent->apply($empty)), 'Explicit second Theme resolver can apply its own empty Blueprint');
expect(is_array($independent->rollback([])), 'Explicit second Theme resolver can rollback');

foreach (['preview','apply','rollback'] as $action) {
    $wrong=new AlternateIdentity('other-theme');
    $wrongExecutor=new Eduardo_Research_Manager_Executor();
    $wrongProfile=new Eduardo_Research_Manager_Identity_Profile();
    $wrongHydrator=new Eduardo_Research_Manager_Blueprint_Hydrator(
        new Eduardo_Research_Manager_Blueprint('research'),
        new Eduardo_Research_Manager_Page_Resource(), $wrongExecutor, $wrongProfile, $wrong
    );
    $out=$action==='rollback' ? $wrongHydrator->rollback(['s1']) : $wrongHydrator->$action($payload);
    expect(is_wp_error($out) && $out->get_error_code()==='research_manager_blueprint_identity_theme_mismatch',
        'Cross-Theme identity ' . $action . ' must fail closed');
    expect($wrong->calls===0 && $wrongProfile->preview_calls===0
        && $wrongExecutor->preview_calls===0 && $wrongExecutor->apply_calls===0
        && $wrongExecutor->rollback_calls===0, 'Cross-Theme denial before any phase or mutation');
}
echo "PASS: Research canonical+legacy Blueprint identity parity; second-Theme positive; cross-Theme fail closed; snapshot rollback\n";
