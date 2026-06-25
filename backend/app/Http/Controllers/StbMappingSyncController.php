<?php

namespace App\Http\Controllers;

use App\Services\StbMappingSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StbMappingSyncController extends Controller
{
    public function __construct(
        private readonly StbMappingSyncService $mappingSyncService,
    ) {
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorizeToken($request);

        if (! (bool) config('stb.sync_worker', false)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Import mapping hanya diaktifkan pada backend STB sync worker.',
            ], 409);
        }

        $snapshot = $request->input('snapshot');
        if (! is_array($snapshot)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payload snapshot tidak valid.',
            ], 422);
        }

        try {
            $result = $this->mappingSyncService->importSnapshot(
                $snapshot,
                $request->boolean('preserve_stock', true),
                $request->boolean('dry_run', false),
            );
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'Import mapping STB gagal: '.$exception->getMessage(),
                'exception' => class_basename($exception),
            ], 422);
        }

        return response()->json($result, ($result['status'] ?? '') === 'error' ? 422 : 200);
    }

    private function authorizeToken(Request $request): void
    {
        $expected = trim((string) config('stb.mapping_sync_token', ''));
        abort_if($expected === '', 403, 'STB mapping sync token belum dikonfigurasi.');

        $provided = trim((string) $request->bearerToken());
        if ($provided === '') {
            $provided = trim((string) $request->header('X-STB-Mapping-Sync-Token', ''));
        }

        abort_unless(hash_equals($expected, $provided), 403, 'Token STB mapping sync tidak valid.');
    }
}
