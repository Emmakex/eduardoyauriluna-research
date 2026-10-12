#!/usr/bin/env php
<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
@mkdir(ABSPATH . 'wp-admin/includes', 0700, true);
foreach (array('file.php','image.php','media.php') as $include) { if (! file_exists(ABSPATH . 'wp-admin/includes/' . $include)) { file_put_contents(ABSPATH . 'wp-admin/includes/' . $include, "<?php\n"); } }

final class WP_Error {
    public function __construct(private string $code, private string $message, private array $data = array()) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
    public function add_data(array $data): void { $this->data = $data; }
}
final class WP_Post { public function __construct(public int $ID, public string $post_type='attachment', public string $post_status='inherit') {} }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_file_name(string $value): string { return preg_replace('/[^A-Za-z0-9._-]/', '-', basename($value)) ?? ''; }
function absint(mixed $value): int { return max(0, (int) $value); }
function wp_json_encode(mixed $value): string|false { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function wp_generate_uuid4(): string { static $i=0; ++$i; return sprintf('00000000-0000-4000-8000-%012d', $i); }
function current_user_can(string $capability): bool { return in_array($capability, array('manage_options','upload_files'), true); }
function wp_tempnam(string $filename=''): string|false { return tempnam(sys_get_temp_dir(), 'erm-ingest-'); }
function wp_check_filetype_and_ext(string $file, string $filename, array $mimes): array {
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    foreach ($mimes as $extensions => $mime) { if (in_array($ext, explode('|', $extensions), true)) { return array('ext'=>$ext,'type'=>$mime,'proper_filename'=>''); } }
    return array('ext'=>false,'type'=>false,'proper_filename'=>false);
}

$GLOBALS['erm_actions'] = array();
$GLOBALS['erm_scheduled'] = array();
function add_action(string $hook, callable $callback, int $priority=10, int $accepted_args=1): bool { $GLOBALS['erm_actions'][$hook][]=$callback; return true; }
function wp_next_scheduled(string $hook, array $args=array()): int|false { $key=$hook.'|'.json_encode($args); return $GLOBALS['erm_scheduled'][$key] ?? false; }
function wp_schedule_single_event(int $timestamp, string $hook, array $args=array()): bool { $key=$hook.'|'.json_encode($args); $GLOBALS['erm_scheduled'][$key]=$timestamp; return true; }
function wp_clear_scheduled_hook(string $hook, array $args=array()): int|false { $key=$hook.'|'.json_encode($args); if (!array_key_exists($key,$GLOBALS['erm_scheduled'])) return 0; unset($GLOBALS['erm_scheduled'][$key]); return 1; }

$GLOBALS['erm_options'] = array();
$GLOBALS['erm_meta'] = array();
$GLOBALS['erm_posts'] = array();
$GLOBALS['erm_files'] = array();
$GLOBALS['erm_mimes'] = array();
$GLOBALS['erm_used'] = array();
function get_option(string $key, mixed $default=false): mixed { return $GLOBALS['erm_options'][$key] ?? $default; }
function add_option(string $key, mixed $value, string $deprecated='', bool $autoload=false): bool { $GLOBALS['erm_options'][$key]=$value; return true; }
function update_option(string $key, mixed $value, bool $autoload=false): bool { $GLOBALS['erm_options'][$key]=$value; return true; }
function get_posts(array $args=array()): array {
    if (($args['meta_key'] ?? '') === '_eduardo_research_manager_ingest_sha256') {
        $ids = array(); foreach ($GLOBALS['erm_meta'] as $id => $meta) { if (($meta['_eduardo_research_manager_ingest_sha256'] ?? '') === ($args['meta_value'] ?? '')) { $ids[] = $id; } } return $ids;
    }
    return array();
}
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['erm_meta'][$id][$key]=$value; return true; }
function get_post_meta(int $id, string $key, bool $single=true): mixed { return $GLOBALS['erm_meta'][$id][$key] ?? ''; }
function get_post(int $id): ?WP_Post { return $GLOBALS['erm_posts'][$id] ?? null; }
function get_attached_file(int $id): string|false { return $GLOBALS['erm_files'][$id] ?? false; }
function get_post_mime_type(int $id): string|false { return $GLOBALS['erm_mimes'][$id] ?? false; }
function media_handle_sideload(array $file_array, int $post_id=0, ?string $desc=null, array $post_data=array()): int|WP_Error {
    static $next=700; ++$next; $dest=tempnam(sys_get_temp_dir(),'erm-attachment-'); if (! is_string($dest) || ! rename($file_array['tmp_name'],$dest)) { return new WP_Error('upload_error','fake sideload failed'); }
    $GLOBALS['erm_posts'][$next]=new WP_Post($next); $GLOBALS['erm_files'][$next]=$dest; $GLOBALS['erm_mimes'][$next]=(string)$file_array['type']; return $next;
}
function wp_delete_attachment(int $id, bool $force=false): WP_Post|false {
    $post=$GLOBALS['erm_posts'][$id]??null; if (!$post) return false; $path=$GLOBALS['erm_files'][$id]??''; if (is_string($path)&&is_file($path)) @unlink($path);
    unset($GLOBALS['erm_posts'][$id],$GLOBALS['erm_files'][$id],$GLOBALS['erm_mimes'][$id],$GLOBALS['erm_meta'][$id]); return $post;
}

final class Eduardo_Research_Manager_Remote_Audit { public array $events=array(); public function record(array $entry): array { $this->events[]=$entry; return $entry; } }
final class Eduardo_Research_Manager_Media_Resource {}
final class Eduardo_Research_Manager_Media_Diagnostics {
    public function __construct(?Eduardo_Research_Manager_Media_Resource $media=null) {}
    public function inspect(int $id): array|WP_Error { return array('usage'=>array('used'=>!empty($GLOBALS['erm_used'][$id]))); }
}

require_once __DIR__ . '/../wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-media-ingest-operations.php';
function check(bool $condition,string $message): void { if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }
function upload_file(string $suffix,string $content): array { $path=tempnam(sys_get_temp_dir(),'erm-upload-'); file_put_contents($path,$content); return array('name'=>'research-'.$suffix,'tmp_name'=>$path,'size'=>filesize($path),'error'=>UPLOAD_ERR_OK,'type'=>''); }
function raw_plan(string $id): array { return (array) (($GLOBALS['erm_options']['eduardo_research_manager_remote_media_ingest_plans'][$id] ?? array())); }

$audit=new Eduardo_Research_Manager_Remote_Audit();
$ops=new Eduardo_Research_Manager_Remote_Media_Ingest_Operations($audit);
$actor=array('connection_id'=>'rmt_media_ingest','wordpress_user_id'=>1,'request_id'=>'req-ingest-1');

$bad=upload_file('script.php','<?php echo 1;');
$badPlan=$ops->create_plan($bad,array('title'=>'Bad','alt_text'=>'Bad file'),$actor);
check(is_wp_error($badPlan)&&'validation_failed'===$badPlan->get_error_code(),'non-allowlisted extension must be rejected'); @unlink($bad['tmp_name']);

$noAlt=upload_file('image.jpg','JPEG-BINARY-ALT');
$noAltPlan=$ops->create_plan($noAlt,array('title'=>'Research image'),$actor);
check(is_wp_error($noAltPlan)&&'validation_failed'===$noAltPlan->get_error_code(),'image ingest must require alt_text'); @unlink($noAlt['tmp_name']);

$cancelFile=upload_file('cancel.jpg','JPEG-CANCEL-PREVIEW');
$cancelActor=array('connection_id'=>'rmt_media_ingest','wordpress_user_id'=>1,'request_id'=>'req-ingest-cancel');
$cancelPlan=$ops->create_plan($cancelFile,array('title'=>'Cancel preview','alt_text'=>'Temporary preview image'),$cancelActor);
check(is_array($cancelPlan)&&'planned'===$cancelPlan['status'],'cancel fixture Preview must succeed');
$cancelRaw=raw_plan((string)$cancelPlan['plan_id']); $cancelStage=(string)($cancelRaw['staged_path']??'');
check(''!==$cancelStage&&is_file($cancelStage),'Preview must create private staged file');
check(false!==wp_next_scheduled('eduardo_research_manager_cleanup_media_ingest_plan',array($cancelPlan['plan_id'])),'Preview must schedule TTL cleanup');
$cancelled=$ops->cancel((string)$cancelPlan['plan_id'],$cancelActor);
check(is_array($cancelled)&&'cancelled'===$cancelled['status'],'planned Preview cancel must succeed');
check(!is_file($cancelStage),'cancel must remove staged binary');
check(false===wp_next_scheduled('eduardo_research_manager_cleanup_media_ingest_plan',array($cancelPlan['plan_id'])),'cancel must clear scheduled cleanup');
check(''===(string)(raw_plan((string)$cancelPlan['plan_id'])['staged_path']??''),'cancelled plan must not retain staged path');
@unlink($cancelFile['tmp_name']);

$expiryFile=upload_file('expiry.jpg','JPEG-EXPIRY-PREVIEW');
$expiryActor=array('connection_id'=>'rmt_media_ingest','wordpress_user_id'=>1,'request_id'=>'req-ingest-expiry');
$expiryPlan=$ops->create_plan($expiryFile,array('title'=>'Expiry preview','alt_text'=>'Temporary expiring image'),$expiryActor);
$expiryRaw=raw_plan((string)$expiryPlan['plan_id']); $expiryStage=(string)($expiryRaw['staged_path']??'');
check(is_file($expiryStage),'expiry fixture must stage binary');
$GLOBALS['erm_options']['eduardo_research_manager_remote_media_ingest_plans'][$expiryPlan['plan_id']]['expires_unix']=time()-1;
$ops->cleanup_expired_plan((string)$expiryPlan['plan_id']);
$expired=$ops->get_plan((string)$expiryPlan['plan_id']);
check(is_array($expired)&&'expired'===$expired['status'],'scheduled cleanup must expire stale Preview');
check(!is_file($expiryStage),'expired Preview cleanup must remove staged binary');
check(false===wp_next_scheduled('eduardo_research_manager_cleanup_media_ingest_plan',array($expiryPlan['plan_id'])),'expiry cleanup must clear scheduled hook');
@unlink($expiryFile['tmp_name']);

$file=upload_file('image.jpg','JPEG-BINARY-CONTENT');
$plan=$ops->create_plan($file,array('title'=>'Research image','alt_text'=>'Research diagram showing bounded Manager workflow','caption'=>'Acceptance image','description'=>'Managed ingest acceptance asset.'),$actor);
check(is_array($plan),'valid ingest Preview must succeed');
check(!array_key_exists('staged_path',$plan),'public plan must not expose staged filesystem path');
check('image/jpeg'===$plan['file']['mime_type'],'MIME contract mismatch');
check(64===strlen($plan['file']['sha256']),'SHA-256 missing from Preview');
check('scheduled-or-explicit-cancel'===($plan['cleanup']['policy']??''),'Preview cleanup policy missing');
check(false!==wp_next_scheduled('eduardo_research_manager_cleanup_media_ingest_plan',array($plan['plan_id'])),'valid Preview must schedule cleanup');

$replay=$ops->create_plan($file,array('title'=>'Research image','alt_text'=>'Research diagram showing bounded Manager workflow'),$actor);
check(is_array($replay)&&$replay['plan_id']===$plan['plan_id'],'same request/checksum must return existing plan');

$operation=$ops->apply($plan['plan_id'],true,$actor);
check(is_array($operation)&&'applied'===$operation['status'],'ingest Apply must succeed');
check(false===wp_next_scheduled('eduardo_research_manager_cleanup_media_ingest_plan',array($plan['plan_id'])),'Apply must clear Preview cleanup hook');
$id=(int)$operation['target']['attachment_id'];
check($id>0&&get_post($id) instanceof WP_Post,'Apply must create attachment');
check($operation['operation_id']===(string)get_post_meta($id,'_eduardo_research_manager_ingest_operation',true),'provenance operation id missing');

$verified=$ops->verify($operation['operation_id'],$actor);
check(is_array($verified)&&'verified'===$verified['status'],'ingest Verify must pass');
check(true===$verified['verification']['checksum_matches']&&true===$verified['verification']['provenance_matches'],'checksum/provenance verification failed');

$GLOBALS['erm_used'][$id]=true;
$blocked=$ops->rollback($operation['operation_id'],true,$actor);
check(is_wp_error($blocked)&&'rollback_unavailable'===$blocked->get_error_code(),'rollback must block Media that became used');
check(get_post($id) instanceof WP_Post,'blocked rollback must preserve attachment');
$GLOBALS['erm_used'][$id]=false;
$rolled=$ops->rollback($operation['operation_id'],true,$actor);
check(is_array($rolled)&&'rolled-back'===$rolled['status'],'owned unused ingest rollback must succeed');
check(null===get_post($id),'rollback must delete only owned unused attachment');
check(count($audit->events)>=7,'ingest lifecycle including Preview cleanup must be audited');

@unlink($file['tmp_name']);
fwrite(STDOUT,"Managed Media ingest lifecycle + staging cleanup: PASS\n");