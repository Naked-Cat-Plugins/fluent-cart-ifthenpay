=== Payment Multibanco and MB WAY for FluentCart via ifthenpay ===
Contributors: nakedcatplugins, webdados, ifthenpay
Tags: ifthenpay, ecommerce, portugal, atm, homebanking
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Secure FluentCart payments with Multibanco and MB WAY via ifthenpay’s payment gateway.

== Description ==

“Pagamento de Serviços” (payment of services) on Multibanco (Portuguese ATM network), and MB WAY (using the customer’s mobile phone number), are the most popular ways to pay for services and (online) purchases in Portugal.
Portuguese consumers trust the “Multibanco” and “MB WAY” payment methods more than any other.

This plugin generates a “Multibanco” Payment Reference that customers can use to pay for their FluentCart orders at an ATM or via home banking, or an “MB WAY” payment request which will send a push notification to the customer’s mobile phone for payment approval.

Soon, this plugin will also have support for Credit card, Apple Pay, Google Pay, Payshop, Cofidis, and PIX.

This is the official [ifthenpay](https://ifthenpay.com/?lang=en) plugin, and a contract with this company is required. Technical support is provided by [Naked Cat Plugins](https://nakedcatplugins.com) (by [Webdados](https://www.webdados.pt)) on the [WordPress.org support forums](https://wordpress.org/support/plugin/payment-multibanco-for-fluent-cart-via-ifthenpay/).

== Features ==
* Generates a Multibanco Reference for simple payment on the Portuguese ATM network or home banking service;
* Allows the customer to pay using MB WAY using their mobile phone;
* Possibility of setting an expiration date for Multibanco references;
* Automatically changes the order status to “Processing” (or “Completed” if the order only contains virtual downloadable products) and notifies both the customer and the store owner if the automatic “Webhook/Callback” upon payment is activated;
* Automatic “Webhook/Callback” can be activated via the plugin settings screen for each payment method;

== External services ==

This plugin connects to the ifthenpay API to make payment requests and activate webhooks.
It does not send any user-identifiable information, only the order ID and the value to be paid.

This service is provided by ifthenpay: [end-user license agreement](https://ifthenpay.com/eula/), [privacy policy](https://ifthenpay.com/politica-de-privacidade/?lang=en).

== Installation ==

* Make sure you already have a contract with ifthenpay;
* Use the included automatic install feature on your WordPress admin panel and search for “ifthenpay fluentcart”;
* Multibanco: Go to FluentCart > Settings > Payment Settings > Multibanco and fill in the MB Key provided by ifthenpay;
* MB WAY: Go to FluentCart > Settings > Payment Settings > MB WAY and fill in the MB WAY Key provided by ifthenpay;
* On all payment methods, after saving the settings: Activate the “Webhook/Callback” to enable automatic payment notification from ifthenpay to your website (the Backoffice Key provided when signing the contract is needed);
* Start receiving payments :-)

== Frequently Asked Questions ==

= Can I start receiving payments right away? Show me the money! =

You have to sign a contract with ifthenpay to activate this service. Go to [ifthenpay.com](https://ifthenpay.com/?lang=en) for more information and to sign up.

= I’m an individual and not a registered business. Can I use this plugin? =

ifthenpay only provides this service to registered businesses and equivalents (such as tax-registered freelancers).
You should [contact ifthenpay](https://ifthenpay.com/?lang=en#contact) if you need additional details on this matter.

= Can I use this plugin and the ifthenpay service on more than one website? =

Yes, but not with the same payment method keys.
Ask ifthenpay for different credentials for each website, and payment method, you need the service to be available.
There are no extra costs, and you can even route payments to separate bank accounts.

= Where do I report security bugs found in this plugin? =

Please report security bugs found in the source code of this plugin through the [Patchstack Vulnerability Disclosure  Program](https://patchstack.com/database/vdp/385a7f2f-159b-488c-8588-81243b8b5365). The Patchstack team will assist you with verification, CVE assignment, and notify the developers of this plugin.

== Changelog ==

= 1.0.1 - 2025-11-20 =
* [NEW] New payment method: MB WAY
* [TWEAK] Add Patchstack VDP information to readme.txt
* [DEV] Stronger validation of Webhook parameters
* [DEV] Requires FluentCart 1.3.0
* [DEV] Tested with WordPress 6.9-RC2-61266 and FluentCart 1.3.0

= 1.0.0 - 2025-11-20 =
* [DEV] Wrong version on readme.txt 🤷‍♂️

= 0.1.0 - 2025-10-31 =
* [NEW] First release
* [DEV] Tested with WordPress 6.9-beta2-61099 and FluentCart 1.2.5