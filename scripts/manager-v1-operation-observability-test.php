#!/usr/bin/env php
<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$GLOBALS['erm_options'] = array();
function get_option(string $key,mixed $default=false): mixed {return $GLOBALS['erm_options'][$key]??$default;}
function add_option(string $key,mixed $value,string $deprecated='',bool $autoload=false): bool {$GLOBALS['erm_options'][$key]=$value;return true;}
function update_option(string $key,mixed $value,bool $autoload=false): bool {$GLOBALS['erm_options'][$key]=$value;return true;}
function delete_option(string $key): bool {unset($GLOBALS['erm_options'][$key]);return true;}
function sanitize_text_field(string $value): string {return trim(strip_tags($value));}
function sanitize_key(string $value): string {return strtolower(preg_replace('/[^a-z0-9_\-]/','',$value)??'');}
function wp_generate_uuid4(): string {static $i=0;++$i;return sprintf('00000000-0000-4000-8000-%012d',$i);}
function add_action(...$args): void {}
function add_filter(...$args): void {}
function register_rest_route(...$args): void {}
function is_wp_error(mixed $value): bool {return $value instanceof WP_Error;}
final class WP_Error {public function __construct(private string $code,private string $message='',private array $data=array()){} public function get_error_code(): string{return $this->code;}}
final class WP_REST_Request {}
final class WP_REST_Response {}
final class WP_REST_Server {public const READABLE='GET';}
final class Eduardo_Research_Manager_Remote_REST {public const NAMESPACE='research-manager/v1';}
final class Eduardo_Research_Manager {public static function remote_rest(): object {return new class{};}}

require_once __DIR__ . '/../wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-audit.php';
require_once __DIR__ . '/../wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-operation-inventory.php';
function check(bool $condition,string $message): void {if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}

$GLOBALS['erm_options']['eduardo_research_manager_navigation_operations']=array(
    'op-nav'=>array(
        'operation_id'=>'op-nav','plan_id'=>'plan-nav','operation'=>'navigation-update','target'=>array('resource'=>'theme:navigation','location'=>'primary'),
        'source_revision'=>'rev-nav-before','post_apply_revision'=>'rev-nav-after','connection_id'=>'conn-a','status'=>'applied','created_at'=>'2026-10-13T04:00:00+00:00','applied_at'=>'2026-10-13T04:01:00+00:00',
        'access_token'=>'must-never-leak',
    ),
);
$GLOBALS['erm_options']['eduardo_research_manager_remote_media_operations']=array(
    'op-media'=>array(
        'operation_id'=>'op-media','plan_id'=>'plan-media','operation'=>'media-metadata-update','target'=>array('resource'=>'media','attachment_id'=>42),
        'source_revision'=>'rev-media-before','post_apply_revision'=>'rev-media-after','connection_id'=>'conn-a','status'=>'verified','verification'=>array('verified'=>true),
        'created_at'=>'2026-10-13T03:00:00+00:00','applied_at'=>'2026-10-13T03:01:00+00:00','verified_at'=>'2026-10-13T03:02:00+00:00',
    ),
);
$GLOBALS['erm_options']['eduardo_research_manager_remote_object_operations']=array(
    'op-failed'=>array(
        'operation_id'=>'op-failed','plan_id'=>'plan-failed','operation'=>'object-update','target'=>array('resource'=>'research_output','post_id'=>77),
        'connection_id'=>'conn-b','status'=>'verification-failed','verification'=>array('verified'=>false),'created_at'=>'2026-10-13T02:00:00+00:00','verified_at'=>'2026-10-13T02:02:00+00:00',
    ),
);
$GLOBALS['erm_options']['eduardo_research_manager_remote_evidence_operations']=array(
    'op-rolled'=>array(
        'operation_id'=>'op-rolled','plan_id'=>'plan-rolled','operation'=>'evidence-update','target'=>array('resource'=>'evidence','group'=>'profile','record_id'=>'bio'),
        'connection_id'=>'conn-b','status'=>'rolled-back','created_at'=>'2026-10-13T01:00:00+00:00','rolled_back_at'=>'2026-10-13T01:05:00+00:00',
    ),
);

$inventory=new Eduardo_Research_Manager_Remote_Operation_Inventory();
$pending=$inventory->query(array('status'=>'pending-verification'));
check(1===count($pending['items'])&&'op-nav'===$pending['items'][0]['operation_id'],'applied operation must surface as pending-verification');
check(!array_key_exists('access_token',$pending['items'][0]),'operation inventory leaked internal token field');
check(true===$pending['items'][0]['rollback_available'],'applied operation must advertise rollback availability');
check('navigation'===$pending['items'][0]['store_family'],'operation store family mismatch');
$verified=$inventory->query(array('status'=>'verified'));
check(1===count($verified['items'])&&'op-media'===$verified['items'][0]['operation_id'],'verified filter mismatch');
check('verified'===$verified['items'][0]['stored_verification_status'],'stored verification status mismatch');
$failed=$inventory->query(array('status'=>'verification-failed'));
check(1===count($failed['items'])&&'op-failed'===$failed['items'][0]['operation_id'],'verification-failed filter mismatch');
$rolled=$inventory->query(array('status'=>'rolled-back'));
check(1===count($rolled['items'])&&'rolled-back'===$rolled['items'][0]['rollback_status'],'rolled-back filter mismatch');
$exact=$inventory->query(array('operation_id'=>'op-media'));
check(1===count($exact['items'])&&'op-media'===$exact['items'][0]['operation_id'],'exact operation_id lookup mismatch');
$get=$inventory->get('op-nav');
check(is_array($get)&&'rev-nav-before'===$get['source_revision']&&'rev-nav-after'===$get['resulting_revision'],'cross-lifecycle get lost revisions');

$audit=new Eduardo_Research_Manager_Remote_Audit();
$entry=$audit->record(array('request_id'=>'req-nav','connection_id'=>'conn-a','operation_id'=>'op-nav','event'=>'remote-navigation-applied','outcome'=>'success','scope'=>'pages.write'));
check('navigation-update'===$entry['operation'],'audit failed to enrich operation type');
check('rev-nav-before'===$entry['source_revision']&&'rev-nav-after'===$entry['resulting_revision'],'audit failed to enrich revisions');
check('applied'===$entry['lifecycle_status'],'audit lifecycle status mismatch');
check(true===$entry['rollback_available']&&'available'===$entry['rollback_status'],'audit rollback state mismatch');
check('pending'===$entry['stored_verification_status'],'audit pending verification mismatch');
$encoded=json_encode($entry);
check(is_string($encoded)&&!str_contains($encoded,'must-never-leak'),'audit leaked an internal operation secret');

fwrite(STDOUT,"Manager v1 unified operation observability: PASS\n");
