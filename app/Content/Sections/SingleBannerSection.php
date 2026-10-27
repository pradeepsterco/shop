<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class SingleBannerSection implements SectionTypeDefinitionInterface
{
    public function type(): string
    {
        return 'single_banner';
    }

    public function label(): string
    {
        return 'Single Banner';
    }

    public function description(): string
    {
        return 'Promotional spotlight banner in boxed or full-width container.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function settingsRules(): array
    {
        return [
            'layout' => ['sometimes', 'string', 'in:boxed,full_width'],
            'height_preset' => ['sometimes', 'string', 'in:sm,md,lg'],
        ];
    }

    public function itemRules(): array
    {
        return [
            'headline' => ['nullable', 'string', 'max:150'],
            'subheadline' => ['nullable', 'string', 'max:250'],
            'cta_text' => ['nullable', 'string', 'max:50'],
            'overlay_opacity' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'layout' => 'boxed',
            'height_preset' => 'md',
        ];
    }

    public function defaultItemSettings(): array
    {
        return [
            'headline' => '',
            'subheadline' => '',
            'cta_text' => 'Explore',
            'overlay_opacity' => 30,
        ];
    }

    public function supportsItems(): bool
    {
        return true;
    }

    public function maxItems(): ?int
    {
        return 1;
    }

    public function bladeComponent(): string
    {
        return 'content.sections.single-banner';
    }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
