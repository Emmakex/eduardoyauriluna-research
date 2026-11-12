<?php
/** Offline executable contract test for the Site Architect Theme profile seam. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('EDUARDO_RESEARCH_MANAGER_DIR', dirname(__DIR__) . '/wordpress/plugins/eduardo-research-manager/');

final class WP_Error {
    public function __construct(public string $code, public string $message) {}
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function sanitize_key(string $key): string {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}
final class SiteArchitectTestTheme {
    public function __construct(private string $stylesheet) {}
    public function get_stylesheet(): string { return $this->stylesheet; }
    public function exists(): bool { return true; }
    public function get(string $key): string { return $key === 'Version' ? '1.2.3' : ''; }
}
function wp_get_theme(?string $stylesheet = null): SiteArchitectTestTheme {
    return new SiteArchitectTestTheme($stylesheet ?? 'irrelevant-active-theme');
}
$research_preset = [
    'id' => 'research',
    'version' => 6,
    'frontend_authority' => 'theme',
    'default_language' => 'en',
    'languages' => ['en', 'es'],
    'pages' => [
        'home' => ['role' => 'front-page'],
        'about' => ['role' => 'profile'],
    ],
];
function eduardo_research_preset(): array {
    global $research_preset;
    return $research_preset;
}

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-contract.php';

function expect(bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}

$contract = new Eduardo_Research_Manager_Contract();
expect($contract->theme_available(), 'existing Theme callback must remain available');
expect($contract->compatible(), 'existing Research preset v6 must remain compatible');
expect($contract->languages() === ['en', 'es'], 'Research EN/ES languages unchanged');
expect($contract->default_language() === 'en', 'Research default language unchanged');
expect($contract->front_page_key() === 'home', 'front page registration unchanged');
expect($contract->theme_version() === '1.2.3', 'profile stylesheet selects Theme fallback');
expect($contract->language_storage_contract() === [
    'en' => ['suffix' => '', 'identity_headline_field' => 'headline'],
    'es' => ['suffix' => '_es', 'identity_headline_field' => 'headline_es'],
], 'physical EN/ES aliases unchanged');
expect($contract->page_option_key('home', 'en') === 'eduardo_research_model', 'Home EN option unchanged');
expect($contract->page_option_key('home', 'es') === 'eduardo_research_model_es', 'Home ES option unchanged');
expect($contract->page_option_key('about', 'es') === 'eduardo_research_surface_about_es', 'About ES option unchanged');
expect($contract->identity_headline_field('es') === 'headline_es', 'Identity ES field unchanged');
expect(is_wp_error($contract->language_storage('fr')), 'unregistered language remains fail-closed');
expect(Eduardo_Research_Manager_Contract::EXPECTED_PRESET === 'research', 'legacy preset constant remains available');
expect(Eduardo_Research_Manager_Contract::EXPECTED_STYLESHEET === 'eduardo-research', 'legacy stylesheet constant remains available');

$research_preset['id'] = 'wrong';
expect(! $contract->compatible(), 'unexpected preset rejected');
$research_preset['id'] = 'research';
$research_preset['version'] = 3;
expect(! $contract->compatible(), 'outdated preset rejected');
$research_preset['version'] = 6;
$research_preset['frontend_authority'] = 'other';
expect(! $contract->compatible(), 'non-Theme frontend authority rejected');
$research_preset['frontend_authority'] = 'theme';
$research_preset['languages'] = ['en', 'fr'];
expect(array_keys($contract->language_storage_contract()) === ['en'], 'unmapped language not invented');
$unmapped = $contract->language_storage('fr');
expect(is_wp_error($unmapped) && $unmapped->get_error_code() === 'research_manager_language_storage_unmapped', 'unknown language storage blocked');
$research_preset['languages'] = ['en', 'es'];

/** A synthetic second Theme demonstrates injection without editing Core declarations. */
final class SiteArchitectOtherThemeProfile implements Site_Architect_Theme_Profile {
    public function available(): bool { return true; }
    public function preset(): array {
        return ['id'=>'sample-site', 'version'=>1, 'frontend_authority'=>'theme',
                'languages'=>['fr','de'], 'default_language'=>'fr',
                'pages'=>['landing'=>['role'=>'front-page']]];
    }
    public function preset_id(): string { return 'sample-site'; }
    public function minimum_preset_version(): int { return 1; }
    public function stylesheet(): string { return 'sample-site-theme'; }
    public function language_storage(): array {
        return ['fr'=>['suffix'=>'_fr', 'identity_headline_field'=>'headline_fr'],
                'de'=>['suffix'=>'_de', 'identity_headline_field'=>'headline_de']];
    }
}
$other = new Eduardo_Research_Manager_Contract(new SiteArchitectOtherThemeProfile());
expect($other->compatible(), 'explicit alternate Theme profile is compatible');
expect($other->languages() === ['fr','de'], 'alternate languages from profile');
expect($other->default_language() === 'fr', 'alternate default from contract');
expect($other->front_page_key() === 'landing', 'alternate registered front page from contract');
expect($other->language_storage_contract()['de']['suffix'] === '_de', 'alternate locale storage declared, not inferred');
expect($other->theme_version() === '1.2.3', 'alternate stylesheet selected');
expect(is_wp_error($other->language_storage('en')), 'other site does not inherit Research language');
expect($other->theme_available(), 'profile availability is explicit');

echo "PASS: Site Architect Theme profile seam preserves Research 0.11.1, fail-closed storage and alternate contract injection\n";
