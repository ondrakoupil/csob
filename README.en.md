# ČSOB payment gate PHP client library

[![Build Status](https://travis-ci.org/ondrakoupil/csob.svg?branch=master)](https://travis-ci.org/ondrakoupil/csob.svg?branch=master)
[![Number of downloads](https://img.shields.io/packagist/dt/ondrakoupil/csob-eapi-paygate.svg)](https://img.shields.io/packagist/dt/ondrakoupil/csob-eapi-paygate.svg)
[![Current version](https://img.shields.io/packagist/v/ondrakoupil/csob-eapi-paygate.svg)](https://img.shields.io/packagist/v/ondrakoupil/csob-eapi-paygate.svg)
[![Licence](https://img.shields.io/packagist/l/ondrakoupil/csob-eapi-paygate.svg)](https://img.shields.io/packagist/l/ondrakoupil/csob-eapi-paygate.svg)

This library enables you to integrate ČSOB payment gateway into your e-shop or other web app without getting your hands dirty with cURL, signatures, return codes or other low-level stuff.

See [https://github.com/csob/paymentgateway][1] for further information about the gateway, it's API, generating keys, payment processing steps, payment statuses and many more.

## News

The library now supports ČSOB eAPI 1.5. Select whichever eAPI version you want to use
by setting address in your config object. Default is now eAPI 1.5.

```
$config->url = "https://iapi.iplatebnibrana.csob.cz/api/v1.5";  // test, eAPI 1.5 - default
$config->url = "https://iapi.iplatebnibrana.csob.cz/api/v1.0";  // test, eAPI 1.0
$config->url = "https://api.platebnibrana.csob.cz/api/v1.5";    // production, eAPI 1.5
// etc.
```

Versioning of this library will now respect versioning of ČSOB eAPI.
This means I am skipping from 0.1.3 directly to 1.5,
but it makes more sense now.

## Installation

The easy way - use composer:

`composer require ondrakoupil/csob-eapi-paygate`

Or, if you don't use composer, copy `dist/csob-client.php` to your project and include it. It contains all necessary classes packed into single file.


## Usage

Apart from this library, you will need:

- Merchant ID - you can obtain an anonymous ID at ČSOB [keygen][2] or use the real ID what was given for your project
- Keys for signing and verifying signatures - can be generated with [keygen][2] if you already don't have them
- Bank public key - download it from [ČSOB's Github][3]

The library consists of these classes:

- Client - the main classes that contains all the magic
- Config - holds the configuration of your application, your Mechant ID, keys, return path etc.
- Payment - represents one payment
- Crypto - handles signing and verifying signatures, you don't need to care about it

First of all, you need to create a Config object and set its properties to proper values.
Then, you can create Client object and use its methods to call various API methods and receive
responses. It has a method for each API method and something more.

All classes are members of `OndraKoupil\Csob` namespace, so it is necessary to `use` them
or use fully qualified names. Following examples expects you have used `use`:

```php
use OndraKoupil\Csob\Client, OndraKoupil\Csob\Config, OndraKoupil\Csob\Payment;
```

```php
$config = new Config(
	"My Merchant ID",
	"path/to/my/private/key/file.key",
	"path/to/bank/public/key.pub",
	"My shop name",

	// This is your shop's URL that customers will return to, after they have paid
	"https://www.my-eshop.cz/return-path.php",

	// Payment API URL - leave empty to use integration (testing) gateway,
	// fill production gateway's URL when you are ready to go
	"https://iapi.iplatebnibrana.csob.cz/api/v1"
);

$client = new Client($config);
```

Note that you use YOUR private key and BANK's public key.

There are some more properties that can set, you probably won't need to do that.
See Config's doc page for more.


### Testing connection
Use testGetConnection() and testPostConnection() to ensure that keys are set correctly and
the gateway listens to your app.

```php
try {
	$client->testGetConnection();
	$client->testPostConnection();

} catch (Exception $e) {
	echo "Something went wrong: " . $e->getMessage();
}
```


### Create new payment (payment/init)

To start a new payment, you need to create a `Payment` object and run `paymentInit()` method.
In response, you'll receive a PayID. You should save that - it represents the payment
and you will need it in future calls.

Use `$payment->addCartItem()` to add one or two items (this is a restriction of payment gateway
and will be changed in future versions).

```php
$payment = new Payment("1234");
$payment->addCartItem("Some cool stuff", 1, 10000);

$response = $client->paymentInit($payment);

$payId = $payment->getPayId();
$payId = $response["payId"];
```

You can set many more properties of `$payment`, this is just the minimum required.

After calling `paymentInit()`, you can get PayID either as part of response array, or just
from the $payment object if everyrhing went right.

Note that all strings with national characters should be encoded in UTF-8. If your application is
not using this encoding, don't forget to `iconv` all strings before settings them into Payment.


### Processing payment

After payment was created, you need to redirect the customer to payment gateway.
Either get the URL from get `getPaymentProcessUrl()` method, or let Client handle the redirect
for you by calling `redirectToGateway`

```php
$url = $client->getPaymentProcessUrl($payment);
redirect($url);

// OR

$client->redirectToGateway($payment);
terminateApp();
```

As the argument, you can use the $payment object from before or just plain string $payId.


### When customer returns...

You specified the URL to return the customer to in Config object or in Payment object. On that URL,
you can use `receiveReturningCustomer()` method to check if incoming data is correct and parse
the response into array.

```php
$response = $client->receiveReturningCustomer();

if ($response["paymentStatus"] == 7) { // or 4, depending on your setup
	echo "Payment was OK, thank you";

} else {
	echo "Something went wrong...";
}
```

See [payment gateway wiki][4] for possible payment statuses.

### Checking payment status

This is simple:

```php
$status = $client->paymentStatus($payId);
```

Set second argument to false if you want more details than just status number.


### Reversing, confirming, refunding

Use `paymentReverse()` to cancel unprocessed payment, `paymentClose()` to confirm payment
(if not set to do that automatically) and `paymentRefund()` to send money back to customer
via API.

Note that payment has to be in [adequate state][4] to use these methods or an exception
will be thrown. Set second argument to true to oppress these exceptions (then some other
kind of error occurs, exception will be thrown anyway).

```php
$client->paymentReverse($payId);
$client->paymentClose($payId);
$client->paymentRefund($payId);
```

Since eAPI 1.5 you can partially authorize (close) the payment or refund it.
Just pass in third argument to `paymentClose()` or `paymentRefund()`
- just beware, use **hundreths** of base currency unit.

```php
// Confirm transaction with amount only 100 CZK
$client->paymentClose($payId, false, 10000);

// Refund 100 CZK
$client->paymentRefund($payId, false, 10000);
```

`paymentRefund()` sometimes returns with HTTP 500 code and throws an exception
when using test environment. Accorting to [this issue][issue43] it is a bug
in test environment that has not yet been fixed.

Note that if using paymentClose() with amount parameter, the amount requested
must be less than the amount originally authorized by customer.

### Customer info

Use `customerInfo()` to check whether this customer (identified i.e. by e-mail address)
has paid anything with payment card before, and if so, do some personalisation stuff:

```php
$hasCards = $client->customerInfo($someCustomerId);
if ($hasCards) {
	echo "You can pay on-line using your card like you did before.";
} else {
	echo "These are payment options...";
}
```

### Recurring payments

Since eAPI 1.5, you can make recurring payments. See the [wiki page][8] for details.

- let customer authorize payment template by going through payment process as usual,
  but before running `paymentInit()`, mark the payment as template
  by calling `$payment->setRecurrentPayment(true)`
- customer fills in card number, security codes etc.
- save resulting PayID so that you can refer to this authorised payment later
- call `paymentRecurrent()` with PayID of the original payment and new Payment
  object. Payment gets processed silently on background.
- new payment has its own PayID and can be manipulated as any other payment.

You need PayID of the original payment and a new Payment object.
Only $orderNo, $totalAmount (sum of cart items added by addToCart), $currency
and $description of $newPayment are used, others are ignored.
Note that if $totalAmount is set, then also $currency should be set. If not,
CZK is used as default value.

$orderNo is the only mandatory value. Other properties
can be left null to use original values from payment template.

## Logging

Client has a simple built-in logging. Two logs can be used, first log is for business-level
messages like "Payment XYZ has been made", second is a tracelog for technical
messages like "API returned this JSON: ...".

Either give some file path or a callback which can forward the message to your
app's standard logging facility. Logs can be set in Client's constructor or using
`setLog()` and `setTraceLog()` method.

```php
$client->setLog("some/file/log.txt");
$client->setTraceLog(function($message) use ($myLogger) {
	$myLogger->log($message);
});
```


## Troubleshooting
If you found a bug or something doesn't work as expected, write an issue.
Feel free to [contact me][5] if you have any questions or suggestions.



[1]: https://github.com/csob/paymentgateway
[2]: https://iplatebnibrana.csob.cz/keygen/
[3]: https://github.com/csob/paymentgateway/tree/master/eshop-integration/keys
[4]: https://github.com/csob/paymentgateway/wiki/eAPI-v1-CZ#user-content-%C5%BDivotn%C3%AD-cyklus-transakce-
[5]: https://github.com/ondrakoupil
[6]: https://platebnibrana.csob.cz/
[7]: https://github.com/csob/paymentgateway/wiki/Testovac%C3%AD-karty
[8]: https://github.com/csob/paymentgateway/wiki/Opakovan%C3%A1-platba
[issue43]: https://github.com/csob/paymentgateway/issues/43
