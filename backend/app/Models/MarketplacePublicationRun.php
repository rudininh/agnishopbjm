<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplacePublicationRun extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'product_uuid',
        'status',
        'requested_accounts',
        'completed_at',
    ];

    protected $casts = [
        'requested_accounts' => 'array',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            if (empty($run->id)) {
                $run->id = (string) Str::uuid();
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_uuid', 'uuid');
    }

    public function results(): HasMany
    {
        return $this->hasMany(MarketplacePublicationResult::class, 'run_id', 'id');
    }
}
