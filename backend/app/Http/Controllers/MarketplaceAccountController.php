<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMarketplaceAccountRequest;
use App\Http\Requests\UpdateMarketplaceAccountRequest;
use App\Models\MarketplaceAccount;
use App\Services\MarketplaceAccountRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use RuntimeException;

class MarketplaceAccountController extends Controller
{
    public function __construct(private readonly MarketplaceAccountRegistry $registry)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'data' => ['accounts' => $this->registry->publicAccounts()],
        ]);
    }

    public function store(StoreMarketplaceAccountRequest $request): JsonResponse
    {
        $account = MarketplaceAccount::create($request->validated());

        return response()->json([
            'status' => 'ok',
            'data' => $this->publicAccount($account->account_key),
        ], 201);
    }

    public function update(UpdateMarketplaceAccountRequest $request, string $accountKey): JsonResponse
    {
        $account = MarketplaceAccount::where('account_key', $accountKey)->firstOrFail();
        $values = $request->validated();
        $account->fill(Arr::only($values, ['name', 'channel', 'enabled', 'settings']));
        if (array_key_exists('credentials', $values)) {
            $account->credentials = $values['credentials'];
        }
        $account->save();

        return response()->json([
            'status' => 'ok',
            'data' => $this->publicAccount($accountKey),
        ]);
    }

    public function test(string $accountKey): JsonResponse
    {
        try {
            $account = $this->registry->account($accountKey);
            $channel = (string) ($account['channel'] ?? '');
            if ($channel === 'shopee') {
                $this->registry->shopeeContext($accountKey);
            } elseif ($channel === 'tiktok') {
                $this->registry->tiktokContext($accountKey);
            } else {
                throw new InvalidArgumentException('Platform marketplace belum didukung.');
            }

            return response()->json([
                'status' => 'ok',
                'data' => ['account_key' => $accountKey, 'configured' => true],
            ]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    private function publicAccount(string $accountKey): array
    {
        $account = collect($this->registry->publicAccounts())->firstWhere('key', $accountKey);
        if (! is_array($account)) {
            abort(404, 'Akun marketplace tidak ditemukan.');
        }

        return $account;
    }
}