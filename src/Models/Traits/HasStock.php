<?php

declare(strict_types=1);

namespace Shopper\Core\Models\Traits;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Shopper\Core\Contracts\StockTransactionRunner;
use Shopper\Core\Exceptions\LazyStockLoadingException;
use Shopper\Core\Models\InventoryHistory;
use Shopper\Core\Models\StockLevel;

trait HasStock
{
    protected static bool $preventLazyStockLoading = false;

    public static function preventLazyStockLoading(bool $prevent = true): void
    {
        static::$preventLazyStockLoading = $prevent;
    }

    /**
     * Batch-load current stock for a collection of stockable models in a single query,
     * avoiding N+1 when iterating over products or variants.
     *
     * @param  Collection<int, Model>  $models
     */
    public static function loadCurrentStock(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $first = $models->first();

        $stocks = StockLevel::query()
            ->selectRaw('stockable_id, SUM(quantity) as aggregate')
            ->where('stockable_type', $first->getMorphClass())
            ->whereIn('stockable_id', $models->pluck($first->getKeyName()))
            ->groupBy('stockable_id')
            ->pluck('aggregate', 'stockable_id');

        $models->each(function (Model $model) use ($stocks): void {
            $model->setAttribute('real_stock', (int) ($stocks[$model->getKey()] ?? 0));
        });
    }

    /**
     * Batch-load stock for a specific inventory location.
     *
     * @param  Collection<int, Model>  $models
     */
    public static function loadStockForInventory(Collection $models, int $inventoryId): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $first = $models->first();

        $stocks = StockLevel::query()
            ->where('stockable_type', $first->getMorphClass())
            ->whereIn('stockable_id', $models->pluck($first->getKeyName()))
            ->where('inventory_id', $inventoryId)
            ->pluck('quantity', 'stockable_id');

        $models->each(function (Model $model) use ($stocks): void {
            $model->setAttribute('real_stock', (int) ($stocks[$model->getKey()] ?? 0));
        });
    }

    public function inStock(int $quantity = 1): bool
    {
        return $this->stock > 0 && $this->stock >= $quantity;
    }

    /**
     * @return Closure(QueryBuilder): QueryBuilder
     */
    public function hasStockQuery(): Closure
    {
        return fn (QueryBuilder $query): QueryBuilder => $query
            ->selectRaw('SUM(quantity)')
            ->from((new StockLevel)->getTable())
            ->where('stockable_type', $this->getMorphClass())
            ->whereColumn('stockable_id', $this->qualifyColumn('id'))
            ->havingRaw('SUM(quantity) > 0');
    }

    /**
     * The current stock is a single indexed read on the snapshot; the ledger
     * is only replayed for point-in-time queries, so the cost of a stock read
     * stays flat no matter how much movement history a product accumulates.
     */
    public function getStock(string|DateTimeInterface|null $date = null): int
    {
        if ($date === null) {
            return (int) $this->stockLevels()->sum('quantity');
        }

        if (! $date instanceof DateTimeInterface) {
            $date = Carbon::create($date);
        }

        return (int) $this->inventoryHistories()
            ->where('created_at', '<=', $date->format('Y-m-d H:i:s'))
            ->sum('quantity');
    }

    public function stockInventory(int $inventoryId, ?string $date = null): int
    {
        if ($date === null) {
            return (int) $this->stockLevels()
                ->where('inventory_id', $inventoryId)
                ->value('quantity');
        }

        return (int) $this->inventoryHistories()
            ->where('created_at', '<=', Carbon::create($date)->format('Y-m-d H:i:s'))
            ->where('inventory_id', $inventoryId)
            ->sum('quantity');
    }

    public function mutateStock(
        int $inventoryId,
        int $quantity = 1,
        int $oldQuantity = 0,
        ?string $event = null,
        ?string $description = null,
        ?int $userId = null,
        ?Model $reference = null,
    ): InventoryHistory {
        return $this->createStockMutation($quantity, $inventoryId, $oldQuantity, $event, $description, $userId, $reference);
    }

    public function decreaseStock(
        int $inventoryId,
        int $quantity = 1,
        int $oldQuantity = 0,
        ?string $event = null,
        ?string $description = null,
        ?int $userId = null,
        ?Model $reference = null,
    ): InventoryHistory {
        return $this->createStockMutation(-1 * abs($quantity), $inventoryId, $oldQuantity, $event, $description, $userId, $reference);
    }

    /**
     * The final clean-up of a stockable that is going away: every non-zero
     * inventory is first zeroed by a ledger movement, so the disappearance
     * is visible to whoever follows the ledger, then the ledger and the
     * snapshot rows are erased.
     */
    public function clearStock(
        ?int $inventoryId = null,
        ?int $newQuantity = null,
        int $oldQuantity = 0,
        ?string $event = null,
        ?string $description = null,
        ?int $userId = null,
        ?Model $reference = null,
    ): bool {
        return resolve(StockTransactionRunner::class)->run(function () use ($inventoryId, $newQuantity, $oldQuantity, $event, $description, $userId, $reference): bool {
            $this->zeroStockLevels(__('shopper-core::status.stock.cleared'));

            $this->inventoryHistories()->delete();
            $this->stockLevels()->delete();

            if ($inventoryId && $newQuantity) {
                $this->createStockMutation($newQuantity, $inventoryId, $oldQuantity, $event, $description, $userId, $reference);
            }

            return true;
        });
    }

    /**
     * Set the absolute quantity of one inventory. The current quantity of
     * that inventory is read under the row lock inside the transaction, so
     * the delta is computed against the latest committed value.
     */
    public function setStock(
        int $newQuantity,
        int $inventoryId,
        int $oldQuantity = 0,
        ?string $event = null,
        ?string $description = null,
        ?int $userId = null,
        ?Model $reference = null,
    ): ?InventoryHistory {
        $stockable = static::class;
        $key = $this->getKey();
        $referenceType = $reference === null ? null : $reference::class;
        $referenceKey = $reference?->getKey();

        return resolve(StockTransactionRunner::class)->retry(static function () use ($stockable, $key, $newQuantity, $inventoryId, $oldQuantity, $event, $description, $userId, $referenceType, $referenceKey): ?InventoryHistory {
            $model = static::loadStockable($stockable, $key);
            $deltaStock = $newQuantity - $model->lockStockLevel($inventoryId);

            if (! $deltaStock) {
                return null;
            }

            return $model->createStockMutation($deltaStock, $inventoryId, $oldQuantity, $event, $description, $userId, static::loadReference($referenceType, $referenceKey));
        });
    }

    /**
     * Lock the snapshot row of one inventory as the first touch and return
     * its quantity. A missing row is created at zero first, so the lock is
     * always taken on a row; the read sees the latest committed version.
     */
    public function lockStockLevel(int $inventoryId): int
    {
        $constraint = [
            'stockable_type' => $this->getMorphClass(),
            'stockable_id' => $this->getKey(),
            'inventory_id' => $inventoryId,
        ];

        $quantity = StockLevel::query()->where($constraint)->lockForUpdate()->value('quantity');

        if ($quantity === null) {
            StockLevel::query()->insertOrIgnore([...$constraint, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);

            $quantity = StockLevel::query()->where($constraint)->lockForUpdate()->value('quantity');
        }

        return (int) $quantity;
    }

    public function createStockMutation(
        int $quantity,
        int $inventoryId,
        int $oldQuantity = 0,
        ?string $event = null,
        ?string $description = null,
        ?int $userId = null,
        ?Model $reference = null,
    ): InventoryHistory {
        $createArguments = [
            'quantity' => $quantity,
            'old_quantity' => $oldQuantity,
            'description' => $description,
            'event' => $event,
            'inventory_id' => $inventoryId,
            'user_id' => $userId,
        ];

        if ($reference) {
            $createArguments['reference_type'] = $reference->getMorphClass();
            $createArguments['reference_id'] = $reference->getKey();
        }

        $stockable = static::class;
        $key = $this->getKey();

        return resolve(StockTransactionRunner::class)->retry(static function () use ($stockable, $key, $createArguments, $inventoryId, $quantity): InventoryHistory {
            $model = static::loadStockable($stockable, $key);
            $history = $model->inventoryHistories()->create($createArguments);

            $model->applyToStockLevel($inventoryId, $quantity);

            return $history;
        });
    }

    /**
     * Zero every non-zero inventory of this stockable with one movement each,
     * the quantity read under the row lock.
     */
    public function zeroStockLevels(?string $event = null): void
    {
        $inventoryIds = $this->stockLevels()->where('quantity', '!=', 0)->pluck('inventory_id');

        foreach ($inventoryIds as $inventoryId) {
            $quantity = $this->lockStockLevel((int) $inventoryId);

            if ($quantity !== 0) {
                $this->createStockMutation(-1 * $quantity, (int) $inventoryId, $quantity, $event);
            }
        }
    }

    /**
     * @return MorphMany<InventoryHistory, $this>
     */
    public function inventoryHistories(): MorphMany
    {
        return $this->morphMany(InventoryHistory::class, 'stockable');
    }

    /**
     * @return MorphMany<StockLevel, $this>
     */
    public function stockLevels(): MorphMany
    {
        return $this->morphMany(StockLevel::class, 'stockable');
    }

    /**
     * A repeatable command loads its stockable by key in every attempt,
     * soft deleted rows included: nothing it uses survives a rollback.
     *
     * @param  class-string<Model>  $stockable
     */
    protected static function loadStockable(string $stockable, int|string $key): Model
    {
        /** @var Model $model */
        $model = (new $stockable)->newQueryWithoutScopes()->findOrFail($key);

        return $model;
    }

    /**
     * @param  class-string<Model>|null  $referenceType
     */
    protected static function loadReference(?string $referenceType, int|string|null $key): ?Model
    {
        if ($referenceType === null || $key === null) {
            return null;
        }

        return (new $referenceType)->newQueryWithoutScopes()->findOrFail($key);
    }

    /**
     * The snapshot moves with every ledger write, atomically: an increment on
     * the existing row, or an insert raced against the unique index with one
     * increment retry when another writer created the row first.
     */
    protected function applyToStockLevel(int $inventoryId, int $delta): void
    {
        $constraint = [
            'stockable_type' => $this->getMorphClass(),
            'stockable_id' => $this->getKey(),
            'inventory_id' => $inventoryId,
        ];

        $updated = StockLevel::query()->where($constraint)->increment('quantity', $delta);

        if ($updated > 0) {
            return;
        }

        try {
            StockLevel::query()->create([...$constraint, 'quantity' => $delta]);
        } catch (QueryException) {
            StockLevel::query()->where($constraint)->increment('quantity', $delta);
        }
    }

    protected function stock(): Attribute
    {
        return Attribute::make(
            get: function (): int {
                if (array_key_exists('real_stock', $this->attributes)) {
                    return (int) $this->attributes['real_stock'];
                }

                if (static::$preventLazyStockLoading) {
                    throw new LazyStockLoadingException(static::class);
                }

                return $this->getStock();
            },
        );
    }
}
