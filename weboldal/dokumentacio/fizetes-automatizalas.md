# Fizetés-automatizálás – a 4 út összevetése

Belső fejlesztői / üzemeltetői leírás (nem kerül csomagba). Utolsó ellenőrzés: 2026-10-19.
Minden út ugyanabba az egyetlen belépési pontba fut: `SDH_Sales_Rendeles::fizetve($id, $forras, $tranzakcio, $osszeg, $nyers)`.
Ez idempotens (a `fizetes` táblában a forrás + tranzakció egyedi), a részfizetéseket összeadja, és amikor a befizetett
összeg eléri a bruttót, pontosan egyszer indítja a kiszolgálást (`sdh_sales_fizetve`). Ezért a módok **együtt is
használhatók**: ugyanaz a befizetés két forrásból sem számít kétszer, ha a tranzakció-kulcs egyezik (a Számlázz.hu IPN és a
lekérdezés kulcsa azonos), különböző forrásból jövő ugyanazon utalásnál viszont túlfizetés-jelzés lesz → ezért élesben
**egy elsődleges módot** válassz (`fizetes.automatizalas`), a többi legyen tartalék.

## Összefoglaló táblázat

| Szempont | 1. Banki kivonat / értesítő beolvasása | 2. Számlázz.hu (Autokassza + IPN / lekérdezés) | 3. Bankkártya (Barion / SimplePay) | 4. Kézi „Fizetve” gomb |
|---|---|---|---|---|
| Beállítás kulcsa | `fizetes.automatizalas = import` (alap) | `fizetes.automatizalas = szamlazz` | `fizetes.barion.*` / `fizetes.simplepay.*` (alapból ki) | mindig elérhető (admin) |
| Automatizáltság | félautomata: a fájlt vagy a banki értesítő szövegét be kell másolni, a párosítás és a rögzítés automatikus | teljesen automatikus, ha a bank be van kötve a Számlázz.hu-ba | teljesen automatikus | kézi |
| Késés | amilyen gyakran beolvasod (pl. naponta 1×) | a Számlázz.hu szerint „valós idejű” a banki adat; nálunk az IPN azonnal, a tartalék lekérdezés 30 percenként (`fizetes.szamlazz_lekerdezes_perc`) | másodpercek (callback / IPN + állapot-lekérdezés) | ahogy rákattintasz |
| Költség | 0 Ft | Autokassza a #digital és #profi csomag része (díj: Számlázz.hu árlista – nem mérhető itt) | tranzakciós díj a szolgáltató szerződése szerint (nem mérhető itt) | 0 Ft |
| Beállítás | semmi; a bank exportja kell (CSV vagy CAMT.053) | Számlázz.hu fiók → Autokassza bankkapcsolat (CIB, K&H, Magnet, MBH, OTP, UniCredit, Wise); IPN-cím beírása; Agent-kulcs | kereskedői szerződés, éles kulcs (Barion POSKey / SimplePay SECRET) a titkok közé, `aktiv = true`, `eles = true` | – |
| Párosítás | közlemény (pontos → normalizált), rendelésazonosító, összeg + név (csak ha egyértelmű) | a díjbekérő száma / rendelésszám a közleményben (a Számlázz.hu párosít), nálunk a díjbekérőhöz kötött rendelés | a kapu fizetés-azonosítója (a saját indításunké) | az admin választja |
| Kockázat | elfelejtett beolvasás → késik a licenc; rossz közleménynél „nem párosítható” sor (jelentésben) | a bankkapcsolat 90 / 180 naponta lejár (Számlázz.hu emlékeztet); rossz közleménynél a Számlázz.hu-ban kézzel kell párosítani; az IPN nincs aláírva (lásd lent) | visszaterhelés (chargeback), kapu-kiesés; a callback nincs aláírva (Barion) – ezért mindig API-val ellenőrzünk | emberi hiba (rossz rendelés, rossz összeg) |

## 1. Banki kivonat / értesítő (`SDH_Sales_Bank_Import`)
- Admin REST: `POST /admin/bank/import` (CSV / XML fájl vagy `tartalom`), `POST /admin/bank/level` (bemásolt banki
  értesítő e-mail / SMS szövege).
- Formátumok: OTP (fejléc nélküli export is), K&H, Erste, MBH, Raiffeisen, Wise, általános fejléces CSV; Windows-1250 / UTF-8
  (BOM-mal is) felismerés; CAMT.053 (bármely `camt.053.001.xx` névtér, DOCTYPE-os XML elutasítva – XXE-védelem).
- Tranzakció-kulcs: a banki hivatkozás (`ref:…`), ha van; különben a tétel lenyomata (`h:…`, azonos tételeknél sorszámmal) –
  így ugyanaz a kivonat kétszer beolvasva sem rögzít kétszer.
- Jelentés: `parositva`, `mar_feldolgozva`, `nem_parosithato` (ezek a `fizetes` táblába `ismeretlen` állapottal kerülnek),
  `osszeg_elteres` (rész- vagy túlfizetés), `kihagyva` (terhelés, nem HUF).

## 2. Számlázz.hu – amit a dokumentáció ténylegesen mond
Források: docs.szamlazz.hu (Számla Agent, Pénzügyi adatkapcsolat), tudastar.szamlazz.hu (Autokassza), 2026-10-19.
- **Autokassza (bankkapcsolat):** a Számlázz.hu a bekötött bank jóváírásait a kiállított számlákhoz és díjbekérőkhöz
  párosítja; a vevőnek a közleménybe a bizonylat sorszámát vagy a rendelésszámot kell írnia. Díjbekérő kifizetésekor a
  végszámla magától elkészülhet. Bankok: CIB, K&H, Magnet, MBH, OTP, UniCredit, Wise. A kapcsolat 180 (K&H, MBH, OTP,
  UniCredit) ill. 90 naponta (CIB, Magnet, Wise) megújítandó. A #digital és #profi csomag része.
  (https://tudastar.szamlazz.hu/en/gyik/autokassza-bank-integration)
- **Fizetettség lekérdezése:** a Számla Agent „Számla lekérdezés (XML)” (`action-szamla_agent_xml`, `xmlszamlaxml`) válasza
  tartalmazza a `kifizetesek/kifizetes` (dátum, jogcím, összeg) és az `osszegek/totalossz/brutto` elemeket – ebből számolja
  a számlázó modul a `SDH_Sales_Szamlazz::fizetettseg()` eredményét. A dokumentáció szerint „csak a Számlázz.hu-ban
  kiállított kimenő számlák” kérdezhetők le; a díjbekérőre ezt **nem írja le kifejezetten** – élesítés előtt egy valódi
  díjbekérővel ki kell próbálni. (https://docs.szamlazz.hu/agent/querying_xml/request, …/response)
- **Fizetési értesítés (IPN):** a Számlázz.hu hívja a fiókban megadott címet; aláírás nincs, ezért a számlázó modul
  titkos tokent tesz a címbe, szűri a küldő IP-t, egyezteti a díjbekérőt és a rendelésszámot, és ha lehet, a lekérdezéssel is
  igazolja az összeget (`/szamlazz/ipn`, a számlázó modul írja).
- **Pénzügyi adatkapcsolat:** push-alapú XML-továbbítás (számlák 15 percen belül, banki tranzakciók „időszakosan”), a
  fogadó nem kérdezhet; a banki tranzakció-üzenetben (`banktranz`) **nincs** párosított számlaszám, csak a szabad közlemény.
  Ezért ezt nem használjuk – az IPN + lekérdezés pontosabb. (https://docs.szamlazz.hu/penzugyi-adatkapcsolat/mukodes)
- **Nálunk:** `fizetes.automatizalas = szamlazz` esetén a cron (5 percenként fut, rendelésenként legfeljebb
  `fizetes.szamlazz_lekerdezes_perc` = 30 percenként) lekérdezi a nyitott díjbekérőket, és a különbözetet rögzíti
  `szamlazz` forrással, `ipn:<díjbekérő>:<eddigi összes>` kulccsal (ugyanaz, mint az IPN-é → nincs dupla).

## 3. Bankkártya
- **Barion** (`SDH_Sales_Fizetes_Barion`): `POST /v2/Payment/Start` (POSKey a törzsben), callback a `/fizetes/barion/ipn`
  címre (nincs aláírva) → `GET /v4/payment/{PaymentId}/paymentstate` (`x-pos-key` fejléc) – csak a saját indításunk
  PaymentId-jére, a saját rendelésazonosítónkkal és az API által mondott összeggel rögzítünk. Teszt: `api.test.barion.com`.
  (https://docs.barion.com/Payment-Start-v2, https://docs.barion.com/Payment-Details-v4, https://docs.barion.com/Callback_mechanism)
- **SimplePay v2** (`SDH_Sales_Fizetes_Simplepay`): minden kérés és válasz `Signature` fejléce base64(HMAC-SHA384(törzs,
  SECRET)); `start` → `paymentUrl`; IPN a `/fizetes/simplepay/ipn` címre: aláírás-ellenőrzés → `query` API-val összeg és
  orderRef → rögzítés; a válasz `receiveDate`-tel, aláírva, bájtra pontosan. Visszairányítás `r`/`s` paraméterrel
  (`/fizetes/simplepay/vissza`). Sandbox: `sandbox.simplepay.hu`. A v2.1 dokumentáció PDF-je innen nem volt letölthető
  (a hálózati proxy tiltotta) – a mezőneveket élesítés előtt a kereskedői dokumentációval egyeztetni kell.
- Sikertelen / félbemaradt fizetés: `sikertelen` állapot + egy levél újrapróba-linkkel; a cron a 60 percnél régebbi
  „fizetésre vár” rendeléseket lekérdezi, és ha nincs kész, sikertelennek jelöli. A vevő átválthat átutalásra.

## 4. Kézi „Fizetve”
`POST /admin/rendeles/{id}/fizetve` (admin, kétlépcsős gomb a felületen) → `fizetve(…, 'kezi', 'kezi-<felhasználó>-<idő>',
hátralék)`. Részösszeg is megadható (`osszeg`). Kifizetett rendelésre 409.

## Ajánlás (becslés, nem mérés)
Induláskor: **1 + 4** (költségmentes, azonnal működik); amint a Számlázz.hu-előfizetés tartalmazza az Autokasszát: **2**
elsődlegesnek, az 1-es tartaléknak. Kártya csak akkor, ha a vevők kérik – a B2B vásárlók többsége díjbekérőre utal.

## Ütemezett feladatok (`sdh_sales_cron`, 5 percenként)
- díjbekérő-emlékeztető `fizetes.emlekezteto_nap` (5) nap után, egyszer;
- fizetetlen díjbekérő törlése `fizetes.torles_nap` (30) nap után (késve érkező utalás feléleszti);
- elakadt kártyás fizetés ellenőrzése `fizetes.kartya_elakadt_perc` (60) után;
- Számlázz.hu fizetettség-lekérdezés (csak `szamlazz` módban);
- lejárt belépő linkek törlése.

## MNB QR-kód
Az átutalási QR az MNB „QR-kód útmutató” (2019-07-12, adatverzió `001`) szerint: 17 mező, mindegyik után LF, UTF-8,
`HCT`, BIC, név, IBAN, `HUF`+összeg, érvényesség (`ÉÉÉÉHHNNóóppmm+Z`, budapesti idő), közlemény; legfeljebb 345 bájt,
M hibajavítás, legfeljebb 13. verzió. https://www.mnb.hu/letoltes/qr-kod-utmutato-20190712-en.pdf (A 8/2024. MNB ajánlás és a
2025-ös EAM technikai kézikönyv nem módosítja az adattartalmat.) A kódoló saját, tiszta PHP; ellenőrizve OpenCV-dekóderrel.
