<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$frontendDist = dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'dist';

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

Route::get('{file}', function (string $file) use ($frontendDist, $mimeTypes) {
    $filePath = realpath($frontendDist.DIRECTORY_SEPARATOR.$file);
    $distRoot = realpath($frontendDist);

    abort_if(! $filePath || ! $distRoot || ! str_starts_with($filePath, $distRoot), 404);
    abort_if(! is_file($filePath), 404);

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    return response()->file($filePath, [
        'Content-Type' => $mimeTypes[$extension] ?? 'application/octet-stream',
    ]);
})->where('file', 'favicon\.png|agni-logo\.png|robots\.txt|manifest\.webmanifest|site\.webmanifest');

Route::get('assets/{path}', function (string $path) use ($frontendDist, $mimeTypes) {
    $assetPath = realpath($frontendDist.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$path);
    $assetsRoot = realpath($frontendDist.DIRECTORY_SEPARATOR.'assets');

    abort_if(! $assetPath || ! $assetsRoot || ! str_starts_with($assetPath, $assetsRoot), 404);

    $extension = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));

    return response()->file($assetPath, [
        'Content-Type' => $mimeTypes[$extension] ?? 'application/octet-stream',
    ]);
})->where('path', '.*');

Route::get('pos/{path}', function (string $path) use ($frontendDist, $mimeTypes) {
    $assetPath = realpath($frontendDist.DIRECTORY_SEPARATOR.'pos'.DIRECTORY_SEPARATOR.$path);
    $assetsRoot = realpath($frontendDist.DIRECTORY_SEPARATOR.'pos');

    abort_if(! $assetPath || ! $assetsRoot || ! str_starts_with($assetPath, $assetsRoot), 404);
    abort_if(! is_file($assetPath), 404);

    $extension = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));

    return response()->file($assetPath, [
        'Content-Type' => $mimeTypes[$extension] ?? 'application/octet-stream',
    ]);
})->where('path', '.*');

Route::get('cached-images/{path}', function (string $path) {
    $baseDir = storage_path('app/public');
    $baseRoot = realpath($baseDir);
    $filePath = realpath($baseDir.DIRECTORY_SEPARATOR.$path);

    abort_if(! $baseRoot || ! $filePath || ! str_starts_with($filePath, $baseRoot), 404);
    abort_if(! is_file($filePath), 404);

    return response()->file($filePath, [
        'Content-Type' => mime_content_type($filePath) ?: 'application/octet-stream',
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('path', '.*');

Route::fallback(function (Request $request) use ($frontendDist) {
    abort_if($request->is('api/*'), 404);

    $indexPath = $frontendDist.DIRECTORY_SEPARATOR.'index.html';

    abort_if(! is_file($indexPath), 404, 'Frontend belum dibuild. Jalankan npm run build di folder frontend.');

    return response()->file($indexPath);
});
