<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_listings')) {
            Schema::create('marketplace_listings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('stock_master_id');
                $table->string('account_key', 100);
                $table->string('channel', 20);
                $table->string('remote_product_id', 100);
                $table->string('remote_variant_id', 100)->default('');
                $table->char('remote_identity_hash', 64);
                $table->string('seller_sku', 150)->nullable();
                $table->string('warehouse_id', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['stock_master_id', 'account_key']);
                $table->unique(['account_key', 'remote_identity_hash']);
                $table->index(['account_key', 'is_active']);
            });
        }

        $this->backfillLegacyMappings();
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
    }

    private function backfillLegacyMappings(): void
    {
        if (! Schema::hasTable('sku_mappings')) {
            return;
        }

        $candidates = [];
        foreach (DB::table('sku_mappings')->orderBy('id')->get() as $mapping) {
            $this->appendCandidate(
                $candidates,
                $mapping,
                'shopee-agnishopbjm',
                'shopee',
                $mapping->shopee_item_id,
                $mapping->shopee_model_id,
            );
            $this->appendCandidate(
                $candidates,
                $mapping,
                'tiktok-agnishopbjm',
                'tiktok',
                $mapping->tiktok_product_id,
                $mapping->tiktok_sku_id,
            );
        }

        $uniqueCandidates = $this->withoutAmbiguousLegacyIdentities($candidates);
        $rows = $this->withoutExistingIdentityConflicts($uniqueCandidates);

        if ($rows === []) {
            return;
        }

        DB::table('marketplace_listings')->upsert(
            $rows,
            ['stock_master_id', 'account_key'],
            [
                'channel',
                'remote_product_id',
                'remote_variant_id',
                'remote_identity_hash',
                'seller_sku',
                'warehouse_id',
                'is_active',
                'updated_at',
            ],
        );
    }

    private function appendCandidate(
        array &$candidates,
        object $mapping,
        string $accountKey,
        string $channel,
        mixed $productId,
        mixed $variantId,
    ): void {
        if (! is_string($productId) || trim($productId) === '' || ! is_string($variantId) || trim($variantId) === '') {
            return;
        }

        $candidates[] = [
            'stock_master_id' => $mapping->stock_master_id,
            'account_key' => $accountKey,
            'channel' => $channel,
            'remote_product_id' => $productId,
            'remote_variant_id' => $variantId,
            'remote_identity_hash' => hash('sha256', json_encode([$channel, $productId, $variantId], JSON_THROW_ON_ERROR)),
            'seller_sku' => $mapping->seller_sku,
            'warehouse_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function withoutAmbiguousLegacyIdentities(array $candidates): array
    {
        $counts = [];
        foreach ($candidates as $candidate) {
            foreach (['stock_master_id', 'remote_identity_hash'] as $column) {
                $key = $candidate['account_key']."\0".$candidate[$column];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return array_values(array_filter($candidates, function (array $candidate) use ($counts): bool {
            return $counts[$candidate['account_key']."\0".$candidate['stock_master_id']] === 1
                && $counts[$candidate['account_key']."\0".$candidate['remote_identity_hash']] === 1;
        }));
    }

    private function withoutExistingIdentityConflicts(array $candidates): array
    {
        $existing = DB::table('marketplace_listings')
            ->select('stock_master_id', 'account_key', 'remote_identity_hash')
            ->get();
        $remoteOwners = [];

        foreach ($existing as $listing) {
            $remoteOwners[$listing->account_key."\0".$listing->remote_identity_hash] = $listing->stock_master_id;
        }

        return array_values(array_filter($candidates, function (array $candidate) use ($remoteOwners): bool {
            $key = $candidate['account_key']."\0".$candidate['remote_identity_hash'];

            return ! isset($remoteOwners[$key]) || $remoteOwners[$key] === $candidate['stock_master_id'];
        }));
    }
};
