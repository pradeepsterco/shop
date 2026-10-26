<?php

namespace App\Catalog\Contracts;

interface VerticalLookupInterface
{
    /**
     * Get all active verticals as lightweight id & name arrays.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getAllActiveVerticals(): array;

    /**
     * Find vertical name by ID.
     */
    public function findVerticalName(int $id): ?string;
}
