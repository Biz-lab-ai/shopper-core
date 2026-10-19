<?php

declare(strict_types=1);

namespace Shopper\Core\Listeners\Orders;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shopper\Core\Contracts\StockTransactionRunner;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Models\Contracts\ProductVariant;
use Shopper\Core\Models\Contracts\Stockable;
use Shopper\Core\Models\OrderItem;

final class RestoreOrderStockListener implements ShouldQueue
{
    /**
     * Each reservation row is stamped `released_at` in the same transaction
     * as its compensation, under a row lock: a queue retry after a partial
     * run only compensates the rows the previous attempt never stamped, so
     * the restock can never be applied twice. The reservation of a soft
     * deleted product (itself or the parent of a variant) is released
     * without a movement: the stock of a removed product never comes back.
     */
    public function handle(OrderCancelled $event): void
    {
        $order = $event->order->load(['items.product' => fn (MorphTo $morphTo): MorphTo => $morphTo->withTrashed()]);
        $orderType = $order->getMorphClass();
        $orderClass = $order::class;
        $orderKey = $order->getKey();

        foreach ($order->items as $item) {
            if (! $item->product instanceof Stockable) {
                continue;
            }

            $itemKey = $item->getKey();

            resolve(StockTransactionRunner::class)->retry(static function () use ($orderType, $orderClass, $orderKey, $itemKey): void {
                /** @var OrderItem $item */
                $item = OrderItem::query()->with(['product' => fn (MorphTo $morphTo): MorphTo => $morphTo->withTrashed()])->findOrFail($itemKey);

                /** @var Model&Stockable $product */
                $product = $item->product;
                $removed = self::isRemoved($product);

                $reservations = $product->inventoryHistories()
                    ->where('reference_type', $orderType)
                    ->where('reference_id', $orderKey)
                    ->where('quantity', '<', 0)
                    ->whereNull('released_at')
                    ->lockForUpdate()
                    ->get();

                $order = $reservations->isEmpty() || $removed
                    ? null
                    : (new $orderClass)->newQueryWithoutScopes()->findOrFail($orderKey);

                foreach ($reservations as $reservation) {
                    if (! $removed) {
                        $product->mutateStock(
                            inventoryId: $reservation->inventory_id,
                            quantity: abs($reservation->quantity),
                            oldQuantity: $product->lockStockLevel($reservation->inventory_id),
                            event: __('shopper-core::status.stock.cancelled'),
                            reference: $order,
                        );
                    }

                    $reservation->updateQuietly(['released_at' => now()]);
                }
            });
        }
    }

    private static function isRemoved(Model $product): bool
    {
        if (in_array(SoftDeletes::class, class_uses_recursive($product), true) && $product->trashed()) {
            return true;
        }

        if (! $product instanceof ProductVariant) {
            return false;
        }

        $parent = $product->product()->withTrashed()->first();

        return $parent !== null && $parent->trashed();
    }
}
