<?php

declare(strict_types=1);

namespace Shopper\Core\Stock;

use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Contracts\InventoryResolver;
use Shopper\Core\Contracts\StockAllocator;
use Shopper\Core\Contracts\StockReserver;
use Shopper\Core\Contracts\StockTransactionRunner;
use Shopper\Core\Models\Contracts\Stockable;

final readonly class LockingStockReserver implements StockReserver
{
    public function __construct(
        private StockAllocator $allocator,
        private InventoryResolver $resolver,
    ) {}

    /**
     * @param  Model&Stockable  $product
     */
    public function reserve(Stockable $product, int $quantity, ?Model $reference = null, ?int $userId = null): int
    {
        $productClass = $product::class;
        $productKey = $product->getKey();
        $referenceClass = $reference !== null ? $reference::class : null;
        $referenceKey = $reference?->getKey();

        // Each attempt reloads the product and the reference, so a retried
        // conflict never works on models the rolled back attempt has touched
        return resolve(StockTransactionRunner::class)->retry(
            static fn (): int => app(self::class)->attempt($productClass, $productKey, $quantity, $referenceClass, $referenceKey, $userId),
        );
    }

    /**
     * @param  class-string<Model&Stockable>  $productClass
     * @param  class-string<Model>|null  $referenceClass
     */
    private function attempt(string $productClass, mixed $productKey, int $quantity, ?string $referenceClass, mixed $referenceKey, ?int $userId): int
    {
        /** @var Model&Stockable $product */
        $product = (new $productClass)->newQueryWithoutScopes()->lockForUpdate()->findOrFail($productKey);

        $reference = $referenceClass !== null && $referenceKey !== null
            ? (new $referenceClass)->newQueryWithoutScopes()->findOrFail($referenceKey)
            : null;

        $allocations = $this->allocator->allocate($product, $quantity);
        $allocated = array_sum(array_map(
            static fn (StockAllocation $allocation): int => $allocation->quantity,
            $allocations,
        ));

        if ($allocated >= $quantity) {
            $this->applyAllocations($product, $allocations, $reference, $userId);

            return $quantity;
        }

        // Insufficient stock and no back-order: leave the ledger untouched
        if (! $product->getAttribute('allow_backorder')) {
            return $allocated;
        }

        $inventory = $this->resolver->resolve($product)->first();

        if ($inventory === null) {
            return 0;
        }

        // Back-ordered: draw the full quantity from the primary inventory
        $this->applyAllocations(
            $product,
            [new StockAllocation($inventory->id, $quantity)],
            $reference,
            $userId,
        );

        return $quantity;
    }

    /**
     * @param  Model&Stockable  $product
     * @param  array<int, StockAllocation>  $allocations
     */
    private function applyAllocations(Stockable $product, array $allocations, ?Model $reference, ?int $userId): void
    {
        foreach ($allocations as $allocation) {
            $product->decreaseStock(
                inventoryId: $allocation->inventoryId,
                quantity: $allocation->quantity,
                oldQuantity: $product->lockStockLevel($allocation->inventoryId),
                event: __('shopper-core::status.stock.reserved'),
                userId: $userId,
                reference: $reference,
            );
        }
    }
}
