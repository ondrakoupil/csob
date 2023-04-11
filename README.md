# PHP knihovna pro práci s ČSOB platební bránou

[![Build Status](https://travis-ci.org/ondrakoupil/csob.svg?branch=master)](https://travis-ci.org/ondrakoupil/csob.svg?branch=master)
[![Number of downloads](https://img.shields.io/packagist/dt/ondrakoupil/csob-eapi-paygate.svg)](https://img.shields.io/packagist/dt/ondrakoupil/csob-eapi-paygate.svg)
[![Current version](https://img.shields.io/packagist/v/ondrakoupil/csob-eapi-paygate.svg)](https://img.shields.io/packagist/v/ondrakoupil/csob-eapi-paygate.svg)
[![Licence](https://img.shields.io/packagist/l/ondrakoupil/csob-eapi-paygate.svg)](https://img.shields.io/packagist/l/ondrakoupil/csob-eapi-paygate.svg)

Pomocí této knihovny lze pohodlně integrovat [platební bránu ČSOB][6] do vašeho e-shopu
nebo jiné aplikace v PHP bez nutnosti přímo pracovat s jejím API, volat
nějaké metody, ověřovat podpisy apod.

**[English readme is here][english]**.

Podrobnosti o API platební brány, o generování klíčů a
o jednotlivých krocích zpracováná platby najdete na [https://github.com/csob/platebnibrana][1].
Testovací platební karty jsou na [wiki zde][7]

Pozor pozor! Často na to někdo naráží, že to raději vypíchnu tady nahoře.
**Používá se zde VÁŠ soukromý klíč a [veřejný klíč BANKY][3].** Ne obráceně. Ne váš veřejný klíč.

## Novinky

### ČSOB API 1.9
V létě 2022 vydala banka API verze 1.9, které kromě několika přidaných funkcí zavádí také některé změny.
Používáte-li tedy knihovnu ve své aplikaci a používáte adresu nejnovější verze `GatewayUrl::PRODUCTION_LATEST`, tak pozor, updatem knihovny na verzi 1.9
dojde i k update na API 1.9 a některé věci tedy mohou fungovat jinak.

Novinky:

- Změnily se metody pro práci s One click platbami. Nově se musí volat `paymentOneClickInit()` a potom `paymentOneClickProcess()`
- Do objektu `Payment` pro metody `paymentInit()` a `paymentOneClickInit()` je nově možné předat mnohem, mnohem více dat o zákazníkovi a celé transakci. Tato data banka předá vydavateli karty, 
  který podle svého interního algoritmu rozhodne, zda je nutné použít 3D ověření nebo ne. Předáním těchto pomocných dat údajně zvýšíte zákazníkův komfort, protože je pak větší šance,
  že vydavatel transakci schválí jako bezpečnou, neboť dostatečně odpovídá jeho dosavadnímu profilu chování a placení na internetu.
  Všechna tato nová data nastavíte pomocí `$payment->setCustomer($customer)` a `$payment->setOrder($order)` a pomocí tříd z namespacu `Metadata`. 

Jako výchozí adresa je testovací platební brána aktuální verze (nyní tedy 1.9 - `GatewayUrl::TEST_1_9`).

Doporučuji používat konstanty třídy GatewayUrl, které obsahují URL jednotlivých verzí API.

```
$config->url = GatewayUrl::TEST_1_8;
$config->url = GatewayUrl::PRODUCTION_1_9;
$config->url = GatewayUrl::PRODUCTION_LATEST;
```

## Instalace

Nejjednodušeji nainstalujete pomocí Composeru:

`composer require ondrakoupil/csob-eapi-paygate`

Pokud nepoužíváte Composer, stačí někam nakopírovat soubor `dist/csob-client.php` a includnout ho - obsahuje všechny potřebné třídy pohromadě.
Soubor si stáhněte [přímo z Githubu](https://raw.githubusercontent.com/ondrakoupil/csob/master/dist/csob-client.php), v exportovaném balíčku není.


## Použití

Kromě této knihovny se bude hodit:

- Merchant ID - anonymní ID lze vygenerovat na stránce [keygenu][2], anebo použijte to ID, které přidělí banka
- Klíče pro podepisování a verifikaci podpisů - opět získáte v [keygenu][2]. Při implementaci použijete jen svůj privátní klíč. Ten veřejný
  odešlete přes keygen bance a pak na něj můžete klidně zapomenout. 
- Veřejný klíč banky - lze stáhnout z [Githubu ČSOB][3]. Pozor, liší se pro testovací a ostrou bránu.

Knihovna se skládá z tříd:

- Client - hlavní třída, se kterou budeme pracovat
- Config - nastavení parametrů komunikace s bránou, klíčů, Merchant ID atd. a různých výchozích hodnot
- Payment - představuje jednu platbu
- Crypto - zajišťuje podepisování a ověřování podpisů
- Extension - třída představující rozšíření. Lze používat buď přímo třídu Extension, anebo jednotlivé odděděné specializované třídy.

Všechny třídy jsou v namespace `OndraKoupil\Csob`, je tedy třeba je na začátku souboru uvést pomocí
`use`, anebo vždy používat celé jméno třídy včetně namespace. Zde uvedené příklady předpokládají,
že jste už použili `use`.


### Nastavení

Nejdřív ze všeho je třeba vytvořit objekt `Config` a nastavit v něm potřebné hodnoty.
Ten pak předáte objektu `Client` a voláte jeho metody, které odpovídají jednotlivým
metodám, které API normálně nabízí.

```php
$config = new Config(
	"My Merchant ID",
	new KeyFileProvider("path/to/my/private/key/file.key"),
	new KeyFileProvider("path/to/bank/public/key.pub"),
	"My shop name",

	// Adresa, kam se mají zákazníci vracet poté, co zaplatí
	"https://www.my-eshop.cz/return-path.php",

	// URL adresa API - výchozí je adresa testovacího (integračního) prostředí,
	// až budete připraveni přepnout se na ostré rozhraní, sem zadáte
	// adresu ostrého API. Nezapomeňte také na ostrý veřejný klíč banky.
	GatewayUrl::TEST_LATEST
);

$client = new Client($config);
```

**Pozor - používá se zde VÁŠ soukromý klíč a veřejný klíč BANKY.** A také nezapomeňte, že testovací a ostré API má odlišný veřejný klíč.

Config umožňuje nastavit i nějaké další parametry a různé výchozí hodnoty, viz [dokumentace][10].

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

Volitelně lze od API 1.9 objektu Payment předat metadata o uživateli, což zjednoduší schválení transakce u vydavatele karty.

```
$customer = new Customer();
$customer->name = 'John Rambo';
$customer->email = 'john@rambo.cz';
$payment->setCustomer($customer);
```

### Zaplacení (payment/process)

Po úspěšném založení platby je třeba přesměrovat prohlížeč zákazníka na platební bránu,
jejíž adresu vygeneruje `getPaymentProcessUrl()`. Jako pomůcka je tu rovnou i metoda
`redirectToGateway()`, která toto přesměrování rovnou provede.


```php
$url = $client->getPaymentProcessUrl($payment);
redirectBrowserTo($url);  // fiktivní funkce pro přesměrování

// NEBO

$client->redirectToGateway($payment);
terminateApp();  // fiktivní funkce pro ukončení skriptu
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

API 1.8 tuto metodu přejmenovalo na `echo/customer`, knihovna zvolí vhodný endpoint sama, v PHP ale volejte vždy `customerInfo()`

### Payment/checkout

Jde o zatím neveřejnou funkce platební brány. Umožní zobrazit minimalistický iframe a celé
odbavení platby a objednávky udělat v iframu vloženém do vašeho webu namísto přechodu na platební bránu.

Pro aktivaci je nutné kontaktovat ČSOB a požádat o aktivování této funkce. Při té příležitosti
vám také pošlou podrobnější dokumentaci.

Poté, co je funkce povolená, můžete místo `payment/process` volat `payment/checkout`.

Pokud pro vaše `merchantId` není funkce povolená, brána zobrazí chybovou hlášku.

```php
$url = $client->getPaymentCheckoutUrl($payment, $oneClickPaymentCheckbox);
redirect($url);
```

`$payment` může být Payment objekt anebo prosté PayID, obdobně jako u jiných metod.

Pro `$oneClickPaymentCheckbox` použijte jednu z možností popsanou v PHPDocu zdrojového kódu metody.

Další parametry jsou popsané v dokumentaci metody.

Díky @rootpd.

### Platební tlačítka

Metody paymentButton() pro API < 1.8 a buttonInit() pro API >= 1.8 slouží k platbě tzv. platebním tlačítkem.
Podrobnější parametry jsou v dokumentaci obou metod.

Tyto metody vrací pole s různými daty, mimo jiné `redirect`, ve kterém je uvedena adresa,
na níž máte uživatele přesměrovat. Nepoužívejte tedy redirectToGateway() nebo něco podobného,
ale přímo přesměrujte uživatele na adresu, kterou banka vrátí.

### Opakované platby (One click payments)

Počínaje API 1.5 lze provádět opakované platby. Jak přesně to funguje se dočtete na
[Wiki ČSOB][8]. Ve verzi API 1.9 je to zhruba takto: 

- necháte zákazníka autorizovat platební šablonu tak, že provedete normálně
  celý platební proces jako obvykle, ale objektu Payment před voláním `paymentInit()`
  nastavíte `$payment->setOneClickPayment(true)`
- zákazník pak zadá číslo karty, kód a provede 3D ověření jako u běžné platby
- vy si uložíte PayID, abyste se na tuto autorizovanou transakci mohli odkazovat. PayID zde bude sloužit jako jakési ID šablony transakce, kterou můžete později sami zopakovat i bez zákazníka.
- od API verze 1.8 existuje `paymentOneClickEcho()` pro ověření, zda je originální PayID stále použitelné.
- pak můžete kdykoliv zavolat metodu `paymentOneClickInit()` s PayID původní transakce
  a s novým Payment objektem. Tím se založí nová platba. Následným zavoláním `paymentOneClickProcess()`
  se platba provede (v API 1.8 a starších to bylo `paymentOneClickStart()`)
- nová platba dostane své vlastní PayID a lze s ní pracovat jako s jakoukoliv jinou platbou
- Od API 1.9 je možné přidávat metadata pomocí `$payment->setCustomer()` a `$payment->setOrder()` obdobně jako u `paymentInit`

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

## Custom request
Pokud potřebujete poslat požadavek na API metodu, která není v této knihovně speciálně implementovaná 
(zatím např. metody Masterpass, ApplePay nebo MallPay), lze využít customRequest() metodu. Je potřeba jen pohlídat,
 v jakém pořadí jsou zadána vstupní data a v jakém pořadí jsou data v odpovědi skládána
 do řetězce pro ověření podpisu odpovědi.
 
```php
$client->customRequest(

    // URL, jenom konec za společnou adresou API, např. "payment/init"
    $methodUrl,                              
    
    // Array se vstupními daty. Pořadí položek v array je důležité.
    // Na vhodná místa lze vložit prázdné dttm a merchantId, doplní se automaticky.
    $inputPayload,                          
    
    // Array s názvy políček v odpovědi v požadovaném pořadí dle dokumentace.
    // U vnořených objektů a polí lze pracovat s tečkou.
    // Například: array('payId', 'dttm', 'resultCode', 'resultMessage', 'redirect.method', 'redirect.url')
    $expectedOutputFields = array(), 
    
    // Volitelně nějaké extensions
    $extensions = array(), 
    
    $method = "POST",     
    
    // Zalogovat vždy podrobně celou návratovou hodnotu z API?
    $logOutput = false,     
    
    // Pokud z nějakého důvodu selhává ověření podpisu, lze ho takto úplně deaktivovat.
    // Nicméně pak je nutné ručně takovou situaci ošetřit.
    $ignoreInvalidReturnSignature = false
)
```

V `$expectedOutputFields` může určitá hodnota začínat na ?, v takovém případě s epovažuje za nepovinnou a není-li v odpovědi banky
vůebc zahrnuta, nebude do základu podpisu zahrnutá. Pokud nezačíná na ?, do základu podpisu se dá prázdný řetězec. 

## Extension

Rozšíření jsou implementována pomocí třídy Extension a volitelně odděděných tříd (momentálně jen pro EET).
Objekty této třídy pak lze přikládat do každé volané metody. Do požadavků se pak budou přidávat dodatečná data,
a u odpovědí se budou automaticky validovat podpisy odpovědí.

Každé rozšíření má své extension ID (definované v dokumentaci od banky).

Pokud má rozšíření přidat nějaká další data do **požadavku**, je třeba zavolat `setInputData()` a předat
 dodatečná data do požadavku jako array. Pořadí prvků v array je důležité, podle něj se sestaví
 signature řetězec a podpis. Vždy se podívejte do dokumentace, v jakém pořadí mají parametry být, a
 to dodržujte. Políčka `dttm` a `extension` můžete klidně nechat prázdné (false nebo null), hodnota se doplní automaticky,
 ale je nutné je do array na patřičné místo dát.
 
Alternativně můžete třídu oddědit a implementovat si po svém metodu `getRequestSignatureBase()`,
která by měla vracet řetězec sloužící jako základ pro podpis.
 
Pokud rozšíření přidává rozšíření nějaká data do **odpovědi** z API, tak se k těmto datům dostanete pomocí 
metody `getResponseData()`. 

Je možné nastavit ověření podpisu odpovědi pomocí `setExpectedResponseKeysOrder()`. Této metodě předáte array s názvy políček
 z odpovědi v tom pořadí, v jakém mají být v podepsaném řetězci. Alternativně můžete oddědit Extension do vlastní třídy
 a implementovat metodu `verifySignature()` po svém.  
  
Pokud se nedaří ověřit podpis odpovědi, můžete pomocí `setStrictSignatureVerification(false)` vypnout ověřování podpisu 
pro dané rozšíření. Po zavolání API metody je pak možné se přes `isSignatureCorrect()` doptat, zda byl podpis v pořádku,
 a pokud nebyl, nějak to řešit po svém.
 
Pro jedno volání metody je možné předat více rozšíření, stačí do patřičného parametru metody Client objektu předat array objektů Extension, ne jen jediný objekt.
  
### DatesExtension
Pokud máte aktivované rozšíření trxDates, je v metodě paymentStatus() možné předat objekt třídy DatesExtension.
Po zavolání metody se pak z DatesExtension dají přečíst požadovaná data jako DateTime objekty.

```php
$extension = new DatesExtension();
$status = $client->paymentStatus($payment, true, $extension);
echo $extension->getCreatedDate()->format("j. n. Y");
```

Dostupné jsou metody `getCreatedDate()`, `getSettlementDate()` a `getAuthDate()`, které vracejí DateTime anebo null,
pokud dané datum v odpovědi nebylo vůbec uvedené. Také pozor, settlementDate je s přesností pouze na dny, ne na sekundy. 

### CardNumberExtension
Pokud máte aktivované rozšíření maskClnRP, je v metodě paymentStatus() možné předat objekt třídy CardNumberExtension.
Po zavolání metody se pak z CardNumberExtension dá přečíst maskované číslo karty a její expirace. 
Nezapomeňte ale na to, že toto rozšíření je dostupné pouze pro "one click" platby.

```php
$extension = new CardNumberExtension();
$status = $client->paymentStatus($payment, true, $extension);
echo $extension->getMaskedCln() . ' ' . $extension->getExpiration();
``` 
 
Dostupné metody jsou `getMaskedCln()`, `getLongMaskedCln()` a `getExpiration()`
 
## EET

EET je již od verze API 1.9 zrušené. Hurá 😀

Pokud je z nějakého důvodu chcete používat, je třebaa použít API 1.8 nebo 1.7.


## Problémy?
Pokud jste narazili na bug, něco nefunguje nebo máte návrh na zlepšení, přidejte issue
nebo mě bez obav [kontaktujte][5] napřímo :-)


[1]: https://github.com/csob/platebnibrana
[2]: https://iplatebnibrana.csob.cz/keygen/
[3]: https://github.com/csob/platebnibrana/tree/main/keys
[4]: https://github.com/csob/platebnibrana/wiki/Průběh-platby#user-content-Životní-cyklus-transakce-
[5]: https://github.com/ondrakoupil
[6]: https://platebnibrana.csob.cz/
[7]: https://github.com/csob/platebnibrana/wiki/Testovac%C3%AD-karty
[8]: https://github.com/csob/platebnibrana/wiki/OneClick-platba
[9]: https://github.com/csob/paymentgateway/wiki/Specifikace-API-roz%C5%A1%C3%AD%C5%99en%C3%AD-pro-EET/61e54d1d966f5ce05d1c903f50d5a5082b676285
[10]: docs/class-OndraKoupil.Csob.Config.html
[issue43]: https://github.com/csob/paymentgateway/issues/43
[english]: README.en.md
