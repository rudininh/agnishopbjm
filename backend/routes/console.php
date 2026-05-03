<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:agnishop', function (): void {
    $this->info('AgniShop Banjarmasin API');
});
