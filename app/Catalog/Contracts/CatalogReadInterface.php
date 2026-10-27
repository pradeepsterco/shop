<?php

namespace App\Catalog\Contracts;

interface CatalogReadInterface
{
    /**
     * Find category by ID or slug.
     *
     * @return array{id: int, name: string, slug: string, is_active: bool}|null
     */
    public function findCategory(int|string $identifier): ?array;

    /**
     * Find brand by ID or slug.
     *
     * @return array{id: int, name: string, slug: string, logo_url: ?string, is_active: bool}|null
     */
    public function findBrand(int|string $identifier): ?array;

    /**
     * Find product by ID, slug, or UPID.
     *
     * @return array{
     *     id: int,
     *     title: string,
     *     slug: string,
     *     upid: ?string,
     *     mrp: ?int,
     *     selling_price: ?int,
     *     discount_percent: ?int,
     *     image_url: ?string,
     *     is_active: bool,
     *     in_stock: bool
     * }|null
     */
    public function findProduct(int|string $identifier): ?array;

    /**
     * Search categories by keyword.
     *
     * @return array<int, array{id: int, name: string, slug: string, is_active: bool}>
     */
    public function searchCategories(string $query, int $limit = 10): array;

    /**
     * Get active categories.
     *
     * @return array<int, array{id: int, name: string, slug: string, is_active: bool}>
     */
    public function getActiveCategories(int $limit = 10): array;

    /**
     * Search brands by keyword.
     *
     * @return array<int, array{id: int, name: string, slug: string, is_active: bool}>
     */
    public function searchBrands(string $query, int $limit = 10): array;

    /**
     * Search products by keyword (title, slug, upid).
     *
     * @return array<int, array{id: int, title: string, slug: string, upid: ?string, is_active: bool}>
     */
    public function searchProducts(string $query, int $limit = 10): array;
}
