<?php

namespace App\Enums;

/**
 * Standard units for the net quantity declaration (Legal Metrology (Packaged Commodities) Rules, 2011).
 */
enum NetQuantityUnit: string
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Millilitre = 'ml';
    case Litre = 'l';
    case Centimetre = 'cm';
    case Metre = 'm';
    case SquareMetre = 'sq_m';
    case Count = 'count';
    case Pair = 'pair';

    public function label(): string
    {
        return match ($this) {
            self::Gram => 'Grams (g)',
            self::Kilogram => 'Kilograms (kg)',
            self::Millilitre => 'Millilitres (ml)',
            self::Litre => 'Litres (l)',
            self::Centimetre => 'Centimetres (cm)',
            self::Metre => 'Metres (m)',
            self::SquareMetre => 'Square metres (m²)',
            self::Count => 'Number of pieces (N)',
            self::Pair => 'Pairs',
        };
    }

    /**
     * Short symbol printed after the quantity, e.g. "500 g", "1 N".
     */
    public function symbol(): string
    {
        return match ($this) {
            self::SquareMetre => 'm²',
            self::Count => 'N',
            default => $this->value,
        };
    }

    public static function format(float|string|null $quantity, ?self $unit): ?string
    {
        if ($quantity === null || $unit === null) {
            return null;
        }

        $number = rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.');

        return $unit === self::Pair
            ? $number.' '.((float) $quantity === 1.0 ? 'pair' : 'pairs')
            : $number.' '.$unit->symbol();
    }
}
