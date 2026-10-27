<?php

namespace App\Content\Enums;

enum LinkType: string
{
    case Category = 'category';
    case Brand = 'brand';
    case Product = 'product';
    case Page = 'page';
    case Search = 'search';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Category => 'Category',
            self::Brand => 'Brand',
            self::Product => 'Product',
            self::Page => 'Content Page',
            self::Search => 'Search Query',
            self::Url => 'Custom / External URL',
        };
    }
}
