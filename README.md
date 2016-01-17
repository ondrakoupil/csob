# PHP knihovna pro práci s ČSOB platební bránou

[![Build Status](https://travis-ci.org/ondrakoupil/csob.svg?branch=master)](https://travis-ci.org/ondrakoupil/csob.svg?branch=master)
[![Number of downloads](https://img.shields.io/packagist/dt/ondrakoupil/csob-eapi-paygate.svg)](https://img.shields.io/packagist/dt/ondrakoupil/csob-eapi-paygate.svg)
[![Current version](https://img.shields.io/packagist/v/ondrakoupil/csob-eapi-paygate.svg)](https://img.shields.io/packagist/v/ondrakoupil/csob-eapi-paygate.svg)
[![Licence](https://img.shields.io/packagist/l/ondrakoupil/csob-eapi-paygate.svg)](https://img.shields.io/packagist/l/ondrakoupil/csob-eapi-paygate.svg)

Pomocí této knihovny lze pohodlně integrovat [platební bránu ČSOB][6] do vašeho e-shopu
nebo jiné aplikace v PHP bez nutnosti přímo pracovat s jejím API, volat
nějaké metody, ověřovat podpisy apod.

Podrobnosti o API platební brány, o generování klíčů a
o jednotlivých krocích zpracováná platby najdete na [https://github.com/csob/paymentgateway][1].
Testovací platební karty jsou na [wiki zde][7]

## Novinky

Knihovna nyní podporuje ČSOB eAPI 1.5, která přidává podporu pro částečné vrácení prostředků
a opakované platby. Jakou verzi eAPI chcete používat lze zvolit nastavením příslušné adresy
v objektu Config. Jako výchozí adresa je testovací platební brána s eAPI 1.5.

```
$config->url = "https://iapi.iplatebnibrana.csob.cz/api/v1.5";  // testovací brána s eAPI 1.5 - default
$config->url = "https://iapi.iplatebnibrana.csob.cz/api/v1.0";  // testovací brána s eAPI 1.0
$config->url = "https://api.platebnibrana.csob.cz/api/v1.5";    // ostrá brána s eAPI 1.5
// atd.
```

Verzování knihovny bude nyní odpovídat verzování eAPI, přeskakuji
tedy z 0.1.3 rovnou na 1.5, ale bude v tom alespoň pořádek.

## Instalace

Nejjednodušeji nainstalujete pomocí Composeru:

`composer require ondrakoupil/csob-eapi-paygate`

Pokud nepoužíváte Composer, stačí někam nakopírovat soubor `dist/csob-client.php` a includnout ho - obsahuje všechny potřebné třídy pohromadě.


## Použití

Kromě této knihovny se bude hodit:

- Merchant ID - anonymní ID lze vygenerovat na stránce [keygenu][2], anebo použijte to ID, které přidělí banka
- Klíče pro podepisování a verifikaci podpisů - opět získáte v [keygenu][2]
- Veřejný klíč banky - lze stáhnout z [Githubu ČSOB][3]. Pozor, liší se pro testovací a ostrou bránu.

Knihovna se skládá z tříd:

- Client - hlavní třída, se kterou budeme pracovat
- Config - nastavení parametrů komunikace s bránou, klíčů, Merchant ID atd. a různých výchozích hodnot
- Payment - představuje jednu platbu
- Crypto - zajišťuje podepisování a ověřování podpisů

Všechny třídy jsou v namespace `OndraKoupil\Csob`, je tedy třeba je na začátku souboru uvést pomocí
`use`, anebo vždy používat celé jméno třídy včetně namespace. Zde uvedené příklady předpokládají,
že jste už použili `use`:

```php
use OndraKoupil\Csob\Client, OndraKoupil\Csob\Config, OndraKoupil\Csob\Payment;
```

### Nastavení

Nejdřív ze všeho je třeba vytvořit objekt `Config` a nastavit v něm potřebné hodnoty.
Ten pak předáte objektu `Client` a voláte jeho metody, které odpovídají jednotlivým
metodám, které API normálně nabízí.

```php
$config = new Config(
	"My Merchant ID",
	"path/to/my/private/key/file.key",
	"path/to/bank/public/key.pub",
	"My shop name",

	// Adresa, kam se mají zákazníci vracet poté, co zaplatí
	"https://www.my-eshop.cz/return-path.php",

	// URL adresa API - výchozí je adresa testovacího (integračního) prostředí,
	// až budete připraveni přepnout se na ostré rozhraní, sem zadáte
	// adresu ostrého API.
	"https://iapi.iplatebnibrana.csob.cz/api/v1"
);

$client = new Client($config);
```

Pozor - používá se zde VÁŠ soukromý klíč a veřejný klíč BANKY.

Config umožňuje nastavit i nějaké další parametry a různé výchozí hodnoty.

### Test připojení
Pro ověření, že spojení funguje a požadavky se správně podepisují, lze využít
metody testGetConnection() and testPostConnection(), které volají API metodu `echo`.

```php
try {
	$client->testGetConnection();
	$client->testPostConnection();

} catch (Exception $e) {
	echo "Something went wrong: " . $e->getMessage();
}
```


### Založení nové platby (payment/init)

Pro založení nové platby je třeba vytvořit objekt `Payment`, nastavit mu požadované hodnoty
a pak ho předat do `paymentInit()`. Pokud je vše v pořádku, API přidělí platbě PayID. To
je třeba někam uložit, bude se později hodit pro volání dalších metod.

Pomocí `$payment->addCartItem()` se přidávají položky do objednávky. V současné verzi
musí mít platba jednu nebo dvě položky, v budoucích verzích se toto omezení má změnit.

Pozor, všechny řetězce by měly být v UTF-8. Používáte-li jiné kódování, je třeba je všude, kde hrozí
nějaká diakritika (zejména u názvu položky v košíku), převádět pomocí funkce `iconv`.

```php
$payment = new Payment("1234");
$payment->addCartItem("Zakoupená věcička", 1, 10000);

$response = $client->paymentInit($payment);

$payId = $payment->getPayId();
$payId = $response["payId"];
```

Toto je nezbytné minimum - v objektu `$payment` toho lze nastavit mnohem více. A pozor, cena
se uvádí v setinách základní jednotky měny (v haléřích nebo v centech) - tj. 10000 znamená
jen 100 Kč.

Při zavolání `paymentInit()` se zadanému objektu $payment nastaví jeho PayID, odkud ho lze
přečíst přes getter, anebo ho lze získat z vráceného pole.

### Zaplacení (payment/process)

Po úspěšném založení platby je třeba přesměrovat prohlížeč zákazníka na platební bránu,
jejíž adresu vygeneruje `getPaymentProcessUrl()`. Jako pomůcka je tu rovnou i metoda
`redirectToGateway()`, která toto přesměrování rovnou provede.


```php
$url = $client->getPaymentProcessUrl($payment);
redirectBrowserTo($url);

// NEBO

$client->redirectToGateway($payment);
terminateApp();
```

Jako argument lze používat buď $payment objekt z předchozího volání, anebo PayID jako obyčejný string.


### Návrat zákazníka

Poté, co zákazník zadá potřebné údaje na platební bráně a vše se ověří a schválí,
brána ho vrátí na Return URL, kterou jste nastavili v Configu nebo v Payment objektu.
Na této URL byste měli buď ověřit stav platby přes `paymentStatus()` anebo
jednoduše zpracovat příchozí data pomocí metody `receiveReturningCustomer()`, která zkontroluje platnost
podpisu příchozích dat a vyextrahuje z nich užitečné hodnoty.


```php
$response = $client->receiveReturningCustomer();

if ($response["paymentStatus"] == 7) {
	// nebo také 4, záleží na nastavení closePayment
	echo "Platba proběhla, děkujeme za nákup.";

} else {
	echo "Něco se pokazilo, sakra...";
}
```

Podrobnosti o stavech platby jsou zde na [wiki platební brány][4].

### Ověření stavu platby (payment/status)

Kdykoliv lze jednoduše zjistit, v jakém stavu je zrovna platba:

```php
$status = $client->paymentStatus($payId);
```

Pokud potřebujete více detailů než jen číslo stavu, dejte druhý argument `$returnStatusOnly` na `false`,
metoda pak vrátí array s různými podrobnostmi.


### Potvrzení, zrušení, vrácení prostředků

Metoda `paymentReverse()` zruší dosud nezprocesovanou platbu, `paymentClose()` potvrdí platbu
a `paymentRefund()` vrátí již proběhlou platbu zpět plátci.

Pozor, platba musí být ve [správném stavu][4], jinak nastane chyba a vyhodí se výjimka. Pokud nastavíte
druhý argument `$ignoreWrongPaymentStatusError` na `true`, tak se tato konkrétní chyba tiše ignoruje a metoda jen vrátí `null`.
Všechny ostatní chyby nadále vyhazují výjimku.

```php
$client->paymentReverse($payId);
$client->paymentClose($payId);
$client->paymentRefund($payId);
```

Počínaje API 1.5 umožňuje platební brána vrátit jen část prostředků pomocí netody `paymentRefund()`
nebo potvrdit transakci s nižší než původně autorizovanou částkou u metody `paymentClose()`.
Jako třetí argument těchto metod lze zadat požadovanou částku k vrácení v **setinách** základní měny (pozor!):

```php
// Potvrdit transakci jen na 100 Kč
$client->paymentClose($payId, false, 10000);

// Vrátit 100 Kč
$client->paymentRefund($payId, false, 10000);
```

`paymentRefund()` občas v testovacím prostředí vrací HTTP stav 500, což vede k vyhození výjimky.
Dle [tohoto issue][issue43] jde o bug v testovacím prostředí platební brány, který zatím není vyřešen.

### Info zákazníka (customer/info)

Metoda `customerInfo()` ověřuje, zda zákazník se zadaným ID (např. e-mailem) už někdy
platil kartou a pokud ano, lze se nějak zachovat (např. vypsat personalizovanou hlášku):

```php
$hasCards = $client->customerInfo($someCustomerId);
if ($hasCards) {
	echo "Chcete zase zaplatit kartou?";
} else {
	echo "Nabízíme tyto možnosti platby: ...";
}
```

### Opakované platby

Počínaje API 1.5 lze provádět opakované platby. Jak přesně to funguje se dočtete na
[Wiki ČSOB][8]. Zhruba to je takto:

- necháte zákazníka autorizovat platební šablonu tak, že provedete normálně
  celý platební proces jako obvykle, ale objektu Payment před voláním `paymentInit()`
  nastavíte `$payOperation` na `Payment::OPERATION_RECURRENT`,
  nejlépe zavoláním `$payment->setRecurrentPayment(true)`
- zákazník pak zadá číslo karty, kód a provede 3D ověření jako u běžné platby
- vy si uložíte PayID, abyste se na tuto autorizovanou transakci mohli odkazovat
- pak můžete kdykoliv zavolat metodu `paymentRecurrent()` s PayID původní transakce
  a s novým Payment objektem. Platba proběhne potichu a zákazník nic nemusí dělat.
- nová platba dostane své vlastní PayID a lze s ní pracovat jako s jakoukoliv jinou platbou

Při volání `paymentRecurrent()` se zadáváte nový Payment objekt, nicméně se bere v potaz pouze
$orderNo, $totalAmount, $currency a $description. Ostatní proměnné jsou ignorovány.
$totalAmount vznikne součtem položek přidávaných přes `addToCart()`.
Pokud je nastaven $totalAmount, pak je vhodné určit i $currency - výchozí je CZK, nezávisle
na hodnotě z původní šablony platby.

$orderNo je jediná proměnná, která musí být nastavena (musí být jedinečná napříč všemi
transakcemi). Ostatní lze vynechat, brána pak použije hodnoty z původní šablony platby.

## Logování

`Client` má vestavěné jednoduché logování. Jeden log slouží pro business-level zprávy
("platba XYZ proběhla úspěšně"), do druhého logu (traceLog) se zaznamenává podrobná
komunikace s API a různé detailní technikálie.

Lze buď jednoduše zadat cestu do souboru, kam se budou zprávy zaznamenávat, anebo
dát callback, který zprávy přesměruje do libovolného loggeru, který vaše aplikace používá.
Logy lze nastavit buď v konstruktoru Client objektu, anebo pomocí setterů.

```php
$client->setLog("some/file/log.txt");
$client->setTraceLog(function($message) use ($myLogger) {
	$myLogger->log($message);
});
```


## Problémy?
Pokud jste narazili na bug, něco nefunguje nebo máte návrh na zlepšení, přidejte issue
nebo mě bez obav [kontaktujte][5] napřímo :-)




[1]: https://github.com/csob/paymentgateway
[2]: https://iplatebnibrana.csob.cz/keygen/
[3]: https://github.com/csob/paymentgateway/tree/master/eshop-integration/keys
[4]: https://github.com/csob/paymentgateway/wiki/eAPI-v1-CZ#user-content-%C5%BDivotn%C3%AD-cyklus-transakce-
[5]: https://github.com/ondrakoupil
[6]: https://platebnibrana.csob.cz/
[7]: https://github.com/csob/paymentgateway/wiki/Testovac%C3%AD-karty
[8]: https://github.com/csob/paymentgateway/wiki/Opakovan%C3%A1-platba
[issue43]: https://github.com/csob/paymentgateway/issues/43
