<?php
/** Eduardo Research implementation of the reusable Site Architect Theme profile. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Research_Theme_Profile implements Site_Architect_Theme_Profile {
    public function available(): bool {
        return function_exists('eduardo_research_preset');
    }

    public function preset(): array {
        if (! $this->available()) { return array(); }
        $preset = eduardo_research_preset();
        return is_array($preset) ? $preset : array();
    }

    public function preset_id(): string {
        return 'research';
    }

    public function minimum_preset_version(): int {
        return 4;
    }

    public function stylesheet(): string {
        return 'eduardo-research';
    }

    /**
     * Accepted production EN/ES option/meta storage aliases.
     *
     * This is Research/instance compatibility data, never universal Core
     * language policy. In particular, other locales must not be guessed.
     */
    public function language_storage(): array {
        return array(
            'en'=>array('suffix'=>'','identity_headline_field'=>'headline'),
            'es'=>array('suffix'=>'_es','identity_headline_field'=>'headline_es'),
        );
    }

    /** Legacy Research option naming belongs to this vertical, not Core. */
    public function page_option_key(string $key, string $suffix): string {
        return 'home' === $key
            ? 'eduardo_research_model' . $suffix
            : 'eduardo_research_surface_' . $key . $suffix;
    }
}
