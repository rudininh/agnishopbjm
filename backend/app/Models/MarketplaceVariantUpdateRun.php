<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceVariantUpdateRun extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'stock_master_id',
        'stock_adjustment_id',
        'operator',
        'requested_stock_qty',
        'requested_accounts',
        'status',
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

    public function results(): HasMany
    {
        return $this->hasMany(MarketplaceVariantUpdateResult::class, 'run_id', 'id');
    }
}
