<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Shopper\Core\Contracts\StockReserver;
use Shopper\Core\Contracts\StockTransactionRunner;
use Shopper\Core\Import\ProductRow;
use Shopper\Core\Import\ProductRowImporter;
use Shopper\Core\Import\VariantRow;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Models\StockLevel;
use Shopper\Core\Stock\StockedModelDeletion;
use Shopper\Core\Tests\Stubs\Conflict;

beforeEach(function (): void {
    $this->inventory = Inventory::factory()->create(['is_default' => true, 'priority' => 0]);
    $this->second = Inventory::factory()->create(['is_default' => false, 'priority' => 1]);
    $this->runner = recordingStockRunner();
});

/**
 * Throw the snapshot conflict once, the first time the event fires.
 */
function conflictOnce(string $event): void
{
    $thrown = false;

    Event::listen($event, function () use (&$thrown): void {
        if (! $thrown) {
            $thrown = true;

            throw Conflict::snapshot();
        }
    });
}

describe('StockRetryBoundaryTest', function (): void {
    it('refuses a command bound to an object before the first attempt', function (): void {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        expect(fn () => resolve(StockTransactionRunner::class)->retry(fn (): int => 1))
            ->toThrow(LogicException::class);

        expect($queries)->toBe(0)
            ->and($this->runner->attempted)->toBe(0);
    });

    it('refuses a command capturing a model or a collection before the first attempt', function (): void {
        $product = Product::factory()->standard()->create();
        $collection = collect([1, 2]);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        expect(fn () => resolve(StockTransactionRunner::class)->retry(static fn (): mixed => $product->getKey()))
            ->toThrow(LogicException::class)
            ->and(fn () => resolve(StockTransactionRunner::class)->retry(static fn (): int => $collection->count()))
            ->toThrow(LogicException::class)
            ->and($queries)->toBe(0)
            ->and($this->runner->attempted)->toBe(0);
    });

    it('accepts a static command over scalars and arrays of scalars', function (): void {
        $id = 7;
        $values = ['a' => 1, 'b' => [true, null, 'x']];

        $result = resolve(StockTransactionRunner::class)->retry(static fn (): array => [$id, $values]);

        expect($result)->toBe([7, ['a' => 1, 'b' => [true, null, 'x']]]);
    });

    it('finishes a force delete conflicted in deleted on the second attempt', function (): void {
        $product = Product::factory()->create();
        $product->mutateStock($this->inventory->id, 4, event: 'Initial');
        $variants = ProductVariant::factory()->count(2)->create(['product_id' => $product->id]);
        $variants->each(fn (ProductVariant $variant) => $variant->mutateStock($this->inventory->id, 10, event: 'Initial'));
        conflictOnce('eloquent.deleted: '.Product::class);
        $this->runner->attempted = 0;

        resolve(StockedModelDeletion::class)->forceDeleteProduct($product->id);

        expect($this->runner->attempted)->toBe(2)
            ->and(Product::withTrashed()->find($product->id))->toBeNull()
            ->and(ProductVariant::query()->count())->toBe(0)
            ->and(StockLevel::query()->count())->toBe(0)
            ->and(InventoryHistory::query()->count())->toBe(0);
    });

    it('finishes a soft delete conflicted in deleted with every movement written once', function (): void {
        $product = Product::factory()->create();
        $variants = ProductVariant::factory()->count(2)->create(['product_id' => $product->id]);
        $variants->each(function (ProductVariant $variant): void {
            $variant->mutateStock($this->inventory->id, 10, event: 'Initial');
            $variant->mutateStock($this->second->id, 20, event: 'Initial');
        });
        conflictOnce('eloquent.deleted: '.Product::class);
        $this->runner->attempted = 0;

        resolve(StockedModelDeletion::class)->deleteProduct($product->id);

        expect($this->runner->attempted)->toBe(2)
            ->and(Product::withTrashed()->findOrFail($product->id)->trashed())->toBeTrue();

        $variants->each(function (ProductVariant $variant): void {
            expect($variant->inventoryHistories()->where('quantity', -10)->count())->toBe(1)
                ->and($variant->inventoryHistories()->where('quantity', -20)->count())->toBe(1)
                ->and($variant->getStock())->toBe(0);
        });
    });

    it('lets a direct model delete fail loudly on a conflict, without a retry', function (): void {
        $product = Product::factory()->standard()->create();
        conflictOnce('eloquent.deleted: '.Product::class);
        $this->runner->attempted = 0;

        expect(fn () => $product->delete())->toThrow(Illuminate\Database\QueryException::class)
            ->and(Product::query()->find($product->id))->not->toBeNull()
            ->and($this->runner->attempted)->toBe(0);
    });

    it('finishes an import conflicted after its first movement as if nothing failed', function (): void {
        conflictOnce('eloquent.created: '.InventoryHistory::class);
        $row = new ProductRow(
            handle: 'retried-import',
            name: 'Retried import',
            optionNames: ['Size'],
            variants: [new VariantRow(options: ['Size' => 'S'], sku: 'RI-S', quantity: 3), new VariantRow(options: ['Size' => 'M'], sku: 'RI-M', quantity: 5)],
        );
        $before = clone $row;

        resolve(ProductRowImporter::class)->import($row);

        $product = Product::query()->where('slug', 'retried-import')->firstOrFail();

        expect($row)->toEqual($before)
            ->and(Product::query()->where('slug', 'retried-import')->count())->toBe(1)
            ->and($product->variants()->count())->toBe(2)
            ->and(InventoryHistory::query()->count())->toBe(2)
            ->and((int) StockLevel::query()->sum('quantity'))->toBe(8);
    });

    it('finishes a reservation conflicted after its movement with one reservation', function (): void {
        $product = Product::factory()->standard()->create();
        $product->mutateStock($this->inventory->id, 10, event: 'Initial');
        $order = Order::factory()->create();
        conflictOnce('eloquent.created: '.InventoryHistory::class);

        resolve(StockReserver::class)->reserve($product, 4, $order);

        expect($product->getStock())->toBe(6)
            ->and($product->inventoryHistories()->where('quantity', -4)->count())->toBe(1);
    });
});
