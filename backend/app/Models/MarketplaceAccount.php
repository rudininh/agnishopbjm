<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceAccount extends Model
{
    protected $fillable = [
        'account_key',
        'name',
        'channel',
        'enabled',
        'settings',
        'credentials',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
            'credentials' => 'encrypted:array',
        ];
    }
}