<?php

namespace App\Services;

use App\Http\Controllers\OmnichannelController;

class MarketplaceTokenRefreshService
{
    public function __construct(private readonly OmnichannelController $omnichannel)
    {
    }

    public function refreshDueTokens(): array
    {
        return $this->omnichannel->autoRefreshMarketplaceTokens(false);
    }
}
