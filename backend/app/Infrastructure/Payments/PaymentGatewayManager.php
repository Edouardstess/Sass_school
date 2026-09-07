<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Domain\Finance\Contracts\PaymentGatewayInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Infrastructure\Payments\Gateways\BankTransferGateway;
use App\Infrastructure\Payments\Gateways\CashGateway;
use App\Infrastructure\Payments\Gateways\ChequeGateway;
use App\Infrastructure\Payments\Gateways\MonCashGateway;
use App\Infrastructure\Payments\Gateways\NatCashGateway;
use App\Infrastructure\Payments\Gateways\StripeGateway;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves payment gateways by key.
 *
 * The registry is a plain map rather than anything clever, so adding a
 * provider is one line here plus one class implementing the interface — and
 * nothing in the finance domain changes.
 */
final class PaymentGatewayManager
{
    /** @var array<string, class-string<PaymentGatewayInterface>> */
    private const GATEWAYS = [
        'cash' => CashGateway::class,
        'bank_transfer' => BankTransferGateway::class,
        'cheque' => ChequeGateway::class,
        'moncash' => MonCashGateway::class,
        'natcash' => NatCashGateway::class,
        'stripe' => StripeGateway::class,
    ];

    /** @var array<string, PaymentGatewayInterface> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function get(string $key): PaymentGatewayInterface
    {
        $class = self::GATEWAYS[$key] ?? throw new DomainException(
            __('finance.unknown_gateway', ['gateway' => $key])
        );

        return $this->resolved[$key] ??= $this->container->make($class);
    }

    public function has(string $key): bool
    {
        return isset(self::GATEWAYS[$key]);
    }

    /** Cash is always present, so there is always a working default. */
    public function default(): PaymentGatewayInterface
    {
        return $this->get('cash');
    }

    /**
     * Providers that are configured and usable right now.
     *
     * This is what the API returns to the payment screen: the frontend offers
     * exactly the methods that will actually work, instead of showing a
     * MonCash button that fails on click.
     *
     * @return list<PaymentGatewayInterface>
     */
    public function available(): array
    {
        return array_values(array_filter(
            array_map(fn (string $key): PaymentGatewayInterface => $this->get($key), array_keys(self::GATEWAYS)),
            fn (PaymentGatewayInterface $gateway): bool => $gateway->isAvailable(),
        ));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::GATEWAYS);
    }
}
