---
cim: SMS-értesítés „kész a javítás” üzenettel – a saját androidos telefonról, SMS-kapu nélkül
leiras: Hogyan küldj automatikus vagy jóváhagyott SMS-t az ügyfeleknek a bolti androidos telefonról? Beállítás, sablonok, csendes idő, leiratkozás és a hibák kezelése.
kategoria: Ügyfélkommunikáció
cimkek: [SMS értesítés szerviz, javítás kész értesítés, SMS gateway, ügyfélértesítés]
datum: 2026-08-10
kulcsszo: SMS értesítés szerviz
seo_cim: SMS-értesítés szervizeknek a saját androidos telefonról
---

**Röviden:**

- A „kész már a telefonom?” hívások jó része megelőzhető, ha az ügyfél SMS-t kap, amikor elkészült a javítás.
- SMS-t nemcsak havidíjas SMS-szolgáltatón keresztül lehet küldeni: egy androidos telefon egy SMS-átjáró alkalmazással a bolt saját számáról küld, a díjcsomag szerint.
- Kezdd **jóváhagyással**: a rendszer állapotváltáskor rákérdez, küldjön-e SMS-t. Az automatikus küldést csak akkor kapcsold be, amikor a sablonok beváltak.
- Állíts be csendes időt, és tartsd tiszteletben, ha az ügyfél leiratkozik.

## Miért SMS?

Az e-mailt sok ügyfél ritkán olvassa, a telefonhívás pedig mindkét félnek időbe kerül. Az SMS rövid, azonnal megérkezik, és nem kell hozzá alkalmazás. Egy „Kedves Kovács Péter, a(z) 1234-es munkalapon szereplő készüléke elkészült, átvehető nyitvatartási időben.” üzenet egy telefonhívást vált ki, és az ügyfél is jobban szereti.

## Két út: SMS-szolgáltató vagy saját telefon

| Szempont | SMS-szolgáltató (SMS-kapu) | Saját androidos telefon |
|---|---|---|
| Feladó | Szolgáltatói szám vagy név | A bolt saját telefonszáma |
| Költség | Havidíj és/vagy darabár a szolgáltatónál | A SIM-kártya díjcsomagja szerint |
| Válasz az ügyféltől | Szolgáltatótól függ | A bolt számára érkezik, a rendszer feldolgozhatja |
| Beállítás | Fiók a szolgáltatónál | Alkalmazás a telefonon, kapcsolat a rendszerrel |
| Függőség | A szolgáltató elérhetősége | A telefon legyen bekapcsolva, térereje legyen |

A {termeknev} a második utat követi: az [SMS-modul](/funkciok/sms/) a bolti androidos telefonon futó SMS-átjáró alkalmazáson keresztül küld. Így az ügyfél a bolt ismert számáról kapja az üzenetet, és ha válaszol, a válasz a munkalap üzenetei közé érkezik.

## Beállítás lépésről lépésre

### 1. Telefon és alkalmazás

Szánj rá egy külön, bolti androidos telefont. Telepítsd az SMS-átjáró alkalmazást, és kapcsold be a helyi szerverét (vagy a felhős módot). A telefon maradjon töltőn, és legyen térereje.

### 2. Kapcsolat a rendszerrel

A rendszer SMS-beállításainál add meg az átjáró címét, portját, a felhasználónevet és a jelszót, majd próbáld ki egy kapcsolatteszttel és egy teszt SMS-sel a saját számodra.

### 3. Sablonok

Írd meg a leggyakoribb üzeneteket:

- **Elkészült**: „Kedves {ugyfel}! A(z) {szam} munkalapon lévő {eszkoz} elkészült, átvehető. Fizetendő: {osszeg}. {szerviz}”
- **Árajánlat**: „Kedves {ugyfel}! A(z) {eszkoz} javításáról árajánlatot küldtünk: {link}”
- **Megérkezett az alkatrész / rendelés**
- **Határidő módosult**

A változók helyére a rendszer a munkalap adatait írja. Ha egy változónak nincs értéke (például még nincs link), jó, ha a rendszer nem küldi el magától a hiányos üzenetet, hanem rákérdez.

### 4. Szabályok: mikor ajánljon SMS-t?

Rendeld hozzá a sablonokat az állapotokhoz: „Elkészült” állapotnál az Elkészült sablon, „Árajánlat elküldve” állapotnál az árajánlat. Kezdetben állítsd **rákérdezésre**: állapotváltáskor felugrik egy ablak a kitöltött szöveggel, és te döntesz, elküldöd-e, átírod-e, vagy kihagyod.

### 5. Csendes idő

Senki nem örül egy szervizes SMS-nek este tízkor. A csendes időben az automatikus üzenetek nem mennek ki, hanem reggel indulnak. A kézi és a jóváhagyott SMS-t ez nem érinti.

## Az ékezetek és a hossz

Az SMS hossza függ a karakterektől. Ékezetes szövegnél egy SMS 70 karakter, hosszabb szövegnél 67 karakterenként újabb darab, és minden darab külön SMS-ként számlázódik. Ezért:

- a sablonok legyenek rövidek,
- a link legyen a végén,
- az aláírás legyen tömör (csak a szerviz neve).

A szerkesztőben a számláló mutatja, hány SMS-darab lesz a szöveg.

## Bejövő SMS: amikor az ügyfél válaszol

Az ügyfél gyakran visszaír: „Holnap délután megyek.” Ha ez a bolti telefonon marad, senki nem látja. Ha az átjáró továbbítja a rendszernek, a válasz az ügyfél nyitott munkalapjának üzenetei közé kerül, és a kollégák is látják. Ha a feladót nem ismeri fel a rendszer, a válasz „párosítatlan”, és kézzel hozzárendelhető egy laphoz.

## Leiratkozás

Az ügyfélnek joga van nem kérni az automatikus üzeneteket. Ha a STOP szóval válaszol, a rendszer jegyezze meg, és automatikus SMS-t többé ne küldjön neki. Kézi, egyedi üzenetet (például egy fontos kérdés a javításról) ezután is küldhetsz.

## Ha nem megy ki az SMS

A telefon néha nem éri el a hálózatot, vagy a szám érvénytelen. Ilyenkor:

- a rendszer próbálja újra növekvő várakozással,
- ha a telefon nem válaszolt, **ne** küldje újra vakon (lehet, hogy elment),
- a sikertelen üzenet kerüljön egy „hívandók” listára, hogy valaki felhívja az ügyfelet.

Fontos, hogy az SMS hibája ne akassza meg a munkát: a munkalap állapota attól még változzon meg.

## Mikor kapcsold be az automatikus küldést?

Az első hetekben minden SMS-t te hagyj jóvá. Közben figyeld:

- melyik sablont írod át gyakran (akkor a sablon nem jó),
- melyik állapotnál hagyod ki rendszeresen az SMS-t (akkor ott nem kell szabály),
- érkezik-e panasz vagy kérdés az üzenetek miatt.

Ha egy sablon két-három hétig változtatás nélkül megy ki, azt az állapotot átállíthatod automatikusra. A ritkább, érzékenyebb üzenetek (például árajánlat, határidő-módosítás) maradhatnak jóváhagyásosak.

## Több eszköz egy munkalapon

Ha egy ügyfél két készüléket hozott, és csak az egyik készült el, az SMS-nek arról kell szólnia, amelyik kész. Jó, ha a rendszer eszközönként kérdez rá, és a szövegben annak a készüléknek a neve szerepel, amelyiknek az állapota változott. Különben az ügyfél bejön mindkettőért, és csak az egyiket viheti.

## Ellenőrzőlista

- Külön bolti androidos telefon, töltőn, térerővel
- Átjáró alkalmazás beállítva, kapcsolatteszt sikeres
- 3–4 rövid sablon, változókkal
- Szabályok állapotokhoz, kezdetben rákérdezéssel
- Csendes idő beállítva
- Bejövő SMS továbbítása beállítva
- A csapat tudja, mit kell tenni a „hívandók” listával

## Adatvédelem

Az SMS-ben személyes adat utazik (név, készülék, összeg). Csak annyit írj bele, amennyi szükséges, és a munkalap részleteit inkább egy linken mutasd meg, ahová az ügyfél belép. Az elküldött SMS-ek naplója visszakereshető, ami vitás esetben is hasznos.

## Összegzés

Az SMS-értesítés a legegyszerűbb módja annak, hogy kevesebbet csörögjön a telefon. A saját androidos telefonnal nincs szükség külön SMS-szolgáltatóra, és az ügyfél a bolt ismert számáról kapja az üzenetet. Kezdd jóváhagyással, rövid sablonokkal, és csak akkor automatizálj, ha a szövegek beváltak.

A {termeknev} [SMS-funkcióit](/funkciok/sms/) a [demóban](/demo/) is megnézheted (ott az SMS csak szimuláció). A modulok és árak az [Árak](/arak/) oldalon vannak.
