<?php

declare(strict_types=1);

use Shopper\Core\Contracts\StockReserver;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Import\ProductRow;
use Shopper\Core\Import\ProductRowImporter;
use Shopper\Core\Import\VariantRow;
use Shopper\Core\Listeners\Orders\RestoreOrderStockListener;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;

beforeEach(function (): void {
    $this->inventory = Inventory::factory()->create(['is_default' => true, 'priority' => 0]);
    $this->product = Product::factory()->standard()->create();
    $this->product->mutateStock($this->inventory->id, 50, event: 'Initial');

    $this->runner = recordingStockRunner();
});

describe('StockTransactionRunnerTest', function (): void {
    it('runs a ledger movement through the runner', function (): void {
        $this->product->mutateStock($this->inventory->id, 5, event: 'Manual');

        expect($this->runner->calls)->toContain('retry:createStockMutation');
    });

    it('runs the product import through the runner', function (): void {
        resolve(ProductRowImporter::class)->import(new ProductRow(
            handle: 'runner-import',
            name: 'Runner import',
            variants: [new VariantRow(quantity: 7)],
        ));

        expect($this->runner->calls[0])->toBe('retry:import')
            ->and(Product::query()->where('slug', 'runner-import')->firstOrFail()->getStock())->toBe(7);
    });

    it('runs the stock reservation through the runner', function (): void {
        $order = Order::factory()->create();

        resolve(StockReserver::class)->reserve($this->product, 3, $order);

        expect($this->runner->calls[0])->toBe('retry:reserve')
            ->and($this->product->getStock())->toBe(47);
    });

    it('runs the stock restoration through the runner', function (): void {
        $order = Order::factory()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_type' => $this->product->getMorphClass(),
            'product_id' => $this->product->id,
            'quantity' => 3,
        ]);
        resolve(StockReserver::class)->reserve($this->product, 3, $order);
        $this->runner->calls = [];

        resolve(RestoreOrderStockListener::class)->handle(new OrderCancelled($order));

        expect($this->runner->calls[0])->toBe('retry:handle')
            ->and($this->product->getStock())->toBe(50);
    });

    it('runs the product delete and force delete through run, never retry', function (): void {
        $this->product->delete();

        expect($this->runner->calls[0])->toBe('run:delete');

        $this->runner->calls = [];
        $this->product->forceDelete();

        expect($this->runner->calls[0])->toBe('run:forceDelete')
            ->and(Product::withTrashed()->find($this->product->id))->toBeNull();
    });

    it('runs the variant delete through run', function (): void {
        $variant = ProductVariant::factory()->create(['product_id' => $this->product->id]);

        $variant->delete();

        expect($this->runner->calls[0])->toBe('run:delete');
    });
});
