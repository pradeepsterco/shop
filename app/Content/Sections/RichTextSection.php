<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class RichTextSection implements SectionTypeDefinitionInterface
{
    public function type(): string
    {
        return 'rich_text';
    }

    public function label(): string
    {
        return 'Rich Text (Markdown)';
    }

    public function description(): string
    {
        return 'Sanitized Markdown content block for policy pages, About Us, terms, FAQs, etc.';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function settingsRules(): array
    {
        return [
            'content' => ['required', 'string'],
            'container_width' => ['sometimes', 'string', 'in:narrow,standard,full'],
        ];
    }

    public function itemRules(): array
    {
        return [];
    }

    public function defaultSettings(): array
    {
        return [
            'content' => "## About Our Marketplace\n\nWelcome to our multi-seller marketplace offering top-tier products across diverse categories.",
            'container_width' => 'standard',
        ];
    }

    public function defaultItemSettings(): array
    {
        return [];
    }

    public function supportsItems(): bool
    {
        return false;
    }

    public function maxItems(): ?int
    {
        return 0;
    }

    public function bladeComponent(): string
    {
        return 'content.sections.rich-text';
    }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
