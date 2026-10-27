<?php

namespace App\Content\Contracts;

interface SectionTypeDefinitionInterface
{
    /**
     * Unique string identifier for the section type.
     */
    public function type(): string;

    /**
     * User-facing label in builder library.
     */
    public function label(): string;

    /**
     * Brief description of what the section renders.
     */
    public function description(): string;

    /**
     * Schema version number.
     */
    public function schemaVersion(): int;

    /**
     * Validation rules for section settings.
     *
     * @return array<string, mixed>
     */
    public function settingsRules(): array;

    /**
     * Validation rules for section items settings.
     *
     * @return array<string, mixed>
     */
    public function itemRules(): array;

    /**
     * Default settings when adding a new section.
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(): array;

    /**
     * Default settings for a new item.
     *
     * @return array<string, mixed>
     */
    public function defaultItemSettings(): array;

    /**
     * Whether this section supports child items (slides, tiles).
     */
    public function supportsItems(): bool;

    /**
     * Maximum number of items (null = unlimited).
     */
    public function maxItems(): ?int;

    /**
     * Blade component name to render.
     */
    public function bladeComponent(): string;

    /**
     * Upgrade old settings schemas to latest version.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function upgradeSettings(array $settings, int $fromVersion): array;
}
