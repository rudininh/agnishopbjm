<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$frontendDist = dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'dist';

Route::get('assets/{path}', function (string $path) use ($frontendDist) {
    $assetPath = realpath($frontendDist.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$path);
    $assetsRoot = realpath($frontendDist.DIRECTORY_SEPARATOR.'assets');

    abort_if(! $assetPath || ! $assetsRoot || ! str_starts_with($assetPath, $assetsRoot), 404);

    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'json' => 'application/json',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    $extension = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));

    return response()->file($assetPath, [
        'Content-Type' => $mimeTypes[$extension] ?? 'application/octet-stream',
    ]);
})->where('path', '.*');

Route::fallback(function (Request $request) use ($frontendDist) {
    abort_if($request->is('api/*'), 404);

    $indexPath = $frontendDist.DIRECTORY_SEPARATOR.'index.html';

    abort_if(! is_file($indexPath), 404, 'Frontend belum dibuild. Jalankan npm run build di folder frontend.');

    return response()->file($indexPath);
});
