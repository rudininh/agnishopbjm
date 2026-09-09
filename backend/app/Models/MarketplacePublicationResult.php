<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplacePublicationResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'account_key',
        'channel',
        'status',
        'idempotency_key',
        'remote_product_id',
        'remote_variant_ids',
        'request_payload',
        'response_payload',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'remote_variant_ids' => 'array',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(MarketplacePublicationRun::class, 'run_id', 'id');
    }
}
