# PHP knihovna pro práci s ČSOB platební bránou

Pomocí této knihovny lze pohodlně integrovat [platební bránu ČSOB][6] do vašeho e-shopu 
nebo jiné aplikace v PHP bez nutnosti přímo pracovat s jejím API, volat 
nějaké metody, ověřovat podpisy apod.

Podrobnosti o API platební brány, o generování klíčů a
o jednotlivých krocích zpracováná platby najdete na [https://github.com/csob/paymentgateway][1].
Testovací platební karty jsou na [wiki zde][7]

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

Pokud potřebujete více detailů než jen číslo stavu, dejte druhý argument na `true`.


### Potvrzení, zrušení, vrácení prostředků

Metoda `paymentReverse()` zruší dosud nezprocesovanou platbu, `paymentClose()` potvrzí platbu 
a `paymentRefund()` vrátí již proběhlou platbu zpět odesílateli. 
Pozor, platba musí být ve správném stavu, jinak nastane chyba a vyhodí se výjimka. Pokud nastavíte 
druhý argument na `true`, tak se tato konkrétní chyba tiše ignoruje a metoda jen vrátí `null`.
Všechny ostatní chyby nadále vyhazují výjimku.

```php
$client->paymentReverse($payId);
$client->paymentClose($payId);
$client->paymentRefund($payId);
```

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


## Logování

`Client` má vestavěné jednoduché logování. Jeden log slouží pro bussiness-level zprávy
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