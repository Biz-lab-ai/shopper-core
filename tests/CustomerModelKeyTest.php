<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Shopper\Core\CoreServiceProvider;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Geolocation;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderAddress;
use Shopper\Core\Models\OrderRefund;
use Shopper\Core\Search\CustomerIndexer;

class CustomerKeyStub extends Authenticatable
{
    protected $table = 'customer_key_stubs';
}

class AuthUserKeyStub extends Authenticatable
{
    protected $table = 'auth_user_key_stubs';
}

beforeEach(function (): void {
    config([
        'auth.providers.users.model' => AuthUserKeyStub::class,
        'shopper.models.customer' => CustomerKeyStub::class,
    ]);
});

it('resolves the customer relations through the shopper customer model key', function (): void {
    expect((new Order)->customer()->getRelated())->toBeInstanceOf(CustomerKeyStub::class)
        ->and((new OrderAddress)->customer()->getRelated())->toBeInstanceOf(CustomerKeyStub::class)
        ->and((new OrderRefund)->customer()->getRelated())->toBeInstanceOf(CustomerKeyStub::class)
        ->and((new Address)->user()->getRelated())->toBeInstanceOf(CustomerKeyStub::class)
        ->and((new Geolocation)->user()->getRelated())->toBeInstanceOf(CustomerKeyStub::class);
});

it('indexes the shopper customer model, not the authentication user model', function (): void {
    config(['shopper.search.indexers' => []]);

    $provider = new CoreServiceProvider($this->app);
    (new ReflectionMethod($provider, 'registerCustomerIndexer'))->invoke($provider);

    expect(config('shopper.search.indexers.'.CustomerKeyStub::class))->toBe(CustomerIndexer::class)
        ->and(config('shopper.search.indexers.'.AuthUserKeyStub::class))->toBeNull();
});
