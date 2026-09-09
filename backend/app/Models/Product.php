<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    protected $primaryKey = 'uuid';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'uuid',
        'name',
        'sku',
        'description',
        'price',
        'stock',
        'category_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $product) {
            if (empty($product->uuid)) {
                $product->uuid = Str::uuid()->toString();
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'uuid');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_uuid', 'uuid')
            ->orderBy('position');
    }

    public function publicationRuns(): HasMany
    {
        return $this->hasMany(MarketplacePublicationRun::class, 'product_uuid', 'uuid');
    }
}
