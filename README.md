# woocommerce-veeqo-shipment-tracking
A plugin to fix Veeqo's shipment tracking issues.

## Requirements [temp]
```
So when an order is shipped by our software the following information is added:

Carrier: Royal Mail
Service: ROYAL MAIL SIGNED FOR 1C/2C
Tracking Number: GK784938473GB

We need to find a way to get this information from order notes into the WooCommerce Shipment Tracking plugin.
You can see documentation for the plugin here - https://docs.woocommerce.com/document/shipment-tracking/
There is an endpoint for the plugin which looks like it would work - POST /wp-json/wc/v1/orders/<order_id>/shipment-trackings

we would need to populate the following information:

tracking_number
tracking_provider	
date_shipped

From the example order in Beachii Staging, the information would be:

tracking_number - GK784938473GB
tracking_provider - Royal Mail
date_shipped - February 6, 2019

So this should make sense.
The information does not need to be added instantly, we could set it up to trigger as a cron or something every 30 minutes etc.
Once this information has been added, we then need to trigger the woocommerce shipment complete plugin so that the customer gets notified their order has been shipped. There should be something in the API docs about this, I had a quick look but didn't see exactly what we would need to use.
```

## Getting Started

Coming soon.

### Prerequisites

What things you need to install the software and how to install them

```
Wordpress v5 (may work on previous versions, however it's untested)
WooCommerce
Veeqo Shipment integration - https://www.veeqo.com/
```

### Installing

Clone the repot into your plugins folder

```
git clone https://github.com/garygoodger/woocommerce-veeqo-shipment-tracking.git
```

Activate the plugin

## Authors

* **Gary Goodger**
* **John** 
