<?php
/** Portable product-profile detection for Research Manager. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Site_Profile {
    public const GENERIC = 'generic-wordpress';
    public const RESEARCH = 'research-theme';

    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    public function id(): string {
        return $this->contract->compatible() ? self::RESEARCH : self::GENERIC;
    }

    public function research_available(): bool {
        return self::RESEARCH === $this->id();
    }

    public function snapshot(): array {
        $theme = wp_get_theme();
        return array(
            'id' => $this->id(),
            'research_available' => $this->research_available(),
            'wordpress_version' => (string) get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'active_theme' => array(
                'stylesheet' => (string) $theme->get_stylesheet(),
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
            ),
            'research_contract' => array(
                'available' => $this->contract->theme_available(),
                'compatible' => $this->contract->compatible(),
                'preset_id' => (string) ($this->contract->preset()['id'] ?? ''),
                'preset_version' => (int) ($this->contract->preset()['version'] ?? 0),
            ),
        );
    }
}
