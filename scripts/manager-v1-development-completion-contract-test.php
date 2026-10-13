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
check(str_contains($page,"unset(\$schema['researcher-name'], \$schema['researcher-headline'])"),'Page slot schema must exclude canonical identity name/headline');
check(str_contains($page,"'slug_editable'=>false"),'Page resource must advertise immutable contract slug');
check(str_contains($page,'str_ends_with((string) $slot, \'-target\')'),'Page CTA/internal target slots must receive dedicated validation');
check(str_contains($page,"array_fill_keys(array_keys(\$this->contract->pages()), true)"),'Page CTA targets must be bounded to registered Theme Page keys');
check(str_contains($page,'research_manager_invalid_page_target'),'Invalid arbitrary Page targets must be rejected');

$pageState=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-page-state-editor.php');
check(str_contains($pageState,'research_manager_page_slug_immutable'),'Page state must reject slug mutation');
check(str_contains($pageState,'research_manager_page_status_locked'),'Page state must protect contract-locked status');
check(str_contains($pageState,"'front-page' ==="),'Page state must identify front-page lock');
check(str_contains($pageState,"array('draft','publish')")||str_contains($pageState,"array('draft', 'publish')"),'Page state must remain bounded to draft/publish');
$pageStateRest=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-page-state-rest.php');
check(str_contains($pageStateRest,'validate_idempotency'),'Page state writes must use request idempotency guard');
check(str_contains($pageStateRest,'remember_request'),'Page state writes must remember successful request results');
check(str_contains($pageStateRest,"'idempotency'=>true"),'Page state capabilities must advertise idempotency');

$theme=source('wordpress/themes/eduardo-research/inc/research-model.php');
check(str_contains($theme,'function eduardo_research_canonical_identity_name'),'Theme must expose canonical identity name');
check(str_contains($theme,'function eduardo_research_canonical_identity_headline'),'Theme must expose canonical identity headline');
check(str_contains($theme,"'headline_es'"),'Theme canonical identity must support Spanish headline');
check(str_contains($theme,"\$model['researcher-name'] = eduardo_research_canonical_identity_name()"),'Theme model must force canonical identity name');
check(str_contains($theme,"\$model['researcher-headline'] = eduardo_research_canonical_identity_headline(\$language)"),'Theme model must force canonical identity headline');
$front=source('wordpress/themes/eduardo-research/front-page.php');
foreach(array('hero-primary-target','hero-secondary-target','final-cta-target') as $target){check(str_contains($front,$target),'Front page must consume bounded Theme target slot: '.$target);}
check(str_contains($front,'eduardo_research_page_url($hero_primary_target)'),'Hero primary CTA must resolve through Theme Page routing');
check(str_contains($front,'eduardo_research_page_url($final_cta_target)'),'Final CTA must resolve through Theme Page routing');

$identity=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-identity-profile.php');
foreach(array("'name'","'headline'","'headline_es'") as $field){check(str_contains($identity,$field),'Identity profile field missing: '.$field);}
check(str_contains($identity,'preview_update'),'Identity profile must expose Preview');
check(str_contains($identity,'apply_preview'),'Identity profile must expose Apply');
check(str_contains($identity,'public function verify'),'Identity profile must expose Verify');
check(str_contains($identity,'public function rollback'),'Identity profile must expose Rollback');
check(str_contains($identity,"'arbitrary_wordpress_profile_proxy'=>false"),'Identity profile must not proxy WordPress users/profiles');

$identityRest=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-identity-profile-rest.php');
check(str_contains($identityRest,"'/identity-profile'"),'Remote canonical identity read surface missing');
foreach(array("'identity.write'","'evidence.write'","'operations.apply'","'operations.rollback'") as $scope){check(str_contains($identityRest,$scope),'Remote identity scope missing: '.$scope);}
check(str_contains($identityRest,"'fields'=>array('name','headline','headline_es')"),'Remote identity capabilities must advertise EN/ES canonical fields');
check(str_contains($identityRest,"'arbitrary_wordpress_profile_proxy'=>false"),'Remote identity must keep WordPress profile proxy disabled');
$identityOps=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-identity-profile-operations.php');
check(str_contains($identityOps,"'source_revision'"),'Identity operations must preserve source revision');
check(str_contains($identityOps,"'post_apply_revision'"),'Identity operations must preserve resulting revision');

$admin=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-v1-controls-admin.php');
check(str_contains($admin,'Eduardo_Research_Manager_Identity_Profile'),'Local Admin must reuse canonical Identity service');
check(str_contains($admin,'Eduardo_Research_Manager_Page_State_Editor'),'Local Admin must reuse Page State service');
check(str_contains($admin,"name=\"headline_es\""),'Local Admin must expose Spanish canonical headline');
check(str_contains($admin,'Contract Page slugs are intentionally immutable'),'Local Admin must explain immutable Page slugs');
check(str_contains($admin,"empty(\$policy['status_editable'])"),'Local Admin must derive publication lock from shared Page policy');

$insight=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-insight-resource.php');
check(str_contains($insight,"array('slug','title','excerpt','content','language','insight_type','line_ids')"),'Insight updates must include bounded slug mutation');
check(str_contains($insight,"'slug'=>'post_name'"),'Insight slug update must write post_name through bounded post_field action');
check(str_contains($insight,'research_manager_insight_slug_required'),'Insight slug must reject empty values');

$line=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-line-resource.php');
check(str_contains($line,"array('status','slug','title','excerpt','content','language','evidence_status','research_status','central_question','order','topics','methods')"),'Research Line update contract must include status and slug');
check(str_contains($line,"'status'=>'post_status'"),'Research Line publication-state update must use bounded post_status action');
check(str_contains($line,"'slug'=>'post_name'"),'Research Line slug update must use bounded post_name action');
check(str_contains($line,'research_manager_line_slug_required'),'Research Line slug must reject empty values');

$resource=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-resource-inventory.php');
foreach(array("'pages'=>array('scope'=>'pages.read'","'insights'=>array('scope'=>'insights.read'","'outputs'=>array('scope'=>'research.read'","'projects'=>array('scope'=>'research.read'","'software'=>array('scope'=>'research.read'","'datasets'=>array('scope'=>'research.read'") as $needle){check(str_contains($resource,$needle),'Resource inventory scope contract missing: '.$needle);}
check(str_contains($resource,"'language'=>\$language"),'Resource inventory must preserve language filters');
check(str_contains($resource,"pages()->inspect(sanitize_key(\$identifier),''!==\$language?\$language:'en')"),'Page get_resource must be language-aware');
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
foreach(array('eduardo_research_manager_navigation_operations','eduardo_research_manager_remote_media_operations','eduardo_research_manager_remote_object_operations','eduardo_research_manager_remote_evidence_operations','eduardo_research_manager_remote_identity_profile_operations','eduardo_research_manager_remote_page_state_operations') as $store){check(str_contains($audit,$store),'Operation store missing from audit registry: '.$store);}

$operations=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-operation-inventory.php');
check(str_contains($operations,"'pending-verification'"),'Operation inventory must expose pending verification');
check(str_contains($operations,"'verification-failed'"),'Operation inventory must expose verification failure');
check(str_contains($operations,"'rolled-back'"),'Operation inventory must expose rolled back state');
check(str_contains($operations,"'eduardo_research_manager_remote_identity_profile_operations'=>'identity-profile'"),'Identity operations must be classified in the unified inventory');
$publicLines=implode("\n",array_filter(explode("\n",$operations),static fn(string $line):bool=>str_contains($line,"'=>")&&!str_contains($line,'store_family')));
check(0===preg_match('/access_token|refresh_token|client_secret|authorization|password/i',$publicLines),'Operation inventory public fields must not expose secret material');

$v1caps=source('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-v1-capabilities.php');
check(str_contains($v1caps,"'slug_updates'=>array('available'=>true,'operation'=>'insight-update'"),'Capabilities must advertise Insight slug updates');
check(str_contains($v1caps,"'publication_state'=>array('available'=>true,'allowed'=>array('draft','publish'),'operation'=>'line-update'"),'Capabilities must advertise Research Line publication state');
check(str_contains($v1caps,"['local_admin_parity']=true"),'Capabilities must advertise Page State local Admin parity');
check(str_contains($v1caps,"'production_acceptance_frozen'=>true"),'Capabilities must keep production acceptance frozen until development closeout');

$bootstrap=source('wordpress/plugins/eduardo-research-manager/eduardo-research-manager.php');
foreach(array('Remote_Page_State_REST','Remote_Resource_Inventory_REST','Remote_Connections_REST','Remote_Operation_Inventory_REST','Remote_Identity_Profile_REST','V1_Controls_Admin','V1_Capabilities') as $class){check(str_contains($bootstrap,$class),'Plugin bootstrap missing '.$class);}

fwrite(STDOUT,"Manager v1 development completion source contract: PASS\n");
