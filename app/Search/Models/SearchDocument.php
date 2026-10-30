<?php

namespace App\Search\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A product's search document, stored by the database engine.
 *
 * @property int $product_id
 * @property string $title normalised title
 * @property string $keywords normalised searchable text other than the title
 * @property bool $in_stock
 * @property array<string, mixed> $document
 */
class SearchDocument extends Model
{
    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $fillable = [
        'product_id',
        'title',
        'keywords',
        'in_stock',
        'document',
    ];

    protected function casts(): array
    {
        return [
            'in_stock' => 'boolean',
            'document' => 'array',
        ];
    }
}
