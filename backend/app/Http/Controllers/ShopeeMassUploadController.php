<?php

namespace App\Http\Controllers;

use App\Services\ShopeeMassUploadService;
use App\Services\GitashopMassUploadWorkerLauncher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopeeMassUploadController extends Controller
{
    public function __construct(
        private readonly ShopeeMassUploadService $service,
        private readonly GitashopMassUploadWorkerLauncher $launcher,
    )
    {
    }

    public function create(): JsonResponse
    {
        $job = $this->service->create();

        return response()->json([
            'data' => $this->service->job((int) $job->id),
            'worker' => $this->launcher->wake(),
        ], 201);
    }

    public function current(): JsonResponse
    {
        return response()->json(['data' => $this->service->current()]);
    }

    public function wake(): JsonResponse
    {
        return response()->json(['data' => $this->launcher->wake()]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->history((int) $request->integer('per_page', 20))]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $this->service->heartbeat((string) $request->input('worker_name', 'gitashop-mass-upload-worker'));
        return response()->json(['status' => 'ok']);
    }

    public function claim(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->claim((string) $request->input('worker_name', 'gitashop-mass-upload-worker'))]);
    }

    public function download(Request $request, int $job, int $file)
    {
        $record = $this->service->filePath($job, $file, (string) $request->header('X-Gitashop-Mass-Upload-Claim', ''));
        $path = storage_path('app/'.$record->storage_path);
        abort_unless(is_file($path), 404, 'File Mass Update tidak ditemukan.');
        return response()->download($path, $record->filename, ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache']);
    }

    public function event(Request $request, int $job, int $file): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'shopee_status' => ['nullable', 'string', 'max:64'],
            'shopee_processed_count' => ['nullable', 'integer', 'min:0'],
            'error_code' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        return response()->json(['data' => $this->service->recordFileEvent($job, $file, (string) $request->header('X-Gitashop-Mass-Upload-Claim', ''), $data)]);
    }

    public function renew(Request $request, int $job): JsonResponse
    {
        $this->service->renewWorkerLease($job, (string) $request->header('X-Gitashop-Mass-Upload-Claim', ''));
        return response()->json(['status' => 'ok']);
    }

    public function reconcile(Request $request, int $job, int $file): JsonResponse
    {
        $data = $request->validate([
            'shopee_status' => ['required', 'string', 'in:Selesai'],
            'shopee_processed_count' => ['required', 'integer', 'min:0'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->service->reconcileVerifiedFile($job, $file, $data)]);
    }

    public function terminal(Request $request, int $job): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string'], 'message' => ['nullable', 'string', 'max:500']]);
        $this->service->terminal($job, $data['status'], $data['message'] ?? null);
        return response()->json(['data' => $this->service->job($job)]);
    }
}
