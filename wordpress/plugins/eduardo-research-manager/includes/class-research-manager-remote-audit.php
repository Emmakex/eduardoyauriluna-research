<?php
/** Bounded non-secret audit trail for the authenticated Research Manager bridge. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Audit {
    private const OPTION = 'eduardo_research_manager_remote_audit';
    private const MAX_RECORDS = 200;

    public function record(array $record): array {
        $entry = array(
            'event_id' => 'rma_' . str_replace('-', '', wp_generate_uuid4()),
            'timestamp' => gmdate(DATE_W3C),
            'request_id' => $this->text($record['request_id'] ?? ''),
            'connection_id' => $this->text($record['connection_id'] ?? ''),
            'wordpress_user_id' => max(0, (int) ($record['wordpress_user_id'] ?? 0)),
            'method' => strtoupper(substr($this->text($record['method'] ?? ''), 0, 12)),
            'route' => substr($this->text($record['route'] ?? ''), 0, 220),
            'scope' => substr($this->text($record['scope'] ?? ''), 0, 80),
            'event' => substr($this->text($record['event'] ?? 'request'), 0, 80),
            'outcome' => substr($this->text($record['outcome'] ?? ''), 0, 40),
            'error_code' => substr(sanitize_key((string) ($record['error_code'] ?? '')), 0, 80),
            'operation_id' => substr($this->text($record['operation_id'] ?? ''), 0, 100),
            'plan_id' => substr($this->text($record['plan_id'] ?? ''), 0, 100),
            'reason' => substr($this->text($record['reason'] ?? ''), 0, 500),
        );

        $records = get_option(self::OPTION, array());
        $records = is_array($records) ? array_values($records) : array();
        array_unshift($records, $entry);
        $records = array_slice($records, 0, self::MAX_RECORDS);

        if (false === get_option(self::OPTION, false)) {
            add_option(self::OPTION, $records, '', false);
        } else {
            update_option(self::OPTION, $records, false);
        }

        return $entry;
    }

    public function recent(int $limit = 50): array {
        $records = get_option(self::OPTION, array());
        if (! is_array($records)) { return array(); }
        $limit = max(1, min(self::MAX_RECORDS, $limit));
        return array_slice(array_values($records), 0, $limit);
    }

    public function clear(): void {
        delete_option(self::OPTION);
    }

    private function text(mixed $value): string {
        if (! is_scalar($value)) { return ''; }
        return sanitize_text_field((string) $value);
    }
}
