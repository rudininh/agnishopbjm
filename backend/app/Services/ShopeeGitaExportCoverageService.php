<?php

namespace App\Services;

use ArrayAccess;
use LogicException;
use Stringable;

final class ShopeeGitaExportCoverageService
{
    public const STATUSES = [
        'mass_update_ready',
        'new_product',
        'new_variant',
        'sku_changed',
        'blocked',
    ];

    public function analyze(iterable $sources, iterable $mappings, array $templateMetadata): array
    {
        $canonicalSources = $this->canonicalSources($sources);
        $canonicalMappings = $this->canonicalMappings($mappings);
        $template = $this->canonicalTemplate($templateMetadata);

        $sourceIdentities = $this->countBy($canonicalSources, fn (array $source): string => $this->sourceIdentity($source));
        $exactMappings = $this->groupBy($canonicalMappings, fn (array $mapping): string => $this->exactMappingKey($mapping));
        $nameMappings = $this->groupBy($canonicalMappings, fn (array $mapping): string => $this->nameMappingKey($mapping));
        $itemMappings = $this->groupBy($canonicalMappings, fn (array $mapping): string => $mapping['source_item_id']);
        $targetIdentities = $this->countBy($canonicalMappings, fn (array $mapping): string => $this->targetIdentity($mapping));

        $items = [];
        foreach ($canonicalSources as $source) {
            [$status, $reason, $target] = $this->classify(
                $source,
                $sourceIdentities,
                $exactMappings,
                $nameMappings,
                $itemMappings,
                $targetIdentities,
            );

            $items[] = [
                'status' => $status,
                'reason' => $reason,
                'source_item_id' => $source['item_id'],
                'source_model_id' => $source['model_id'],
                'product_name' => $source['product_name'],
                'variant_name' => $source['variant_name'],
                'source_seller_sku' => $source['seller_sku'],
                'target_item_id' => $target['target_item_id'] ?? '',
                'target_model_id' => $target['target_model_id'] ?? '',
                'target_seller_sku' => $target['source_seller_sku'] ?? '',
            ];
        }

        $targetClaims = $this->countBy(
            array_values(array_filter(
                $items,
                fn (array $item): bool => in_array($item['status'], ['mass_update_ready', 'sku_changed'], true),
            )),
            fn (array $item): string => $this->targetIdentity($item),
        );
        foreach ($items as &$item) {
            if (
                in_array($item['status'], ['mass_update_ready', 'sku_changed'], true)
                && ($targetClaims[$this->targetIdentity($item)] ?? 0) > 1
            ) {
                $item['status'] = 'blocked';
                $item['reason'] = 'duplicate_target_identity';
            }
        }
        unset($item);

        $readyTargets = [];
        foreach ($items as $item) {
            if ($item['status'] === 'mass_update_ready') {
                $readyTargets[$this->targetIdentity($item)] = [
                    'target_item_id' => $item['target_item_id'],
                    'target_model_id' => $item['target_model_id'],
                ];
            }
        }

        ksort($readyTargets, SORT_STRING);
        $summary = $this->summary($items);
        if (array_sum($summary['variants_by_status']) !== count($canonicalSources)) {
            throw new LogicException('Coverage classifier did not classify every source variant exactly once.');
        }

        return [
            'revision' => hash('sha256', json_encode([
                'sources' => $canonicalSources,
                'mappings' => $canonicalMappings,
                'template' => $template,
            ], JSON_THROW_ON_ERROR)),
            'generated_at' => gmdate('c'),
            'template' => $template,
            'summary' => $summary,
            'items' => $items,
            'ready_targets' => array_values($readyTargets),
        ];
    }

    public function assertRevision(array $snapshot, string $revision): void
    {
        abort_unless(
            trim($revision) !== '' && hash_equals($snapshot['revision'], trim($revision)),
            409,
            'Katalog sumber atau template Gitashop berubah. Muat ulang preflight sebelum download.'
        );
    }

    private function canonicalSources(iterable $sources): array
    {
        $canonical = [];
        foreach ($sources as $source) {
            $canonical[] = [
                'item_id' => $this->value($source, 'item_id'),
                'model_id' => $this->value($source, 'model_id'),
                'seller_sku' => $this->value($source, 'seller_sku'),
                'product_name' => $this->value($source, 'product_name'),
                'variant_name' => $this->value($source, 'variant_name'),
            ];
        }

        usort($canonical, fn (array $left, array $right): int => $this->compare($left, $right));

        return $canonical;
    }

    private function canonicalMappings(iterable $mappings): array
    {
        $canonical = [];
        foreach ($mappings as $mapping) {
            $canonical[] = [
                'source_item_id' => $this->value($mapping, 'source_item_id'),
                'source_seller_sku' => $this->value($mapping, 'source_seller_sku'),
                'target_item_id' => $this->value($mapping, 'target_item_id'),
                'target_model_id' => $this->value($mapping, 'target_model_id'),
                'target_product_name' => $this->value($mapping, 'target_product_name'),
                'target_variant_name' => $this->value($mapping, 'target_variant_name'),
            ];
        }

        usort($canonical, fn (array $left, array $right): int => $this->compare($left, $right));

        return $canonical;
    }

    private function canonicalTemplate(array $templateMetadata): array
    {
        return [
            'sales_last_modified_at' => $this->scalar($templateMetadata['sales_last_modified_at'] ?? ''),
            'sales_sha256' => $this->scalar($templateMetadata['sales_sha256'] ?? ''),
        ];
    }

    private function classify(
        array $source,
        array $sourceIdentities,
        array $exactMappings,
        array $nameMappings,
        array $itemMappings,
        array $targetIdentities,
    ): array {
        $emptyTarget = [];
        if (($sourceIdentities[$this->sourceIdentity($source)] ?? 0) > 1) {
            return ['blocked', 'duplicate_source_identity', $emptyTarget];
        }

        $exact = $exactMappings[$this->exactSourceKey($source)] ?? [];
        if (count($exact) > 1) {
            return ['blocked', 'duplicate_target_sku_mapping', $emptyTarget];
        }
        if (count($exact) === 1) {
            $target = $exact[0];
            if (($targetIdentities[$this->targetIdentity($target)] ?? 0) > 1) {
                return ['blocked', 'duplicate_target_identity', $target];
            }

            return ['mass_update_ready', 'matched_target_sku', $target];
        }

        if (! isset($itemMappings[$source['item_id']])) {
            return ['new_product', 'missing_target_product', $emptyTarget];
        }

        $named = $nameMappings[$this->nameSourceKey($source)] ?? [];
        if (count($named) > 1) {
            return ['blocked', 'ambiguous_target_variant_name', $emptyTarget];
        }
        if (count($named) === 1) {
            $target = $named[0];
            if (($targetIdentities[$this->targetIdentity($target)] ?? 0) > 1) {
                return ['blocked', 'duplicate_target_identity', $target];
            }

            return ['sku_changed', 'target_variant_sku_changed', $target];
        }

        return ['new_variant', 'missing_target_variant', $emptyTarget];
    }

    private function summary(array $items): array
    {
        $variantsByStatus = array_fill_keys(self::STATUSES, 0);
        $productsByStatus = array_fill_keys(self::STATUSES, []);
        $sourceProducts = [];
        $exceptionProducts = [];
        $readyProducts = [];
        $exceptionVariants = 0;
        $readyVariants = 0;

        foreach ($items as $item) {
            $status = $item['status'];
            $sourceProducts[$item['source_item_id']] = true;
            $variantsByStatus[$status]++;
            $productsByStatus[$status][$item['source_item_id']] = true;
            if ($status === 'mass_update_ready') {
                $readyVariants++;
                $readyProducts[$item['source_item_id']] = true;
            } else {
                $exceptionVariants++;
                $exceptionProducts[$item['source_item_id']] = true;
            }
        }

        foreach ($productsByStatus as $status => $products) {
            $productsByStatus[$status] = count($products);
        }

        return [
            'source_products' => count($sourceProducts),
            'source_variants' => count($items),
            'ready_products' => count($readyProducts),
            'ready_variants' => $readyVariants,
            'exception_products' => count($exceptionProducts),
            'exception_variants' => $exceptionVariants,
            'products_by_status' => $productsByStatus,
            'variants_by_status' => $variantsByStatus,
        ];
    }

    private function value(mixed $row, string $key): string
    {
        if (is_array($row)) {
            return $this->scalar($row[$key] ?? '');
        }
        if ($row instanceof ArrayAccess) {
            return $this->scalar($row[$key] ?? '');
        }
        if (is_object($row)) {
            return $this->scalar($row->{$key} ?? '');
        }

        return '';
    }

    private function scalar(mixed $value): string
    {
        return trim(is_scalar($value) || $value instanceof Stringable ? (string) $value : '');
    }

    private function compare(array $left, array $right): int
    {
        foreach ($left as $key => $value) {
            $comparison = strcmp($value, $right[$key]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private function countBy(array $rows, callable $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $identity = $key($row);
            $counts[$identity] = ($counts[$identity] ?? 0) + 1;
        }

        return $counts;
    }

    private function groupBy(array $rows, callable $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$key($row)][] = $row;
        }

        return $groups;
    }

    private function sourceIdentity(array $source): string
    {
        return $this->tuple($source['item_id'], $source['model_id']);
    }

    private function exactSourceKey(array $source): string
    {
        return $this->tuple($this->lower($source['item_id']), $this->lower($source['seller_sku']));
    }

    private function exactMappingKey(array $mapping): string
    {
        return $this->tuple($this->lower($mapping['source_item_id']), $this->lower($mapping['source_seller_sku']));
    }

    private function nameSourceKey(array $source): string
    {
        return $this->tuple($source['item_id'], $this->normalizedName($source['variant_name']));
    }

    private function nameMappingKey(array $mapping): string
    {
        return $this->tuple($mapping['source_item_id'], $this->normalizedName($mapping['target_variant_name']));
    }

    private function targetIdentity(array $mapping): string
    {
        return $this->tuple($mapping['target_item_id'], $mapping['target_model_id']);
    }

    private function tuple(string ...$values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    private function normalizedName(string $name): string
    {
        if (! function_exists('normalizer_normalize')) {
            throw new LogicException('Unicode normalizer is required for Gitashop coverage classification.');
        }
        $normalized = normalizer_normalize($name);
        if ($normalized === false) {
            throw new LogicException('Gitashop coverage variant name is not valid Unicode.');
        }

        return $this->lower(preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized));
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
