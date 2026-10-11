<?php
/**
 * Phase 4 read-only production Media preflight for eduardoyauriluna.com.
 *
 * This script invokes only authenticated GET endpoints. It validates the
 * temporary Insight-targeted Media acceptance credential and the declared
 * P3.1-P3.4 Media contract before any production mutation is attempted.
 *
 * The bearer token is read only from ERM_TOKEN and is never printed or
 * persisted. Optional evidence contains only non-secret summaries.
 *
 * Usage:
 *   ERM_BASE_URL='https://eduardoyauriluna.com' ERM_TOKEN='...' \
 *     php scripts/p4-media-production-preflight.php \
 *     --evidence=tmp/p4-media-production-preflight.json
 */
declare(strict_types=1);

const API_PREFIX = '/wp-json/research-manager/v1';

$options = getopt('', array('evidence::', 'help'));
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/p4-media-production-preflight.php [--evidence=tmp/p4-media-production-preflight.json]\n");
    exit(0);
}

$baseUrl = rtrim(trim((string) getenv('ERM_BASE_URL')), '/');
$token = trim((string) getenv('ERM_TOKEN'));
if ('' === $baseUrl || '' === $token) {
    fwrite(STDERR, "ERM_BASE_URL and ERM_TOKEN must be set in the local environment.\n");
    exit(2);
}
if (! preg_match('#^https://#i', $baseUrl)) {
    fwrite(STDERR, "ERM_BASE_URL must use HTTPS.\n");
    exit(2);
}

$normaliseSet = static function (array $values): array {
    $values = array_values(array_unique(array_map('strval', $values)));
    sort($values);
    return $values;
};

$expectedScopes = $normaliseSet(array(
    'site.read',
    'site.diagnostics',
    'media.read',
    'media.write',
    'insights.read',
    'insights.write',
    'operations.apply',
    'operations.rollback',
));

$requestJson = static function (string $path) use ($baseUrl, $token): array {
    $requestId = 'p4-media-preflight-' . time() . '-' . bin2hex(random_bytes(6));
    $nonce = bin2hex(random_bytes(16));
    $timestamp = (string) time();
    $url = $baseUrl . API_PREFIX . $path;

    $ch = curl_init($url);
    if (false === $ch) {
        throw new RuntimeException('Unable to initialise cURL.');
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-Research-Manager-Request-Id: ' . $requestId,
            'X-Research-Manager-Nonce: ' . $nonce,
            'X-Research-Manager-Timestamp: ' . $timestamp,
        ),
    ));

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (false === $raw || '' !== $curlError) {
        throw new RuntimeException('Request failed for ' . $path . ': ' . $curlError);
    }
    $decoded = json_decode((string) $raw, true);
    if (! is_array($decoded)) {
        throw new RuntimeException(sprintf('Invalid JSON from %s (HTTP %d).', $path, $status));
    }
    if ($status < 200 || $status >= 300 || empty($decoded['ok']) || ! is_array($decoded['data'] ?? null)) {
        $code = (string) ($decoded['code'] ?? $decoded['error'] ?? 'request_failed');
        $message = (string) ($decoded['message'] ?? 'Request did not succeed.');
        throw new RuntimeException(sprintf('%s failed: HTTP %d %s — %s', $path, $status, $code, $message));
    }

    return array(
        'request_id'=>$requestId,
        'status'=>$status,
        'data'=>$decoded['data'],
    );
};

$checks = array();
$warnings = array();
$failure = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
};

try {
    $capabilitiesResponse = $requestJson('/capabilities');
    $capabilities = $capabilitiesResponse['data'];

    $grantedScopes = $normaliseSet((array) ($capabilities['granted_scopes'] ?? array()));
    $failure('exact_8_scope_media_profile', $grantedScopes === $expectedScopes);
    $failure('namespace_v1', 'research-manager/v1' === (string) ($capabilities['namespace'] ?? ''));
    $failure('writes_enabled', ! empty($capabilities['writes_enabled']));

    $media = is_array($capabilities['media_control'] ?? null) ? $capabilities['media_control'] : array();
    $diagnosticsContract = is_array($media['diagnostics'] ?? null) ? $media['diagnostics'] : array();
    $metadataTransport = is_array($media['metadata_transport'] ?? null) ? $media['metadata_transport'] : array();
    $assignment = is_array($media['assignment'] ?? null) ? $media['assignment'] : array();
    $assignmentTargets = is_array($assignment['targets'] ?? null) ? $assignment['targets'] : array();
    $assignmentInsight = is_array($assignmentTargets['insight'] ?? null) ? $assignmentTargets['insight'] : array();
    $assignmentTransport = is_array($assignment['transport'] ?? null) ? $assignment['transport'] : array();

    $failure('media_inventory_available', ! empty($media['inventory']));
    $failure('media_inspection_available', ! empty($media['inspection']));
    $failure('media_read_scope', 'media.read' === (string) ($media['read_scope'] ?? ''));
    $failure('media_write_available', ! empty($media['write_available']));
    $failure('media_write_scope', 'media.write' === (string) ($media['write_scope'] ?? ''));
    $failure('media_metadata_operation', in_array('media-metadata-update', (array) ($media['operations'] ?? array()), true));
    $failure('no_filesystem_paths', empty($media['filesystem_paths_exposed']));
    $failure('no_arbitrary_upload_proxy', empty($media['arbitrary_upload_proxy']));
    $failure('no_destructive_delete', empty($media['destructive_delete']));
    $failure('no_arbitrary_wordpress_proxy', empty($media['arbitrary_wordpress_proxy']));

    $failure('diagnostics_available', ! empty($diagnosticsContract['available']));
    $failure('diagnostics_media_read_scope', 'media.read' === (string) ($diagnosticsContract['read_scope'] ?? ''));
    $failure('diagnostics_aggregate_readiness', ! empty($diagnosticsContract['aggregate_readiness']));
    $failure('diagnostics_no_content_hashing', empty($diagnosticsContract['content_hashing']));
    $failure('diagnostics_no_destructive_delete', empty($diagnosticsContract['destructive_delete']));
    $failure('diagnostics_no_binary_replacement', empty($diagnosticsContract['binary_replacement']));
    $expectedDiagnosticChecks = $normaliseSet(array(
        'missing-alt', 'weak-alt', 'unused-attachment', 'duplicate-candidate', 'large-image-dimensions',
    ));
    $failure('diagnostic_check_contract', $normaliseSet((array) ($diagnosticsContract['checks'] ?? array())) === $expectedDiagnosticChecks);

    $failure('metadata_lifecycle_contract', $normaliseSet((array) ($metadataTransport['lifecycle'] ?? array())) === $normaliseSet(array('plan','apply','status','verify','rollback')));
    $failure('metadata_write_scope_contract', $normaliseSet((array) ($metadataTransport['required_write_scopes'] ?? array())) === $normaliseSet(array('operations.apply','media.write')));
    $failure('metadata_rollback_scope_contract', $normaliseSet((array) ($metadataTransport['required_rollback_scopes'] ?? array())) === $normaliseSet(array('operations.rollback','media.write')));
    $failure('metadata_stale_revision_protection', ! empty($metadataTransport['stale_attachment_revision_protection']));
    $failure('metadata_idempotency', ! empty($metadataTransport['idempotency']));

    $failure('assignment_available', ! empty($assignment['available']));
    $failure('assignment_operation', 'media-assignment' === (string) ($assignment['operation'] ?? ''));
    $failure('assignment_image_only', 'image' === (string) ($assignment['attachment_type'] ?? ''));
    $failure('assignment_insight_read_scope', 'insights.read' === (string) ($assignmentInsight['read_scope'] ?? ''));
    $failure('assignment_insight_write_scope', 'insights.write' === (string) ($assignmentInsight['write_scope'] ?? ''));
    $failure('assignment_lifecycle_contract', $normaliseSet((array) ($assignmentTransport['lifecycle'] ?? array())) === $normaliseSet(array('inspect','plan','apply','status','verify','rollback')));
    $failure('assignment_inspect_scope_contract', $normaliseSet((array) ($assignmentTransport['inspect_scopes'] ?? array())) === $normaliseSet(array('media.read','target.read')));
    $failure('assignment_write_scope_contract', $normaliseSet((array) ($assignmentTransport['write_scopes'] ?? array())) === $normaliseSet(array('operations.apply','media.write','target.write')));
    $failure('assignment_rollback_scope_contract', $normaliseSet((array) ($assignmentTransport['rollback_scopes'] ?? array())) === $normaliseSet(array('operations.rollback','media.write','target.write')));
    $failure('assignment_verify_scope_contract', $normaliseSet((array) ($assignmentTransport['verify_scopes'] ?? array())) === $normaliseSet(array('site.diagnostics','media.read','target.read')));
    $failure('assignment_dual_stale_protection', ! empty($assignmentTransport['dual_resource_stale_protection']));
    $failure('assignment_rendered_verification', ! empty($assignmentTransport['rendered_verification']));
    $failure('assignment_idempotency', ! empty($assignmentTransport['idempotency']));
    $failure('assignment_no_arbitrary_post_meta', empty($assignment['arbitrary_post_meta']));
    $failure('assignment_theme_layout_authority', 'theme' === (string) ($assignment['layout_authority'] ?? ''));

    $inventoryResponse = $requestJson('/media?mime_type=image&per_page=20&page=1');
    $inventory = $inventoryResponse['data'];
    $inventoryItems = is_array($inventory['items'] ?? null) ? $inventory['items'] : array();
    $failure('inventory_read_only', ! empty($inventory['read_only']));
    $failure('inventory_filter_image', 'image' === (string) (($inventory['filters']['mime_type'] ?? '')));
    $failure('inventory_safe_item_types', ! in_array(false, array_map(static fn($item): bool => is_array($item) && 'image' === (string) ($item['media_type'] ?? ''), $inventoryItems), true));

    $imageCandidate = null;
    foreach ($inventoryItems as $item) {
        if (! is_array($item) || (int) ($item['id'] ?? 0) <= 0 || '' === (string) ($item['source_url'] ?? '')) { continue; }
        $imageCandidate = array(
            'attachment_id'=>(int) $item['id'],
            'source_url'=>(string) $item['source_url'],
            'width'=>$item['width'] ?? null,
            'height'=>$item['height'] ?? null,
            'metadata_ready'=>! empty($item['readiness']['metadata_ready']),
            'missing_alt'=>! empty($item['readiness']['missing_alt']),
        );
        break;
    }
    $failure('image_candidate_available', is_array($imageCandidate));

    $diagnosticsResponse = $requestJson('/media/diagnostics?limit=500');
    $diagnostics = $diagnosticsResponse['data'];
    $failure('diagnostics_read_only_response', ! empty($diagnostics['read_only']));
    $failure('diagnostics_non_destructive', empty($diagnostics['remediation']['automatic_delete']) && empty($diagnostics['remediation']['binary_replacement']));
    if (empty($diagnostics['scan']['complete'])) {
        $warnings[] = 'Media diagnostics scan is truncated at the bounded 500-attachment limit; findings remain read-only.';
    }

    $insightsResponse = $requestJson('/insights?language=en');
    $insights = $insightsResponse['data'];
    $insightItems = is_array($insights['items'] ?? null) ? $insights['items'] : array();
    $insightCandidate = null;
    foreach ($insightItems as $item) {
        if (! is_array($item) || 'publish' !== (string) ($item['status'] ?? '') || (int) ($item['post_id'] ?? 0) <= 0) { continue; }
        $insightCandidate = array(
            'post_id'=>(int) $item['post_id'],
            'language'=>(string) ($item['language'] ?? ''),
            'status'=>(string) $item['status'],
            'url'=>(string) ($item['url'] ?? ''),
            'title'=>(string) ($item['title'] ?? ''),
        );
        break;
    }
    $failure('published_insight_candidate_available', is_array($insightCandidate));

    $failedChecks = array_keys(array_filter($checks, static fn(bool $passed): bool => ! $passed));
    $passed = empty($failedChecks);

    $evidence = array(
        'schema'=>'p4-media-production-preflight-v1',
        'generated_at'=>gmdate(DATE_W3C),
        'base_url'=>$baseUrl,
        'read_only'=>true,
        'mutation_requests'=>0,
        'scope_profile'=>array(
            'expected'=>$expectedScopes,
            'granted'=>$grantedScopes,
            'exact_match'=>$checks['exact_8_scope_media_profile'] ?? false,
        ),
        'requests'=>array(
            'capabilities'=>$capabilitiesResponse['request_id'],
            'media_inventory'=>$inventoryResponse['request_id'],
            'media_diagnostics'=>$diagnosticsResponse['request_id'],
            'insights'=>$insightsResponse['request_id'],
        ),
        'checks'=>$checks,
        'failed_checks'=>$failedChecks,
        'warnings'=>$warnings,
        'inventory'=>array(
            'returned_count'=>(int) ($inventory['count'] ?? 0),
            'total_images'=>(int) ($inventory['total'] ?? 0),
            'candidate'=>$imageCandidate,
        ),
        'diagnostics'=>array(
            'ready'=>! empty($diagnostics['ready']),
            'summary'=>is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : array(),
            'scan'=>is_array($diagnostics['scan'] ?? null) ? $diagnostics['scan'] : array(),
            'advisory_count'=>(int) ($diagnostics['advisory_count'] ?? 0),
            'blocking_count'=>(int) ($diagnostics['blocking_count'] ?? 0),
        ),
        'insight_candidate'=>$insightCandidate,
        'pass'=>$passed,
    );

    if (isset($options['evidence']) && '' !== trim((string) $options['evidence'])) {
        $evidencePath = (string) $options['evidence'];
        $directory = dirname($evidencePath);
        if ('.' !== $directory && ! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create evidence directory: ' . $directory);
        }
        $encoded = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded || false === file_put_contents($evidencePath, $encoded . PHP_EOL)) {
            throw new RuntimeException('Unable to write evidence file: ' . $evidencePath);
        }
    }

    fwrite(STDOUT, 'Phase 4 Media production preflight: ' . ($passed ? 'PASS' : 'FAIL') . PHP_EOL);
    fwrite(STDOUT, 'Granted scopes: ' . count($grantedScopes) . ' (exact expected: ' . (($checks['exact_8_scope_media_profile'] ?? false) ? 'yes' : 'no') . ')' . PHP_EOL);
    fwrite(STDOUT, 'Media images: ' . (int) ($inventory['total'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'Diagnostics advisories: ' . (int) ($diagnostics['advisory_count'] ?? 0) . '; blocking: ' . (int) ($diagnostics['blocking_count'] ?? 0) . PHP_EOL);
    if (is_array($imageCandidate)) {
        fwrite(STDOUT, 'Image candidate: #' . $imageCandidate['attachment_id'] . ' ' . $imageCandidate['source_url'] . PHP_EOL);
    }
    if (is_array($insightCandidate)) {
        fwrite(STDOUT, 'Insight candidate: #' . $insightCandidate['post_id'] . ' ' . $insightCandidate['url'] . PHP_EOL);
    }
    foreach ($warnings as $warning) {
        fwrite(STDOUT, 'WARNING: ' . $warning . PHP_EOL);
    }
    if (! $passed) {
        fwrite(STDERR, 'Failed checks: ' . implode(', ', $failedChecks) . PHP_EOL);
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Phase 4 Media production preflight failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
