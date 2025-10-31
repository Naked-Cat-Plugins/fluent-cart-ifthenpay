=== Payment Multibanco for FluentCart via ifthenpay  ===
Contributors: nakedcatplugins, webdados, ifthenpay
Tags: ifthenpay, ecommerce, portugal, atm, homebanking
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Secure FluentCart payments with Multibanco via ifthenpay’s payment gateway.

== Description ==

“Pagamento de Serviços” (payment of services) on Multibanco (Portuguese ATM network) is the most popular way to pay for services and (online) purchases in Portugal.
Portuguese consumers trust the “Multibanco” payment method more than any other.

This plugin generates a “Multibanco” Payment Reference that customers can use to pay for their FluentCart orders at an ATM or via home banking.

Soon, this plugin will also have support for MB WAY, Credit card, Apple Pay, Google Pay, Payshop, Cofidis, and PIX.

This is the official [ifthenpay](https://ifthenpay.com/?lang=en) plugin, and a contract with this company is required. Technical support is provided by [Naked Cat Plugins](https://nakedcatplugins.com) (by [Webdados](https://www.webdados.pt)) on the [WordPress.org support forums](https://wordpress.org/support/plugin/payment-multibanco-for-fluent-cart-via-ifthenpay/).

== Features ==
* Generates a Multibanco Reference for simple payment on the Portuguese ATM network or home banking service;
* Possibility of setting an expiration date for Multibanco references;
* Automatically changes the order status to “Processing” (or “Completed” if the order only contains virtual downloadable products) and notifies both the customer and the store owner if the automatic “Webhook/Callback” upon payment is activated;
* Automatic “Webhook/Callback” can be activated via the plugin settings screen for each payment method;

== External services ==

This plugin connects to the ifthenpay API to make payment requests and activate webhooks.
It does not send any user identifiable information, only order ID and value to be paid.

This service is provided by ifthenpay: [end-user license agreement](https://ifthenpay.com/eula/), [privacy policy](https://ifthenpay.com/politica-de-privacidade/?lang=en).

== Installation ==

* Make sure you already have a contract with ifthenpay;
* Use the included automatic install feature on your WordPress admin panel and search for “ifthenpay for fluentcart”;
* Multibanco: Go to FluentCart > Settings > Payment Settings > Multibanco and fill in the MB Key provided by ifthenpay;
* After saving the settings: Activate the “Webhook/Callback” to enable automatic payment notification from ifthenpay to your website (the Backoffice Key provided when signing the contract is needed);
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

= How can I report security bugs? =

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team helps validate, triage, and handle any security vulnerabilities. - Available soon

== Changelog ==

= 0.1.0 - 2025-10-31 =
* [NEW] First release
* [DEV] Tested with WordPress 6.9-beta2-61099 and FluentCart 1.2.5