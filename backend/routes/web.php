<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$frontendDist = dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'dist';

Route::get('assets/{path}', function (string $path) use ($frontendDist) {
    $assetPath = realpath($frontendDist.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$path);
    $assetsRoot = realpath($frontendDist.DIRECTORY_SEPARATOR.'assets');

    abort_if(! $assetPath || ! $assetsRoot || ! str_starts_with($assetPath, $assetsRoot), 404);

    return response()->file($assetPath);
})->where('path', '.*');

Route::fallback(function (Request $request) use ($frontendDist) {
    abort_if($request->is('api/*'), 404);

    $indexPath = $frontendDist.DIRECTORY_SEPARATOR.'index.html';

    abort_if(! is_file($indexPath), 404, 'Frontend belum dibuild. Jalankan npm run build di folder frontend.');

    return response()->file($indexPath);
});
