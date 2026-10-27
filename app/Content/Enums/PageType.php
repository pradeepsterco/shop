<?php

namespace App\Content\Enums;

enum PageType: string
{
    case Home = 'home';
    case Landing = 'landing';
    case Static = 'static';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Homepage',
            self::Landing => 'Landing Page',
            self::Static => 'Static / Policy Page',
        };
    }
}
