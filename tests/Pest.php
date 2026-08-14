<?php

declare(strict_types=1);

uses()
    ->beforeEach(function (): void {
        btpw_test_reset();
        \ZirkelDesign\BankTransfersForWooCommerce\Stripe\PluginIntegration::clearCache();
        \ZirkelDesign\BankTransfersForWooCommerce\Stripe\ClientFactory::reset();
    })
    ->in('Unit', 'Feature');
