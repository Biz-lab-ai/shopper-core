<?php

declare(strict_types=1);

use Shopper\Core\Observers\ProductObserver;
use Shopper\Core\Observers\ProductVariantObserver;

/**
 * @return array<int, array{0: string, 1: int}>
 */
function clearStockCallSites(string $directory): array
{
    $sites = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $index => $line) {
            if (str_contains($line, 'clearStock(') && ! str_contains($line, 'function clearStock(')) {
                $sites[] = [realpath($file->getPathname()), $index + 1];
            }
        }
    }

    return $sites;
}

describe('StockForkLaw', function (): void {
    it('opens no transaction of its own in the stock entries', function (string $path): void {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/'.$path);

        expect($source)->not->toContain('DB::transaction')
            ->and($source)->not->toContain('beginTransaction');
    })->with([
        'Models/Traits/HasStock.php',
        'Import/ProductRowImporter.php',
        'Stock/LockingStockReserver.php',
        'Listeners/Orders/RestoreOrderStockListener.php',
    ]);

    it('clears stock only when a product is force deleted or a variant is deleted', function (): void {
        $allowed = [
            new ReflectionMethod(ProductObserver::class, 'forceDeleting'),
            new ReflectionMethod(ProductVariantObserver::class, 'deleting'),
        ];

        $sites = clearStockCallSites(dirname(__DIR__, 2).'/src');

        expect($sites)->toHaveCount(2);

        foreach ($sites as [$file, $line]) {
            $inside = array_filter($allowed, fn (ReflectionMethod $method): bool => $method->getFileName() === $file
                && $line >= $method->getStartLine()
                && $line <= $method->getEndLine());

            expect($inside)->toHaveCount(1, "clearStock() called outside the final clean-up at {$file}:{$line}");
        }
    });
});
