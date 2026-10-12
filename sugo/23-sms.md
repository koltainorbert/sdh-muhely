---
kulcs: sms
cim: SMS-értesítések
csoport: Kapcsolat
modul: sms
szakaszok: sms
kulcsszavak: sms, szöveges üzenet, értesítés, telefon, átjáró, sms gateway, android, sablon, szabály, állapotváltás, küldjek sms-t, popup, bejövő sms, webhook, leiratkozás, stop, csendes idő, újraküldés, hívandó, felhívtam, monitor, szegmens, ékezet, tiltólista, kézi sms
osszefoglalo: A rendszer az Android-telefonodon futó SMS-átjárón át küld SMS-t az ügyfeleknek – állapotváltáskor rákérdez (vagy magától küld), kézzel is küldhetsz, és a válaszok a munkalap üzenetei közé érkeznek.
---

## Mire való

Az **SMS** menüpont és a **Beállítások › SMS-értesítések** lapfül együtt arra való, hogy az ügyfél SMS-ben értesüljön: elkészült a javítás, megérkezett a rendelés, árajánlatot kapott. A rendszer nem külső SMS-szolgáltatón küld, hanem a **saját Android-telefonodon** futó SMS-átjáró alkalmazáson (az „SMS Gateway for Android” appon) át – a szöveg a te számodról, a te díjcsomagod terhére megy ki.

Alapból minden **ki van kapcsolva**: új telepítés magától soha nem küld SMS-t, amíg be nem kapcsolod a főkapcsolót.

Az SMS sosem akadályoz: ha a küldés elakad, a munkalap állapota ettől még megváltozik, a hiba pedig piros jelzéssel megjelenik.

## Hol találod

- **SMS menüpont:** az oldalmenüben, [SMS](modul:sms) néven. A neve mellett szám jelzi, mennyi a teendő (megválaszolandó kérdés, olvasatlan bejövő SMS, hívandó ügyfél).
- **Beállítások:** Beállítások → **SMS-értesítések** lapfül. Csak adminisztrátor módosíthatja.
- **A munkalap és a rendelés:** az RMA-csevegő beírója alatt az **SMS-ben is** jelölő, az ügyfél adatlapján és a munkalapon az **Új SMS** gomb.
- **Állapotváltáskor:** a **Küldjek SMS-t az ügyfélnek?** ablak magától megjelenik.

## Lépésről lépésre: az első bekapcsolás

1. Telepítsd az SMS-átjáró appot a céges telefonra, és kapcsold be a helyi szervert (vagy a felhős módot).
2. Nyisd meg a Beállítások → **SMS-értesítések** lapfület. Add meg az átjáró üzemmódját, címét, portját, a felhasználónevet és a jelszót.
3. Nyomd meg a **Kapcsolat-teszt** gombot (a **Teszt SMS** dobozban egy próbaüzenetet is küldhetsz a saját számodra). Zöld jelzés esetén a telefon elérhető.
4. Állítsd be a csendes időt, az újrapróbálások számát és az aláírást, majd kapcsold be a főkapcsolót, és mentsd.
5. Nyisd meg az SMS menüpont **Sablonok** nézetét, nézd át a négy „PÉLDA – jóváhagyásra vár” szöveget, és amelyiket használnád, szerkeszd át és mentsd el – mentés után válik aktívvá.
6. Ugyanott a **Mikor ajánljon SMS-t?** részben válaszd ki, melyik állapothoz melyik sablon tartozzon, és hogy kérdezzen rá, vagy automatikusan küldjön.

## Lépésről lépésre: SMS állapotváltáskor

1. Változtasd meg a munkalap (vagy a rendelés) állapotát, ahogy eddig is.
2. Ha az új állapothoz van szabály, megjelenik a **Küldjek SMS-t az ügyfélnek?** ablak, a sablon már kitöltve a munkalap adataival.
3. Írd át a szöveget, ha kell – a kijelzőn látod a karakter- és SMS-darabszámot –, majd válassz:
   - **SMS küldése** – a szöveg a sorba kerül és elmegy,
   - **Nem küldök** – a döntés rögzül, nem lesz SMS,
   - **Később döntök** – a kérdés nyitva marad, az SMS menüpont **Függő döntések** nézetében megtalálod.
4. Több eszközös munkalapnál a kérdés eszközönként jön, az SMS szövegében annak az eszköznek a neve szerepel, amelyiknek az állapota változott.

## Lépésről lépésre: kézi SMS

1. Nyomd meg az **Új SMS** gombot a munkalapon, az ügyfélnél, vagy az SMS menüpontban (ez utóbbi önálló SMS: ott a számot is te adod meg).
2. Válassz sablont, vagy írj szabad szöveget.
3. Küldés. A sor a **Monitor** nézetben azonnal látszik.

A munkalap RMA-csevegőjében az **SMS-ben is** jelölővel ugyanaz az üzenet az ügyféloldalra és SMS-ben is megy; a szálban egyetlen sor jelenik meg.

## Lépésről lépésre: bejövő SMS

1. A **Bejövő SMS** nézet tetején látod a **webhook-címet**. Másold be az SMS-átjáró app Webhooks részébe, esemény: `sms:received` (és ha akarod, `sms:delivered`, `sms:sent`).
2. Ettől kezdve az ügyfél válasza megjelenik a **Bejövő SMS** listában, és az ügyfél legutóbbi nyitott munkalapjának üzenetei közé kerül. Te e-mailben is értesítést kapsz.
3. Ha a feladó számát nem ismeri fel a rendszer, vagy az ügyfélnek nincs nyitott munkalapja, a sor **Párosítatlan**. Írd be a munkalap számát, és nyomd meg a **Hozzárendel** gombot.
4. Az ügyfél a **STOP** (vagy LEIRATKOZAS) szóval lemondhatja az automatikus SMS-eket. Kézi SMS-t ezután is küldhetsz neki.

> **Fontos:** a webhook-címben titkos kulcs van. Ne oszd meg; ha kiszivárgott, kérj **Új kulcs**at (a telefonban át kell írni a címet).

## Mezők és gombok

| Név | Mit jelent |
|---|---|
| Főkapcsoló | Ki van kapcsolva, amíg be nem kapcsolod. Kikapcsolva semmi nem megy ki. |
| Üzemmód | Helyi (a telefon a helyi hálózaton), felhős (az app szolgáltatásán át) vagy saját szerver. |
| Csendes idő | Ebben az időszakban az **automatikus** SMS-ek nem mennek ki, reggel indulnak. A kézi és a megerősített SMS-t nem érinti. |
| Újrapróbálás | Hibánál növekvő várakozással újra próbálja (5, 10, 20 perc…), az utolsó sikertelen próba után a sor a **Hívandók** közé kerül. |
| Aláírás / lábléc | Minden SMS végére kerülő szöveg. |
| Telefonszám | Az ügyfél **1. telefonmezője**. Ha azt az átjáró visszautasítja, a **2. mezőre** próbálkozik, ha az is hibás, értesítést kapsz. |

A sablonokban használható változók: `{ugyfel}`, `{szam}` (munkalapszám), `{eszkoz}`, `{allapot}`, `{szerviz}`, `{link}` (az ügyfélnek szóló követő oldal), `{hatarido}`, `{osszeg}`. Ismeretlen vagy üres változó esetén a rendszer nem küldi el a levelet, hanem rákérdez.

## Szabályok, amiket a rendszer betart

- A telefonszámot egységes alakra hozza (`06 30 …` → `+36 30 …`). Az üres, a „0” és az érvénytelen szám nem kap SMS-t, a hiba pirosan látszik.
- Ugyanaz az SMS nem megy ki kétszer: a sorban minden küldésnek egyedi azonosítója van, a dupla kattintás sem küld másodikat.
- Ha a telefon nem válaszol időben, a rendszer **nem küldi újra** magától (lehet, hogy az SMS elment) – az ilyen sor a **Hívandók** közé kerül.
- A sablonok változtatáskor új verziót kapnak; az elküldött SMS mindig megőrzi a sablon verzióját és a pontos szöveget.
- Az SMS-napló csak bővül, sor nem törlődik belőle.
- A beállításokat és a sablonokat csak adminisztrátor módosíthatja, az SMS-t a normál munkajoggal lehet küldeni.
- Ékezetes szöveg (á, í, ó, ú, ő, ű) egy SMS-ben 70 karakter, hosszabb szövegnél 67 karakter / darab – több darab több SMS-ként számlázódik. Egy üzenet legfeljebb 480 karakter lehet.

## Ha valami nem megy

- **Nem megy ki SMS:** nézd meg a Monitort és a Beállítások › SMS-értesítések állapotsorát. Ellenőrizd, hogy a főkapcsoló be van-e kapcsolva, a telefon bekapcsolt, és az app szervere fut.
- **Piros jelzés a sorban:** a **Hívandók** nézetben látod, mi a hiba. Hívd fel az ügyfelet, majd nyomd meg a **Felhívtam** gombot.
- **Nem jön a bejövő SMS:** a Bejövő SMS oldalon ellenőrizd a webhook-címet; helyi telepítésnél a címben a gép hálózati címe legyen, ne a „localhost”.
- **Nem jelenik meg a kérdés állapotváltáskor:** csak azokhoz az állapotokhoz jön, amelyekhez a **Sablonok** nézetben szabályt állítottál, és amelyek sablonja aktív.

## GYIK

### Kell hozzá külön SMS-szolgáltató?

Nem. A saját Android-telefonod SIM-kártyáján megy ki az SMS, a telefon díjcsomagja szerint.

### Elküldi a rendszer magától az SMS-t?

Csak akkor, ha egy állapothoz **automatikus** működést állítottál. Alapból minden állapotváltásnál rákérdez.

### Miért „PÉLDA – jóváhagyásra vár” a sablonok neve?

Mert a minták kikapcsolva érkeznek: míg át nem nézed és el nem mented őket, nem használja őket a rendszer.

### Mi történik, ha az ügyfél nem kap SMS-t?

Ha a telefon visszautasítja, a rendszer a második telefonmezőre próbálkozik. Ha az sem megy, értesítést kapsz, és a sor a **Hívandók** között várja, hogy felhívd az ügyfelet.

### Hogyan tiltom le egy ügyfélnek az automatikus SMS-t?

Az ügyfél a **STOP** szóval maga is leiratkozhat. Az automatikus SMS-ek ezután nem mennek neki, a kézi SMS igen.
### Mit csináljak, ha az SMS „Hívandók” közé került?

Hívd fel az ügyfelet, mondd el neki az üzenetet, majd nyomd meg a **Felhívtam** gombot. Ettől a sor kikerül a teendők közül, de a naplóban megmarad.

### Kimehet a szöveg kitöltetlen `{változóval}`?

Nem. Ha a sablon egyik változójának nincs értéke (például a lapnak még nincs száma, ezért nincs link), a rendszer nem küldi el magától, hanem rákérdez, és a küldés előtt neked kell kitölteni.

### Mennyibe kerül egy hosszabb SMS?

Az ékezetes szöveg 70 karakterig egy, utána 67 karakterenként újabb SMS-nek számít. A szerkesztőben a számláló mutatja a darabszámot. Egy üzenet legfeljebb 480 karakter lehet.

### Látja az ügyfél az SMS-t az ügyféloldalon?

Igen, ha a munkalap üzenetei között van: a munkalaphoz kötött SMS-ek bekerülnek a munkalap üzenetszálába, és a bejövő válasz is oda érkezik.

### Mi van, ha az ügyfélnek nincs telefonszáma?

Az **Új SMS** és az **SMS-ben is** jelölő ilyenkor nem küld, és kiírja az okát. A munkalap állapota ettől még változik.

## Túra: sms

- `.sdh-szolg-pillek` | Nézetek | Itt váltasz a Monitor, a Bejövő SMS, a Sablonok, a Hívandók és a Függő döntések között. A számok a teendőket jelzik.
- `.sdh-tabla--sms` | Az SMS-lista | Minden SMS egy sor: mikor, kinek, mi a szöveg, és hol tart (vár, elküldve, kézbesítve, hiba). A piros sor figyelmet kér.
- `.sdh-sms-uj` | Új bejövő SMS | A kiemelt sor olvasatlan bejövő üzenet. Párosítatlan sornál beírhatod a munkalap számát, és hozzárendelheted.
