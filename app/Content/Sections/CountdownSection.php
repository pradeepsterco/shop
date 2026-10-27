<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class CountdownSection implements SectionTypeDefinitionInterface
{
    public function type(): string
    {
        return 'countdown';
    }

    public function label(): string
    {
        return 'Flash Sale Countdown';
    }

    public function description(): string
    {
        return 'Urgent countdown timer with headline and optional featured items; automatically hides when expired.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function settingsRules(): array
    {
        return [
            'end_time' => ['required', 'string'], // ISO 8601 UTC string
            'headline' => ['required', 'string', 'max:150'],
            'subheadline' => ['nullable', 'string', 'max:250'],
            'badge' => ['nullable', 'string', 'max:50'],
            'expired_behavior' => ['sometimes', 'string', 'in:hide,show_message'],
        ];
    }

    public function itemRules(): array
    {
        return [
            'offer_label' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'end_time' => now()->addDays(2)->toIso8601String(),
            'headline' => 'Flash Sale Ending Soon!',
            'subheadline' => 'Hurry, grab limited edition marketplace deals before time runs out.',
            'badge' => 'Limited Time',
            'expired_behavior' => 'hide',
        ];
    }

    public function defaultItemSettings(): array
    {
        return [
            'offer_label' => '',
        ];
    }

    public function supportsItems(): bool
    {
        return true;
    }

    public function maxItems(): ?int
    {
        return 6;
    }

    public function bladeComponent(): string
    {
        return 'content.sections.countdown';
    }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
