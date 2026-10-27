<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SectionTypeRegistry
{
    /**
     * @var array<string, SectionTypeDefinitionInterface>
     */
    protected array $types = [];

    public function __construct()
    {
        $this->registerDefaultTypes();
    }

    protected function registerDefaultTypes(): void
    {
        $this->register(new HeroCarouselSection);
        $this->register(new SingleBannerSection);
        $this->register(new WideBannerSection);
        $this->register(new ImageTileGridSection);
        $this->register(new CategoryNavSection);
        $this->register(new PromoCardCarouselSection);
        $this->register(new BrandTilesSection);
        $this->register(new CountdownSection);
        $this->register(new RichTextSection);
    }

    public function register(SectionTypeDefinitionInterface $definition): void
    {
        $this->types[$definition->type()] = $definition;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): SectionTypeDefinitionInterface
    {
        if (! isset($this->types[$type])) {
            throw new InvalidArgumentException("Unknown section type: {$type}");
        }

        return $this->types[$type];
    }

    public function find(string $type): ?SectionTypeDefinitionInterface
    {
        return $this->types[$type] ?? null;
    }

    /**
     * @return array<string, SectionTypeDefinitionInterface>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Validate section settings according to its type definition.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateSettings(string $type, array $settings): array
    {
        $definition = $this->get($type);
        $rules = $definition->settingsRules();

        if (empty($rules)) {
            return $settings;
        }

        return Validator::make($settings, $rules)->validate();
    }

    /**
     * Validate an item's settings according to section type definition.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateItemSettings(string $type, array $settings): array
    {
        $definition = $this->get($type);
        $rules = $definition->itemRules();

        if (empty($rules)) {
            return $settings;
        }

        return Validator::make($settings, $rules)->validate();
    }
}
