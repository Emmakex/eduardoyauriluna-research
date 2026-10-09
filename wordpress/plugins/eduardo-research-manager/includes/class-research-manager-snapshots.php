<?php
/** Bounded provenance store for reversible Research Manager mutations. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Snapshots {
    private const OPTION = 'eduardo_research_manager_snapshots';
    private const LIMIT = 25;

    public function create(array $plan, array $before): string {
        $snapshots = $this->all();
        $id = 'erm-snapshot-' . wp_generate_uuid4();
        $snapshots[$id] = array(
            'id'=>$id,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'plan_checksum'=>(string) ($plan['checksum'] ?? ''),
            'intent'=>(string) ($plan['intent'] ?? ''),
            'created_at'=>gmdate(DATE_W3C),
            'created_by'=>get_current_user_id(),
            'status'=>'applied',
            'before'=>$before,
        );

        while (count($snapshots) > self::LIMIT) {
            array_shift($snapshots);
        }
        $this->save($snapshots);
        return $id;
    }

    public function get(string $id): array {
        $snapshots = $this->all();
        $snapshot = $snapshots[$id] ?? array();
        return is_array($snapshot) ? $snapshot : array();
    }

    public function mark_rolled_back(string $id): void {
        $snapshots = $this->all();
        if (! isset($snapshots[$id]) || ! is_array($snapshots[$id])) { return; }
        $snapshots[$id]['status'] = 'rolled-back';
        $snapshots[$id]['rolled_back_at'] = gmdate(DATE_W3C);
        $snapshots[$id]['rolled_back_by'] = get_current_user_id();
        $this->save($snapshots);
    }

    public function all(): array {
        $snapshots = get_option(self::OPTION, array());
        return is_array($snapshots) ? $snapshots : array();
    }

    private function save(array $snapshots): void {
        if (false === get_option(self::OPTION, false)) {
            add_option(self::OPTION, $snapshots, '', false);
            return;
        }
        update_option(self::OPTION, $snapshots, false);
    }
}
