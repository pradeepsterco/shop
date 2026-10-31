<?php

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('repair migration fixes a product_offers table pointing at a missing sellers table', function () {
    // As an early migration left it on SQLite. With foreign keys on, nothing can be inserted into it, so it is always empty.
    Schema::drop('product_offers');
    DB::statement('CREATE TABLE "product_offers" ("id" integer primary key autoincrement not null, "product_id" integer not null, "variant_id" integer, "seller_id" integer not null, "seller_sku" varchar, "mrp" numeric not null, "price" numeric not null, "stock" integer not null default \'0\', "status" tinyint(1) not null default \'1\', "created_at" datetime, "updated_at" datetime, "deleted_at" datetime, foreign key("product_id") references "products"("id") on delete cascade, foreign key("variant_id") references "product_variants"("id") on delete cascade, foreign key("seller_id") references "sellers"("id") on delete cascade)');

    expect(fn () => Product::factory()->create()->delete())->toThrow(QueryException::class, 'main.sellers');

    (require database_path('migrations/2026_11_01_000000_repair_product_offers_seller_foreign_key.php'))->up();

    expect((string) DB::table('sqlite_master')->where('name', 'product_offers')->value('sql'))
        ->toContain('references "seller_profiles"')
        ->not->toContain('"sellers"');

    $product = Product::factory()->create();
    $product->delete();
    expect(Product::find($product->id))->toBeNull();
});

test('repair migration leaves a correct product_offers table alone', function () {
    $before = (string) DB::table('sqlite_master')->where('name', 'product_offers')->value('sql');

    (require database_path('migrations/2026_11_01_000000_repair_product_offers_seller_foreign_key.php'))->up();

    expect((string) DB::table('sqlite_master')->where('name', 'product_offers')->value('sql'))->toBe($before);
});
