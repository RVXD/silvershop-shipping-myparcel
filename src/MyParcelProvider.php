<?php

declare(strict_types=1);

namespace SilverShop\ShippingMyParcel;

use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use RuntimeException;
use SilverShop\Model\Order;
use SilverShop\ShippingLabels\Model\ShippingLabel;
use SilverShop\ShippingLabels\Provider\ShippingLabelProvider;
use SilverShop\ShippingLabels\Provider\ShippingLabelResult;
use SilverShop\ShippingLabels\Provider\TrackingInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use Throwable;

/**
 * MyParcel shipping-label provider for silvershop/shipping-labels.
 *
 * v1: creates a carrier label (PostNL) for an order via the MyParcel SDK, returning the label PDF + barcode/tracking;
 * the shipping-labels framework stores and serves the PDF. Carriers/services are config-driven (add DHL/DPD in
 * {@see self::$services} once the installed myparcelnl/sdk major provides the carrier) so expansion is config-only.
 *
 * The API key lives in .env (never SiteConfig); the recipient comes from the order's shipping address; the parcel
 * weight comes from silvershop/shipping's createShippingPackage() when available, else a configurable fallback.
 */
class MyParcelProvider implements ShippingLabelProvider
{
    use Configurable;

    /**
     * Name of the .env variable holding the MyParcel API key.
     */
    private static string $api_key_env = 'SS_MYPARCEL_API_KEY';

    /**
     * Selectable services, as code => [title, carrier_id, package_type]. v1 ships PostNL (carrier_id 1);
     * package_type 1 = parcel, 2 = mailbox parcel. Add DHL/DPD here when the SDK major in use supports them.
     *
     * @var array<string, array{title: string, carrier_id: int, package_type: int}>
     */
    private static array $services = [
        'postnl_parcel' => ['title' => 'PostNL — Parcel', 'carrier_id' => 1, 'package_type' => 1],
        'postnl_mailbox' => ['title' => 'PostNL — Mailbox parcel', 'carrier_id' => 1, 'package_type' => 2],
    ];

    private static string $default_service = 'postnl_parcel';

    /**
     * Fallback parcel weight in grams, used when the order's shipping package reports no weight.
     */
    private static int $default_weight_grams = 1000;

    /**
     * Multiplier from the shipping package's weight unit to grams. Leave at 1 if products are stored in grams;
     * set to 1000 if your shop stores product weight in kilograms (MyParcel expects grams).
     */
    private static float $weight_to_grams = 1.0;

    public function getName(): string
    {
        return 'myparcel';
    }

    public function getTitle(): string
    {
        return _t(self::class . '.Title', 'MyParcel (PostNL)');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function getServiceOptions(): array
    {
        $options = [];
        foreach ((array) self::config()->get('services') as $code => $service) {
            $options[$code] = (string) ($service['title'] ?? $code);
        }

        return $options;
    }

    public function createLabel(Order $order, array $options = []): ShippingLabelResult
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new RuntimeException(
                'MyParcel API key is not configured (set ' . self::config()->get('api_key_env') . ' in .env).'
            );
        }

        $services = (array) self::config()->get('services');
        $code = (string) ($options['service'] ?? self::config()->get('default_service'));
        if (!isset($services[$code])) {
            throw new RuntimeException("Unknown MyParcel service '{$code}'.");
        }
        $service = $services[$code];

        $address = $order->ShippingAddress();
        if (!$address || !$address->exists()) {
            throw new RuntimeException('Order has no shipping address to ship to.');
        }

        $person = trim(((string) $address->FirstName) . ' ' . ((string) $address->Surname));
        if ($person === '') {
            $person = (string) $order->Name;
        }

        try {
            $consignment = ConsignmentFactory::createByCarrierId((int) $service['carrier_id'])
                ->setApiKey($key)
                ->setReferenceId((string) $order->Reference)
                ->setCountry((string) $address->Country)
                ->setPerson($person)
                ->setFullStreet((string) $address->Address)
                ->setPostalCode((string) $address->PostalCode)
                ->setCity((string) $address->City)
                ->setEmail((string) $order->getLatestEmail())
                ->setPackageType((int) $service['package_type'])
                ->setTotalWeight($this->weightGrams($order));

            $collection = $this->newCollection()
                ->setUserAgent('SilverShop', '')
                ->addConsignment($consignment)
                ->setPdfOfLabels(null);

            $created = $collection->first();
            $pdf = (string) $collection->getLabelPdf();
            $barcode = (string) $created->getBarcode();
            $consignmentId = (string) $created->getConsignmentId();
        } catch (Throwable $e) {
            throw new RuntimeException('MyParcel label creation failed: ' . $e->getMessage(), 0, $e);
        }

        return new ShippingLabelResult(
            pdfBytes: $pdf,
            trackingNumber: $barcode,
            trackingUrl: $this->trackUrl($barcode, (string) $address->PostalCode, (string) $address->Country),
            carrier: 'PostNL',
            service: $code,
            providerRef: $consignmentId,
        );
    }

    public function cancelLabel(ShippingLabel $label): bool
    {
        $key = $this->apiKey();
        $ref = (string) $label->ProviderRef;
        if ($key === '' || $ref === '') {
            return false;
        }

        try {
            // MyParcel's API only removes shipments still in "concept" status. Once a label is produced the shipment
            // is registered/pre-announced and the API silently won't delete it — so attempt the delete and then VERIFY
            // the shipment is actually gone before reporting success. (consignment ids are ints; find() is typed int.)
            $id = (int) $ref;
            MyParcelCollection::find($id, $key)->deleteConcepts();

            foreach (MyParcelCollection::find($id, $key) as $consignment) {
                if ($consignment->getBarcode() || $consignment->getStatus() > 0) {
                    // Still registered at MyParcel — a produced label can't be cancelled via the API.
                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function track(ShippingLabel $label): ?TrackingInfo
    {
        $barcode = (string) $label->TrackingNumber;
        if ($barcode === '') {
            return null;
        }

        return new TrackingInfo(
            status: (string) $label->Status,
            trackingNumber: $barcode,
            trackingUrl: (string) $label->TrackingUrl,
        );
    }

    protected function apiKey(): string
    {
        return (string) Environment::getEnv((string) self::config()->get('api_key_env'));
    }

    /**
     * Parcel weight in grams: from silvershop/shipping's shipping package when present, else the configured fallback.
     */
    protected function weightGrams(Order $order): int
    {
        $grams = 0;
        if ($order->hasMethod('createShippingPackage')) {
            $package = $order->createShippingPackage();
            if ($package) {
                $grams = (int) round(((float) $package->weight()) * (float) self::config()->get('weight_to_grams'));
            }
        }

        return $grams > 0 ? $grams : (int) self::config()->get('default_weight_grams');
    }

    protected function trackUrl(string $barcode, string $postcode, string $country): string
    {
        if ($barcode === '') {
            return '';
        }

        // SDK TrackTraceUrl helper is unreliable; build the public URL directly.
        return sprintf(
            'https://myparcel.me/track-trace/%s/%s/%s',
            rawurlencode($barcode),
            rawurlencode($postcode),
            rawurlencode($country)
        );
    }

    /**
     * Seam for testing: override to inject a fake MyParcelCollection (avoids real HTTP in unit tests).
     */
    protected function newCollection(): MyParcelCollection
    {
        return new MyParcelCollection();
    }
}
