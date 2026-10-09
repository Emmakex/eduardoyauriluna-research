<?php
/** Operating scenario for the Research Manager. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Mode {
    public const GREENFIELD = 'greenfield';
    public const MIGRATION = 'migration';

    /**
     * Research is a new WordPress build, so Greenfield is the native/default path.
     * Migration remains an explicit reusable scenario and must never be inferred
     * merely because diagnostics or existing records are present.
     */
    public static function current(): string {
        $configured = sanitize_key((string) get_option('eduardo_research_manager_mode', self::GREENFIELD));
        return in_array($configured, self::supported(), true) ? $configured : self::GREENFIELD;
    }

    public static function supported(): array {
        return array(self::GREENFIELD, self::MIGRATION);
    }

    public static function is_greenfield(): bool {
        return self::GREENFIELD === self::current();
    }

    public static function is_migration(): bool {
        return self::MIGRATION === self::current();
    }

    public static function capabilities(): array {
        if (self::is_migration()) {
            return array(
                'direct_contract_creation' => true,
                'legacy_discovery' => true,
                'legacy_mapping' => true,
                'legacy_url_preservation' => true,
                'migration_preview' => true,
            );
        }

        return array(
            'direct_contract_creation' => true,
            'legacy_discovery' => false,
            'legacy_mapping' => false,
            'legacy_url_preservation' => false,
            'migration_preview' => false,
        );
    }

    public static function describe(): array {
        return array(
            'mode' => self::current(),
            'greenfield' => self::is_greenfield(),
            'migration' => self::is_migration(),
            'capabilities' => self::capabilities(),
        );
    }
}
