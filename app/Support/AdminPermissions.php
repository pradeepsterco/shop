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

    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_MANAGE = 'orders.manage';

    public const FEES_VIEW = 'fees.view';

    public const FEES_MANAGE = 'fees.manage';

    public const SETTLEMENTS_VIEW = 'settlements.view';

    public const PAYOUTS_VIEW = 'payouts.view';

    public const PAYOUTS_MANAGE = 'payouts.manage';

    public const SHIPPING_VIEW = 'shipping.view';

    public const SHIPPING_MANAGE = 'shipping.manage';

    public const BRANDING_VIEW = 'branding.view';

    public const BRANDING_MANAGE = 'branding.manage';

    public const PAGES_VIEW = 'pages.view';

    public const PAGES_MANAGE = 'pages.manage';

    public const PAGES_PUBLISH = 'pages.publish';

    public const COUPONS_VIEW = 'coupons.view';

    public const COUPONS_MANAGE = 'coupons.manage';

    public const RETURNS_VIEW = 'returns.view';

    public const RETURNS_MANAGE = 'returns.manage';

    public const REFUNDS_MANAGE = 'refunds.manage';

    public const REVIEWS_VIEW = 'reviews.view';

    public const REVIEWS_MANAGE = 'reviews.manage';

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

            'Orders' => [
                self::ORDERS_VIEW => [
                    'label' => 'View Orders',
                    'description' => 'Inspect customer orders, seller fulfillment breakdown, and items audit.',
                ],
                self::ORDERS_MANAGE => [
                    'label' => 'Manage Orders',
                    'description' => 'Override order statuses, issue refunds, and manage disputes.',
                ],
            ],

            'Fees & Commissions' => [
                self::FEES_VIEW => [
                    'label' => 'View Fee Schedules',
                    'description' => 'Inspect marketplace fee schedules, commission slabs, and calculation rules.',
                ],
                self::FEES_MANAGE => [
                    'label' => 'Manage Fee Schedules',
                    'description' => 'Create draft schedules, edit slabs, clone, and activate fee schedules.',
                ],
            ],

            'Settlements & Payouts' => [
                self::SETTLEMENTS_VIEW => [
                    'label' => 'View Settlements & Ledgers',
                    'description' => 'Inspect seller order settlements, double-entry financial ledgers, and revenue reports.',
                ],
                self::PAYOUTS_VIEW => [
                    'label' => 'View Payout Batches',
                    'description' => 'Inspect weekly disbursement batches and seller payout records.',
                ],
                self::PAYOUTS_MANAGE => [
                    'label' => 'Manage Payout Batches',
                    'description' => 'Generate weekly disbursement batches, download bank CSVs, mark paid with UTR, and cancel batches.',
                ],
            ],
            'Shipping & Logistics' => [
                self::SHIPPING_VIEW => [
                    'label' => 'View Shipments & Carrier Settings',
                    'description' => 'View shipments across sellers, tracking events, and carrier configuration.',
                ],
                self::SHIPPING_MANAGE => [
                    'label' => 'Manage Shipping & Carriers',
                    'description' => 'Switch active carrier, test API connections, manage seller pickup registrations, and trigger NDR actions.',
                ],
            ],
            'Branding & Settings' => [
                self::BRANDING_VIEW => [
                    'label' => 'View Branding',
                    'description' => 'View marketplace branding, logos, and area names.',
                ],
                self::BRANDING_MANAGE => [
                    'label' => 'Manage Branding',
                    'description' => 'Update marketplace name, upload light/dark logos and favicon, or reset to default.',
                ],
            ],
            'Content & Pages' => [
                self::PAGES_VIEW => [
                    'label' => 'View Pages',
                    'description' => 'View pages listing and preview drafts.',
                ],
                self::PAGES_MANAGE => [
                    'label' => 'Manage Pages',
                    'description' => 'Create pages, edit sections, reorder items, and upload media.',
                ],
                self::PAGES_PUBLISH => [
                    'label' => 'Publish Pages',
                    'description' => 'Publish pages, schedule publication, and rollback versions.',
                ],
            ],
            'Promotions & Coupons' => [
                self::COUPONS_VIEW => [
                    'label' => 'View Coupons',
                    'description' => 'View coupons list, scopes, and redemption statistics.',
                ],
                self::COUPONS_MANAGE => [
                    'label' => 'Manage Coupons',
                    'description' => 'Create, edit, pause, duplicate, and configure coupon scopes and limits.',
                ],
            ],
            'Returns & Claims' => [
                self::RETURNS_VIEW => [
                    'label' => 'View Returns & Claims',
                    'description' => 'View RTO condition reports, inspections, and courier claims.',
                ],
                self::RETURNS_MANAGE => [
                    'label' => 'Manage Returns & Claims',
                    'description' => 'Decide RTO restock vs write-off, file claims, and handle partial delivery.',
                ],
                self::REFUNDS_MANAGE => [
                    'label' => 'Manage Refunds',
                    'description' => 'See full customer payout details and record refund payouts (UTR) or failures.',
                ],
            ],
            'Reviews & Ratings' => [
                self::REVIEWS_VIEW => [
                    'label' => 'View Reviews',
                    'description' => 'View customer product reviews, star ratings, and review summaries.',
                ],
                self::REVIEWS_MANAGE => [
                    'label' => 'Moderate Reviews',
                    'description' => 'Hide, reject, restore, or delete product reviews and recalculate ratings.',
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
