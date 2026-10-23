<?php

namespace App\Support;

class AdminPermissions
{
    public const ADMINS_VIEW = 'admins.view';

    public const ADMINS_MANAGE = 'admins.manage';

    public const ADMINS_PASSWORD = 'admins.password';

    public const ROLES_MANAGE = 'roles.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_CREATE = 'customers.create';

    public const CUSTOMERS_MANAGE = 'customers.manage';

    public const CUSTOMERS_PASSWORD = 'customers.password';

    public const SELLERS_VIEW = 'sellers.view';

    public const SELLERS_MANAGE = 'sellers.manage';

    public const SELLERS_KYC = 'sellers.kyc';

    public const CATALOG_CATEGORIES = 'catalog.categories';

    public const CATALOG_VERTICALS = 'catalog.verticals';

    public const CATALOG_ATTRIBUTES = 'catalog.attributes';

    public const CATALOG_BRANDS_MANAGE = 'catalog.brands.manage';

    public const CATALOG_BRANDS_REVIEW = 'catalog.brands.review';

    public const CATALOG_PRODUCTS_VIEW = 'catalog.products.view';

    public const CATALOG_QC = 'catalog.qc';

    /**
     * Get all registered permissions grouped by module.
     *
     * @return array<string, array<string, array{label: string, description: string}>>
     */
    public static function grouped(): array
    {
        return [
            'Staff & Roles' => [
                self::ADMINS_VIEW => [
                    'label' => 'View Staff',
                    'description' => 'View list of admin staff and their assigned roles.',
                ],
                self::ADMINS_MANAGE => [
                    'label' => 'Manage Staff',
                    'description' => 'Invite, edit, assign roles, and activate/deactivate staff.',
                ],
                self::ADMINS_PASSWORD => [
                    'label' => 'Reset Staff Passwords',
                    'description' => 'Reset staff passwords directly or send password reset links.',
                ],
                self::ROLES_MANAGE => [
                    'label' => 'Manage Roles',
                    'description' => 'Create, edit, and delete roles and configure permission sets.',
                ],
                self::AUDIT_VIEW => [
                    'label' => 'View Activity Logs',
                    'description' => 'Inspect admin action audit trail and security logs.',
                ],
            ],

            'Customers' => [
                self::CUSTOMERS_VIEW => [
                    'label' => 'View Customers',
                    'description' => 'View customer directory and individual customer details.',
                ],
                self::CUSTOMERS_CREATE => [
                    'label' => 'Create Customers',
                    'description' => 'Create new customer accounts with set password or welcome link.',
                ],
                self::CUSTOMERS_MANAGE => [
                    'label' => 'Manage Customers',
                    'description' => 'Activate or deactivate customer accounts.',
                ],
                self::CUSTOMERS_PASSWORD => [
                    'label' => 'Reset Customer Passwords',
                    'description' => 'Reset customer passwords directly or send password reset links.',
                ],
            ],

            'Sellers & KYC' => [
                self::SELLERS_VIEW => [
                    'label' => 'View Sellers',
                    'description' => 'Browse seller profiles, applications, and store listings.',
                ],
                self::SELLERS_MANAGE => [
                    'label' => 'Manage Sellers',
                    'description' => 'Approve, reject, suspend, and reactivate seller accounts.',
                ],
                self::SELLERS_KYC => [
                    'label' => 'View Sensitive KYC & Financials',
                    'description' => 'Access unmasked PAN, GSTIN, bank numbers, and download KYC files.',
                ],
            ],

            'Catalog Taxonomy' => [
                self::CATALOG_CATEGORIES => [
                    'label' => 'Manage Categories',
                    'description' => 'Create, edit, and reorganize the category taxonomy tree.',
                ],
                self::CATALOG_VERTICALS => [
                    'label' => 'Manage Verticals',
                    'description' => 'Create, edit, and toggle verticals on leaf categories.',
                ],
                self::CATALOG_ATTRIBUTES => [
                    'label' => 'Manage Attributes',
                    'description' => 'Create and configure vertical specifications and variant attributes.',
                ],
                self::CATALOG_BRANDS_MANAGE => [
                    'label' => 'Manage Brands',
                    'description' => 'Create official brands and maintain the brand directory.',
                ],
                self::CATALOG_BRANDS_REVIEW => [
                    'label' => 'Review Brand Requests',
                    'description' => 'Approve or reject brand requests submitted by sellers.',
                ],
            ],

            'Catalog Quality Control' => [
                self::CATALOG_PRODUCTS_VIEW => [
                    'label' => 'View Products',
                    'description' => 'Inspect universal catalog products and listings.',
                ],
                self::CATALOG_QC => [
                    'label' => 'Product QC & Approvals',
                    'description' => 'Approve or reject product submissions, variants, and pending changes.',
                ],
            ],
        ];
    }

    /**
     * Get a flat list of all permission names.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $all = [];
        foreach (self::grouped() as $group) {
            foreach (array_keys($group) as $perm) {
                $all[] = $perm;
            }
        }

        return $all;
    }
}
