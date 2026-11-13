<?php
/** Offline regression: Theme-bound Blueprint catalogs, no WordPress writes. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('EDUARDO_RESEARCH_MANAGER_DIR', dirname(__DIR__) . '/wordpress/plugins/eduardo-research-manager/');

final class WP_Error {
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function sanitize_key(string $value): string {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? '';
}
function sanitize_title(string $value): string {
    return trim(strtolower(preg_replace('/[^a-z0-9-]/i', '-', $value) ?? ''), '-');
}
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function expect(bool $yes, string $message): void {
    if (! $yes) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
function error_code(mixed $value, string $expected): bool {
    return $value instanceof WP_Error && $value->get_error_code() === $expected;
}

final class Eduardo_Research_Manager_Mode {
    public static function is_greenfield(): bool { return true; }
    public static function current(): string { return 'greenfield'; }
}

/** Read-only site contract fixture. WordPress never boots in this test. */
final class Eduardo_Research_Manager_Contract {
    public function __construct(private string $id = 'research') {}
    public function compatible(): bool { return true; }
    public function preset(): array { return ['id'=>$this->id]; }
    public function languages(): array { return 'research' === $this->id ? ['en','es'] : ['fr','de']; }
    public function default_language(): string { return $this->languages()[0]; }
    public function page_state(string $key): array { return ['exists'=>false,'key'=>$key]; }
}
class BlueprintStubResource {
    public function __construct(private string $key) {}
    public function build_creation_plan(mixed ...$args): array {
        return ['service'=>$this->key,'args'=>$args];
    }
    public function find_by_slug(string $slug, string $language): int {
        return 'lines' === $this->key && 'existing-line' === $slug && 'en' === $language ? 77 : 0;
    }
}
final class Eduardo_Research_Manager {
    public static function research_services(): array {
        $services = [];
        foreach (['pages','insights','lines','outputs','projects','software','datasets'] as $key) {
            $services[$key] = new BlueprintStubResource($key);
        }
        return $services;
    }
}

$root = EDUARDO_RESEARCH_MANAGER_DIR . 'includes/';
require_once $root . 'class-research-manager-blueprint.php';
require_once $root . 'class-research-manager-greenfield.php';
require_once $root . 'class-research-manager-blueprint-compiler.php';

$catalog = new Eduardo_Research_Manager_Research_Blueprint_Resources();
$registry = Eduardo_Research_Manager_Resource_Registry::vertical_blueprint_resource_keys();
$expected = ['insights','lines','outputs','projects','software','datasets'];
expect($catalog->theme_id() === 'research', 'Research Blueprint catalog has exact Theme identity');
expect($catalog->resource_keys() === $expected && $registry === $expected,
    'Research Blueprint keys and order preserve seven-service 0.11.1 contract');
foreach ($expected as $key) {
    expect($catalog->resource_label($key) === Eduardo_Research_Manager_Resource_Registry::blueprint_resource_label($key),
        'Research intent label unchanged for ' . $key);
}
expect('' === $catalog->resource_label('foreign_resource'), 'unknown Blueprint label is never inferred');
$research = new Eduardo_Research_Manager_Contract();
$blueprints = new Eduardo_Research_Manager_Blueprint($catalog, $research);
$greenfield = new Eduardo_Research_Manager_Greenfield($research, $catalog);
$compiler = new Eduardo_Research_Manager_Blueprint_Compiler($blueprints, $greenfield);
expect($blueprints->resource_keys() === array_merge(['pages'], $expected), 'Blueprint keys preserve Pages and Research ordering');
expect($greenfield->ready() === true, 'Research provider belongs to Research Theme');
expect(array_keys($greenfield->resources()) === array_merge(['pages'], $expected),
    'Greenfield binds the seven exact installed 0.11.1 services');

$payload = [
    'version'=>1,'languages'=>['en','es'],
    'pages'=>[['key'=>'home']],
    'insights'=>[['title'=>'Insight sample','evidence'=>['confirmed'=>false,'reference'=>'']]],
    'lines'=>[
        ['title'=>'Existing Line','slug'=>'existing-line','language'=>'en'],
        ['title'=>'New Line','slug'=>'new-line','language'=>'es',
         'evidence'=>['confirmed'=>true,'reference'=>'CI-reviewed source'],
         'translation_key'=>'line-one','line_refs'=>['foo']],
    ],
];
$normalized = $blueprints->validate($payload);
expect(is_array($normalized) && array_keys($normalized) === [
    'version','site','languages','pages','insights','lines','outputs','projects','software','datasets'
], 'Research normalized Blueprint preserves exact key order and resource families');
$summary = $blueprints->summarize($payload);
expect(is_array($summary) && $summary['total_resources'] === 4
    && $summary['resources']['lines'] === 2 && $summary['resources']['insights'] === 1,
    'Research Blueprint summary unchanged');
$compiled = $compiler->compile($payload);
expect(is_array($compiled) && $compiled['plan_count'] === 3 && $compiled['skipped_count'] === 1,
    'Research compiler still plans Pages/Insights/new Lines and skips existing Line');
expect($compiled['plans'][0]['resource'] === 'pages'
    && $compiled['plans'][1]['resource'] === 'insights'
    && $compiled['plans'][2]['resource'] === 'lines'
    && $compiled['plans'][2]['plan']['service'] === 'lines',
    'Research services retain exact creation order');
expect($compiled['skipped'][0] === [
    'resource'=>'lines','index'=>0,'slug'=>'existing-line','language'=>'en',
    'reason'=>'already_exists','post_id'=>77,
], 'Research Line idempotency skip payload and order remain byte-compatible');
$line_args = $compiled['plans'][2]['plan']['args'];
expect(! isset($line_args[0]['translation_key']) && ! isset($line_args[0]['line_refs'])
    && ! isset($line_args[0]['evidence'])
    && $line_args[2] === ['evidence_confirmed'=>true,'evidence_reference'=>'CI-reviewed source'],
    'Compiler preserves stripped relation fields and evidence Plan context');
expect(error_code($blueprints->validate(['unknown_research_key'=>[]]), 'research_manager_blueprint_key_unknown'),
    'Unregistered Blueprint resources remain closed');
$bad = $payload;
$bad['lines'][1]['evidence'] = ['confirmed'=>true];
expect(error_code($compiler->compile($bad), 'research_manager_blueprint_record_invalid'),
    'Evidence reference is still required for confirmed academic claim');

final class SiteArchitectSampleBlueprintResources implements Site_Architect_Blueprint_Resource_Provider {
    public function theme_id(): string { return 'sample'; }
    public function resource_keys(): array { return ['articles']; }
    public function resource_label(string $key): string { return 'articles' === $key ? 'article' : ''; }
    public function services(): array {
        return ['pages'=>new BlueprintStubResource('pages'),'articles'=>new BlueprintStubResource('articles')];
    }
    public function existing_record(string $key, array $record, array $services, string $language): array|WP_Error {
        return [];
    }
}
$sample = new SiteArchitectSampleBlueprintResources();
$sample_contract = new Eduardo_Research_Manager_Contract('sample');
$sample_blueprints = new Eduardo_Research_Manager_Blueprint($sample, $sample_contract);
$sample_greenfield = new Eduardo_Research_Manager_Greenfield($sample_contract, $sample);
$sample_compiler = new Eduardo_Research_Manager_Blueprint_Compiler($sample_blueprints, $sample_greenfield);
expect($sample_blueprints->resource_keys() === ['pages','articles']
    && $sample_greenfield->ready() === true, 'alternate Theme owns its own explicit catalog');
$sample_plan = $sample_compiler->compile([
    'version'=>1,'languages'=>['fr'],'pages'=>[['key'=>'landing']],
    'articles'=>[['title'=>'Sample editorial article']],
]);
expect(is_array($sample_plan) && $sample_plan['plan_count'] === 2
    && $sample_plan['plans'][1]['resource'] === 'articles'
    && $sample_plan['plans'][1]['plan']['args'][1] === 'Greenfield blueprint: create article record 1',
    'alternate Theme compiles its declared service without Research labels or schemas');
expect(error_code($sample_blueprints->validate(['insights'=>[]]), 'research_manager_blueprint_key_unknown'),
    'alternate Theme cannot inherit Research Insights');
expect(error_code($sample_blueprints->validate(['lines'=>[]]), 'research_manager_blueprint_key_unknown'),
    'alternate Theme cannot inherit Research Lines');
expect(error_code((new Eduardo_Research_Manager_Greenfield($sample_contract, $catalog))->ready(),
    'research_manager_blueprint_catalog_mismatch'), 'Research service provider cannot be borrowed by another Theme');
expect(error_code((new Eduardo_Research_Manager_Blueprint($catalog, $sample_contract))->validate([]),
    'research_manager_blueprint_catalog_mismatch'), 'Research Blueprint schema cannot be borrowed by another Theme');
expect(error_code((new Eduardo_Research_Manager_Blueprint_Compiler(
    $blueprints, $sample_greenfield
))->compile([]), 'research_manager_blueprint_catalog_mismatch'),
    'Compiler rejects split Blueprint and Greenfield providers');
$broken_provider = new class implements Site_Architect_Blueprint_Resource_Provider {
    public function theme_id(): string { return 'sample'; }
    public function resource_keys(): array { return ['articles']; }
    public function resource_label(string $key): string { return 'article'; }
    public function services(): array { return ['pages'=>new BlueprintStubResource('pages')]; }
    public function existing_record(string $key, array $record, array $services, string $lang): array|WP_Error { return []; }
};
expect(error_code((new Eduardo_Research_Manager_Greenfield($sample_contract, $broken_provider))->resources(),
    'research_manager_blueprint_services_mismatch'), 'missing writer services fail closed before compilation');

echo "PASS: Greenfield Blueprint catalog and service ownership, Research parity and alternate Theme isolation\n";
