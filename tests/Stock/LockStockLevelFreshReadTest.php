<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\StockLevel;

beforeEach(function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('The snapshot read needs InnoDB: run with DB_CONNECTION=mysql.');
    }

    config(['database.connections.second' => config('database.connections.'.DB::getDefaultConnection())]);

    $this->inventory = Inventory::factory()->create(['is_default' => true]);
    $this->product = Product::factory()->standard()->create();
    $this->product->mutateStock($this->inventory->id, 10, event: 'Initial');
});

afterEach(function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        return;
    }

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    DB::statement('SET SESSION innodb_snapshot_isolation = DEFAULT');
    DB::purge('second');
});

/**
 * Open the transaction snapshot with a plain read, then let a second
 * session move the quantity from 10 to 20 and commit.
 */
function changeQuantityBehindTheSnapshot(Product $product, int $inventoryId): void
{
    $row = [
        'stockable_type' => $product->getMorphClass(),
        'stockable_id' => $product->getKey(),
        'inventory_id' => $inventoryId,
    ];

    DB::beginTransaction();

    expect(StockLevel::query()->where($row)->value('quantity'))->toBe(10);

    DB::connection('second')->table((new StockLevel)->getTable())->where($row)->update(['quantity' => 20]);
}

describe('LockStockLevelFreshReadTest', function (): void {
    it('reads the latest committed quantity when snapshot isolation is off', function (): void {
        DB::statement('SET SESSION innodb_snapshot_isolation = OFF');

        changeQuantityBehindTheSnapshot($this->product, $this->inventory->id);

        expect($this->product->lockStockLevel($this->inventory->id))->toBe(20);
    });

    it('raises 1020 when snapshot isolation is on', function (): void {
        DB::statement('SET SESSION innodb_snapshot_isolation = ON');

        changeQuantityBehindTheSnapshot($this->product, $this->inventory->id);

        try {
            $this->product->lockStockLevel($this->inventory->id);
            $this->fail('lockStockLevel read a row changed behind the snapshot.');
        } catch (QueryException $exception) {
            expect((int) $exception->errorInfo[1])->toBe(1020);
        }
    });
});
