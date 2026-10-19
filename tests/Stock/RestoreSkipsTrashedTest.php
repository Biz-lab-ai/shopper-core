<?php

declare(strict_types=1);

use Shopper\Core\Contracts\StockReserver;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Listeners\Orders\RestoreOrderStockListener;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\ProductVariant;
use Shopper\Core\Stock\StockedModelDeletion;

beforeEach(function (): void {
    $this->inventory = Inventory::factory()->create(['is_default' => true, 'priority' => 0]);
    $this->order = Order::factory()->create();
});

function reserveOnOrder(Order $order, Product|ProductVariant $stockable, int $quantity): void
{
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_type' => $stockable->getMorphClass(),
        'product_id' => $stockable->id,
        'quantity' => $quantity,
    ]);

    resolve(StockReserver::class)->reserve($stockable, $quantity, $order);
}

describe('RestoreSkipsTrashedTest', function (): void {
    it('loads the soft deleted product of an item and releases its reservation without a movement', function (): void {
        $product = Product::factory()->standard()->create();
        $product->mutateStock($this->inventory->id, 10, event: 'Initial');
        reserveOnOrder($this->order, $product, 3);

        resolve(StockedModelDeletion::class)->deleteProduct($product->id);
        $movements = InventoryHistory::query()->count();

        resolve(RestoreOrderStockListener::class)->handle(new OrderCancelled($this->order->fresh()));

        expect(InventoryHistory::query()->count())->toBe($movements)
            ->and($product->getStock())->toBe(0)
            ->and(InventoryHistory::query()->where('quantity', -3)->whereNull('released_at')->count())->toBe(0);
    });

    it('releases the reservation of a variant whose parent is soft deleted without a movement', function (): void {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->mutateStock($this->inventory->id, 10, event: 'Initial');
        reserveOnOrder($this->order, $variant, 2);

        resolve(StockedModelDeletion::class)->deleteProduct($product->id);
        $movements = InventoryHistory::query()->count();

        resolve(RestoreOrderStockListener::class)->handle(new OrderCancelled($this->order->fresh()));

        expect(InventoryHistory::query()->count())->toBe($movements)
            ->and($variant->getStock())->toBe(0)
            ->and(InventoryHistory::query()->where('quantity', -2)->whereNull('released_at')->count())->toBe(0);
    });

    it('still restores the reservation of a live product exactly once', function (): void {
        $product = Product::factory()->standard()->create();
        $product->mutateStock($this->inventory->id, 10, event: 'Initial');
        reserveOnOrder($this->order, $product, 3);

        $listener = resolve(RestoreOrderStockListener::class);
        $listener->handle(new OrderCancelled($this->order->fresh()));
        $listener->handle(new OrderCancelled($this->order->fresh()));

        expect($product->getStock())->toBe(10);
    });
});
