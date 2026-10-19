<?php

declare(strict_types=1);

use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Product;

describe('SetStockPerInventoryTest', function (): void {
    it('sets the quantity of one inventory and leaves the others untouched', function (): void {
        $first = Inventory::factory()->create(['is_default' => true, 'priority' => 0]);
        $second = Inventory::factory()->create(['is_default' => false, 'priority' => 1]);
        $product = Product::factory()->standard()->create();

        $product->mutateStock($first->id, 10, event: 'Initial');
        $product->mutateStock($second->id, 20, event: 'Initial');

        $product->setStock(15, $first->id);

        expect($product->stockInventory($first->id))->toBe(15)
            ->and($product->stockInventory($second->id))->toBe(20)
            ->and($product->inventoryHistories()->where('inventory_id', $first->id)->latest('id')->value('quantity'))->toBe(5);
    });
});
