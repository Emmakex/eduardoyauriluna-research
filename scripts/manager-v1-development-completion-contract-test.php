#!/usr/bin/env php
<?php
declare(strict_types=1);

function check(bool $condition,string $message): void {if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function source(string $relative): string {$path=__DIR__.'/../'.$relative;$data=@file_get_contents($path);if(!is_string($data)){fwrite(STDERR,"FAIL: cannot read {$relative}\n");exit(1);}return $data;}

$page=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-page-resource.php');
$start=strpos($page,'public function build_hydration_plan');$end=strpos($page,'public function verify_hydration');
check(false!==$start&&false!==$end&&$end>$start,'Page hydration method boundaries missing');
$hydration=substr($page,$start,$end-$start);
check(false===strpos($hydration,"'post_status'"),'Page slot hydration must not change publication status');
check(str_contains($page,"unset(\$schema['researcher-name'])"),'Page slot schema must exclude canonical identity');
check(str_contains($page,"'slug_editable'=>false"),'Page resource must advertise immutable contract slug');

$pageState=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-page-state-editor.php');
check(str_contains($pageState,'research_manager_page_slug_immutable'),'Page state must reject slug mutation');
check(str_contains($pageState,'research_manager_page_status_locked'),'Page state must protect contract-locked status');
check(str_contains($pageState,"'front-page' ==="),'Page state must identify front-page lock');
check(str_contains($pageState,"array('draft','publish')")||str_contains($pageState,"array('draft', 'publish')"),'Page state must remain bounded to draft/publish');

$resource=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-resource-inventory.php');
foreach(array("'pages'=>array('scope'=>'pages.read'","'insights'=>array('scope'=>'insights.read'","'outputs'=>array('scope'=>'research.read'","'projects'=>array('scope'=>'research.read'","'software'=>array('scope'=>'research.read'","'datasets'=>array('scope'=>'research.read'") as $needle){check(str_contains($resource,$needle),'Resource inventory scope contract missing: '.$needle);}
check(false===stripos($resource,'wp/v2'),'Resource inventory must not proxy arbitrary WordPress REST');

$connections=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-connections-rest.php');
foreach(array('orcid','google_scholar','crossref','zenodo','openalex','github') as $provider){check(str_contains($connections,$provider),'Academic connection provider missing: '.$provider);}
check(str_contains($connections,"permission(\$r,'connections.read')"),'Academic connections must require connections.read');
check(str_contains($connections,"'oauth_completion_remote'=>false"),'Remote OAuth completion must remain disabled');
check(str_contains($connections,"'external_publish_remote'=>false"),'External publishing must remain disabled');
check(str_contains($connections,"'arbitrary_http_proxy'=>false"),'Arbitrary HTTP proxy must remain disabled');

$audit=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-audit.php');
foreach(array('source_revision','resulting_revision','stored_verification_status','rendered_verification_status','rollback_available','rollback_status','lifecycle_status') as $field){check(str_contains($audit,"'{$field}'"),'Audit lifecycle field missing: '.$field);}
check(str_contains($audit,'operation_option_stores'),'Audit must use a canonical operation store registry');
foreach(array('eduardo_research_manager_navigation_operations','eduardo_research_manager_remote_media_operations','eduardo_research_manager_remote_object_operations','eduardo_research_manager_remote_evidence_operations','eduardo_research_manager_remote_page_state_operations') as $store){check(str_contains($audit,$store),'Operation store missing from audit registry: '.$store);}

$operations=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-operation-inventory.php');
check(str_contains($operations,"'pending-verification'"),'Operation inventory must expose pending verification');
check(str_contains($operations,"'verification-failed'"),'Operation inventory must expose verification failure');
check(str_contains($operations,"'rolled-back'"),'Operation inventory must expose rolled back state');
$publicLines=implode("\n",array_filter(explode("\n",$operations),static fn(string $line):bool=>str_contains($line,"'=>")&& !str_contains($line,'store_family')));
check(0===preg_match('/access_token|refresh_token|client_secret|authorization|password/i',$publicLines),'Operation inventory public fields must not expose secret material');

$bootstrap=source('wordpress/plugins/eduardo-research-manager/eduardo-research-manager.php');
foreach(array('Remote_Page_State_REST','Remote_Resource_Inventory_REST','Remote_Connections_REST','Remote_Operation_Inventory_REST') as $class){check(str_contains($bootstrap,$class),'Plugin bootstrap missing '.$class);}

fwrite(STDOUT,"Manager v1 development completion source contract: PASS\n");
