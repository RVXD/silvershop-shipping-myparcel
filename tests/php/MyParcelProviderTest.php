<?php

declare(strict_types=1);

namespace SilverShop\ShippingMyParcel\Tests;

use SilverShop\ShippingLabels\Model\ShippingLabel;
use SilverShop\ShippingLabels\Provider\ProviderRegistry;
use SilverShop\ShippingLabels\Provider\ShippingLabelProvider;
use SilverShop\ShippingLabels\Provider\TrackingInfo;
use SilverShop\ShippingMyParcel\MyParcelProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers the MyParcel provider's non-network surface (identity, config, registry wiring, track/cancel guards).
 * createLabel() talks to the MyParcel API and is exercised in integration testing with a MyParcel test key.
 */
class MyParcelProviderTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testIdentity(): void
    {
        $provider = new MyParcelProvider();
        $this->assertSame('myparcel', $provider->getName());
        $this->assertNotEmpty($provider->getTitle());
    }

    public function testServiceOptions(): void
    {
        $options = (new MyParcelProvider())->getServiceOptions();
        $this->assertArrayHasKey('postnl_parcel', $options);
        $this->assertArrayHasKey('postnl_mailbox', $options);
        $this->assertSame('PostNL — Parcel', $options['postnl_parcel']);
    }

    public function testIsConfiguredFollowsEnvKey(): void
    {
        $env = (string) Config::inst()->get(MyParcelProvider::class, 'api_key_env');
        $provider = new MyParcelProvider();

        Environment::setEnv($env, '');
        $this->assertFalse($provider->isConfigured(), 'Not configured without an API key');

        Environment::setEnv($env, 'test-key');
        $this->assertTrue($provider->isConfigured(), 'Configured once the .env key is present');

        Environment::setEnv($env, '');
    }

    public function testRegisteredInProviderRegistry(): void
    {
        /** @var ProviderRegistry $registry */
        $registry = Injector::inst()->get(ProviderRegistry::class);
        $provider = $registry->get('myparcel');
        $this->assertInstanceOf(MyParcelProvider::class, $provider);
        $this->assertInstanceOf(ShippingLabelProvider::class, $provider);
        $this->assertArrayHasKey('myparcel', $registry->options());
    }

    public function testTrackReturnsNullWithoutTrackingNumber(): void
    {
        $label = ShippingLabel::create();
        $this->assertNull((new MyParcelProvider())->track($label));
    }

    public function testTrackReturnsInfoWithTrackingNumber(): void
    {
        $label = ShippingLabel::create([
            'TrackingNumber' => '3SABC123456789',
            'TrackingUrl' => 'https://myparcel.me/track-trace/3SABC123456789/1011AB/NL',
            'Status' => 'Created',
        ]);

        $info = (new MyParcelProvider())->track($label);
        $this->assertInstanceOf(TrackingInfo::class, $info);
        $this->assertSame('3SABC123456789', $info->trackingNumber);
        $this->assertSame('Created', $info->status);
    }

    public function testCancelReturnsFalseWithoutProviderRef(): void
    {
        Environment::setEnv((string) Config::inst()->get(MyParcelProvider::class, 'api_key_env'), 'test-key');
        $label = ShippingLabel::create(); // no ProviderRef
        $this->assertFalse((new MyParcelProvider())->cancelLabel($label));
        Environment::setEnv((string) Config::inst()->get(MyParcelProvider::class, 'api_key_env'), '');
    }
}
