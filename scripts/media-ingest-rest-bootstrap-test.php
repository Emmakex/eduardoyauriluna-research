#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/erm-media-rest-bootstrap-' . bin2hex(random_bytes(4)) . '/';
define('ABSPATH', $root);
@mkdir(ABSPATH . 'wp-admin/includes', 0700, true);
file_put_contents(
    ABSPATH . 'wp-admin/includes/file.php',
    "<?php\nfunction wp_tempnam(string \$filename=''): string|false { return tempnam(sys_get_temp_dir(), 'erm-rest-stage-'); }\n"
);

final class WP_Error {
    public function __construct(private string $code, private string $message, private array $data = array()) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_file_name(string $value): string { return preg_replace('/[^A-Za-z0-9._-]/', '-', basename($value)) ?? ''; }
function absint(mixed $value): int { return max(0, (int) $value); }
function wp_check_filetype_and_ext(string $file, string $filename, array $mimes): array {
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    foreach ($mimes as $extensions => $mime) {
        if (in_array($ext, explode('|', $extensions), true)) {
            return array('ext'=>$ext, 'type'=>$mime, 'proper_filename'=>'');
        }
    }
    return array('ext'=>false, 'type'=>false, 'proper_filename'=>false);
}
function get_posts(array $args = array()): array { return array(); }

require_once __DIR__ . '/../wordpress/plugins/eduardo-research-manager/includes/class-research-manager-media-ingest.php';

if (function_exists('wp_tempnam')) {
    fwrite(STDERR, "FAIL: wp_tempnam must not exist before Media prepare loads wp-admin/includes/file.php.\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'erm-rest-upload-');
if (! is_string($tmp) || false === file_put_contents($tmp, 'PNG-REST-BOOTSTRAP')) {
    fwrite(STDERR, "FAIL: unable to create upload fixture.\n");
    exit(1);
}
$file = array(
    'name'=>'rest-bootstrap.png',
    'tmp_name'=>$tmp,
    'size'=>filesize($tmp),
    'error'=>UPLOAD_ERR_OK,
    'type'=>'',
);
$service = new Eduardo_Research_Manager_Media_Ingest();
$result = $service->prepare($file, array(
    'title'=>'REST bootstrap fixture',
    'alt_text'=>'One pixel acceptance fixture',
));

if (is_wp_error($result)) {
    fwrite(STDERR, 'FAIL: ' . $result->get_error_code() . ': ' . $result->get_error_message() . PHP_EOL);
    exit(1);
}
if (! function_exists('wp_tempnam')) {
    fwrite(STDERR, "FAIL: Media prepare did not load WordPress file helpers.\n");
    exit(1);
}
$staged = (string) ($result['staged_path'] ?? '');
if ('' === $staged || ! is_file($staged)) {
    fwrite(STDERR, "FAIL: Media prepare did not create a staged file.\n");
    exit(1);
}
if (64 !== strlen((string) ($result['file']['sha256'] ?? ''))) {
    fwrite(STDERR, "FAIL: Media prepare did not return SHA-256.\n");
    exit(1);
}

$service->cleanup_stage($staged);
@unlink($tmp);
@unlink(ABSPATH . 'wp-admin/includes/file.php');
@rmdir(ABSPATH . 'wp-admin/includes');
@rmdir(ABSPATH . 'wp-admin');
@rmdir(ABSPATH);

fwrite(STDOUT, "Media ingest REST bootstrap loads wp_tempnam dependency: PASS\n");
