<?php
/** Research Blueprint identity operations preserve the 0.11.1 Profile service. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Research_Blueprint_Identity_Operations implements Site_Architect_Blueprint_Identity_Operations {
    public function __construct(private Eduardo_Research_Manager_Identity_Profile $profile) {}

    public function theme_id(): string { return 'research'; }

    public function preview_update(array $changes, bool $evidence_confirmed, string $evidence_reference): array|WP_Error {
        return $this->profile->preview_update($changes, $evidence_confirmed, $evidence_reference);
    }

    public function apply_preview(array $prepared): array|WP_Error {
        return $this->profile->apply_preview($prepared);
    }
}
