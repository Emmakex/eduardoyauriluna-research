<?php
/** Fresh-WordPress acceptance for remote M6 SEO/GEO diagnostics and deterministic remediation. */
wp_set_current_user(1);

function m6_fail(string $message, mixed $context = null): never {
    fwrite(STDERR, "M6 SEO/GEO FAIL: {$message}\n");
    if (null !== $context) { fwrite(STDERR, wp_json_encode($context, JSON_PRETTY_PRINT) . "\n"); }
    exit(1);
}
function m6_request(string $method, string $route, string $token, array $body = array(), array $query = array(), string $rid = ''): WP_REST_Response {
    $r = new WP_REST_Request($method, '/research-manager/v1/' . ltrim($route, '/'));
    $r->set_header('Authorization', 'Bearer ' . $token);
    $r->set_header('X-Research-Manager-Request-Id', '' !== $rid ? $rid : 'req-' . wp_generate_uuid4());
    $r->set_header('X-Research-Manager-Nonce', 'nonce-' . str_replace('-', '', wp_generate_uuid4()));
    $r->set_header('X-Research-Manager-Timestamp', (string) time());
    if ($query) { $r->set_query_params($query); }
    if ($body) { $r->set_header('Content-Type','application/json'); $r->set_body((string) wp_json_encode($body)); }
    return rest_do_request($r);
}
function m6_fake_http(array $surfaces): void {
    add_filter('pre_http_request', static function ($preempt, $args, $url) use ($surfaces) {
        foreach ($surfaces as $surface) {
            if (untrailingslashit((string) $url) !== untrailingslashit((string) $surface['url'])) { continue; }
            $language = (string) $surface['language'];
            $canonical = esc_url((string) $surface['url']);
            $title = esc_html((string) $surface['title']);
            $schema = esc_html((string) ($surface['schema'] ?? 'CreativeWork'));
            $en = esc_url((string) ($surface['en'] ?? $surface['url']));
            $es = esc_url((string) ($surface['es'] ?? $surface['url']));
            $body = '<!doctype html><html lang="' . esc_attr($language) . '"><head>'
                . '<link rel="canonical" href="' . $canonical . '">'
                . '<link rel="alternate" hreflang="en" href="' . $en . '">'
                . '<link rel="alternate" hreflang="es" href="' . $es . '">'
                . '<link rel="alternate" hreflang="x-default" href="' . $en . '">'
                . '<meta property="og:url" content="' . $canonical . '">'
                . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"' . $schema . '"}</script>'
                . '</head><body><article><h1>' . $title . '</h1></article></body></html>';
            return array('headers'=>array(),'body'=>$body,'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array(),'filename'=>null);
        }
        return $preempt;
    }, 10, 3);
}
function m6_plan(string $token, string $check_id, string $rid = ''): array {
    $r = m6_request('POST','seo-geo/remediation/plan',$token,array('check_id'=>$check_id,'intent'=>'CI exact SEO/GEO remediation'),array(),$rid);
    if (200 !== $r->get_status()) { m6_fail('remediation Plan failed',$r->get_data()); }
    return (array) ($r->get_data()['data'] ?? array());
}

$blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
if (is_wp_error($blueprint)) { m6_fail('blueprint unavailable',$blueprint->get_error_message()); }
$seed = Eduardo_Research_Manager::pipeline()->apply($blueprint);
if (is_wp_error($seed) || empty($seed['verified'])) { m6_fail('seed failed',$seed); }
rest_get_server();

// seo.read is independent from generic diagnostics.
$without_seo = Eduardo_Research_Manager::remote_credentials()->issue_token(array('site.read','site.diagnostics','operations.apply','operations.rollback'),1,'CI M6 without SEO scope');
if (is_wp_error($without_seo) || empty($without_seo['token'])) { m6_fail('limited token issuance failed'); }
$denied = m6_request('GET','seo-geo/site',(string)$without_seo['token']);
if (403 !== $denied->get_status() || 'scope_denied' !== (string)($denied->get_data()['code'] ?? '')) { m6_fail('seo.read scope not enforced',$denied->get_data()); }

$issued = Eduardo_Research_Manager::remote_credentials()->issue_token(array('site.read','site.diagnostics','seo.read','seo.write','operations.apply','operations.rollback'),1,'CI M6 SEO GEO');
if (is_wp_error($issued) || empty($issued['token'])) { m6_fail('M6 token issuance failed'); }
$token = (string) $issued['token'];

$cap = m6_request('GET','capabilities',$token);
$control = (array)($cap->get_data()['data']['seo_geo_control'] ?? array());
if (200 !== $cap->get_status() || 'M6' !== (string)($control['milestone'] ?? '') || empty($control['available']) || empty($control['stale_target_protection']) || ! in_array('page',(array)($control['resource_rendered_inspection'] ?? array()),true) || ! in_array('line',(array)($control['resource_rendered_inspection'] ?? array()),true) || ! empty($control['arbitrary_meta_editor'])) {
    m6_fail('M6 capability discovery incomplete',$cap->get_data());
}

$home_en = function_exists('eduardo_research_page_url') ? (string)eduardo_research_page_url('home','en') : home_url('/');
$home_es = function_exists('eduardo_research_page_url') ? (string)eduardo_research_page_url('home','es') : home_url('/es/');
$home_title = (string)get_the_title(Eduardo_Research_Manager::contract()->page_id('home'));
$line = get_posts(array('post_type'=>'research_line','post_status'=>'publish','posts_per_page'=>1,'meta_key'=>'_research_evidence_status','meta_value'=>'verified','suppress_filters'=>true));
if (! isset($line[0]) || ! $line[0] instanceof WP_Post) { m6_fail('seed has no verified public Research Line'); }
$line_id = (int)$line[0]->ID;
$line_url = (string)get_permalink($line_id);
$line_language = function_exists('eduardo_research_post_language') ? (string)eduardo_research_post_language($line_id) : 'en';
m6_fake_http(array(
    array('url'=>$home_en,'language'=>'en','title'=>$home_title,'schema'=>'WebPage','en'=>$home_en,'es'=>$home_es),
    array('url'=>$home_es,'language'=>'es','title'=>$home_title,'schema'=>'WebPage','en'=>$home_en,'es'=>$home_es),
    array('url'=>$line_url,'language'=>$line_language,'title'=>(string)$line[0]->post_title,'schema'=>'CreativeWork','en'=>$line_url,'es'=>$line_url),
));

$page = m6_request('GET','seo-geo/resource',$token,array(),array('type'=>'page','key'=>'home','language'=>'en'));
if (200 !== $page->get_status() || empty($page->get_data()['data']['ready']) || empty($page->get_data()['data']['rendered']['checks']['canonical']) || empty($page->get_data()['data']['rendered']['checks']['json_ld'])) { m6_fail('unified Page SEO/GEO inspection failed',$page->get_data()); }
$line_check = m6_request('GET','seo-geo/resource',$token,array(),array('type'=>'line','id'=>$line_id));
if (200 !== $line_check->get_status() || empty($line_check->get_data()['data']['ready']) || 'CreativeWork' !== (string)($line_check->get_data()['data']['rendered']['expected_schema_type'] ?? '')) { m6_fail('unified Research Line SEO/GEO inspection failed',$line_check->get_data()); }

// Break a deterministic readiness condition after canonical Greenfield seed.
$seed_show_on_front = get_option('show_on_front');
$seed_page_on_front = get_option('page_on_front');
update_option('show_on_front','posts',false);
update_option('page_on_front',0,false);
$site = m6_request('GET','seo-geo/site',$token);
$site_data = (array)($site->get_data()['data'] ?? array());
if (200 !== $site->get_status() || empty($site_data['remediation']['auto_count'])) { m6_fail('site SEO/GEO diagnostics did not expose remediation',$site->get_data()); }
$front_candidate = false;
foreach ((array)($site_data['remediation']['auto_remediable'] ?? array()) as $row) { if (is_array($row) && 'front-page' === (string)($row['check_id'] ?? '')) { $front_candidate=true; } }
if (! $front_candidate) { m6_fail('front-page finding is not an auto-remediation candidate',$site_data); }

$rid = 'req-m6-' . substr(str_replace('-','',wp_generate_uuid4()),0,20);
$plan = m6_plan($token,'front-page',$rid);
if (empty($plan['apply_allowed']) || 'exact-remediation' !== (string)($plan['confirmation_class'] ?? '') || 'planned' !== (string)($plan['status'] ?? '')) { m6_fail('front-page Preview invalid',$plan); }
if ('posts' !== get_option('show_on_front') || 0 !== (int)get_option('page_on_front')) { m6_fail('SEO/GEO Preview mutated WordPress'); }
$replay = m6_request('POST','seo-geo/remediation/plan',$token,array('check_id'=>'front-page','intent'=>'CI exact SEO/GEO remediation'),array(),$rid);
if (200 !== $replay->get_status() || empty($replay->get_data()['idempotent_replay']) || (string)($replay->get_data()['data']['plan_id'] ?? '') !== (string)$plan['plan_id']) { m6_fail('SEO/GEO Plan idempotency failed',$replay->get_data()); }
$no_confirm = m6_request('POST','seo-geo/plans/'.rawurlencode((string)$plan['plan_id']).'/apply',$token,array('confirm'=>false));
if (409 !== $no_confirm->get_status() || 'confirmation_required' !== (string)($no_confirm->get_data()['code'] ?? '')) { m6_fail('SEO/GEO confirmation gate failed',$no_confirm->get_data()); }

// Target drift after Preview must invalidate Apply.
update_option('page_on_front',999999,false);
$stale = m6_request('POST','seo-geo/plans/'.rawurlencode((string)$plan['plan_id']).'/apply',$token,array('confirm'=>true));
if (409 !== $stale->get_status() || 'stale_revision' !== (string)($stale->get_data()['code'] ?? '')) { m6_fail('SEO/GEO stale target was not rejected',$stale->get_data()); }
update_option('page_on_front',0,false);

$fresh = m6_plan($token,'front-page');
$applied_response = m6_request('POST','seo-geo/plans/'.rawurlencode((string)$fresh['plan_id']).'/apply',$token,array('confirm'=>true));
if (200 !== $applied_response->get_status()) { m6_fail('SEO/GEO Apply failed',$applied_response->get_data()); }
$operation = (array)($applied_response->get_data()['data'] ?? array());
$operation_id = (string)($operation['operation_id'] ?? '');
$home_id = Eduardo_Research_Manager::contract()->page_id('home');
if ('' === $operation_id || 'page' !== get_option('show_on_front') || $home_id !== (int)get_option('page_on_front')) { m6_fail('SEO/GEO remediation did not repair front-page routing',$operation); }

$verify = m6_request('POST','seo-geo/operations/'.rawurlencode($operation_id).'/verify',$token,array('rendered'=>true));
$verified = (array)($verify->get_data()['data'] ?? array());
if (200 !== $verify->get_status() || empty($verified['stored_verification']['verified']) || empty($verified['rendered_verification']['verified']) || 'verified' !== (string)($verified['status'] ?? '')) { m6_fail('SEO/GEO post-Apply Verify failed',$verify->get_data()); }

$ready = m6_request('GET','seo-geo/site',$token);
if (200 !== $ready->get_status() || empty($ready->get_data()['data']['ready'])) { m6_fail('readiness did not recover after remediation',$ready->get_data()); }

$rollback = m6_request('POST','seo-geo/operations/'.rawurlencode($operation_id).'/rollback',$token,array('confirm'=>true));
$rolled = (array)($rollback->get_data()['data'] ?? array());
if (200 !== $rollback->get_status() || 'rolled-back' !== (string)($rolled['status'] ?? '') || empty($rolled['rollback_restored_baseline']) || 'posts' !== get_option('show_on_front') || 0 !== (int)get_option('page_on_front')) { m6_fail('SEO/GEO rollback did not restore broken baseline',$rollback->get_data()); }

// Restore the canonical seed state so the fresh installation finishes clean.
update_option('show_on_front',$seed_show_on_front,false);
update_option('page_on_front',$seed_page_on_front,false);
remove_all_filters('pre_http_request');

fwrite(STDOUT,"M6 SEO/GEO diagnostics and deterministic remediation OK\n");
