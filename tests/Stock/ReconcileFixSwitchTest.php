<?php

declare(strict_types=1);

use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\StockLevel;

beforeEach(function (): void {
    $inventory = Inventory::factory()->create(['is_default' => true]);
    $this->product = Product::factory()->standard()->create();
    $this->product->mutateStock($inventory->id, 10, event: 'Initial');

    StockLevel::query()->update(['quantity' => 3]);
});

describe('ReconcileFixSwitchTest', function (): void {
    it('rewrites the drifted snapshot with --fix by default', function (): void {
        $this->artisan('shopper:stock:reconcile', ['--fix' => true])->assertSuccessful();

        expect(StockLevel::query()->value('quantity'))->toBe(10);
    });

    it('refuses --fix when the switch is off and keeps the snapshot', function (): void {
        config(['shopper.stock.reconcile_fix' => false]);

        $this->artisan('shopper:stock:reconcile', ['--fix' => true])->assertFailed();

        expect(StockLevel::query()->value('quantity'))->toBe(3);
    });

    it('still reports the drift when the switch is off', function (): void {
        config(['shopper.stock.reconcile_fix' => false]);

        $this->artisan('shopper:stock:reconcile')->assertFailed();
    });
});
