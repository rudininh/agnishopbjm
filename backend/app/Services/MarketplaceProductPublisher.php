<?php

namespace App\Services;

use App\Contracts\MarketplaceProductGateway;
use App\Models\MarketplacePublicationResult;
use App\Models\MarketplacePublicationRun;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class MarketplaceProductPublisher
{
    public function __construct(
        private readonly MarketplaceProductGateway $gateway = new FailClosedMarketplaceProductGateway(),
        private readonly ?MarketplaceAccountRegistry $registry = null,
        private readonly ?MarketplaceProductPreflightService $preflight = null,
        private readonly ?MarketplaceProductPayloadBuilder $payloadBuilder = null,
    ) {
    }

    public function publish(Product $product, array $accountContexts): array
    {
        $accountKeys = array_keys($accountContexts);
        $preflight = $this->preflight()->validate($product, $accountKeys);

        if (! $preflight['valid']) {
            throw new InvalidArgumentException(json_encode($preflight['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $run = MarketplacePublicationRun::query()->create([
            'product_uuid' => $product->uuid,
            'status' => 'running',
            'requested_accounts' => $accountKeys,
        ]);

        foreach ($accountContexts as $accountKey => $context) {
            $this->publishAccount($run, $product, (string) $accountKey, is_array($context) ? $context : []);
        }

        return $this->summarize($run->fresh(['results']));
    }

    public function retry(MarketplacePublicationRun $run): array
    {
        $run->loadMissing(['product.variants', 'results']);

        foreach ($run->results as $result) {
            if ($result->status === 'success') {
                continue;
            }

            $payload = $result->request_payload;
            if (! is_array($payload) || $payload === []) {
                continue;
            }

            $this->sendResult($result, $payload);
        }

        return $this->summarize($run->fresh(['results']));
    }

    private function publishAccount(MarketplacePublicationRun $run, Product $product, string $accountKey, array $context): void
    {
        $account = $this->registry()->account($accountKey);
        $channel = (string) $account['channel'];
        $payload = $this->payloadFor($channel, $product, $context);
        $idempotencyKey = hash('sha256', implode('|', [$run->id, $accountKey, $product->uuid]));

        $result = MarketplacePublicationResult::query()->updateOrCreate(
            ['run_id' => $run->id, 'account_key' => $accountKey],
            [
                'channel' => $channel,
                'status' => 'running',
                'idempotency_key' => $idempotencyKey,
                'request_payload' => $payload,
                'response_payload' => null,
                'error_code' => null,
                'error_message' => null,
            ]
        );

        $this->sendResult($result, $payload);
    }

    private function sendResult(MarketplacePublicationResult $result, array $payload): void
    {
        $result->forceFill([
            'status' => 'running',
            'error_code' => null,
            'error_message' => null,
        ])->save();

        try {
            $response = $this->gateway->publish($result->account_key, $result->channel, $payload, $result->idempotency_key);

            $result->forceFill([
                'status' => 'success',
                'remote_product_id' => Arr::get($response, 'remote_product_id'),
                'remote_variant_ids' => Arr::get($response, 'remote_variant_ids'),
                'response_payload' => Arr::get($response, 'response_payload', $response),
                'error_code' => null,
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $result->forceFill([
                'status' => 'failed',
                'error_code' => class_basename($exception),
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }

    private function summarize(MarketplacePublicationRun $run): array
    {
        $run->loadMissing('results');
        $statuses = $run->results->pluck('status')->all();
        $hasRunning = in_array('running', $statuses, true) || in_array('pending', $statuses, true);
        $hasSuccess = in_array('success', $statuses, true);
        $hasFailed = in_array('failed', $statuses, true);

        $status = match (true) {
            $hasRunning => 'running',
            $hasSuccess && $hasFailed => 'partial_success',
            $hasSuccess => 'success',
            $hasFailed => 'failed',
            default => 'pending',
        };

        DB::transaction(function () use ($run, $status, $hasRunning): void {
            $run->forceFill([
                'status' => $status,
                'completed_at' => $hasRunning ? null : now(),
            ])->save();
        });

        return [
            'run' => $run->fresh(['results']),
            'results' => $run->fresh(['results'])->results,
        ];
    }

    private function payloadFor(string $channel, Product $product, array $context): array
    {
        return match ($channel) {
            'shopee' => $this->payloadBuilder()->forShopee($product, $context),
            'tiktok' => $this->payloadBuilder()->forTiktok($product, $context),
            default => throw new InvalidArgumentException('Kanal marketplace tidak didukung.'),
        };
    }

    private function registry(): MarketplaceAccountRegistry
    {
        return $this->registry ?? app(MarketplaceAccountRegistry::class);
    }

    private function preflight(): MarketplaceProductPreflightService
    {
        return $this->preflight ?? app(MarketplaceProductPreflightService::class);
    }

    private function payloadBuilder(): MarketplaceProductPayloadBuilder
    {
        return $this->payloadBuilder ?? app(MarketplaceProductPayloadBuilder::class);
    }
}