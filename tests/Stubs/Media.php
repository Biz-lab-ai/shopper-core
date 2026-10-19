<?php

declare(strict_types=1);

namespace Shopper\Core\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * The admin package brings the media library; the core tests only need
 * the relation the delete observers clean up.
 */
class Media extends Model
{
    protected $guarded = [];

    protected $table = 'media';
}
