<?php

declare(strict_types=1);

use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Models\StockLevel;
use Shopper\Core\Stock\StockedModelDeletion;

beforeEach(function (): void {
    $this->first = Inventory::factory()->create(['is_default' => true, 'priority' => 0]);
    $this->second = Inventory::factory()->create(['is_default' => false, 'priority' => 1]);

    $this->product = Product::factory()->create();
    $this->product->mutateStock($this->first->id, 4, event: 'Initial');

    $this->variants = ProductVariant::factory()->count(2)->create(['product_id' => $this->product->id]);
    $this->variants->each(function (ProductVariant $variant): void {
        $variant->mutateStock($this->first->id, 10, event: 'Initial');
        $variant->mutateStock($this->second->id, 20, event: 'Initial');
    });
});

describe('ProductDeleteZeroesVariantsTest', function (): void {
    it('zeroes the product and every variant on a soft delete and keeps the ledger', function (): void {
        resolve(StockedModelDeletion::class)->deleteProduct($this->product->id);

        expect(Product::withTrashed()->findOrFail($this->product->id)->trashed())->toBeTrue()
            ->and($this->product->getStock())->toBe(0)
            ->and($this->product->inventoryHistories()->count())->toBe(2);

        $this->variants->each(function (ProductVariant $variant): void {
            expect(ProductVariant::query()->find($variant->id))->not->toBeNull()
                ->and($variant->stockInventory($this->first->id))->toBe(0)
                ->and($variant->stockInventory($this->second->id))->toBe(0)
                ->and($variant->inventoryHistories()->count())->toBe(4)
                ->and($variant->inventoryHistories()->where('quantity', -20)->count())->toBe(1);
        });
    });

    it('deletes the variants through their model on a force delete and leaves no stock rows', function (): void {
        resolve(StockedModelDeletion::class)->forceDeleteProduct($this->product->id);

        expect(Product::withTrashed()->find($this->product->id))->toBeNull()
            ->and(ProductVariant::query()->where('product_id', $this->product->id)->count())->toBe(0)
            ->and(StockLevel::query()->count())->toBe(0)
            ->and(InventoryHistory::query()->count())->toBe(0);
    });

    it('force deletes a product that was soft deleted first', function (): void {
        $deletion = resolve(StockedModelDeletion::class);

        $deletion->deleteProduct($this->product->id);
        $deletion->forceDeleteProduct($this->product->id);

        expect(Product::withTrashed()->find($this->product->id))->toBeNull()
            ->and(ProductVariant::query()->count())->toBe(0)
            ->and(StockLevel::query()->count())->toBe(0);
    });
});
