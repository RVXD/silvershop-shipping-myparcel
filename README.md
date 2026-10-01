# silvershop/shipping-myparcel

A **MyParcel** label provider for [`silvershop/shipping-labels`](https://github.com/silvershop/silvershop-shipping-labels).
It creates a real carrier label (PostNL) for an order through the [MyParcel SDK](https://github.com/myparcelNL/sdk),
returning the label PDF + barcode/tracking; the shipping-labels framework stores and serves the PDF.

MyParcel is an **NL/BE-account** aggregator (ships NL/BE domestic + EU/international). Merchants outside NL/BE should
use a different adapter.

## Install

```bash
composer require silvershop/shipping-myparcel
```

Pulls in `silvershop/shipping-labels` and `myparcelnl/sdk`. Install `silvershop/shipping` too if you want the parcel
weight derived automatically from your products.

## Configure

Put your MyParcel API key in `.env` (never in config/SiteConfig):

```
SS_MYPARCEL_API_KEY="your-myparcel-key"
```

Everything else is optional config on `SilverShop\ShippingMyParcel\MyParcelProvider`:

```yaml
SilverShop\ShippingMyParcel\MyParcelProvider:
  default_service: 'postnl_parcel'
  default_weight_grams: 1000      # fallback when a parcel has no product weight
  weight_to_grams: 1              # set 1000 if your shop stores product weight in kilograms
  services:
    postnl_parcel:  { title: 'PostNL — Parcel',         carrier_id: 1, package_type: 1 }
    postnl_mailbox: { title: 'PostNL — Mailbox parcel',  carrier_id: 1, package_type: 2 }
    # Add DHL/DPD here once the installed myparcelnl/sdk major provides the carrier — config only, no code.
```

## Use

Create a label for an order (the only entry point; the shipping-labels framework persists the result):

```php
$label = $order->createShippingLabel('myparcel', ['service' => 'postnl_parcel']);
// $label->LabelFile (PDF), $label->TrackingNumber (barcode), $label->TrackingUrl, $label->ProviderRef (consignment id)
```

The label PDF is viewable at `/shippinglabels/label/{ID}`. `cancelLabel()` deletes an unordered concept; `track()`
returns the barcode + public MyParcel track-trace URL.

## Scope

v1 = labels (PostNL, carrier-extensible via config). Planned: pickup-point + delivery-date selection in checkout,
opt-in auto-create on payment, bulk label creation, more carriers/options.
