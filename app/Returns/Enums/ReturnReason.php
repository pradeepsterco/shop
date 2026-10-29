<?php

namespace App\Returns\Enums;

enum ReturnReason: string
{
    case Damaged = 'damaged';
    case Defective = 'defective';
    case WrongItem = 'wrong_item';
    case MissingItem = 'missing_item';
    case NotAsDescribed = 'not_as_described';
    case SizeFit = 'size_fit';
    case Quality = 'quality';
    case ChangedMind = 'changed_mind';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged',
            self::Defective => 'Defective',
            self::WrongItem => 'Wrong item received',
            self::MissingItem => 'Missing item or part',
            self::NotAsDescribed => 'Not as described',
            self::SizeFit => 'Size or fit issue',
            self::Quality => 'Quality not as expected',
            self::ChangedMind => 'Changed my mind',
        };
    }

    /**
     * Claims about what arrived: allowed even for non-returnable verticals, and need photo proof.
     */
    public function isClaim(): bool
    {
        return in_array($this, [self::Damaged, self::Defective, self::WrongItem, self::MissingItem], true);
    }

    public function requiresPhotos(): bool
    {
        return $this->isClaim();
    }

    /**
     * Default fault for the reason: problems with what was sent are the seller's; the rest is customer remorse.
     */
    public function fault(): ReturnFault
    {
        return match ($this) {
            self::Damaged, self::Defective, self::WrongItem, self::MissingItem, self::NotAsDescribed => ReturnFault::SellerFault,
            self::SizeFit, self::Quality, self::ChangedMind => ReturnFault::CustomerRemorse,
        };
    }

    /**
     * Missing items are not picked up: the admin decides refund or reship directly.
     */
    public function needsPickup(): bool
    {
        return $this !== self::MissingItem;
    }
}
