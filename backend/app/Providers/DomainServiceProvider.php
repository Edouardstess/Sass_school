<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Finance\Contracts\PaymentGatewayInterface;
use App\Domain\Notification\Contracts\SmsSender;
use App\Domain\Notification\Contracts\WhatsAppSender;
use App\Infrastructure\Messaging\LogSmsSender;
use App\Infrastructure\Messaging\LogWhatsAppSender;
use App\Infrastructure\Messaging\TwilioSmsSender;
use App\Infrastructure\Messaging\WhatsAppCloudSender;
use App\Infrastructure\Payments\PaymentGatewayManager;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the domain's outbound ports to concrete infrastructure adapters.
 *
 * This is the only file that knows which SMS vendor or payment provider is in
 * use. Domain code depends on the interfaces, so swapping a provider is a
 * change here and nowhere else.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);

        // Resolving the interface yields the tenant's configured default
        // gateway; call sites that need a specific one ask the manager by key.
        $this->app->bind(
            PaymentGatewayInterface::class,
            fn ($app) => $app->make(PaymentGatewayManager::class)->default(),
        );

        $this->app->bind(SmsSender::class, function ($app) {
            return match (config('services.sms.driver', 'log')) {
                'twilio' => $app->make(TwilioSmsSender::class),
                default => $app->make(LogSmsSender::class),
            };
        });

        $this->app->bind(WhatsAppSender::class, function ($app) {
            return match (config('services.whatsapp.driver', 'log')) {
                'cloud_api' => $app->make(WhatsAppCloudSender::class),
                default => $app->make(LogWhatsAppSender::class),
            };
        });
    }
}
