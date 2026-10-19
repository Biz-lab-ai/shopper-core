<?php

declare(strict_types=1);

namespace Shopper\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Jobs\SyncProductWithCollectionsJob;
use Shopper\Core\Models\Contracts\Product;
use Shopper\Core\Models\Contracts\ProductVariant;

class ProductObserver
{
    public function saved(Product $product): void
    {
        SyncProductWithCollectionsJob::dispatch($product)->afterCommit();
    }

    /**
     * A force delete removes every variant through its model first, so the
     * variant observer runs, then performs the final clean-up of the
     * product's own stock.
     *
     * @param  Model&Product  $product
     */
    public function forceDeleting(Product $product): void
    {
        $product->variants()->get()->each(
            /** @param Model&ProductVariant $variant */
            fn (ProductVariant $variant): ?bool => $variant->delete(),
        );

        $product->clearStock();
    }

    /**
     * A soft delete is reversible: the stock of the product and of each of
     * its variants is zeroed by ledger movements, the ledger is kept and the
     * variants stay as rows.
     *
     * @param  Model&Product  $product
     */
    public function deleting(Product $product): void
    {
        $product->media()->delete();
        $product->prices()->delete();

        if ($product->isForceDeleting()) {
            return;
        }

        $event = __('shopper-core::status.stock.cleared');

        $product->zeroStockLevels($event);

        $product->variants()->get()->each(
            /** @param Model&ProductVariant $variant */
            fn (ProductVariant $variant) => $variant->zeroStockLevels($event),
        );
    }
}
