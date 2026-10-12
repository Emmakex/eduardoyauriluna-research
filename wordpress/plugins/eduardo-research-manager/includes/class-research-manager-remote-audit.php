<?php
/** Bounded non-secret audit trail for the authenticated Research Manager bridge. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Audit {
    private const OPTION = 'eduardo_research_manager_remote_audit';
    private const MAX_RECORDS = 200;
    private const MAX_PAGE_SIZE = 100;

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
        $result = $this->query(array('page'=>1, 'per_page'=>$limit));
        return (array) ($result['items'] ?? array());
    }

    /**
     * Return a bounded, exact-match filtered page of non-secret audit records.
     *
     * Supported filters intentionally mirror safe correlation identifiers only.
     */
    public function query(array $args = array()): array {
        $records = get_option(self::OPTION, array());
        $records = is_array($records) ? array_values($records) : array();

        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['per_page'] ?? 50)));
        $filters = array(
            'request_id' => substr($this->text($args['request_id'] ?? ''), 0, 120),
            'operation_id' => substr($this->text($args['operation_id'] ?? ''), 0, 100),
            'connection_id' => substr($this->text($args['connection_id'] ?? ''), 0, 120),
            'outcome' => substr($this->text($args['outcome'] ?? ''), 0, 40),
        );

        $filtered = array_values(array_filter($records, static function ($record) use ($filters): bool {
            if (! is_array($record)) { return false; }
            foreach ($filters as $field => $value) {
                if ('' !== $value && (string) ($record[$field] ?? '') !== $value) { return false; }
            }
            return true;
        }));

        $total = count($filtered);
        $offset = ($page - 1) * $per_page;
        $items = $offset < $total ? array_slice($filtered, $offset, $per_page) : array();

        return array(
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => 0 === $total ? 0 : (int) ceil($total / $per_page),
            'filters' => array_filter($filters, static fn(string $value): bool => '' !== $value),
            'items' => $items,
        );
    }

    public function clear(): void {
        delete_option(self::OPTION);
    }

    private function text(mixed $value): string {
        if (! is_scalar($value)) { return ''; }
        return sanitize_text_field((string) $value);
    }
}
