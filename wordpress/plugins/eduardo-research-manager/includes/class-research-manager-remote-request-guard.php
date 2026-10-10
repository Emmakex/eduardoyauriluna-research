<?php
/** Replay, expiry, rate and idempotency guards for remote Manager requests. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Request_Guard {
    private const CLOCK_SKEW_SECONDS = 300;
    private const REPLAY_TTL_SECONDS = 600;
    private const IDEMPOTENCY_TTL_SECONDS = DAY_IN_SECONDS;
    private const RATE_WINDOW_SECONDS = 60;
    private const RATE_LIMIT = 120;

    public function validate_metadata(WP_REST_Request $request): array|WP_Error {
        $request_id = trim((string) $request->get_header('x-research-manager-request-id'));
        $nonce = trim((string) $request->get_header('x-research-manager-nonce'));
        $timestamp = trim((string) $request->get_header('x-research-manager-timestamp'));

        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $request_id)) {
            return $this->error('validation_failed', 'A valid X-Research-Manager-Request-Id header is required.', 400);
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $nonce)) {
            return $this->error('validation_failed', 'A valid X-Research-Manager-Nonce header is required.', 400);
        }
        if (! ctype_digit($timestamp)) {
            return $this->error('validation_failed', 'A Unix timestamp is required in X-Research-Manager-Timestamp.', 400);
        }

        $timestamp_int = (int) $timestamp;
        if (abs(time() - $timestamp_int) > self::CLOCK_SKEW_SECONDS) {
            return $this->error('validation_failed', 'Remote Manager request timestamp is outside the allowed validity window.', 401);
        }

        return array(
            'request_id' => $request_id,
            'nonce' => $nonce,
            'timestamp' => $timestamp_int,
        );
    }

    public function consume_nonce(string $connection_id, string $nonce): true|WP_Error {
        $key = 'erm_remote_nonce_' . substr(hash('sha256', $connection_id . '|' . $nonce), 0, 40);
        if (false !== get_transient($key)) {
            return $this->error('validation_failed', 'Remote Manager request nonce has already been used.', 409);
        }
        set_transient($key, 1, self::REPLAY_TTL_SECONDS);
        return true;
    }

    public function enforce_rate_limit(string $connection_id): true|WP_Error {
        $window = (int) floor(time() / self::RATE_WINDOW_SECONDS);
        $key = 'erm_remote_rate_' . substr(hash('sha256', $connection_id . '|' . $window), 0, 40);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return $this->error('rate_limited', 'Remote Manager request rate limit exceeded.', 429);
        }
        set_transient($key, $count + 1, self::RATE_WINDOW_SECONDS + 5);
        return true;
    }

    public function request_fingerprint(WP_REST_Request $request): string {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = $request->get_body_params();
        }
        return hash('sha256', wp_json_encode(array(
            'method' => strtoupper($request->get_method()),
            'route' => $request->get_route(),
            'params' => $body,
        )) ?: '');
    }

    public function previous_request(string $connection_id, string $request_id): ?array {
        $key = $this->idempotency_key($connection_id, $request_id);
        $value = get_transient($key);
        return is_array($value) ? $value : null;
    }

    public function remember_request(string $connection_id, string $request_id, string $fingerprint, array $result = array()): void {
        set_transient(
            $this->idempotency_key($connection_id, $request_id),
            array(
                'fingerprint' => $fingerprint,
                'result' => $result,
                'recorded_at' => gmdate(DATE_W3C),
            ),
            self::IDEMPOTENCY_TTL_SECONDS
        );
    }

    public function validate_idempotency(string $connection_id, string $request_id, string $fingerprint): true|array|WP_Error {
        $previous = $this->previous_request($connection_id, $request_id);
        if (null === $previous) { return true; }
        if (! hash_equals((string) ($previous['fingerprint'] ?? ''), $fingerprint)) {
            return $this->error('validation_failed', 'The request ID has already been used with a different request payload.', 409);
        }
        return $previous;
    }

    private function idempotency_key(string $connection_id, string $request_id): string {
        return 'erm_remote_req_' . substr(hash('sha256', $connection_id . '|' . $request_id), 0, 40);
    }

    private function error(string $code, string $message, int $status): WP_Error {
        return new WP_Error($code, $message, array('status'=>$status));
    }
}
