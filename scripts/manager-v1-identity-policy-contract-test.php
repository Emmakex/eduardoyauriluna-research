#!/usr/bin/env php
<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$GLOBALS['erm_options'] = array(
    'eduardo_research_identity'=>array('name'=>'Canonical Researcher'),
    'eduardo_research_model'=>array('researcher-name'=>'Drifted editorial slot'),
    'eduardo_research_manager_remote_evidence_plans'=>array(),
    'eduardo_research_manager_remote_evidence_operations'=>array(),
);
function get_option(string $key, mixed $default=false): mixed { return $GLOBALS['erm_options'][$key] ?? $default; }
function get_bloginfo(string $field): string { return 'name' === $field ? 'Site Title' : 'Site Description'; }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value) ?? ''); }
function add_filter(...$args): void {}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function eduardo_research_current_language(): string { return 'en'; }
final class WP_Error {
    public function __construct(private string $code, private string $message='', private mixed $data=null) {}
    public function get_error_code(): string { return $this->code; }
}
final class WP_REST_Request {
    public function __construct(private string $method, private string $route, private array $json=array(), private array $headers=array()) {}
    public function get_method(): string { return $this->method; }
    public function get_route(): string { return $this->route; }
    public function get_json_params(): array { return $this->json; }
    public function get_body_params(): array { return $this->json; }
    public function get_header(string $name): string { return (string) ($this->headers[strtolower($name)] ?? ''); }
}
final class WP_REST_Response {
    public function __construct(private array $data) {}
    public function get_data(): array { return $this->data; }
    public function set_data(array $data): void { $this->data = $data; }
}
final class Eduardo_Research_Manager_Remote_REST { public const NAMESPACE = 'research-manager/v1'; }
require_once __DIR__ . '/../wordpress/themes/eduardo-research/inc/research-model.php';
require_once __DIR__ . '/../wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-semantic-scope-guard.php';
function check(bool $condition,string $message): void { if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }
$model=eduardo_research_model();
check('Canonical Researcher'===($model['researcher-name']??''),'Theme must use canonical identity instead of editorial slot');
$guard=new Eduardo_Research_Manager_Remote_Semantic_Scope_Guard();
$prefix='/research-manager/v1';
$identifierPlan=new WP_REST_Request('POST',$prefix.'/research-evidence/plan',array(
    'operation'=>'evidence-update',
    'payload'=>array('group'=>'identifiers','record_id'=>'academic-id','data'=>array('value'=>'verified-id-value')),
),array('x-research-manager-request-id'=>'identity-policy-check'));
check(array('identity.write')===$guard->required_scopes($identifierPlan),'identifier mutation must require identity.write');
$profilePlan=new WP_REST_Request('POST',$prefix.'/research-evidence/plan',array(
    'operation'=>'evidence-update','payload'=>array('group'=>'profile','record_id'=>'bio','data'=>array('summary'=>'Profile update')),
));
check(array()===$guard->required_scopes($profilePlan),'ordinary evidence must not be over-gated');
$identityPlan=new WP_REST_Request('POST',$prefix.'/research-evidence/plan',array('operation'=>'identity-update','payload'=>array('name'=>'Changed Name')));
check(array('identity.write')===$guard->required_scopes($identityPlan),'identity-update must require identity.write');
$GLOBALS['erm_options']['eduardo_research_manager_remote_evidence_plans']['plan-identifiers']=array(
    'operation'=>'evidence-update','target'=>array('resource'=>'evidence','group'=>'identifiers','record_id'=>'academic-id'),
);
$apply=new WP_REST_Request('POST',$prefix.'/research-evidence/plans/plan-identifiers/apply');
check(array('identity.write')===$guard->required_scopes($apply),'stored identifier Apply must remain elevated');
$GLOBALS['erm_options']['eduardo_research_manager_remote_evidence_operations']['op-identifiers']=array(
    'operation'=>'evidence-update','target'=>array('resource'=>'evidence','group'=>'identifiers','record_id'=>'academic-id'),
);
$rollback=new WP_REST_Request('POST',$prefix.'/research-evidence/operations/op-identifiers/rollback');
check(array('identity.write')===$guard->required_scopes($rollback),'stored identifier Rollback must remain elevated');
$capRequest=new WP_REST_Request('GET',$prefix.'/capabilities');
$capResponse=new WP_REST_Response(array('data'=>array('research_evidence_control'=>array('available'=>true))));
$guard->augment_capabilities($capResponse,null,$capRequest);
$policy=$capResponse->get_data()['data']['research_evidence_control']['identity_policy']??array();
check('identifiers'===($policy['critical_identifier_group']??''),'capabilities must advertise identifiers as identity-critical');
check('identity.write'===($policy['critical_write_scope']??''),'capabilities must advertise identity.write');
check(false===($policy['arbitrary_wordpress_profile_proxy']??true),'arbitrary WP profile proxy must stay disabled');
fwrite(STDOUT,"Manager v1 canonical identity + elevated identifier policy: PASS\n");
