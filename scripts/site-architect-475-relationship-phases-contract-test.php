<?php
/** Offline #475.13 contract: vertical pairing/relations are Theme-bound. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('EDUARDO_RESEARCH_MANAGER_DIR', dirname(__DIR__) . '/wordpress/plugins/eduardo-research-manager/');

class WP_Error {
    public function __construct(public string $code, public string $message) {}
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function current_user_can(string $cap): bool { return $cap === 'manage_options'; }
function expect(bool $yes, string $message): void {
    if (! $yes) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
final class Eduardo_Research_Manager_Mode {
    public static function is_greenfield(): bool { return true; }
    public static function current(): string { return 'greenfield'; }
}
final class Eduardo_Research_Manager_Bootstrap {
    public int $called = 0;
    public function preview(array $blueprint): array|WP_Error {
        $this->called++;
        return ['apply_allowed'=>true, 'operation_count'=>0, 'operations'=>[]];
    }
    public function apply(array $blueprint): array|WP_Error {
        $this->called++;
        return ['status'=>'applied', 'snapshot_ids'=>[]];
    }
    public function rollback(array $ids): array|WP_Error {
        $this->called++;
        return ['snapshot_count'=>count($ids)];
    }
}
final class Eduardo_Research_Manager_Blueprint_Hydrator {
    public int $called = 0;
    public function preview(array $blueprint): array|WP_Error {
        $this->called++;
        return ['apply_allowed'=>true, 'operation_count'=>0, 'operations'=>[]];
    }
    public function apply(array $blueprint): array|WP_Error {
        $this->called++;
        return ['status'=>'applied', 'snapshot_ids'=>[]];
    }
    public function rollback(array $ids): array|WP_Error {
        $this->called++;
        return ['snapshot_count'=>count($ids)];
    }
}
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-site-architect-blueprint-resource-provider.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-site-architect-greenfield-relationship-phase.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-greenfield-pipeline.php';

final class FakeProvider implements Site_Architect_Blueprint_Resource_Provider {
    public function __construct(private string $id) {}
    public function theme_id(): string { return $this->id; }
    public function resource_keys(): array { return []; }
    public function resource_label(string $key): string { return $key; }
    public function services(): array { return []; }
    public function existing_record(string $key, array $record, array $services, string $default_language): array|WP_Error { return []; }
}
final class FakeRelationship implements Site_Architect_Greenfield_Relationship_Phase {
    public int $called = 0;
    public function __construct(private string $id) {}
    public function theme_id(): string { return $this->id; }
    public function preview(array $blueprint): array|WP_Error {
        $this->called++;
        return ['apply_allowed'=>true, 'operation_count'=>0, 'operations'=>[]];
    }
    public function apply(array $blueprint): array|WP_Error {
        $this->called++;
        return ['status'=>'applied', 'snapshot_ids'=>[], 'verified'=>true];
    }
    public function rollback(array $ids): array|WP_Error {
        $this->called++;
        return ['status'=>'rolled-back', 'snapshot_count'=>count($ids)];
    }
}

foreach (['research', 'second-theme'] as $theme) {
    $bootstrap = new Eduardo_Research_Manager_Bootstrap();
    $hydrator = new Eduardo_Research_Manager_Blueprint_Hydrator();
    $pair = new FakeRelationship($theme);
    $relations = new FakeRelationship($theme);
    $pipeline = new Eduardo_Research_Manager_Greenfield_Pipeline(
        $bootstrap, $pair, $hydrator, $relations, new FakeProvider($theme)
    );
    $preview = $pipeline->preview([]);
    expect(is_array($preview) && ! empty($preview['apply_allowed']), "$theme: preview");
    expect(array_keys($preview['phases']) === ['bootstrap','pairing','hydration','relations'], "$theme: ordering");
    $result = $pipeline->apply([]);
    expect(is_array($result) && ! empty($result['verified']), "$theme: apply");
    $rollback = $pipeline->rollback(['relations'=>['r1'], 'pairing'=>['p1']]);
    expect(is_array($rollback) && $rollback['status'] === 'rolled-back', "$theme: rollback");
}

foreach ([
    ['research','other','research'],
    ['research','research','other'],
    ['second-theme','research','research'],
    ['','', ''],
] as [$provider_id, $pair_id, $relation_id]) {
    $bootstrap = new Eduardo_Research_Manager_Bootstrap();
    $hydrator = new Eduardo_Research_Manager_Blueprint_Hydrator();
    $pair = new FakeRelationship($pair_id);
    $relations = new FakeRelationship($relation_id);
    $pipeline = new Eduardo_Research_Manager_Greenfield_Pipeline(
        $bootstrap, $pair, $hydrator, $relations, new FakeProvider($provider_id)
    );
    foreach (['preview','apply','rollback'] as $op) {
        $out = $op === 'rollback' ? $pipeline->rollback(['relations'=>['s']]) : $pipeline->$op([]);
        expect(is_wp_error($out) && $out->get_error_code() === 'research_manager_greenfield_relationship_theme_mismatch', "Mismatched $op must fail closed");
    }
    expect($bootstrap->called === 0 && $hydrator->called === 0 && $pair->called === 0 && $relations->called === 0, 'No service may execute on mismatched Theme');
}
echo "PASS: Theme-owned pairing/relations pipeline accepts exact contracts; cross-Theme preview/apply/rollback rejected before side effects\n";
