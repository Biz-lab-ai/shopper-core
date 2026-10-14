<?php

declare(strict_types=1);

use Shopper\Core\Helpers\Migration;

return new class extends Migration
{
    /**
     * The host application owns the spatie/laravel-permission tables and
     * creates them with its own migration. The Shopper-specific columns
     * (group_name, display_name, description, can_be_removed) are added by
     * the host as well, so this migration intentionally does nothing.
     */
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
