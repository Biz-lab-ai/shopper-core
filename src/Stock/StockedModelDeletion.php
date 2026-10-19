<?php

declare(strict_types=1);

namespace Shopper\Core\Stock;

use Illuminate\Database\Eloquent\Model;
use Shopper\Core\Contracts\StockTransactionRunner;
use Shopper\Core\Models\Contracts\Product as ProductContract;
use Shopper\Core\Models\Contracts\ProductVariant as ProductVariantContract;

/**
 * Deletes a stocked model by its key. Every attempt loads the model again,
 * so a retried conflict starts from the stored row and never from an
 * instance the rolled back attempt has already marked as deleted.
 */
final class StockedModelDeletion
{
    public function deleteProduct(int $id): void
    {
        resolve(StockTransactionRunner::class)->retry(
            static fn (): ?bool => self::product($id)->delete(),
        );
    }

    public function forceDeleteProduct(int $id): void
    {
        resolve(StockTransactionRunner::class)->retry(
            static fn (): ?bool => self::product($id)->forceDelete(),
        );
    }

    public function deleteVariant(int $id): void
    {
        resolve(StockTransactionRunner::class)->retry(
            static function () use ($id): ?bool {
                /** @var Model&ProductVariantContract $variant */
                $variant = resolve(ProductVariantContract::class)::query()->findOrFail($id);

                return $variant->delete();
            },
        );
    }

    /**
     * @return Model&ProductContract
     */
    private static function product(int $id): ProductContract
    {
        /** @var Model&ProductContract $product */
        $product = resolve(ProductContract::class)::query()->withTrashed()->findOrFail($id);

        return $product;
    }
}
