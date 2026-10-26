<?php

namespace App\Catalog\Services;

use App\Catalog\Contracts\VerticalLookupInterface;
use App\Models\Vertical;

class VerticalLookupService implements VerticalLookupInterface
{
    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function getAllActiveVerticals(): array
    {
        return Vertical::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Vertical $v) => [
                'id' => (int) $v->id,
                'name' => (string) $v->name,
            ])
            ->values()
            ->all();
    }

    public function findVerticalName(int $id): ?string
    {
        return Vertical::query()->where('id', $id)->value('name');
    }
}
