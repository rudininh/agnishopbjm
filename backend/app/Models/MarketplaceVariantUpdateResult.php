<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceVariantUpdateResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'marketplace_listing_id',
        'account_key',
        'channel',
        'status',
        'idempotency_key',
        'requested_stock_qty',
        'requested_price',
        'response_summary',
        'error_code',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'requested_price' => 'decimal:2',
        'response_summary' => 'array',
        'completed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(MarketplaceVariantUpdateRun::class, 'run_id', 'id');
    }
}
