#!/usr/bin/env php
<?php
declare(strict_types=1);

function check(bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function source_file(string $relative): string {
    $path = __DIR__ . '/../' . $relative;
    $data = @file_get_contents($path);
    if (! is_string($data)) {
        fwrite(STDERR, "FAIL: cannot read {$relative}\n");
        exit(1);
    }
    return $data;
}

$credentials = source_file('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-credentials.php');
$admin = source_file('wordpress/plugins/eduardo-research-manager/includes/class-research-manager-remote-admin.php');

$methodStart = strpos($credentials, 'public function set_temporary_scopes');
$methodEnd = strpos($credentials, 'public function revoke_temporary', $methodStart === false ? 0 : $methodStart);
check(false !== $methodStart && false !== $methodEnd && $methodEnd > $methodStart, 'Temporary scope mutation method is missing');
$method = substr($credentials, $methodStart, $methodEnd - $methodStart);
check(str_contains($method, '$this->sanitize_scopes($scopes)'), 'Temporary scopes must use the supported-scope sanitizer');
check(str_contains($method, '$this->persist_temporary_states($temporary)'), 'Temporary scope updates must persist to the temporary credential store');
check(str_contains($method, "Revoked temporary Remote Manager credentials cannot change scopes."), 'Revoked temporary credentials must reject scope edits');
check(false === strpos($method, 'generate_token('), 'Editing temporary scopes must not rotate the bearer token');
check(false === strpos($method, "['token_hash'] ="), 'Editing temporary scopes must not replace the token hash');
check(false === strpos($method, "['token_prefix'] ="), 'Editing temporary scopes must not replace the token prefix');

$actionStart = strpos($admin, "case 'save_temporary_scopes':");
$actionEnd = strpos($admin, "case 'revoke_temporary':", $actionStart === false ? 0 : $actionStart);
check(false !== $actionStart && false !== $actionEnd && $actionEnd > $actionStart, 'Temporary scope admin action is missing');
$action = substr($admin, $actionStart, $actionEnd - $actionStart);
check(str_contains($action, 'set_temporary_scopes($connectionId, $scopes)'), 'Temporary scope admin action must delegate to credential service');
check(false === strpos($action, 'issue_temporary_token'), 'Saving temporary scopes must not issue a replacement token');
check(str_contains($admin, 'value="save_temporary_scopes"'), 'Temporary session UI must expose scope editing');
check(str_contains($admin, 'Edit scopes'), 'Temporary session UI must expose an Edit scopes action');
check(str_contains($admin, 'style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap"'), 'Edit scopes and Revoke temporary controls must share the Action cell');
check(str_contains($admin, 'Save temporary scopes'), 'Temporary session UI must expose an explicit save action');
check(str_contains($admin, 'The existing temporary token is not rotated.'), 'Temporary scope editor must explain token preservation');
check(str_contains($admin, "'scope' => 'credentials.manage'"), 'Temporary scope changes must remain locally audited as credential management');

fwrite(STDOUT, "Temporary Remote Manager scope editor contract: PASS\n");
