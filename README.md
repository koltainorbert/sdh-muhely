# SDH Műhely

Belső műhely- és ügyfélkezelő rendszer (CRM + munkalap) az **SDH Szerviz** számára.
A régi *MunkaLap* desktop program (Java/Derby) webes utódja, WordPress plugin formában.

---

## Mi ez

Önálló, belső WordPress-telepítésben futó plugin. **Nem** a nyilvános
`sdh.hu`-n él, hanem egy elzárt belső rendszerben. A cél a MunkaLap
funkcionalitásának átültetése, modulonként:

- **Ügyfél** – törzsadat, több címtípus, kategória, kedvezmény
- **Eszköz** – ügyfélhez kötve, gyártó/típus, IMEI, sorozatszám, garancia
- **Munkalap** – állapot, felelős, határidő, fizetés
- **Hiba** – munkalaphoz kötött hibajegyek
- **Tétel** – termék és szolgáltatás, beszerzési ár, haszonkulcs, készlet
- **Napi árukiadás** – pultos eladás javítás nélkül, helyszínenként; **Rendelések** – ügyfélnek rendelt áru, beszállítóval, értesítéssel
- **Házipénztár** – napi kassza, címletes zárás, napló, riport, statisztika, egyeztető ügynök, Excel-import
- **Csapat** – belső üzenetek kollégának, csoportnak, mindenkinek; fontos üzenet felugró ablakban; kollégák, csoportok
- **Asszisztens** – AI (Claude) segítő minden oldalon: kérdez-felel, vezet, tanul; csak jóváhagyással módosít, mentéssel
- **Súgó** – bemutató, részletes dokumentáció, kérdés–válasz, oldaltúrák; a `sugo/` mappa fejezeteiből épül, és az asszisztens is ebből tanul. **Ha a rendszer változik, a fejezet vele változik** (szabályok: `sugo/OLVASSEL.md`, őre: `tesztek/t36-sugo.php`)
- **Nyomtatás** – átvételi elismervény, munkalap, belső munkalap, árajánlat, szállítólevél, bianko lap, pénztárzárás, listák: előnézet, példányszám, PDF, e-mail; minden nyomtatvány szerkeszthető a Beállításokban
- **Modulok** – a fő funkciók modulonként ki- és bekapcsolhatók (külön értékesíthetők); telepítői zár kóddal. Az alaprendszer (Áttekintés, Munkalapok, Ügyfelek, Eszközök, Szolgáltatások, Beállítások, Súgó, Mentés) mindig fut
- *(később)* raktár, határidő

A meglévő **SDH Platform** plugin (beszállítói katalógus → rendelés) külön
fut; a kettő összekötése egy későbbi lépés.

---

## Fejlesztői környezet

| | |
|---|---|
| **Helyi stack** | Local by Flywheel |
| **PHP** | 8.1+ |
| **WordPress** | 6.4+ |
| **Verziókezelés** | Git + privát GitHub repo |
| **Munkagépek** | munkahelyi asztali + otthoni, git-en keresztül szinkronban |

A repo gyökere maga a plugin mappa:
`wp-content/plugins/sdh-muhely/`

### Napi munkamenet

```bash
# Munka kezdetén – behúzom a másik gépen készült változásokat
git pull

# ... fejlesztés ...

# Munka végén
git add -A
git commit -m "Rövid, beszédes üzenet"
git push
```

Otthon ugyanez, fordított sorrendben: előbb `pull`, végén `push`.
Mindig a végén tolj fel, hogy a másik gép a `pull`-lal mindent megkapjon.

---

## Verziónapló

### 0.59.6
- **Automatikus mentés** a rekord-ablakokban (munkalap, rendelés, árukiadás, ügyfél, eszköz, termék, szolgáltatás):
  tétel/hibasor, választó, pipa azonnal; beírt mező elhagyáskor; megjegyzés a gépelés megállása után. Az ablak nyitva
  marad, bezáráskor a lista frissül (nem vált az Áttekintésre). Új (még nem mentett) és zárolt lapon nincs; számla,
  fizetés, küldés soha. A szerver visszaadja az új sorok azonosítóját (`uj_sorok`), így nincs duplázás. Mentett lapon a
  „Mégsem" felirata „Bezárás".
- **Számla ablaka gyorsabban**: mentett lapnál egyetlen kérés (nincs mentés + újratöltés); mentetlen változásnál előbb a
  számla nyílik, a munkalap a háttérben, „Betöltés…" nélkül frissül (`csendesFrissit`). A nagy ablakok betöltés közben
  is a végleges méretükben állnak.
- **Lezáráskor** a nyomtatási ajánlatban **Számlázz.hu számla** és **Számla-előkészítő (PDF)** gomb.
- **Sebesség 40 000 munkalapnál**: új indexek (`fajta_szam`, `fajta_keszult`, DB_VERSION 0.38.0), a lista rendezése
  indexből, a darabszám keresés nélkül összekapcsolás nélkül. Mérve (SQLite, helyi gép): munkalap-lista 139–172 ms → 71–90 ms.
- A munkalap Eszköz fülén a mockup nem lóg a fejlécbe.

### 0.59.5
- **Záró számla bevizsgálási díj után**: ha a díj levonódik (javítás), a számla ablakában és a kiállított számlán sem
  a díj, sem a levonás nem szerepel – a díjat az ügyfél már kifizette és számlát kapott róla. A javítás tétele a saját
  nevén, a még fizetendő összeggel áll (10 000 Ft termék − 8 000 Ft díj → 2 000 Ft); több tételnél a legnagyobb
  értékűből vonódik le. A helyi Számla-előkészítő PDF is így készül. A munkalap tételei nem változnak (`Szamla::dij_beszamit`).
- A beírt bevizsgálási díj **azonnal** megjelenik a Szolgáltatások között (előnézeti sor, mentés nélkül; `bevElonezet`).

### 0.59.4
- **Szikra élete**: a figura lélegzik, 441 arckifejezése van (50 alap-érzelem árnyalatokkal), szükségletei nőnek
  (éhség, pisi, WC, fáradtság, büdösség, magány, kalandvágy), és 21 rövid jelenetet játszik a képernyő alján:
  csirke, egér, támadó madár, Pechás Pityu, Ködbáró, csajozás, a barátnő (Csilla) látogatása, motor, autó, evés, ivás,
  tüsszentés, csuklás, edzés, zene, telefon, vakarózás, valamint pisilés, kakilás, fing és zuhany. Saját rajzok
  (`assets/szikra-elet.js`, `.css`, az asszisztens.js `window.SDH_SZIKRA` felületére épül).
- Beállítások › Asszisztens: **Szikra élete** és **Altesti humor** kapcsoló. Kattintás vagy gépelés azonnal leállítja
  a jelenetet; „kevesebb mozgás” mellett csak a mimika marad.

### 0.59.3
- **Termék- és szolgáltatás-címke** Brother címkenyomtatóra: a termék és a szolgáltatás ablakának alján **Címke** gomb
  (előnézet, példányszám legfeljebb 50, böngészős vagy hálózati nyomtatás, naplózva a tételhez). Termékcímke alapból:
  megnevezés, bruttó ár, cikkszám, vonalkód középen a kóddal (a termék vonalkódja, ha nincs: a cikkszám); szolgáltatás:
  megnevezés, ár vagy ársáv, kategória. Minden további adat bekapcsolható, sorrend, felirat, betűméret állítható.
- **Beállítások › Címkenyomtatás** (eddig RMA-matrica): felül a közös nyomtató és tekercs, alatta fülek – RMA-matrica /
  Termékcímke / Szolgáltatás-címke – saját hosszal, tájolással, betűvel, tartalommal, élő előnézettel és próbanyomattal.
  A modul neve: Címkenyomtatás (Brother).

### 0.59.2
- **RMA-matrica, böngészős nyomtatás:** a Matrica ablak kiírja, milyen tekercsnek kell a nyomtatóban lennie, és
  mit kell a nyomtatás-ablakban választani (Papírméret a Brother-név szerint, pl. „62mm” vagy „29mm x 90mm”;
  Margók: Nincs; 100%; fejléc/lábléc ki). A „címke nem egyezik” Brother-hibához súgó és tipp a Beállításokban.
- A plugin fejlécének `Version:` sora is a valódi verziót mutatja (eddig 0.58.0-n ragadt).

### 0.59.1
- **A munkalap új dizájnja.** Az oldalsáv több eszköznél sem törik két hasábra: egy hasáb, csoportonként puha
  kártyában (Állapot, Dátumok, Összeg, Tételek, Fizetés); a rokon mezők egy sorban, felirattal fölöttük (Készült +
  Határidő, Kedvezmény + Áfakulcs); a Lezárva zöld címke; az Összegben a nettó és az áfa kis cellában, a bruttó
  nagyban; a Fizetendő kiemelt sáv.
- **Bevizsgálási díj:** saját kártya; a „Ha az ügyfél kéri a javítást” kétállású kapcsoló (**Levonódik** /
  **Munkadíj marad**); a figyelmeztető doboz helyett tételes összesítő (díj, levonás jobbra zárt összeggel).
- **Eszközkártyák (Eszközök a lapon):** nagyobb kép keretben, a fő eszköznél kiemelő csík, a kész eszköznél zöld
  pipa a kép sarkában (színezett háttér helyett), pill alakú állapotválasztó és díjmező, a pénz három egyforma
  cellában (végösszeg, befizetve, fizetendő), kifizetve zöld sáv; a kártyák kitöltik a sort.

### 0.59.0
- **Lezárt lap zárolása.** A lezárt állapotú lap (Lezárt, Érvénytelen – minden `zart` jelzős állapot) csak
  olvasható: a munkalap / árukiadás / rendelés ablakának tetején zöld sáv, a mezők és a szerkesztő gombok letiltva,
  a Mentés rejtve. A szerver is elutasít minden módosítást (mentés, eszköz állapota és „fizetve”, fizetés – kivéve
  a már kifizetett lap utólagos pénztárba írását –, rendelés útja, AI-javaslat: megjegyzés, határidő). Ami nem a
  lap tartalma, zárolva is megy: nyomtatás, matrica, számla, ügyfélüzenet, ügyfél / eszköz adatlapja, Napló.
  **Újranyitás** a sávból (állapot + ok, kétlépcsős gomb). Új lapfül: **Beállítások › Lezárt lapok** (zárolás
  be/ki, kötelező újranyitási ok).
- **Változásnapló (új alaprész, Napló menüpont).** Minden módosítás mezőszinten: munkalap, árukiadás, rendelés
  (tételek, hibák, eszközök, fizetés, állapot), ügyfélüzenetek, SMS és döntések, csapatüzenetek, üzenőfal
  (cetlik), naptár, termékek és készletmozgás, ügyfelek, eszközök, szolgáltatások, pénztár, számlák, helyszínek,
  beszállítók, SMS-sablonok / -szabályok, a Beállítások (titkok értéke nélkül), és események (újranyitás okkal,
  elutasított szerkesztés, nyomtatvány nyomtatása / letöltése / e-mailje, matrica). Ki, mikor, honnan (felület,
  AI-asszisztens a javaslat számával, időzített, ügyféloldal, SMS-átjáró), régi → új érték.
  Az adatbázis-réteg `query` szűrőjén figyel, így nincs kerülőút; csak hozzáfűzés (a napló táblájára irányuló
  UPDATE / DELETE / TRUNCATE / DROP nem fut le, a kísérlet is naplózódik), soronkénti HMAC-aláírás, épség-
  ellenőrzés (átírt sorok, hézagok). **Napló menüpont** (admin): idővonal, szűrők (mi, ki, honnan, művelet,
  időszak, munkalapszám, szöveg), lapozás, CSV, épség. **Napló gomb** a munkalap / árukiadás / rendelés
  ablakán: a rekord története popupban. Új lapfül: **Beállítások › Változásnapló**. DB_VERSION 0.37.0
  (új tábla: `sdh_valtozasnaplo`).
- **AI-asszisztens és a Napló.** A nyitott rekord (munkalap, Napló ablak, Napló oldal) története magától
  bekerül a kérdés mellé – azonnal, idővonalszerűen elmondja, mi történt; a Napló ablak megnyitásakor fel is
  ajánlja. Négy új olvasó eszköz: **Egy rekord története (Napló)**, **Keresés a Naplóban**,
  **Napló-összesítés**, **A napló épsége**. A zárkód és a minta a naplóból sem megy hozzá; a beállítások
  naplóját csak adminisztrátornak mutatja. Az AI-végrehajtás és -visszaállítás forrása a naplóban látszik.
- **RMA-matrica Brother címkenyomtatóra (új modul, Modulok: RMA-matrica (Brother)).** A munkalap **Matrica**
  gombja: eszközönként egy matrica előnézettel, példányszám. Alaptartalom: név, munkalapszám, eszköz típusa +
  modellszám, RMA-kód + QR, hiba, feloldó minta (rajz + számok) + kód, IMEI + sorozatszám, dátum; bekapcsolható:
  telefon, szerviz, határidő, tartozékok, szín, átvételi állapot, megjegyzés, sorszám, vonalkód, külön QR.
  **Beállítások › RMA-matrica:** nyomtató (QL / QL széles / PT / TD), mód: **böngésző** (pontos méretű PDF,
  Brother illesztőprogram) vagy **hálózat** (QL raszterparancsok közvetlenül a 9100-as portra – QL-580N, 710W,
  720NW, 810W, 820NWB, 1060N, 1110NWB, 1115NWB), DK-tekercsek és TZe-szalagok, egyedi méret, tájolás, margó,
  betű, mezőnként be/ki, felirat, méret, félkövér, sorrend, élő előnézet (300 dpi), próbanyomat. Ami nem fér ki,
  arányosan kisebb betűvel. A közvetlen hálózati nyomtatás valódi nyomtatón **még nincs kipróbálva**.
- Új súgófejezetek: **Napló** (`sugo/25-naplo.md`), **RMA-matrica** (`sugo/26-matrica.md`); frissült:
  Munkalapok (zárolás, újranyitás), Asszisztens, Modulok, Nyomtatás. Új tesztek: `t48-zarolt-lap`,
  `t49-valtozasnaplo`, `t50-matrica`.

### 0.58.0
- **Modulok (új admin-menüpont, a Beállítások alatt).** A rendszer 15 fő funkciója modulonként ki- és
  bekapcsolható: Termékek és készlet, Napi árukiadás, Rendelések, Házipénztár, Számlázz.hu számlázás,
  Ügyfélüzenetek és RMA-oldal, SMS-értesítések, Levelezés, Csapat-üzenetek, Naptár, AI-asszisztens,
  Nyomtatványok, IMEI-lekérdezés, TAC-adatbázis, Eszköz-mockupok. Az alaprendszer mindig fut. Alapból minden be
  van kapcsolva.
- **A kikapcsolt modul** eltűnik a menüből, az Áttekintés csempéi és gyorsgombjai közül, a Beállítások
  lapfülei közül, a súgóból és az asszisztens tudásából; a más oldalakon lévő gombjai sem látszanak; az
  időzített munkái (SMS-sor, naptár-emlékeztető, nyomtatási felajánlás) nem futnak; az AJAX-végpontjai egy
  központi őrön elutasítanak. **Az adatai megmaradnak**, visszakapcsolva minden folytatódik.
- **Függőség:** az SMS és a Rendelések az Ügyfélüzenetek és RMA-oldal modulra épül – azzal együtt kikapcsolnak.
- **Különleges esetek:** kikapcsolt Házipénztárnál a munkalap Fizetés ablaka megmarad, csak nem ír a pénztárba;
  kikapcsolt Számlázz.hu-nál a helyi számla-előkészítő (PDF) megmarad; kikapcsolt RMA-nál a munkalap RMA
  lapfüléből „Címke” lesz (QR-kód nélküli címke és nyomtatása); kikapcsolt Csapatnál a kollégák kezelése
  megmarad.
- **Telepítői zár:** kóddal lezárható a modulok átkapcsolása (más cégnél telepítve az ügyfél adminisztrátora
  sem kapcsol be magának meg nem rendelt modult). Szervezési zár, nem kódvédelem.
- Új súgófejezet: **Modulok** (`sugo/24-modulok.md`); új teszt: `t47-modulok`.

### 0.57.0
- **SMS-értesítések (új SMS menüpont és Beállítások › SMS-értesítések).** Az SMS a céges Android-telefonon futó
  SMS-átjárón át megy (helyi, felhős vagy saját szerveres mód). Új telepítésnél minden ki van kapcsolva.
- **Mindig sorból, soha nem blokkol:** idempotens küldés (kétszer nem megy ki), újrapróbálás növekvő várakozással,
  időtúllépésnél nincs automatikus újraküldés (a sor a **Hívandók** közé kerül), csendes idő és tiltólista az
  automatikus SMS-ekre, az 1. telefonmező hibájánál a 2. mezőre esik vissza, hibánál e-mail értesítés. Az SMS-napló
  csak bővül.
- **Állapotváltáskor „Küldjek SMS-t az ügyfélnek?” ablak** javítás és rendelés esetén, eszközönként is
  (Küldés / Nem küldök / Később döntök); a döntések az adatbázisban maradnak, a **Függő döntések** nézetben
  később is meghozhatók. Állapotonként beállítható az automatikus küldés.
- **Verziózott sablonok** változókkal (`{ugyfel}`, `{szam}`, `{eszkoz}`, `{allapot}`, `{szerviz}`, `{link}`,
  `{hatarido}`, `{osszeg}`); négy kikapcsolt „PÉLDA – jóváhagyásra vár” minta. Az elküldött SMS megőrzi a sablon
  verzióját és a pontos szöveget.
- **Kézi SMS** a munkalapról, az ügyféltől vagy önállóan; az RMA-csevegőben **SMS-ben is** jelölő.
- **Bejövő SMS webhookon át:** titkos kulcsos cím, az ügyfél legutóbbi nyitott munkalapjára párosul, a
  párosítatlanok kézzel rendelhetők hozzá; STOP = leiratkozás az automatikus SMS-ekről; a kimenő SMS állapotát
  (elküldve / kézbesítve) a webhook is gyorsítja.
- **Javítás:** a rendelés állapotváltása az űrlapról mentve is jelez a kapcsolódó funkcióknak (eddig csak az
  állapotgombok jeleztek).
- A webhook adatmezői és az átjáró health-végpontja az app dokumentációja (swagger.json) alapján véglegesítendők.

### 0.56.0
- **Eszközök a lapon: áttekinthető, több eszközös munkalap.** A munkalap jobb oldalán minden eszköz egy kártya:
  termékkép, név, kategória / IMEI / szín, saját állapot (azonnal mentődik), díj, és az eszköz saját
  végösszege, befizetett része és fizetendője (vagy „✓ Fizetve”). A bal oldali külön „Eszközönként” blokk, a
  Fizetés alatti külön bontás és a „További eszközök” címkesor megszűnt – minden egy helyen van.
- **A 2. (3., …) eszköz is teljes adatlapot kap** az Eszköz lapfülön, képpel – eddig csak egy tömör sor volt.
- **Termékkép (mockup) minden eszköz mellett:** munkalap, Áttekintés részletpanel (az eszközök kártyasorban az
  adatok fölött), Eszközök lista. 20 gyári, márkajel nélküli termékfotó (Magnific-kal készült), kategória szerint.
- **Beállítások › Mockupok:** képek cseréje, feltöltése, törlése, új mockup; szabály gyártóra / típusra
  (pl. Apple + iPhone); minden képhez generáló szöveg (prompt) másolás gombbal és „Megnyitás a Magnificben”;
  a beillesztett külső képcím mentéskor a médiatárba töltődik; gyári készlet visszaállítása.

### 0.55.3
- **A „Bekerüljön a házipénztárba?” ablak kiírja, mennyi van már a pénztárban.** Eddig csak a hiányzó összeget
  ajánlotta, magyarázat nélkül: két eszköz 10 000 + 10 000 Ft díjánál 12 000 Ft-ot (2 000 + 10 000), mert a fő
  eszköz korábbi 8 000 Ft-os díja már a pénztárban volt. A számítás helyes volt, de nem látszott, miért kevesebb.
  Most az ablak tetején: „Előleg a lapon · már a pénztárban · hiányzik”, és eszközönként: „járna …, már a
  pénztárban …”.

### 0.55.2
- **Javítás: az AI „messages.1.content.3.tool_use.input: Input should be an object” hibát adott.** Ha az AI
  paraméter nélküli eszközt hívott, a rendszer a második körben az üres bemenetet üres tömbként (`[]`) küldte
  vissza, az API viszont objektumot (`{}`) vár. Most mindig objektum megy.

### 0.55.1
- **Az óra felveszi a stílus színét.** Alapból a Megjelenés kiemelő színét viseli (eddig fix zöld / kék volt).
  Zárás előtt (borostyán) és zárva (szürke) külön színű marad. Beállítások › Nyitvatartás › **Az óra színe**:
  „A stílus színe” (alap) vagy „Állapot szerint” (zöld, borostyán, kék).

### 0.55.0
- **Új megjelenés: az óra neon üveg.** Matt üvegtábla állapotszínű fénnyel (nyitva: zöld, zárás előtt: borostyán és
  lüktet, nyitás előtt: kék, zárva: szürke), átmenetes neon kerettel és lassan átfutó fényvisszaverődéssel. A
  haladásjelző vékony csík helyett gyűrű lett az ikon körül: fent kezdődik, óramutató irányban telik, a végén
  fénylő pont. Papír megjelenésben, csökkentett mozgásnál és átlátszóság-csökkentésnél lapos marad.

### 0.54.1
- **Javítás: éles a szöveg az üzenőfal cetlijein.** A cetlik eddig enyhén meg voltak döntve, ettől a böngésző
  homályosan rajzolta a feliratukat (csak húzás közben volt éles). Most egyenesen állnak; a kézzel felragasztott
  hatást a cetlinként kicsit eltérő árnyék adja.

### 0.54.0
- **A levél-jelzéseket nem takarja a figura.** Ha az asszisztens be van kapcsolva, az új levelek felugró jelzései
  a figura bal oldalán sorakoznak (telefonon a figura fölött). Ha a figura épp megszólal, a buborékja a jelzések
  fölé emelkedik.
- **Párbaj a Hiba-bogárral.** Az asszisztens figurája időnként pár másodperces fénypenge-párbajt vív egy morcos
  kis bogárral a képernyő alján – többnyire a bogár szalad el, néha a figura pengéje merül le. Kattintásra,
  gépelésre vagy új levél jelzésekor azonnal abbamarad; levél-jelzés látszása közben el sem kezdődik. Az élénkség
  adja a gyakoriságát; Beállítások › Asszisztens › **Párbaj a Hiba-bogárral** kapcsolóval kikapcsolható.

### 0.53.0
- **Gyors szövegek az RMA csevegőben.** A munkalap RMA fülén a beírómező alatt új **Gyors szövegek** gomb: popupból
  egy kattintással a beírómezőbe kerül egy előre megírt üzenet (pl. „Kedves Ügyfelünk! Megrendelése megérkezett, mai
  naptól átvehető üzletünkben, nyitvatartási időben.”). Magától nem megy el. A lista a popupban szerkeszthető
  (felvétel, átírás, sorrend, kétlépcsős törlés, azonnali mentés) és a Beállítások › Ügyféloldal (RMA) lapfülön is.
- **Javítás: Számla-előkészítő PDF.** A „tFPDF error: Undefined font: dejavu B” hiba megszűnt. Oka: a betűk
  gyorsítótára annak a nevét őrizte meg, aki először használta a betűfájlt (a 0.52 nyomtatványai), és a
  Számla-előkészítő félkövér betűje így idegen néven került be. Mostantól minden PDF a saját betűnevét használja,
  bármelyik készül előbb.

### 0.52.0
- **Minden nyomtatható – egy ablakból.** Új **Nyomtatás** gomb (nyomtató ikon) a munkalap, az árukiadás és a
  rendelés ablakában, az Áttekintés részletpaneljén, a Pénztárban és a felső fejlécben. Az ablak megmutatja az
  **előnézetet**, kinyomtatja a kért **példányszámban** (1–9), **elmenti PDF-be**, és **elküldi e-mailben** –
  ugyanaz a PDF megy mind a négy helyre.
- **Nyomtatványok:** Átvételi elismervény, Munkalap, Belső munkalap (ár nélkül, belső megjegyzéssel és zárkóddal),
  Árajánlat (érvényességi idővel), Szállítólevél, Átadási bizonylat (árukiadás, rendelés), Megrendelés,
  **Bianko munkalap** (üres, kézzel kitölthető), Pénztárzárás, Pénztárjelentés, Pénztártétel-igazolás, Lapok
  listája (nyitott / lezárt / fizetetlen / időszak), Készletlista, Számlák listája. Több eszközös lapról egyetlen
  eszköz is nyomtatható. Egyik sem számla.
- **Felajánlás.** Új munkalap mentése után a rendszer magától felajánlja az **átvételi elismervény**, a lap
  lezárásakor a **munkalap** nyomtatását (árukiadásnál az átadási bizonylatot, új rendelésnél a megrendelést) –
  akárhonnan történik a lezárás: az ablakból, a listából vagy a fizetésből. Oldalváltás után is megjelenik;
  külön-külön kikapcsolható.
- **E-mail.** A nyomtatvány PDF csatolmányként megy az ügyfél címére, a bekötött postafiókból (Levelezés) vagy a
  rendszer levélküldőjével; a tárgy és a szöveg nyomtatványonként sablonból jön. Ami az ügyfélnek ment, az a lap
  üzenetei között is megjelenik.
- **Beállítások › Nyomtatványok: minden szerkeszthető.** Nyomtatványonként: cím, alap példányszám, papírméret
  (A4 / A5), hogy mi látszik rajta (ügyfél, eszköz, tartozékok, hibák, elvégzett javítás, tételek, árak,
  összesítő, előleg, megjegyzés, belső megjegyzés, zárkód, határidő, állapot, felelős, QR-kód az ügyféloldalhoz),
  bevezető, apró betűs (jogi) és záró szöveg jelölőkkel, aláírások, igazolványszám sor, bélyegző helye, a kísérő
  e-mail. A szerkesztőben **élő előnézet**, a listában **Próbanyomat** kitalált adatokkal, és gyári
  visszaállítás. Közösen: **logó** (feltöltés, szélesség, hely), **betűtípus** (négy csomagolt készlet),
  **betűméret**, margó, kiemelő szín, a fejléc és a lábléc szövege, a „Kelt” település.
- **Zárás utáni emlékeztető.** Amikor valaki lezárja a pénztárban a napot, az asszisztens figurája mindig szól,
  hogy a **bankkártya-terminált is le kell zárni** – a buborék nem tűnik el, amíg vissza nem igazolják, és innen
  a zárás is nyomtatható. A szöveg átírható (Beállítások › Házipénztár); kikapcsolt asszisztensnél felugró ablak.
- **Független napi mentés.** Mostantól minden nap készül teljes mentés, az asszisztens használatától
  függetlenül: időzítve, vagy – ha a gép éjjel ki volt kapcsolva – a nap első bejelentkezése után, a háttérben.
  Új **Beállítások › Mentés** lapfül: be- és kikapcsolás, a megőrzött mentések száma, Mentés most, letöltés.
- **Titkosított kulcsok.** A Számla Agent kulcs és a fizetős IMEI-szolgáltató kulcsa az adatbázisban – így a
  mentésfájlban is – titkosítva áll (a korábban eltárolt kulcs a frissítéskor magától átáll). Az IMEI-kulcs
  mezője többé nem mutatja vissza a kulcsot. **Költöztetéskor** (más `wp-config.php` sók) a kulcsokat újra be
  kell írni; erre a lapfül figyelmeztet.
- Az asszisztens meg tudja nyitni egy lap Nyomtatás ablakát („nyomtasd ki az 1234-es átvételijét”) – nyomtatni
  és levelet küldeni a felhasználó fog. A súgó új fejezete: **Nyomtatás, PDF és e-mail**.
- A belső papír (belső megjegyzés, zárkód) nem küldhető az ügyfél saját címére. A mentés ideiglenes néven készül,
  így a félbeszakadt mentés nem számít mentésnek.
- Javítás: azonos másodpercben készült mentések közül a takarítás a frisset is törölhette.
- Új tesztek: `tesztek/t37-nyomtatas.php`.

### 0.51.0
- **Súgó menüpont: bemutató, dokumentáció, kérdés–válasz.** Új menüpont, négy lapfüllel. A **Bemutató** megmutatja,
  milyen sorrendben érdemes ismerkedni, és a teljes rendszer térképét adja. A **Dokumentáció** 22 fejezetben írja le
  minden menüpont és minden Beállítások-lapfül működését (mire való, lépésről lépésre, mezők és gombok, szabályok,
  hibaelhárítás). A **Kérdés–válasz** több száz hétköznapi kérdést tartalmaz fejezetenként szűrve, „Segített?"
  értékeléssel. Az **Újdonságok** a verziónapló. Fölöttük kereső: gépelés közben ad találatot, érti a toldalékos
  és a köznyelvi szavakat (kassza → pénztár, telefon → eszköz).
- **Saját kérdés–válasz.** Az adminisztrátor a cég saját szabályait is felveheti (Súgó › Kérdés–válasz › „Saját
  kérdés–válasz"); a Súgóban és az asszisztensnél is azonnal él. Az „Amire nem volt találat" doboz mutatja, mire
  kerestek a kollégák eredmény nélkül.
- **Az asszisztens végigvezet az oldalakon.** A „Vezess végig ezen az oldalon" mostantól valódi, többlépcsős túra:
  Előző / Következő gombbal vagy a nyilakkal lépked, és **nem tűnik el, ha közben máshova kattintasz** – ha felugró
  ablakot nyitsz, megvár. Minden oldalnak kész túrája van, API-kulcs nélkül is működik. Az asszisztens egy feladat
  lépésein is végig tud vezetni (nem csak egy elemet mutat meg).
- **Az asszisztens a teljes súgóból felel.** A dokumentáció, a kérdés–válaszok és a túrák egy forrásból élnek (a
  bővítmény `sugo/` mappája): minden kérdésnél megkapja a hozzá illő részeket, és maga is keres, olvas a súgóban.
  Új eszközei: naptár, árukiadások és rendelések, készlet, szolgáltatások és árak, eszköz előélete, nyitvatartás,
  és hogy mi van beállítva.
- **Élő figura: több mimika, mozgás, humor.** Kezet, lábat, antennát, szemöldököt kapott. Nevet, kacsint, megijed,
  elszédül, ásít; integet, táncol, nyújtózik, kávézik, olvas, elsétál a képernyő alján – és abbahagyja, amint
  dolgozni kezdesz. Mentésnél konfettit szór. Időnként megszólal egy vicces mondattal (a napszakhoz igazodva).
  A Beállítások › Asszisztens lapfülön két új csúszka: **Humorérzék** (alap 90%) és **Élénkség**.
- **Papír megjelenés.** Új, negyedik megjelenés a profilmenüben: e-könyv-olvasó-szerű, nyugodt felület – meleg
  papírszín, tintaszínű, könyvszerű betű, üveghatás, fényudvar és neon keret nélkül.
- **Kékfény-szűrő.** Csúszka a profilmenüben (0–100%): melegebb, a szemnek nyugodtabb kép, a felugró ablakokra is
  hat. A cég alapértelmezése a Beállítások › Logó és megjelenés lapfülön állítható.
- **Az óra a fejlécben.** Az óra és a zárásig hátralévő idő a felső fejlécbe került: minden oldalon látszik, nem
  csak az Áttekintésen.
- **Nyugodtabb betűméret-csúszka.** Húzás közben csak a csúszka fölötti minta és a százalék változik; a felület az
  elengedéskor, lágy átmenettel vált méretet (eddig húzás közben ugrált). A csúszka lekerült az Áttekintésről: a
  profilmenüben és a Beállításokban van.
- **Naptár: a „fontos" jelek a helyükön.** A felkiáltójeles jel a szöveg közepére ül, bármekkora a betű, és nincs
  körülötte fényudvar.
- A fejlécben új „?" gomb: súgó az éppen nyitott oldalhoz. A mezők tartalma nem megy az asszisztenshez (csak a
  feliratuk).
- **A dokumentáció a rendszerrel együtt frissül** – ez mostantól szabály: az új `t36-sugo` teszt elbukik, ha egy
  menüpontnak, egy Beállítások-lapfülnek vagy az asszisztens egy eszközének nincs leírása, vagy ha a súgó verziója
  lemaradt (az írási szabályok: `sugo/OLVASSEL.md`).

### 0.50.0
- **Új modul: Naptár.** Minden névhez saját naptár tartozik. Közös fióknál (Csapat: Üzenőfal mód) a nevek kézzel
  vehetők fel – belépés nem kell hozzájuk; többfelhasználós módban minden fiók (profil) magától kap egyet, és
  mindkét módban felvehető további név (pl. „Műhely", „Kiszállás"). A nevek ki-be kapcsolva egymás mellé tehetők.
- **Tizenkét nézet:** Ma (a nap idővonala, a hátralévő szabad idő, a lejárt teendők és a fontos levelek), Nap,
  3 nap, Munkahét, Hét, Hónap, Negyedév, Év (hőtérkép), Napirend, Idővonal (nevenként, a nap óráira vagy a hét
  napjaira bontva), Személyek (nevenként egy oszlop) és Tábla (állapot szerint). Görgetősáv nincs: ami nem fér ki,
  lapozható vagy „+N" mögött áll.
- **Az esemény mindent tud:** időpont vagy egész nap, több nap, helyszín, leírás, kategória és szín, fontosság,
  állapot, résztvevők, ismétlődés (napi, heti a hét napjaival, havi, éves; végdátumig vagy alkalomszámig; egy
  alkalom külön törölhető), több emlékeztető, ügyfél, munkalap száma, hivatkozás, privát jelző.
- **Visszaszámlálás:** minden eseménynél élőben látszik, mennyi idő van még hátra, hogy éppen zajlik-e, vagy
  mikor ért véget; az oldalsávon a következő események.
- **Értesítés a rendszerben, amely nem tűnik el magától.** Amikor az esemény (vagy az emlékeztetője) esedékes,
  kártya jelenik meg minden oldalon, és ott marad – oldalváltás után is –, amíg kézzel ki nem nyomják. Kis
  jelzővé összecsukható, hogy ne takarjon; a menü jelvénye mutatja, hány vár.
- **E-mail értesítés:** a megjelölt és a „Nagyon fontos" eseményekről levél megy a naptár gazdájának és a
  résztvevőknek az első emlékeztető idején. Kapcsolható napi összefoglaló levél is: a nap eseményei és a levelező
  ügynöke szerint nagyon fontos levelek.
- **Húzással:** az időrácsban az esemény áthelyezhető és átméretezhető (15 perces lépés), a hónapban másik napra,
  a Táblán másik állapotba, a Személyek nézetben másik név naptárába húzható. Üres helyre kattintva új esemény
  nyílik arra a napra, időpontra és névre.
- Beállítások › Naptár: alapnézet, a nap látható órasávja, kategóriák, alap emlékeztetők, e-mail, közös értesítő
  cím, napi összefoglaló. Új táblák: `naptar_szemely`, `naptar_esemeny`, `naptar_jelzes` (séma 0.35.0). Új teszt: `t35-naptar`.

### 0.49.0
- **A betöltött eszköz szerkeszthető a munkalapról.** Ceruza az eszközmező mellett, „Szerkesztés" az eszköz kártyáján,
  és a csatolt eszközökön is (a címkén és a További eszközök listáján): az eszköz ablaka a lap fölött nyílik, mentés
  után a lap megmarad, a név és az adatok helyben frissülnek.
- **Csak két számlagomb maradt:** „Számlázz.hu számla" és „Számla-előkészítő (PDF)". Az „SDH számla" gomb és a
  beállításai megszűntek; a vele korábban kiállított számlák megmaradnak.
- **A Pénztár csempéje nem ír ki többé „157&nbsp;700"-at.** A csempék frissülő száma sima szövegként érkezik (a magyar
  WordPress ezres elválasztója HTML-entitás volt).
- **Több eszközös munkalap rendes betűmérettel.** A bal oldal (eszközönkénti állapot és bontás) széles képernyőn két
  hasábra törik, így a lap nem lesz magasabb a képernyőnél, és nem kicsinyíti le magát apró betűsre.
- **Betűnagyító csúszka az Áttekintésen** (és a profilmenüben): húzás közben azonnal nő vagy csökken minden betű és
  vele a felület, 80–160% között. A saját gépen megmarad; a körkörös nyíl visszaáll a cég alapméretére.
- **Betűtípus és alap betűméret a Beállításokban** (Logó és megjelenés): tizenkét betűtípus vagy egyéni, a gépre
  telepített betű neve; a rendszer nem tölt le betűt az internetről.
- **Új színválasztó a minta szerint:** Megjelenés (Rendszer / Világos / Sötét) és Üvegszín két sorban – Duó
  (kontrasztos színpár) és Egyszínű (egy színcsalád) –, nyolc színnel: Alapértelmezett, Kék, Smaragdzöld, Ibolya,
  Rózsaszín, Borostyán, Türkiz, Rubin. A profilmenüben a saját gépre, a Beállításokban a cég alapértelmezésére szól.
- Fejlesztőknek: a stíluslapok képpontméretei a `--sdh-n` változóval szorzódnak. Új CSS után futtasd:
  `python3 tools/css_nagyitas.py` (a `t34` teszt bukik, ha kimaradt). Új teszt: `t34-szerkesztes-betu-szin`. Séma nem változott.

### 0.48.0
- **Az ügyfél látszik a napi árukiadáson és a rendelésen is.** Ugyanaz az ügyfélkártya, mint a munkalapon (név, cím,
  telefon, e-mail, másoló gombokkal) – eladásnál a lapfülek fölött áll, mindig láthatóan.
- **A betöltött ügyfél szerkeszthető a lapról.** Ceruza az ügyfélmező mellett és „Szerkesztés" az ügyfél kártyáján, a
  munkalapon, az árukiadáson és a rendelésen is: az ügyfél ablaka a lap fölött nyílik, mentés után a lap megmarad.
- **A napi árukiadás a munkalapok rácsában is ott áll.** Az Azonosító oszlopban „Napi árukiadás", a Típusban az eladott
  tételek; új nézetek: „Napi árukiadás" és „Csak javítás". A rendelés a saját menüpontjában marad.
- **Állapotváltás a listából.** A rendelés útja (felvéve … átadva) és az árukiadás állapota a lista sorában is
  állítható, nem csak az űrlapon; az átadott és kifizetett rendelés így is magától lezárul.
- **Szebb gombok.** A fejlécek gombjai lekerekített pillek SVG ikonnal, rámutatásra kiemelő színnel és halvány fénnyel.
- **Óra az Áttekintésen, a zárásig hátralévő idővel.** A nyitvatartás naponként állítható (Beállítások › Nyitvatartás
  és óra); nyitás előtt a nyitásig, zárás után a következő nyitásig számol.
- **A Levelezés menüpontja az új levelet jelzi**, nem az olvasatlanok számát: mennyi jött a legutóbbi megnézés óta, és
  több postafióknál fiókonként külön sorban, hogy melyikbe.
- **Az Asszisztens kikerült a menüből és a csempék közül:** a Beállítások › Asszisztens lapfülről nyílik (javaslatok
  és napló, tudástár, mentések). A beszélgető figura marad.
- Új teszt: `t33-ugyfel-lista-ora-level`. Séma nem változott.

### 0.47.0
- **A műhely-felületről semmi nem visz át a WordPress-adminba.** A Beállítások a műhely-felületen nyílik (az
  oldalmenü alján és a csempén is); a wp-adminba vivő „Karbantartás" link megszűnt. Kolléga nem nyithatja meg.
- **Új Beállítások: egy oldal, témánként külön lapfül.** Az eddigi egyetlen hosszú oldal és a külön Arculat / TAC
  menüpont helyett húsz lapfül (pill), hat csoportban: Cég és megjelenés, Munkalap, Pénz és eladás, Kapcsolat és
  csapat, Készülékadatok, Rendszer. Minden lapfül külön ment – egy lapfül mentése a többihez nem nyúl.
- **Mentés helyben.** A Mentés sávja a képernyő alján marad; hiba esetén az üzenet a gomb mellett áll. Nem mentett
  módosításnál a lapfülváltás rákérdez (Mentés és tovább / Elvetés / Maradok). Ctrl+S is ment.
- **Kép feltöltése a műhely-felületről is** (logó, ügyféloldal-logó, háttérkép, háttérvideó): saját feltöltő gomb,
  kis előnézettel.
- **Új állítható adatok:** a rendelés öt állapotának neve, az árukiadás- és rendeléslista oldalmérete, a munkalap
  csevegőjének frissítési ideje.
- A wp-adminból megnyitva a Beállítások ugyanez a felület. Új teszt: `t32-beallitasok`. Séma nem változott.

### 0.46.0
- **Az ügyfél adatlapján látszanak a rendelései és a vásárlásai.** Három új lapfül az ügyfél ablakának alján:
  Munkalapok, Rendelések, Vásárlások – darabszámmal, összeggel, tartozással. Egy sorra kattintva a lap az ügyfél
  ablaka fölött nyílik meg.
- **Gyakori megjegyzések.** A munkalap (és az árukiadás, rendelés) Megjegyzés fülén új gomb: popupból, egy
  kattintással kerül a megjegyzésbe az előre megírt szöveg – egyszerre több is, bármelyik állapotban; újra kattintva
  lekerül. Az első: „Az ügyfél a készülékét időpontra hozza". A lista a popupból és a Beállításokban is bővíthető.
- **Arculat: logó és design az adminból.** Feltölthető logó (a színes jel helyére), átírható a jel és a név – ez
  jelenik meg az oldalsávban, a böngészőfül címében és a menüben. Egyéni kiemelő szín színkóddal (a cég saját
  színe), a sarkok lekerekítése (szögletes / normál / kerek), és az oldalsáv stílusa (világos / sötét / a kiemelő
  színnel kitöltve).
- Új teszt: `t31-megjegyzes-ugyfel-arculat`. Séma nem változott.

### 0.45.0
- **Napi árukiadás.** Új menüpont, csempe és gomb az Áttekintésen: pultos eladás javítás nélkül – termék és / vagy
  szolgáltatás, fizetéssel. Ugyanaz a lap, mint a munkalap (fizetés, előleg, számla, pénztár), csak eszköz, hibák
  és bevizsgálási díj nélkül. A vevő alapból „Napi árukiadás"; választható meglévő ügyfél, vagy a + gombbal új
  vehető fel. „Rögzítés és fizetés": mentés után rögtön a fizetés ablaka jön; a kifizetett eladás magától lezárul.
- **Helyszínek.** A Beállításokban (és adminként a Napi árukiadás oldalán) felvehetők az üzletek. Több helyszínnél
  az árukiadáson választani kell, és a lista helyszínre szűrhető; egy üzletnél a választó meg sem jelenik.
- **Rendelések.** Új menüpont, csempe és gomb: az ügyfeleknek rendelt áru egy helyen. Mindig ügyfélhez kötve; a
  forrás („Honnan") szerkeszthető listából, a + gombbal popupban bővíthető. A rendelés útja külön állapot: Felvéve →
  Megrendelve → Megérkezett → Ügyfél értesítve → Átadva; beszállítói rendelésszám, várható érkezés. Ugyanúgy fizet
  (előleggel is), és van RMA-ja.
- **„Megjött – értesítés".** A rendelésen egy gomb: az átírható értesítő az ügyféloldalra kerül, és ha van e-mail
  címe, e-mailben is elmegy. A menü jelzi, hány megérkezett rendelésről nem tud még az ügyfél.
- **Csevegő a munkalap RMA-fülén.** Az üzenetek időrendben állnak, a legfrissebb alul; a szál görgethető, nincs
  lapozás. A szerviz üzenete jobbra, az ügyfélé balra áll, napelválasztóval. Enter küld, Shift+Enter új sort kezd;
  nyitott ablaknál az ügyfél válasza magától megjelenik.
- **Ügyféloldal: külön Termékek fül.** A régi közös „Tételek" helyett Szolgáltatások és Termékek fül, ahogy a
  munkalapon; mindkettő alatt ott a lap végösszege is.
- **Áttekintés:** új gombok – „+ Új termék", „+ Új szolgáltatás" (mentés után az Áttekintésen maradsz), „Napi
  árukiadás", „Rendelések". A csempék szerkesztése csak a Beállításokban van.
- A munkalap-rács, a Munkalapok lista és a „nyitott munkalap" számláló csak a javítási lapokat mutatja; a sorszám
  a háromféle lapnál közös.
- Új tesztek: `t29-eladas`, `t30-rma-csevego`. Séma (DB_VERSION 0.34.0): `sdh_munkalap.fajta`, `helyszin_id`,
  `beszallito_id`, `rend_allapot`, `rend_szam`, `rend_ertesitve`; új táblák: `sdh_helyszin`, `sdh_beszallito`.

### 0.44.0
- **A „Fizetett" mindig az, ami ténylegesen befolyt.** Eddig teljes fizetés után is csak a díj és az előlegek
  álltak ott (pl. 20 000 Ft-os kifizetett lapon 11 000 Ft). Mostantól a lezáró fizetés is hozzáadódik: kifizetett
  lapon Fizetett = a kifizetett végösszeg. Eszközönként is látszik, mennyi folyt be („Fizetve 10 000 Ft",
  illetve „befizetve 1 000 Ft · fizetendő 9 000 Ft"). A korábban kifizetett lapok frissítéskor egyszer rendbe jönnek.
- **Pipa levétele előtt az asszisztens elmondja, mi történik.** Ha egy kifizetett lapon kiveszed a „Fizetve"
  pipát, a „Bevizsgálási díj" pipát, vagy egy eszközről leveszed a „Fizetve" jelzőt, a buborék konkrét számokkal
  megmondja a következményt és a teendőt, és gombot ad: „Mégsem", „Fizetés visszavonása", „Csak a pipát veszem
  ki". Ha az asszisztens ki van kapcsolva, ugyanez a mező alatt jelenik meg.
- **Fizetés visszavonása.** A lapot lezáró fizetés eszközönként pontosan visszavonható: a díjak, a korábbi előlegek
  és a korábban külön kifizetett eszköz érintetlen marad, a fizetendő visszajön. A pénztártételt a
  Házipénztárban kézzel kell törölni – a buborék szól róla, és kiírja az összeget.
- **Lejjebb írt előleg:** a „Fizetett (előleg)" csökkentése előbb a díjakon felüli befizetéseket viszi el, a
  bevizsgálási díj csak ezután csökken.
- Új teszt: `t28-fizetett-osszeg`. Séma (DB_VERSION 0.33.0): `sdh_munkalap.fo_veg`, `sdh_munkalap_eszkoz.veg`
  (a lapot lezáró fizetés eszközönkénti része).

### 0.43.1
- **Javítás: a bevizsgálási díj nem nő a későbbi befizetéstől.** Eddig a fő (első) eszköz díja „az előleg
  maradéka" volt, ezért ha a Fizetés ablakban előleget vagy részfizetést rögzítettél (pl. az egyik eszközt
  kifizették), a díjsor és a levonása ennyivel megnőtt (1 000 Ft-ból 10 000 Ft lett). A végösszeg és a fizetendő
  jó maradt, de a tételsorok és a számlára kerülő díj nem. Mostantól a díjon felül befizetett összeg külön
  vezetődik (`sdh_munkalap.fo_kulon`), a díj változatlan marad – egy és több eszközös lapon is.
- **Régi, már elromlott lapok:** utólag nem dönthető el, mennyi volt a díj és mennyi a befizetés, ezért ezek nem
  javulnak meg maguktól. Javítás kézzel: a lapon a „Fizetett (előleg)" mezőbe a díjak összegét írd vissza, és ments.
- Tudnivaló: bepipált „Bevizsgálási díj" mellett a „Fizetett (előleg)" mezőbe kézzel írt összeg díjnak számít.
  További előleget a **Fizetés** gombbal („Előleg / részfizetés") rögzíts.
- Új teszt: `t27-bevizsgalasi-dij` (a bejelentett eset lépésről lépésre). Séma: DB_VERSION 0.32.0.

### 0.43.0
- **Eszközönként saját állapot.** Több eszközös munkalapon minden eszköznek külön állapota van: az egyik kész és
  elviszik, a másik még alkatrészre vár. Az űrlap bal oldalán és az Áttekintés részletpanelén eszközönként
  állítható, azonnal mentődik. Egy eszközös lapon minden a régi.
- **Közös állapot: „Részben elkészült".** A lap állapota az eszközökéből adódik: ha mind ugyanaz, az; ha az egyik
  már kész (elkészült vagy lezárt), a másik még nem, akkor „Részben elkészült" – és kiírja, melyik eszköz kész
  (pl. „1 / 2 kész: 2. Samsung Galaxy A54"; a rácsban „1/2" jelzés). A lap csak akkor zárul le, ha minden eszköz
  lezárt. A lap állapotát átírva minden eszköz megkapja. Új jelzők az állapotlistában: `kesz`, `reszben`,
  `ervenytelen` (Beállítások › Munkalap-állapotok); a „Részben elkészült" átnevezhető, átszínezhető.
- **Eszközönként „Fizetve".** Ha a Fizetés ablakban csak az egyik eszközt fizetik ki, az az eszköz „Fizetve" lesz
  (a napjával), a lap nyitva marad; ha így minden eszköz ki van fizetve, a lap is fizetve lesz. A kifizetett eszköz
  nem fizethető újra, a pénztárban teljes fizetésként (nem előlegként) szerepel. A jelző az eszközről levehető.
- **RMA-üzenet eszközhöz kötve.** Az üzenet írásakor választható, melyik eszközről szól (alapból az egész
  munkalapról); a szálban és az e-mailben ott az eszköz neve. Az ügyfél is megjelölheti, melyik készülékről ír.
  Az ügyféloldalon eszközönként látszik az állapot és a fizetés, a történetben az eszköz saját állapotváltásai.
- **Eszközönként külön számla – választható.** A számla ablakában (Számlázz.hu számla, SDH számla,
  számla-előkészítő) megadható, hogy minden eszköz egy bizonylatra kerül, vagy csak az egyik: ilyenkor csak annak
  a tételei maradnak kipipálva, a megjegyzésbe az az egy eszköz kerül, a helyi nyomtatvány saját sorszámot kap
  (…-2). A számla megjegyzi, melyik eszközé; az ablakban felvett új tétel is ahhoz az eszközhöz kerül.
- **Tesztek a repóban** (`tesztek/`, a telepítő zipbe nem kerülnek be): `SDH_TESZT_ADATBAZIS=1 php tesztek/futtat.php`
  – csak külön tesztoldalon futtasd, mert adatot ír az adatbázisba.
- Séma (DB_VERSION 0.31.0): `sdh_munkalap.fo_allapot / fo_fizetve / fo_fizetes_ideje`,
  `sdh_munkalap_eszkoz.allapot / fizetve / fizetes_ideje`, `eszkoz_id` az `sdh_munkalap_naplo`, `sdh_uzenet` és
  `sdh_szamla` táblában (a valódi eszköz-azonosító; 0 = az egész lap).

### 0.42.0
- **Minden eszköz önálló ügy a munkalapon belül.** Ha egy ügyfél egyszerre több készüléket ad le, egy munkalapon
  mennek, de eszközönként külön kezelve: saját hibák (0.41), saját **szolgáltatások és termékek**, saját pénzügy.
- **Tételek eszközönként:** több eszközös lapon a Szolgáltatások és a Termékek minden sorában választó áll, melyik
  eszköz tétele (az új sor az előző sor eszközét örökli). A bal oldalon „Eszközönként" bontás: készülékenként a
  végösszeg és a fizetendő. A részletek Szolgáltatások / Termékek lapfülén Eszköz oszlop, a Munkalap lapfülön
  eszközönkénti összeg.
- **Bevizsgálási díj eszközönként, a saját tételekből levonva:** minden eszköznek saját díj- és saját levonássora
  van. A díj az eszköz SAJÁT javítási tételeiből vonódik le – amelyik eszközt nem javítják, annak a díja
  munkadíjként megmarad (nem kell külön állítani).
- **Pénztár eszközönként:** a Fizetés ablakban minden eszköz külön sor (összeg, előleg, fizetendő) pipával. A
  fizetés készülékenként KÜLÖN pénztártétel lesz, a készülék nevével és tételeivel. Ha csak az egyik eszközt
  fizetik ki, az részfizetésként rögzül arra az eszközre, a munkalap nyitva marad. A mentés utáni „Bekerüljön a
  pénztárba?" ajánlat is készülékenként kér összeget.
- **RMA / ügyféloldal:** a tételek készülékenként csoportosítva, részösszeggel; a hibáknál a készülék neve; minden
  eszköz adatai az Eszköz lapon. **Számla:** a tételek megjegyzése előre kitöltve a készülékkel (név + IMEI),
  átírható vagy törölhető; a díj- és levonássorok nevében a készülék.
- Séma (DB_VERSION 0.30.0): `sdh_penztar.eszkoz_id`, `sdh_munkalap_eszkoz.fizetett` (az eszközre külön befizetett
  összeg); a tételek eszközét a 0.41-ben bevezetett `sdh_munkalap_tetel.eszkoz_id` tárolja (0 = a fő eszköz).

### 0.41.0
- **Hiba eszközhöz kötve:** több eszközös munkalapon minden hibasor elején választó áll, melyik eszköz hibája
  (alapból az első, azaz a fő eszközé). A „Gyakori hibák" popupban előbb az eszköz választható, utána a hibák –
  ugyanaz a hiba több eszközre külön felvehető. A részletek Hibák lapfülén és az ügyféloldalon is látszik, melyik
  készüléké. Ha egy eszköz lekerül a lapról, a hibái a fő eszközhöz kerülnek (nem vesznek el).
- **Bevizsgálási díj eszközönként:** a csatolt eszköz címkéjén saját díjmező van (kézzel vagy a díjlistából). Minden
  eszköz díja külön tételsor a munkalapon és a számlán, az eszköz nevével. Az előleg a díjak összege: a csatolt
  eszköz díjának beírásakor magától nő, levételekor csökken; a fő eszköz díja az előleg maradéka. „Javítás:
  levonódik" módban az összes díj levonódik a végösszegből. Egy eszközös lapon minden a régi.
- **Címke eszközönként:** a címkenyomtató oldalon minden eszköz saját címkét kap (1/3, 2/3…). Nyomtatás előtt
  bármelyik kihagyható (pipa), és az eszköz sora átírható. Több címkénél a nyomtatás nem indul magától.
- **Számla-előkészítő és számla:** a megjegyzés `{eszkoz}` és `{imei}` helyőrzője a lap minden eszközét felsorolja;
  a számla ablakában a megjegyzés és a díjtételek ugyanúgy átírhatók, törölhetők, mint eddig.
- **Javítás:** a listák típus-címkéi (Cég, Magánszemély, garanciális) széles dobozként jelentek meg, mert az
  osztálynevük ütközött a nyomtatott RMA-címkéével; a nyomtatott címke külön osztályt kapott.
- Séma (DB_VERSION 0.29.0): `sdh_munkalap_hiba.eszkoz_id`, `sdh_munkalap_tetel.eszkoz_id`,
  `sdh_munkalap_eszkoz.bev_dij / bev_nev / bev_szolg`.

### 0.40.1
- **Javítás – az AI-asszisztens nem válaszolt** („`temperature` is deprecated for this model"): az újabb Claude-modellek
  elutasítják ezt a paramétert, ezért kikerült a kérésből.

### 0.40.0
- **Több eszköz egy munkalapon:** a fő eszköz mellé akárhány további csatolható („Eszköz csatolása" a munkalapon:
  az ügyfél meglévő készülékei választó popupban, vagy új felvitele). A csatoltak címkeként állnak, egy kattintással
  levehetők. A kezdőképernyő rácsában „+2" jelzi, a részletek „Eszközök (3)" lapfüle mindet mutatja; a rács és a
  Munkalapok keresője a csatolt eszköz IMEI-jére, sorozatszámára, típusára is talál. A számla, a címke és az
  ügyféloldal továbbra is a fő eszközre épül. Új tábla: `sdh_munkalap_eszkoz` (DB_VERSION 0.28.0).
- **Állapotváltás a munkalapon:** már mentett lapnál az új állapot azonnal mentődik, a popup nyitva marad, a rács,
  a részletek és az Áttekintés csempéi (nyitott munkalap, ügyfél, eszköz, kassza) maguktól frissülnek.
- **Javítás – a munkalap becsukódott az állapotra kattintva:** kicsinyített (alacsony képernyőn zoomolt) popupban
  a legördülő lista a mező fölé csúszott, és az egér felengedése háttérkattintásnak számított. A lista most a
  mező alatt nyílik; popupot a háttér csak akkor zár be, ha a gombot is ott nyomták le (kihúzott szövegkijelölés
  sem zár be többé). Az irányítószám-lista helye is jó a kicsinyített popupban.
- **Gyakori hibák:** a munkalap Hibák lapfülén „Gyakori hibák" popup a húsz leggyakoribb készülékhibával – kattintásra
  felkerül a lapra, egymás után több is. Új hiba ott helyben felvehető (a listára is), a teljes lista a
  Beállítások › Gyakori hibák dobozban szerkeszthető (legfeljebb 60).
- **Másoló gomb minden adat mellett:** IMEI, sorozatszám, típus, név, cím, telefon, e-mail… a munkalap Eszköz
  lapfülén, a kezdőképernyő részleteiben (Munkalap, Eszköz, Ügyfél) és a rács celláiban (a sorra állva). Belső
  hálózati, http-s címen is működik.
- **Teljes cím mindenhol:** az ügyféllistában, a Munkalapok listában, az ügyfélválasztóban és a globális keresőben
  irányítószám + település + utca áll (nem csak a település); a rácsban új **Cím** oszlop, levágás („…") helyett
  több sorba törve. Az ügyfél címére keresni és rendezni is lehet. Az ügyfélűrlapon az utca mezője szélesebb.
- **Popupok neon kerete:** minden felugró ablak világító keretet kap. A szín és a fényerő az Arculat › „Popupok –
  neon keret" dobozban állítható (kiemelő szín, cián, kék, lila, magenta, narancs, lime, egyéni színkód, vagy
  kikapcsolva), élő előnézettel; a dolgozó a profilmenüben a saját gépén mást választhat.
- Az Áttekintés adminos csempéi (Arculat, Beállítások, TAC) az admin oldalára visznek (eddig holt linkek voltak).

### 0.39.0
- **Csapat: két mód** (Beállítások › Csapat – üzenetek). **Többfelhasználós csevegés** – a 0.38-as rendszer
  (saját fiók kollégánként, személyes / csoportos / mindenkinek szóló beszélgetések). **Üzenőfal** – ha a cég egy
  közös fiókkal használja a CRM-et (ott a csevegésben mindenki mindent látna): a Csapat menüpont helyén egy
  elnevezhető fal (alap: „Üzenőfal") cetlikkel, mint a Windows Öntapadó jegyzetei.
- **Cetli:** „+ Új cetli" popup – üzenet, **kinek szól** (egy kolléga vagy Mindenkinek), **ki írta**, szín (sárga,
  rózsaszín, zöld, kék, lila, narancs). A fejlécénél fogva szabadon áthúzható (a helye mentődik, a fal méretéhez
  arányosan), „Elintézve" pipa (áthúzott szöveg), szerkesztés, levétel a falról (második kattintásra; a „Levett
  cetlik" lapon visszatehető – semmi nem törlődik). Szűrés kinek szól (egy kolléga szűrője a mindenkinek szólókat is
  mutatja), „Rendezés" rácsba állítja a cetliket. A kollégák nevei a Beállításokban (fiók nélkül), üresen a fiókok
  nevei. Új tábla: `sdh_csapat_cetli` (DB_VERSION 0.27.0).
- Az **AI-asszisztens** üzenőfal módban a csapatüzenet helyett cetlit javasol a falra (jóváhagyással, visszavonható).
- **Pénztár-táblázatok:** minden cella függőlegesen középre igazítva (a kétsoros leírás mellett sem csúsznak el a
  számok), az összegek és a sorszám balra igazítva, a fejlécükkel együtt.

### 0.38.0
- **Új modul: Csapat – belső üzenetek** (oldalmenü: Csapat). Üzenet egy kollégának, több kiválasztott kollégának
  (közös beszélgetés, saját névvel), egy csoportnak vagy **mindenkinek**. Élő beszélgetés (3,5 mp-enként frissül),
  **válasz idézettel** (a buborékon a Válasz gomb), „gépel…" jelzés, **„Látta"** a saját üzeneten, elérhető (zöld pont).
  **Fontos üzenet:** felugró ablakban jelenik meg a címzetteknek, amíg „Elolvastam"-mal nyugtázzák (a küldő látja, ki
  olvasta el). **Kitűzés:** az üzenet a beszélgetés tetején marad – üzenet hagyása a rendszerben. Saját üzenet
  visszavonható (dupla kattintással megerősítve; a nyoma megmarad). Görgetősáv helyett „Korábbi üzenetek" és lapozó.
- **Minden oldalon:** az új üzenet felugró kártyán jelenik meg a bal alsó sarokban, **gyors válasszal**; az oldalmenü
  Csapat jelvénye az olvasatlanok száma; az Áttekintés Csapat-csempéje is mutatja. Egy közös „pulzus" kérés
  (15 mp, háttérben 60 mp) szolgálja ki az üzeneteket és az asszisztenst.
- **Kollégák** (Csapat › Kollégák, csak adminisztrátor): új kolléga felvétele popupban – név, e-mail, felhasználónév,
  jelszó (üresen erőset generál; egyszer látszik), beosztás, szín, csoportok, szerep (Kolléga / Adminisztrátor),
  letiltás (azonnal kilépteti; törlés nincs). **Csoportok** (Csapat › Csoportok): név, szín, tagok; saját beszélgetés.
- **Jogosultság:** új „SDH kolléga" szerepkör a `sdh_muhely_hasznal` joggal – a CRM napi moduljait használja, a
  WordPress-adminba nem jut be (belépés után a műhelybe kerül). A Beállítások, az Arculat, a TAC és a Karbantartás
  csak adminisztrátornak látszik.
- **Új modul: Asszisztens – AI (Claude, Anthropic).** Az „élő" figura (alapneve: Szikra) minden oldal jobb alsó
  sarkában: lélegzik, pislog, követi az egeret, elalszik és felébred, buborékban szól. **F1** vagy kattintás nyitja a
  beszélgetést. Kérdezni lehet tőle bármit a rendszerről; **belelát** a munkalapokba, ügyfelekbe, eszközökbe,
  termékekbe, szolgáltatásokba, a pénztárba és a csapatüzenetekbe; **vezet**: a képernyőn neon kerettel megmutatja,
  hova kell kattintani (odarepül egy képregénybuborékkal), és gombot ad a megfelelő oldalra / rekordra.
- **Csak emberi jóváhagyással cselekszik:** minden módosítás javaslat-kártya (Engedélyezem / Elutasítom). Átírás és
  törlés csak a felhasználó kifejezett kérésére, és a kártyán egy megerősítő kódot is be kell írni. Végrehajtás előtt
  **mindig mentés**: a rekord teljes előtte-állapota a műveletnél, és naponta az első módosítás előtt teljes mentés
  (Asszisztens › Mentések; az utolsó 20 marad, letölthető). Minden végrehajtott művelet **visszaállítható**
  (a művelet számának beírásával). Ügyfelet és munkalapot elvből nem töröl.
- **Tanul:** a tudását a rendszerből építi (modulok, ez a verziónapló, munkalap-állapotok, fizetési módok,
  adatbázis-szerkezet); verzióváltáskor az újdonságokat megtanulja és szól („Frissültem, ezt tanultam"). Tudástár
  (Asszisztens › Tudástár): kézzel tanítható, és egy jó válasznál a „Jegyezd meg" gomb jóváhagyással elmenti.
  Hüvelykujj-értékelés a válaszokon.
- **Magától szól** (API nélkül, helyben számolva): lejárt / ma lejáró határidejű munkalap, lezáratlan tegnapi kassza,
  jóváhagyásra váró javaslat, űrlaphiba („Hibát látok – segítsek?"). Popup nyitva tartásakor is kéznél van.
- **Beállítások** (Asszisztens › Beállítások, adminisztrátor): be/ki, név, modell (alap `claude-sonnet-5-5`, ha az API
  nem ismeri: `claude-haiku-4-5-20251001`), API-kulcs (titkosítva; vagy `SDH_MUHELY_CLAUDE_KEY` a wp-config.php-ban,
  végül a Levelezés kulcsa), napi kérdéskeret kollégánként, „magától szól", „olvasáshoz is engedélyt kér",
  és eszközönként, mit tehet.
- Új táblák: `sdh_csapat_*` (beszélgetés, tag, üzenet, nyugta, csoport, csoporttag), `sdh_ai_uzenet`,
  `sdh_ai_muvelet`, `sdh_ai_tudas`. DB_VERSION 0.26.0.

### 0.37.0
- **Bevizsgálási díj – levonás mínusz tétellel.** A munkalapon a „Fizetett (előleg)" + „Bevizsgálási díj" jelölő
  továbbra is +díj szolgáltatássort ad. Új „Ha javítás" választó: **Javítás: levonódik** (alap) – a díj mellé egy
  `bevizsgalas_le` forrású **−díj** sor kerül (legfeljebb a többi tétel összegéig), így a végösszeg a javítás ára,
  a fizetendő javítás − előleg; **Munkadíj marad** – nincs levonás, a díj munkadíjként marad. Nincs más tétel →
  nincs levonás (a díj marad). A −sor csak olvasható, a szinkron tartja karban minden tételváltozás után
  (`SDH_Muhely_Tetel::ujraszamol` → `SDH_Muhely_Szamla::bevizsgalas_szinkron`); a számlán negatív tételként megy.
  Beállítás: a levonás sorának neve (`bevizsgalas_le_sablon`), a díj nevében új helyőrző: `{dij}`.
- **Díj a listából:** a jelölő melletti „Díjlista" gomb választót nyit (kategória, kód, ár, keresés) – a választott
  díj az előlegbe kerül, a jelölő bepipálódik, a díj neve a laphoz mentődik (`bevizsgalas_nev`, `bevizsgalas_szolg`,
  `bevizsgalas_mod`; DB_VERSION 0.25.0).
- **A díjak a Szolgáltatások között:** új mezők – kategória, kód, ár felső határa (sávos ár), „Bevizsgálási díj"
  jelölő, sorrend. Nézetek: Mind / **Bevizsgálási díjak** / **Árlista** (kategóriánként, nyomtatható, az árak és a
  díj-jelölő helyben írhatók, „Árak mentése"). Az árlistás sor árát a munkalap nem írja felül.
- **Árlista beillesztése:** a saját díjlista szövegként bemásolható (weboldal, Word, Excel), élő előnézettel;
  „Kategória: 100-" fejléc → bevizsgálási díjak, kód zárójelben, sávos ár, felár ≠ díj, ismételt beillesztés frissít.
  **A plugin nem tartalmaz árat** – a díjlista a felhasználó adata.
- **Cégadat nélkül:** a pénztár kivétre jogosultjai nincsenek beégetve; meglévő telepítésen a korábbi kivétek
  neveiből töltődnek fel. Választók lapozója rajzolt nyíllal.

### 0.36.0
- **Levelezés:** a fiókok különálló pillek a doboz fölött (nem rátapadó lapfülek), köztük hézaggal, a doboz minden
  sarka kerek – nem csúsznak össze. A lapozó nyilai rajzok (nem ‹ › betű), a gomb közepén ülnek, nagyobbak.
- **Oldalmenü:** összecsukva a jelvény csak egy kis pont az ikon sarkán (nem lóg az ikonba); kinyitva 999 fölött
  „999+", a pontos szám a súgóban.
- **Áttekintés csempéi szerkeszthetők** (Beállítások → Áttekintés csempéi, és az Áttekintés „Csempék szerkesztése"
  gombja): látszik / rejtve, sorrend nyilakkal, saját cím és alsó felirat, a szám ki-be. Option `sdh_muhely_csempek`;
  „Mindent vissza az alapértelmezésre".
- **Pénztár-táblázatok Excel-szerű rácsa:** oszlopvonalak, sorszám (#), halványan színezett pénzoszlopok
  (KP kék, kártya narancs, utalás zöld, kifizetés piros) és színes fejlécvonal, sorkiemelés rámutatásra.
- **Kipipálható sorok:** minden sor elején pipa – kipipálva a sor áthúzva, halványítva (pl. egyeztetéskor „ezt már
  megnéztem"); a fejléc pipája az egész napot váltja. A szerveren megmarad (`sdh_penztar.jelolt`, DB_VERSION 0.24.0),
  az összegekre nincs hatása.

### 0.35.1
- **Import javítás:** a 30 000. sor után „A szerver nem várt választ adott (500)" hibával megállt – a záró lépés
  (~2400 nap összesítése, lezárása, záró összegei) napronként külön lekérdezéssel ment (~7000 lekérdezés), és
  kifutott az időből. Most külön kérésben fut, csomagolt `INSERT … ON DUPLICATE KEY UPDATE`-tel (~22 lekérdezés).
- `SDH_Muhely_Penztar::ujraszamol()` sok nap változásánál (import, régi tétel javítása) csomagokban ír (`napok_ir`).
- Félbemaradt import folytatható: újrafeltöltés → „Importálás" csak a hiányzó sorokat és a zárást végzi el; a
  korábbi félkész („nyitott") napokat is rendbe teszi. Hiba esetén „Folytatás innen" gomb, és végzetes PHP-hibánál
  is olvasható üzenet jön (nem csak „500").

### 0.35.0
- **Új modul: Házipénztár** (oldalmenü: Pénztár) – a régi „Zárás" Excel-tábla utódja. Lapfülek: **Ma**
  (KPI-csempék, gyorsbeviteli sor Enterre, a nap tételei az Excel oszlopaival), **Napló** (minden nap egyben,
  napi összesítővel, lapozva, dátumra ugrás, csak eltérés / lezáratlan szűrő), **KP – kassza** (címletenkénti
  számolás, élő összevetés a várhatóval, zárás), **Riport**, **Statisztika**, **Ügynök**, **Keresés**.
- **Napok:** minden nap magától nyílik; a nyitó a tegnapi záró (a megszámolt, ha volt számolás). Zárás után
  a tétel módosítása naplózódik, a nap eltérése újraszámolódik; újranyitás is van. Összesítők gyorsítótára:
  `sdh_penztar_nap`; tételek: `sdh_penztar` (a kivét / kifizetés a KP-oszlopban negatív); változásnapló:
  `sdh_penztar_naplo`. Törlés csak jelölés. DB_VERSION 0.23.0.
- **Kivét:** mindig negatív, és csak a Beállításokban megadott személyek közül (becenevekkel, amelyekből az import
  is felismeri). A plugin nem hoz magával nevet (0.37.0 óta).
- **Munkalap:** új **Fizetés** gomb a láblécben (mentés → fizetés ablaka: teljes vagy előleg, mód, összeg,
  „kerüljön a házipénztárba"); a „Fizetve" pipa vagy a nőtt előleg mentése után ajánlat-ablak
  („Bekerüljön a házipénztárba?" – Beírás / Nem kell / Később). A „Nem kell" nyoma megmarad (`kihagyva`).
- **Számla:** kiállítás után a munkalap számla nélküli pénztártételei megkapják a számlaszámot
  (`sdh_muhely_szamla_kiallitva`); ha a pénz még nincs a pénztárban, ajánlat (app.js: `sdh:szamla-kesz` esemény).
- **Keresés:** számlaszámra, munkalapszámra (a CRM munkalapja és számlái is megjelennek, jelezve, ha a pénz nincs
  a pénztárban), összegre, vonalkódra, szavakra; szűrők: időszak, típus, mód, összeghatár, számla / munkalap nélkül,
  törölt tételek.
- **Ügynök** (`SDH_Muhely_Penztar_Ugynok`, csak javasol): a nap egyeztetése a munkalapokkal és a számlákkal
  (fizetve, de nincs a pénztárban; KP ↔ kártya tévesen; kétszer rögzítve; elgépelés; törölt tétel; előző nap),
  és kiszámolja, mely tételek adják ki pontosan az eltérést. Nyitott ügyek az utolsó 21 napból, „mind beírása"
  a pótolható számlaszámokra. Kérdés szabad szöveggel: a Levelezésnél megadott Claude API-kulccsal AI válaszol
  (csak olvas), kulcs nélkül a helyi nyomkövetés. Zárás eltéréssel → az ügynök azonnal lefut.
- **Import** (`SDH_Muhely_Penztar_Import`): .xlsx (saját olvasó: ZipArchive + XMLReader, streamelve) vagy CSV;
  előnézet, majd 2500 soros adagok folyamatjelzővel; tartalom alapú kulcs → újrafeltöltéskor csak az új sorok
  kerülnek be; „korábbi import cseréje" és visszavonás. A régi tábla napi „KP a kasszában" összege a nap záró
  összege lesz – így az utolsó nap pontosan a táblában lévő egyenleggel zár.
- **Riport / statisztika:** időszakok (ma … mind, egyéni), CSV-export a régi tábla oszlopaival, nyomtatás;
  halmozott oszlopdiagram (KP / kártya / utalás), a hét napjai, kategóriák (a leírás kulcsszavaiból, Beállításokban
  szerkeszthető), bizonylatoltság, kivét személyenként, kifizetés partnerenként, évek × hónapok, eltérések.
- Áttekintés: új „Pénztár" csempe (a kasszában várható összeg). `SDH_Muhely_Levelezes::ai_kulcs()` – a Claude-kulcs
  más modulnak (csak szerveroldalon).

### 0.34.0
- **Levelezés: minden e-mail-cím külön lapfülön.** A fiókok eddig egymás alatt álltak egy görgethető hasábban;
  most a doboz tetején lapfülek vannak (név, cím, olvasatlan és azonnali teendők száma, zárolt fióknál lakat).
  Egyszerre egy fiók mappái és levelei látszanak. A legutóbb nézett lap megmarad (böngészőnként).
- **A két cím levelei semmiben nem keverednek.** A „Teendők" eddig minden nyitott fiók jelzéseit egy listában
  mutatta – most fiókonként külön van (a szerver is fiókra szűr). A felugró értesítés kiírja, melyik címre jött
  a levél; rákattintva a levél saját fiókjának lapja nyílik meg. Lapfülváltáskor a megnyitott levél bezárul.
- **Oldalmenü-jelvény:** kisebb (16 px), nincs körülötte kilógó gyűrű, és nem villog végtelenül – új levélnél
  kétszer halványul el a saját helyén, azonnali teendőnél sem lüktet.
- **Levélíró:** a feladó nem natív legördülő, hanem „pill"; a választás felugró ablakban történik
  (`SDH_MUHELY_APP.pillek` – más modul is használhatja). A csatolás gombja is a közös formát kapta, a kiválasztott
  fájlok neve mellette látszik.
- **Ablakgombok macOS módra:** minden felugró ablak bal felső sarkában piros (bezárás) és zöld (teljes képernyő)
  kör (`app.js: ablakGombok`); a jel rajz, ezért pontosan középen ül, és akkor látszik, ha az egér a gombokon jár.
  Színek: `--sdh-ablak-piros`, `--sdh-ablak-zold`.
- **Pluszjel:** a hozzáadó gombokon rajzolt ikon (`.sdh-plusz`) áll a betű helyett – a betűkészlet pluszjele a sor
  közepe alatt ült. A fejléc „+ …" kezdetű gombjai maguktól ikont kapnak.

### 0.33.0
- **Gyors levélbetöltés.** A levelező eddig minden kattintásnál megvárta a levelezőszervert (kapcsolódás,
  belépés, szinkron); most előbb a saját gyorsítótárából rajzol, és a háttérben frissít.
  - A **lista** a gyorsítótárból jön azonnal, a szerverről a háttérben frissül (új levelek, máshol történt változások).
  - A **levelek törzse** titkosított gyorsítótárba kerül (`uploads/sdh-muhely-levelek`, 30 napig); a megnyitott
    oldal és az újonnan érkezett levelek törzsét a rendszer **előre letölti** (300 KB-ig levelenként), így a megnyitás
    nem vár a szerverre. Az „olvasott" jelzés külön háttérkérésben megy a Gmailbe.
  - A **műveletek** (törlés, áthelyezés, csillag, olvasott) azonnal látszanak; a szerver a háttérben követi.
  - Kevesebb adat és kérés: a listához csak a szükséges fejlécmezők jönnek le, az első szinkron 50 levél (a többi lapozáskor).
  - Mérés mesterséges hálózati késleltetéssel (0,7 mp kapcsolódás + belépés, 0,1 mp / parancs): oldal megnyitása
    1,9 mp → 0,15 mp; mappaváltás 1,7 → 0,06; levél megnyitása 1,5 → 0,09; törlés 3,6 → 0,06. Valódi Gmailen nem mért.
- **Rendező ügynök** (`SDH_Muhely_Level_Rendezo`): a Levelezés „Rendező ügynök" gombjával kérésre leveleket rendez
  mappákba. **Két dolgot tehet: leveleket áthelyez, és – engedélykérés után – új mappát hoz létre. Semmi mást.**
  Előbb tervet mutat (mely levelek hová), és csak a jóváhagyott részt hajtja végre. A jogai a Beállításokban
  kapcsolhatók, csak ott: bekapcsolva-e, mozgathat-e, létrehozhat-e mappát, tehet-e a Kukába / Spambe (alapból nem),
  kell-e jóváhagyás, hány levelet kérésenként, honnan, mely fiókokban. Alapból **ki van kapcsolva**. Szabad szavas
  kéréshez Claude API-kulcs kell; anélkül szűrővel dolgozik (feladó / tárgy tartalmazza → mappa).
- **Felugró jelzések**: egyszerre legfeljebb 4 látszik, a többi „+N további új levél" összesítőbe kerül – sok levélnél
  sem lógnak ki a képernyőről. „Összes bezárása".
- **Áttekintés**: minden menüpont csempe a tetején (Levelezés: olvasatlan levelek és azonnali teendők, élőben);
  a fejléc „Csempék elrejtése / megjelenítése" gombjával eltüntethetők, a választás böngészőnként megmarad.

### 0.32.0
- **Levelezés – a szerviz e-mail-fiókjai a CRM-ben** (új modul az oldalsávban). A fiókok IMAP-on és SMTP-n,
  **alkalmazásjelszóval** kapcsolódnak (Gmail: `imap.gmail.com:993`, `smtp.gmail.com:465`); a levelek a
  Gmailben maradnak, a CRM csak a fejadatok gyorsítótárát tartja (`sdh_level`, `sdh_level_mappa`).
  Amit itt csinálsz, az a valódi postafiókban történik – a telefonon is úgy látszik.
  - **Három hasáb**: fiókok és mappák – levéllista – olvasó. **Minden mappa** (Gmail-címke, almappával)
    látszik; a szerepüket (Elküldött, Kuka…) a szerver jelzőiből tudja, nem a fiók nyelvétől függő névből.
  - **Műveletek**: új levél, válasz, válasz mindenkinek, továbbítás (az eredeti csatolmányaival),
    csatolmány fel- és letöltés, piszkozat, olvasott/olvasatlan, csillag, archiválás, áthelyezés,
    spam / nem spam, törlés (Kuka), végleges törlés (rákérdez), keresés (Gmailnél a Gmail keresője),
    mappa létrehozása, átnevezése, törlése.
  - **Új levél jelzése minden CRM-oldalon**: felugró jelzés, hang, jelvény az oldalsávban, és (ha engedélyezed)
    a böngésző értesítése. Ami máshol (telefonon) történt – olvasás, törlés –, azt a lista követi.
  - **Jelszóval védett fiók**: a Beállításokban fiókonként külön CRM-jelszó adható; amíg valaki be nem írja,
    annak a fióknak semmilyen adata nem megy ki a böngészőbe (a szerver ellenőrzi), értesítésben is csak annyi,
    hogy levél érkezett. 5 hibás próba után 15 perc szünet.
  - **Biztonság**: az alkalmazásjelszó titkosítva áll az adatbázisban, a HTML-be soha nem kerül vissza.
    A levél HTML-je szkript nélküli, elzárt keretben jelenik meg; a távoli képek alapból tiltva (a feladó nem
    látja, hogy megnyitottad); a csatolmány mindig letöltésként megy ki.
- **Fontos-levél ügynök** (`SDH_Muhely_Level_Ugynok`): minden beérkező levelet besorol – **Azonnal** (1 órán
  belül), **Ma**, **Ráér**, **Zaj** –, egymondatos indokkal, és szól az azonnaliakról („Teendők" nézet,
  kiemelt jelzés, ami nem tűnik el magától). **Mást nem csinál és nem is tud**: a postafiókhoz nincs
  hozzáférése, csak a levél szövegét kapja meg. A protokoll a hatás × sürgősség mátrix (P1–P4) szervizre
  igazítva: azonnali a hatósági / jogi ügy, a fiókbiztonság, a gyártói partner teendővel, a fizetés vagy
  szolgáltatás leállása, az ügyfélpanasz, a sürgősnek jelzett ügy és a kiemelt feladó. Saját listák a
  Beállításokban (kiemelt feladók, partnerek, figyelt kifejezések, zajlista). Nem kötelezően Claude API-kulccsal
  az AI finomíthat – de amit a szabályok azonnalinak ítélnek, azt nem minősítheti le. A megválaszolt levél
  magától lekerül a teendők közül.
- Saját IMAP-kliens és MIME-feldolgozó tiszta PHP-ben (`includes/lib/sdh-imap.php`, `sdh-mime.php`) – a
  php-imap bővítmény nem kell. DB_VERSION 0.22.0 (két új tábla).

### 0.31.0
- **A számla ablaka szerkeszthető** – mindhárom gombnál (Számlázz.hu számla, SDH számla, helyi nyomtatvány):
  - **Tételek**: „+ Termék…" és „+ Szolgáltatás…" (kereshető, lapozható választó), „+ Kézi tétel" (üres sor);
    az új sor neve, mennyisége, bruttó egységára és áfakulcsa átírható, a × törli. A munkalap tételei
    továbbra is pipával vehetők ki. A számla kiállításakor az új tételek a munkalapra is rákerülnek
    (a termék a készletből is fogy); a helyi nyomtatványnál csak a papírra.
  - **Megjegyzés tételenként**: a „+ megjegyzés" a megnevezés alatt nyit mezőt; a számlán (és a helyi
    PDF-en) a tétel alatt jelenik meg.
  - **Adattörlő kód**: tételenként kapcsoló; a Számlázz.hu rendeli a kódot a tételhez (`torloKod`,
    darabonként egy). A Beállításokban ki-/bekapcsolható („Adattörlő kód használata"), és a
    Számlázz.hu-fiókban is be kell kapcsolni.
  - **Vevő**: név, adószám, cím és e-mail átírható – az ügyfél adatlapja ettől nem változik.
    „Listáról…": az ügyfelek és a korábbi számlák vevői. Adószámból (8 vagy 11 számjegy után magától,
    vagy a „Cég keresése" gombbal) a név és a székhely a NAV-tól jön, a Számla Agenten keresztül.
    Beírt cégnévre a saját listában keres: pontos egyezésnél kitölt, több találatnál a választó nyílik.
    Külső cégadatbázis a `sdh_muhely_cegkereso` szűrővel köthető be.
  - **E-mail az ügyfélnek**: a pipa már akkor is használható, ha az ügyfélnek nincs e-mail-címe –
    ilyenkor bekéri; a beírt cím az ügyfélnél is megmarad.
  - Az Enter a számla ablakának szövegmezőiben nem állít ki számlát.

### 0.30.0
- **Helyi nyomtatvány (számla-előkészítő) – API nélkül**: a munkalap láblécének harmadik gombja
  helyben készít letölthető, nyomtatható PDF-et a kipipált tételekből, a szerviz logójával és
  adataival (kiállító, adószám, bank, vevő, fizetési adatok, tételek nettó/áfa/bruttó bontásban,
  végösszeg). Internet és Számlázz.hu nélkül is megy, hiányos vevőcímmel is elkészül. Ez az a papír,
  ami a kész termék mellé kerül, és amiből később a számla készül.
- **Nem számla, és nem is nézhet ki annak**: a címe a Beállításokban választható (Számla-előkészítő,
  Díjbekérő, Elszámolás, Átadási bizonylat – „Számla" nincs köztük), a törzsben és minden oldal
  láblécében ott áll, hogy nem minősül számlának, a sorszáma pedig előtag-év-munkalapszám, saját
  előtaggal (alap: SDE), amely nem egyezhet a számlatömbök előtagjával. Nem megy róla adat sehová,
  és a számlák nyilvántartásába sem kerül.
- Beállítások → Számlázás → „Helyi nyomtatvány": cím, előtag, kiállító adatai, bank, lábléc, logó.
  A kiállító kezdőértékei a `data/kiallito.json`-ból jönnek (más szerviznek adott példányból kiürítendő),
  az alap logó az `assets/logo-nyomtatvany.png`.
- PDF: tFPDF 1.33 (LGPL) + DejaVu Sans Condensed, az `includes/lib/tfpdf` alatt – csak PDF-készítéskor töltődik be.

### 0.29.0
- **Számlázz.hu-összekötés** (`SDH_Muhely_Szamla`, Számla Agent): a munkalap-ablak láblécében
  „Számlázz.hu számla" és „SDH számla" gomb. A gomb elmenti a munkalapot, és megnyitja a számla
  ablakát – számla csak ott, a „Számla kiállítása" gombra készül. Az ablakban: a vevő (név, cím,
  adószám az ügyfélből), tételenként kipipálható, mi kerüljön a számlára, fizetési mód, teljesítés,
  határidő, „Fizetve", „E-mail az ügyfélnek", megjegyzés, és **Előnézet (PDF)** – előnézetnél számla
  nem készül. A kiállított számla száma és PDF-je a munkalapon marad (jelvény a láblécben).
- **Tételesen**: a számlára a munkalap szolgáltatásai, termékei és a bevizsgálási díj kerülnek, soronként.
  A számlázott tétel megjegyzi a számla számát, a következő számlán alapból nincs kipipálva, és a
  munkalapról nem törölhető – így a bevizsgálási díj átvételkor, a javítás a végén külön számlázható.
- **Bevizsgálási díj** jelölő az előlegnél: bepipálva az előleg külön tételsor a munkalapon és a
  számlán, a készülék típusával és fajtájával („Samsung SM-A175B mobiltelefon bevizsgálási díj").
  A sort a rendszer tartja karban; a megnevezés sablonja beállítás.
- **SDH számla**: ugyanaz a Számlázz.hu számla, a beállított előtaggal (alap: SD) – a Számlázz.hu
  külön, folyamatos sorszámot vezet hozzá (SD-2026-1, -2…). Az előtagot a Számlázz.hu-n fel kell
  venni (Beállítások → Előtagok). Saját, a Számlázz.hu-t megkerülő számlakészítés nincs.
- **Beállítások → Számlázás – Számlázz.hu**: Agent kulcs (a HTML-be soha nem kerül vissza),
  „Kapcsolat ellenőrzése" (számla nélkül), e-számla / papír, előtagok, fizetési mód, határidő,
  e-mail, megjegyzés- és díjsablon.
- Magánszemélynél az adóazonosító jel nem megy a számlára (csak a valódi, 8-1-2 alakú adószám).
- Séma 0.21.0: `sdh_szamla`, `munkalap.bevizsgalasi_dij`. A PDF-ek védett mappában, kitalálhatatlan
  néven; csak belépett, jogosult felhasználó nyithatja meg.

### 0.28.0
- **Termékek modul** (`SDH_Muhely_Termek`, oldalsáv: Termékek) – a MunkaLap 3 „Tétel" ablakának megfelelője.
  Lista széltől szélig (kódok, készlet, beszerzési és eladási ár, készletérték Σ), kereső minden kódra,
  kategóriaszűrő, rendezhető oszlopok, nézetfülek: Összes / Készleten / Rendelni kell / Inaktív /
  Összes tételmozgás. Felvitel és szerkesztés popupban: balra a kódok (gyári szám, cikkszám, termékkód,
  vonalkód, osztály, könyvelve), jobbra megnevezés, kategória, beszállító, készlet, árak; alul
  Leírás / Megjegyzés / Készletmozgás fül. Az űrlap élőben számol: nettó ↔ bruttó, haszonkulcs ↔ eladási ár.
- **Készlet**: minden változás egy sor a `sdh_termek_mozgas` táblában (nyitókészlet, bevételezés,
  korrekció, munkalap); a termék készlete ezek összege. Az űrlapon átírt készlet mozgásként rögzül
  (okkal). A munkalapra tett termék magától levonódik – csak számozott, nem érvénytelen lapnál;
  az árajánlat és a sablon nem fogyaszt, az érvénytelenített lap visszaadja a készletet.
- **Termékválasztó a munkalapon**: a „+ Termék" gomb és a Megnevezés végén álló gomb popupot nyit
  (kereső névre és minden kódra – vonalkódolvasó + Enter –, lapozás, szerkesztés, új termék,
  „Kézzel írom be"). A választás kitölti a nevet, a kódokat, az egységet, az árat és az áfakulcsot;
  a tétel megjegyzi a terméket (`munkalap_tetel.termek_id`).
- **20 fiktív mintatermék** nyitókészlettel: helyi/fejlesztői telepítésen és demó adatok mellett a
  frissítés magától betölti (üres terméktörzsbe), egyébként az üres lista gombjával tölthető be.
- **RMA ügyféloldal**: a munkatársi előnézetből (`?elonezet=1`, a CRM „Ügyféloldal" gombja) eddig nem
  lehetett üzenetet küldeni – most lehet, az üzenet az ügyfél nevében kerül a szálba. A valódi
  ügyfél (telefonszám + sorszám után) eddig is tudott írni; kijelentkezve az előnézet nem él.
- Séma 0.20.0: `sdh_termek`, `sdh_termek_mozgas`, `munkalap_tetel.termek_id`.

### 0.27.0
- **QR és vonalkód javítva az Áttekintésben**: a részletpanel ikon-szabálya (`stroke: currentColor`)
  összefolyatta a QR-modulokat. A kód-SVG-k inline stílussal védettek; a címke-blokk
  (`Rma::cimke_blokk`) ugyanaz a nyomtatáson, az Áttekintésben és a munkalap-ablakban.
  A vonalkód a címkén kitölti a szélességet, a szám alatta áll.
- **RMA lapfül a munkalap-ablakban** (a Termékek mellett): címke, üzenetküldés, állapottörténet;
  lapozás görgetősáv helyett. Az Áttekintés „RMA / Üzenetek" füle a munkalap-ablakot nyitja
  meg az RMA fülön (`data-sdh-ful`, `sdh:urlap-betoltve` esemény az app.js-ben).
- **Olvasatlan-jelzés**: új „Üzenetek" menüpont (postafiók, munkalaponként), piros jelvény az
  oldalmenüben (45 mp-enként frissül) és a wp-admin menüben; az RMA fül jelvénye piros, ha új
  üzenet van. A fül megnyitásakor az üzenetek olvasottra állnak.
- **Ügyféloldal újratervezve** (`SDH_Muhely_Rma_Oldal`, assets/rma-ugyfel.css/js): ablak a háttér
  fölött a CRM formanyelvével – fejléc (logó, munkalapszám, állapot), bal oldalt állapot,
  dátumok, bruttó (piros) és fizetendő, jobbra lapfülek (Eszköz, Hibák, Tételek, Történet,
  Üzenetek, Elérhetőség). Mobilon teljes képernyő, „Összegzés" fül. Világos/sötét mód,
  nyelvválasztás (magyar, angol, német). Munkatársi előnézet: `?elonezet=1` / `?elonezet=zar`.
- **Beállítások → Ügyféloldal**: logó, háttér (alap / szín / kép / videó, sötétítés, üveghatás)
  a médiatárból, kiemelő szín, alapmód, nyelvek, elérhetőség (cím, telefon, e-mail, weboldal,
  térkép, nyitvatartás, egyéb).

### 0.26.0
- **QR-kód és vonalkód minden munkalaphoz** (`SDH_Muhely_Kodok`, szerveroldali SVG, offline is):
  a QR az ügyfél saját oldalára mutat, a vonalkód (Code 128) a munkalapszámot hordozza.
  Nyomtatható címke: `/?sdh_muhely_cimke=<id>` (belépéshez kötött).
- **Ügyféloldal (RMA)**: `/rma/<token>/` (szép URL nélkül `/?sdh_rma=<token>`). Zárva nyílik,
  telefonszám (06304004636 / +36… is jó) + munkalap sorszáma oldja fel; aláírt süti, 90 nap.
  Próbálkozási korlát: 8 / 15 perc / IP + munkalap. Mutatja: állapot, utolsó módosítás,
  fizetendő (kiemelve), adatok, hibák, tételek, állapottörténet, üzenetek; az ügyfél válaszolhat.
- **Állapotnapló** (`sdh_munkalap_naplo`): minden állapotváltás egy sor (esemény:
  `sdh_muhely_munkalap_allapot`). Sémafrissítéskor a régi lapok kezdősort kapnak.
- **Üzenetek** (`sdh_uzenet`, irany ki/be, csatorna crm/rma): a munkalap részletein új
  „RMA / Üzenetek" lapfül (QR, vonalkód, link, címke, üzenetküldés, állapottörténet).
  Ügyfélválasz → e-mail a beállított címre (Reply-To: az ügyfél); CRM-üzenet → kérésre
  e-mail az ügyfélnek a linkkel.
- **Beállítások → Ügyféloldal**: nyilvános cím (ha a CRM nem érhető el az internetről),
  szerviz neve, értesítési e-mail, elérhetőség.
- **Áttekintés részletpanel**: a Munkalap lapfül bal sávjában a Megjegyzés alatt a bruttó
  összeg pirossal, és ha fizetni kell, a fizetendő összeg kiemelve.
- Séma 0.19.0: `munkalap.rma_token`, `sdh_munkalap_naplo`, `sdh_uzenet`.

### 0.25.1
- **Minden oldal teljes szélességű**: az Ügyfelek, Eszközök, Munkalapok, Szolgáltatások és a többi
  oldal tartalma széltől szélig ér, ahogy az Áttekintés rácsa; az oldalsáv becsukásakor kitölti a
  felszabaduló helyet. A `.sdh-wrap` alapból korlát nélküli – új modulnál nincs külön teendő.

### 0.25.0
- **Szolgáltatások modul** az oldalsávban (az Ügyfelek, Eszközök, Munkalapok mellett): lista
  kereséssel (névsorban vagy a leggyakoribbak elöl), **új felvitel és szerkesztés popupban** –
  megnevezés, bruttó ár (a nettó élőben látszik), áfakulcs, mennyiségi egység, belső megjegyzés –,
  és törlés (két kattintás; a munkalapok tételei megmaradnak). `SDH_Muhely_Szolgaltatas`,
  AJAX: `sdh_muhely_szolgaltatasok_urlap` / `_ment`.
- **Választó popup a munkalapon** a lenyíló lista helyett: a „+ Szolgáltatás" gomb és a Megnevezés
  mező végi gomb (vagy ↓) külön ablakot nyit – kereső, táblázat (megnevezés, m.e., bruttó ár,
  használat), 10 soronként lapozva, görgetősáv nélkül. Sorra kattintás vagy ↑ ↓ + Enter választ;
  a ceruza szerkeszt; „+ Új szolgáltatás" felveszi és rögtön a munkalapra teszi; „Kézzel írom be"
  üres sort ad (app.js `szolgValaszto*`).
- **Egy név = egy sor** az űrlapon is: létező névvel felvitt vagy létező névre átnevezett
  szolgáltatás összeolvad a meglévővel (`torzsbe()`), a használat összeadódik.
- **Javítás**: a listák keresője nem szabványos porton (Local „localhost" mód) rossz címre küldött.
- **Séma 0.18.0**: `sdh_szolgaltatas` + `megjegyzes`.

### 0.24.0
- **Szolgáltatás-törzs**: amit a munkalap Szolgáltatások lapfülén egyszer beírtak, azt a rendszer
  megjegyzi (név, m.e., bruttó ár, áfakulcs) – külön felvinni nem kell, a munkalap mentése veszi
  fel (`SDH_Muhely_Szolgaltatas`, tábla: `sdh_szolgaltatas`).
- **Kereső a Megnevezés mezőben** (lenyíló lista) – a 0.25.0 választó popupra cserélte.
- **Egy név = egy sor**: az egyforma nevű szolgáltatás (kis-/nagybetű és szóközök nélkül nézve)
  nem jön létre még egyszer – a meglévő kapja az új árat és nő a használat-számlálója. Egyedi index
  védi; frissítéskor a meglévő tételekből nevenként egy sor kerül a törzsbe (`osszevon()`,
  `tetelekbol()`).
- **Javítás**: a „Fizetett (előleg)" mező nem kerek ezres összegnél (pl. 4 500 Ft) megfogta a
  munkalap mentését; a léptető továbbra is 1000-esével lép (`data-sdh-lepes`).
- **Séma 0.17.0**: új tábla `sdh_szolgaltatas`.

### 0.23.0
- **Munkalap-ablak a MunkaLap 3 elrendezésében**: balra oldalsáv (Állapot, Dátumok, Összeg,
  Tételek, Fizetés), jobbra az Ügyfél és az Eszköz, alattuk lapfülek: **Eszköz** (az eszköz és az
  ügyfél adatai, választáskor frissül), **Hibák**, **Szolgáltatások**, **Termékek**; legalul
  Megjegyzés (ügyfél felé is mehet) és Belső megjegyzés. Az ablak alapszélessége 1480 px.
- **Tételek szerkesztése**: szolgáltatás- és terméksorok (megnevezés, termékkód, cikkszám, gyári
  szám, mennyiség, m.e., bruttó ár, kedvezmény %, áfakulcs), soronként nettó és bruttó érték,
  lapfülenként Σ. Az ár **bruttó**; a nettót az áfakulcs adja. Gépelés közben számol (`app.js`
  `munkalapSzamol`), mentéskor a szerver számol újra (`SDH_Muhely_Tetel::mentes`).
- **Áfakulcs**: alapértelmezés mindig **27%**; a MunkaLap listája (5%, 18%, 27%, EAM, ATK, AAM,
  NAM, FOA, HO, KBAET, KBAUK, KSZH, KSZM, KSZR, KSZU, EUFAD37, EUE, APP, TAM) –
  `SDH_Muhely_Tetel::afakulcsok()`, szűrővel bővíthető. A lap kedvezménye és áfakulcsa minden
  tételsorra érvényes, és az új sorok is ezzel indulnak.
- **Előleg és fizetés**: a „Fizetett (előleg)" mezőbe írt összeg mindig levonódik a teljes
  összegből: Fizetendő = Bruttó − Fizetett (negatív is lehet, ha az előleg több); kifizetett lapon 0.
  „Fizetve" bejelölésekor a fizetés napja a mai nap. **Fizetési módok** a Beállításokban
  szerkeszthetők (alap: Átutalás, Bankkártya, Barion, Előre utalás, Halasztott KP, Készpénz,
  Kombinált, Kompenzáció szerint, PayPal, SZÉP Kártya, Utalvány, Utánvét).
- **Séma 0.16.0**: `munkalap` + `fizetesi_mod`, `kedvezmeny`, `afakulcs`, `ugyfel_megjegyzes`;
  `munkalap_tetel` + `afa_kulcs`. A rácsban új (alapból rejtett) oszlop: Fizetési mód.

### 0.22.0
- **Munkalap-popup széles és alacsony**: az űrlap ugyanazt a kompakt, lapfüles szerkezetet kapta,
  mint az ügyfél és az eszköz (címke + mező egy sorban, két szimmetrikus oszlop; alul
  „Hibasorok" és „Belső megjegyzés" lapfül). A régi, három egymás alatti dobozból álló űrlap
  túl magas volt, ezért a képernyőhöz kicsinyítve keskeny sávként jelent meg. A Készült és a
  Határidő a saját naptárat használja (nem a böngészőét); a hibasorok egy sorban, oszlopfejjel.
- **Minden űrlap-popup méretezhető és teljes képernyőre tehető** (`app.js` `meretezhetoveTesz`,
  a `vaz()` minden ablakára – új modulhoz nem kell semmi): ⬜ gomb a bezárás mellett, vagy dupla
  kattintás a címen; húzható a négy szél és a négy sarok (a popup középen marad, a szemközti szél
  együtt mozog). A méret űrlapfajtánként megmarad (`localStorage` `sdh-popup:<modul>`); dupla
  kattintás egy szélen = alapméret. Megnövelt ablakban a többlethelyet az alsó lapfülsor kapja.
  Görgetősáv így sincs: a magasság csak a tartalom fölé nőhet, és ha kell, a popup kicsinyít.

### 0.21.0
- **Kezdőképernyő = munkalap-rács** (a MunkaLap 3 főablaka): az összes munkalap egy rácsban,
  a MunkaLap oszlopaival (Sorszám, Jelzés, Állapot, Felelős, Azonosító, Gyártó, Típus, Garancia,
  IMEI, Sorozatszám, Ügyfél, Telefonszám, Készült, Határidő, Lezárva, Fizetve, Fizetés ideje,
  Bruttó é., Fizetett, Fizetendő, Belső megjegyzés; rejtve: Név, Megnevezés, Létrehozva, Módosítva).
  Minden a szerveren szűr, rendez és lapoz (`SDH_Muhely_Racs`, `assets/racs.js`, `assets/racs.css`).
- **Szűrők**: oszloponkénti szűrősor. Gépelve azonnal szűr; a ▾ gomb feltételt ad
  (tartalmazza / nem tartalmazza / egyenlő / kezdődik / végződik / üres…, számnál és pénznél
  `>`, `>=`, `<`, `<=`, `a..b`; dátumnál ma, tegnap, ezen a héten, ebben a hónapban, utolsó 7/30 nap,
  ma előtt, között). Az állapot, felelős, azonosító, garancia, fizetve, jelzés többes választó,
  kizárással. „Keresés a látható oszlopokban", „Szűrők törlése", összesítő (Σ) sor.
- **Nézetek**: beépítettek (Minden munkalap, Nyitott, Elkészült – átvételre vár, Fizetendő,
  Lejárt határidő, Ma készült, Árajánlatok és sablonok) és saját, névvel menthető nézetek
  (szűrés + rendezés + oszlopok; felhasználónként, `sdh_muhely_racs_nezetek`).
  Oszlopok: ki-be kapcsolás, sorrend a fejléc húzásával, szélesség a szélének húzásával.
- **Kijelölt sor + részletek**: a kijelölt munkalap sora színes (kiemelő szín + sáv), alatta
  lapfülek: Munkalap, Eszköz, Ügyfél (Adatok / Megjegyzés / Csatolt fájlok), Hibák,
  Szolgáltatások, Termékek, Számlák, Pénztárbizonylatok. Dupla kattintás vagy Enter: szerkesztés
  popupban; mentés és állapotváltás után a rács helyben frissül (`sdh:mentve`, `sdh:allapot`).
- **Séma 0.15.0**: `munkalap` + `jelzes`, `lezarva`, `fizetve`, `fizetes_ideje`, `fizetett`,
  `netto_ertek`, `brutto_ertek`; új `munkalap_tetel` tábla (szolgáltatások és termékek,
  `SDH_Muhely_Tetel`). A `lezarva` lezárt állapotba lépéskor áll be; a régi lezárt lapok az utolsó
  módosítás napját kapják. A frontend is ellenőrzi a sémát (nem csak az `admin_init`).
- **Demó**: a 10 ügyfél–eszköz párhoz 10 munkalap, a nyolc állapot mindegyikével, hibasorokkal,
  tételekkel, előleggel és kifizetéssel. Ahol már vannak demó ügyfelek, a frissítés magától pótolja.
  A számozott demó lapok valódi munkalapszámot kapnak.
- **Javítás**: a telt (elsődleges) gomb felirata linkként (`<a>`) a gomb színével egyezett
  (`.sdh-app a` felülírta) – világos módban üres piros gombnak látszott. Új változó:
  `--sdh-accent-felirat`.
- Még nincs: tételek és fizetés szerkesztése az űrlapon, számla- és pénztármodul
  (a két lapfül a MunkaLap oszlopaival, üresen jelenik meg), munkalaphoz csatolt fájl.

### 0.20.1
- **Saját legördülő menü** a böngésző natív listája helyett (minden egysoros `select`,
  popupban is): lekerekített panel, színpöttyös állapotok, pipa a kiválasztotton,
  billentyűzet (↑ ↓ Enter Esc). Az érték és a `change` esemény a selecten marad.
  Kikapcsolás egy selecten: `data-sdh-nativ`. A popup CSS zoomját követi.

### 0.20.0
- **Munkalapok fejléc**: egységes, lekerekített keresősáv (mező, választó, gombok
  azonos 36 px magas, pill forma) – az Ügyfelek és Eszközök listán is.
- **Állapot a listából**: a munkalap-lista állapot-jelvénye maga a választó
  (AJAX, `sdh_muhely_munkalapok_allapot`). Ugyanazok a szabályok, mint az űrlapon:
  számozott állapothoz ügyfél + eszköz kell, az első számozott állapotnál a lap
  munkalapszámot kap (`GET_LOCK` alatt). Hibánál az előző állapot áll vissza + jelzés.
- **Lezárt = zöld** (a sor halvány zöld hátteret kap), az Árajánlat lila. A korábban
  mentett alap-beállítás egyszer átíródik (`sdh_muhely_allapot_szin_v2`); saját színt nem bántunk.
- **Demó adatok** (Beállítások → Demó adatok betöltése): 10 magyar ügyfél minden mezővel,
  mindegyikhez 1 eszköz (érvényes Luhn-IMEI, minta, garancia, belső megjegyzés), idempotens.
- **Séma 0.14.0**: az `eszkoz` táblából hiányzó `belso_megjegyzes` oszlop pótlása
  (a 0.19.0-ban tévedésből csak az `ugyfel` táblába került).

### 0.19.7
- **Egyik popupban sincs görgetősáv**: ha a popup magasabb, mint a képernyő,
  arányosan kisebb lesz (CSS zoom, legalább 55%) – app.js `illesztPopup`.
  Újraszámolás: megnyitáskor, lapfülváltáskor, tartalom/mező változásakor, ablak-átméretezéskor.
- Készülékkép / fájlok lapfül: az üres jelölőkocka (üres képdoboz) eltűnt
  (`.sdh-kep:not(:has(img))`), tömörebb fájl-zóna.

### 0.19.6
- Eszközűrlap: **az Azonosítók lapfül megszűnt**, minden az Eszköz lapfülön van
  (IMEI 2., Modellszám, Zárkód/PIN, Ellenőrzés, Gyári adatok a mezők alatt;
  a feloldó minta jobbra, teljes magasságban). Lapfülek: Eszköz | Garancia és átvétel.

### 0.19.5
- **Feloldó minta: keresztezés.** A vonalak metszhetik egymást, egy pötty
  többször is érinthető, és a köztes pötty kihagyható. A „Szabad rajz
  (kattintással)” kapcsolóval pöttyönként kattintva épül a minta; alapban marad
  az Android-szerű behúzás. Új „Vissza” gomb (utolsó lépés). Tárolás: legfeljebb
  20 lépés, két egymás utáni nem lehet azonos (a szerver már nem dobja el az
  ismétlődő pöttyöt tartalmazó mintát). A nyílhegy a szakasz 60%-nál ül, hogy
  keresztezésnél ne fedjék egymást.

### 0.19.4
- Eszközűrlap: a **feloldó minta visszakapta az eredeti, 180 px-es méretét**
  (0.19.1 óta kicsinyítve volt); az alsó megjegyzés-dobozok visszaálltak 62 px-re.
  Az egyenlő panelmagasság (nincs ugrálás) csak a felső három fülre vonatkozik.

### 0.19.3
- Eszközűrlap: **fülváltáskor nem ugrál a popup** – a felső és alsó lapfülek
  panelei egy rácscellában vannak, így a popup magassága minden fülnél azonos
  (a legmagasabb panelé); a megjegyzés-szövegdobozok kitöltik a panelt.

### 0.19.2
- **Elavult oldal felismerése**: minden saját AJAX-válasz egy `X-SDH-Verzio`
  fejlécben megadja a friss CSS|JS verziót. Ha a nyitva felejtett oldal régi
  stíluslappal fut, az app.js az oldal újratöltése nélkül kicseréli; régi
  szkriptnél egyszer frissíti az oldalt (a popup ilyenkor még csak töltődik).
  Ok: fájlcsere után a régi oldal új űrlap-HTML-t kapott régi CSS-sel (az
  Azonosítók fül „összenyomva” jelent meg).

### 0.19.1
- Eszközűrlap: **IMEI és Sorozatszám az Eszköz lapfülön** (az Azonosítók fülről
  ide kerültek); az **Azonosítók fül tömör**: mezők balra, kicsinyített feloldó
  minta jobbra, az egész popup görgetés nélkül elfér.
- **Saját naptár** a böngésző beépített dátumválasztója helyett (hónap/év
  választó, Ma, Törlés); az érték továbbra is ÉÉÉÉ-HH-NN.
- **Szélesebb, alacsonyabb választó popupok**: a kategória és a gyártó
  csoportjai 4–5 oszlopban egymás mellett állnak; a tartozéklista 3 oszlopos.
- Javítva: a tartozék-popup megnyitásakor az első elem fókuszkerete
  elcsúszva/levágva látszott – megnyitáskor nincs automatikus fókusz, a keret
  belül rajzolódik, a görgetődoboz nem vágja le.
- A modal alcíme elmaradt az eszközűrlapról (helyet takarít).

### 0.19.0
- **Eszközűrlap újratervezve** az ügyfélűrlap kompakt, lekerekített mintájára
  (lapfülek, címke + mező sorban, két szimmetrikus oszlop, nem táblázatos):
  Eszköz | Azonosítók | Garancia és átvétel; alul Megjegyzés | Belső megjegyzés |
  Átvételkori állapot | Készülékkép / fájlok.
- **Új ügyfél a „+” gombbal** az eszközűrlapon is (ráépülő popup; az új ügyfél
  azonnal be van töltve). A ráépülő popup mostantól az űrlap saját szintje fölé
  nyílik, így az eszköz-popup is lehet egy munkalap fölött.
- **IMEI-ellenőrző gombok valódi linkek**: mindig megnyitják az oldalt új lapon
  (korábban 15 számjegy nélkül nem történt semmi). Teljes IMEI-nél a vágólapra
  kerül; `{imei}` helyőrzős címbe beírja.
- **Kategória** a teljes elektronikai piacot lefedi (52 kategória, 9 csoportban:
  telefon, TV, konzol, hajszárító, háztartási gépek…); középen felugró
  popupban, kereséssel. A régi kulcsok (telefon, tablet, laptop, ora, asztali,
  egyeb) változatlanok.
- **Gyártó** (102 nagy gyártó, csoportosítva, kereséssel, „Más gyártó” sorral),
  **Szín** (színmintás paletta, „Más szín” sorral) és **Tartozékok** (jelölőnégyzetes
  lista a MunkaLap 3 átvételi lapja szerint + Egyéb) is popupban.
- **Készülékkép / csatolt fájlok**: ugyanaz a panel, mint az ügyfélnél
  (húzás, ikon/lista/tömör nézet, eltávolítás mentéskor); `SDH_Muhely_Csatolmany`
  típusa: `eszkoz`.
- **Belső megjegyzés** (új oszlop: `belso_megjegyzes`): csak a CRM-ben látszik.
  Az ügyfél felé menő bármilyen kimenet (nyomtatvány, elismervény, üzenet) az
  eszköz adatait a `SDH_Muhely_Eszkoz::kulso_adatok()` függvényen át kérje –
  abban a belső megjegyzés szándékosan nincs benne.
- DB_VERSION 0.13.0: `belso_megjegyzes` (text), `tartozekok` varchar(255) → text.

### 0.18.3
- Irányítószám-lista: a **Magyar Posta hivatalos táblázata** (2026-09-23)
  a GeoNames helyett, **településrészekkel**: 3569 sor, 3155 település,
  3046 irányítószám, 339 településrész (pl. 8411 Veszprém – Kádárta,
  8412 Veszprém – Gyulafirátót, 8297 Tapolca – Diszel; Budapestnél a kerület).
- A legördülőben a településrész is látszik („8411 Veszprém – Kádárta”), és a
  saját települése alatt áll. Kitöltéskor a mezőbe „Veszprém-Kádárta” kerül;
  Budapestnél csak „Budapest” (a kerületet a kód hordozza).
- Településrészre is lehet keresni („Kádárta”, „Diszel”); a település
  beírásakor a részei is felkínálódnak.
- Közös kódnál, ha több település osztozik rajta (pl. 8300 Tapolca / Raposka),
  nem tölt ki magától, csak listát ad; ha egy település részei osztoznak, a
  fő település azonnal bekerül.
- Javítva: gépelés közben a Település mezőt nem írja át (korábban pl. a
  „Tap” „Táp”-ra javult, és eltűnt a kötőjel); a hivatalos alak (ékezet,
  kötőjel) kilépéskor kerül be.
- A böngésző a listát a fájl verziójával kéri (`v=`), így adatcserénél
  azonnal az újat tölti le, nem a gyorsítótárban maradt régit.
- Frissítés új Posta táblázatból: `tools/iranyitoszam_posta.py`
  (leírás: `data/iranyitoszam-forras.txt`).

### 0.18.2
- Ügyfélűrlap: minden címke balra zárva; a két oszlop szimmetrikus (azonos
  címkeoszlop, azonos mezőszélesség, bal és jobb margó egyenlő).
- Kedvezmény: a % jel a mezőn belül van.
- Az egész rendszerben modern fel/le léptető minden számmezőn (a böngésző
  nyilai helyett): két kis chevron-gomb a mezőn belül, nyomva tartva
  folyamatosan léptet, a min/max határon a gomb letilt, a fókusz a mezőn
  marad. Popupban betöltött űrlapokon is.
- Minden legördülő (select) saját vékony chevront kap, sötét módban is.
- CSS/JS gyorsítótár-törés a fájl módosítási idejével is (nem csak a
  verziószámmal), hogy frissítés után biztosan az új stílus töltődjön be.

### 0.18.1
- Ügyfél: a Telefon / Telefon 2. / E-mail / Kapcsolattartó már nem szegélyes
  rács, hanem külön mezők, ugyanúgy, mint a Kategória / Adószám sorok
  (címke + mező, két oszlop; mobilon egy oszlop).
- Az ügyfélűrlap összes mezőcímkéje balra igazított.

### 0.18.0
- Ügyfél: új **Szállítási cím** és **Telephely** lap (a MunkaLap 3 sorrendjében:
  Központi | Levelezési | Szállítási | Telephely). Mindkettő alapból
  „Megegyezik a központi címmel”; kivéve a jelölést Isz. / Település / Utca
  mezők, irányítószám ↔ település automatikus kitöltéssel. A Telephelyen
  külön (nem kötelező) név mező is van. Azonos címnél a mezők üresen
  tárolódnak (nincs két külön igazság). Új oszlopok: `szallitasi_*`,
  `telephely_*` (DB_VERSION 0.12.0, a meglévő ügyfeleknél alapból „azonos”).
- Keskeny kijelzőn a lapfülsor vízszintesen görgethető, nem tördel.

### 0.17.0
- Új ügyfél: **Csatolt fájlok** lap a Megjegyzés mellett (fotó, PDF, számla,
  dokumentum). Húzd ide a fájlokat vagy „+ Fájl hozzáadása”; Ikon / Lista /
  Tömör nézet. A fájlok az űrlap mentésekor kerülnek fel; meglévő fájl
  törlésre jelölhető (× / ↺). Fájlonként legfeljebb 25 MB, de nem több, mint
  amit a szerver (upload_max_filesize / post_max_size) enged – a felületen
  kiírja. Hibás fájlnál semmi nem mentődik félig. Biztonság: engedélyezett
  kiterjesztések, képek és PDF tartalmi ellenőrzése, véletlen tárolt név a
  wp-content/uploads/sdh-muhely/csatolmany/ mappában, közvetlen elérés tiltva;
  letöltés csak bejelentkezett, jogosult felhasználónak, nosniff fejléccel,
  nem kép/PDF mindig letöltésként. Új tábla: `sdh_csatolmany`
  (DB_VERSION 0.11.0), bármely modulhoz használható (`tipus` + `ref_id`).
- **Irányítószám ↔ település** (Isz. / Település mezők, központi és
  levelezési cím is): irányítószámra azonnal kitölti a települést, településre
  az irányítószámot. Több kódú településnél (Budapest, Győr, Veszprém…) vagy
  közös kódnál listát ad, nyilakkal / Enterrel / egérrel választható;
  ékezet nélkül is keres. A teljes lista egyszer töltődik le (kb. 86 KB), a
  keresés helyben fut.
- Adat: GeoNames HU (0.18.3 óta a Magyar Posta hivatalos listája váltotta).

### 0.16.4
- Új / szerkesztett ügyfél: kompakt, lapfüles űrlap a MunkaLap 3 mintájára.
  Központi cím lap (név, cím egy sorban, kategória, ügyfélszám, adószám,
  kedvezmény, státusz gombsor, elérhetőségek rácsban) és Levelezési cím lap;
  alul Megjegyzés lap. Nem kell lapozni: 1280×720-as képernyőn is egy
  képernyőn elfér. A lapfülek CSS-ből működnek (JS nélkül is). A „Központi
  cím” a számlázási cím mezőit tárolja; Szállítási cím / Telephely lap még
  nincs (nincs hozzá adatmező).
- Hibás mezőnél (pl. üres név) az űrlap automatikusan a hibát tartalmazó
  lapfülre vált.

### 0.16.3
- Új munkalap: az alapértelmezett állapot a Nyitott (az állapotlistában az
  `alap` jelzővel állítható; a lista sorrendje változatlan).
- Új munkalap: az ügyfél és az eszköz mellett „+” gomb. Az új ügyfél / új eszköz
  a munkalap fölött, külön popupban nyílik; mentés után a munkalap-űrlap
  megmarad, az új ügyfél / eszköz be van töltve. Az eszköz popupja a már
  kiválasztott ügyféllel előtöltve nyílik, és ha ott másik ügyfelet választanak,
  az ügyfélmező követi.
- A popup két szintes lett (fő + ráépülő).

### 0.16.2
- A `/muhely/…` címet a bővítmény akkor is felismeri, ha a WordPress
  szabálylistájából a szabály kiesett: a felület elérhetősége már nem függ
  a rewrite szabályoktól (tartalék a `request` szűrőben).

### 0.16.1
- A `/muhely/` útvonal szabálya önjavító: minden verzióváltás után, és ha a
  szabály kiesik a WordPress listájából, az első kérésnél újraíródik (a
  frontenden is, nem csak adminban). Kézi permalink-mentés nem kell.

### 0.16.0
- Munkalap-modul (Munkalapok a menüben): lista kereséssel (szám, név,
  ügyfél, telefon, IMEI, készülék) és állapotszűrővel, felvitel és
  szerkesztés popupban, JS nélküli teljes oldalas tartalékkal.
- A munkalap állapotai a MunkaLap 3-éi: Bejelentett, Árajánlat, Sablon,
  Függő, Nyitott, Elkészült, Lezárt, Érvénytelen – a MunkaLap 3 színeivel.
  Az állapotlista a Beállításokban szerkeszthető (kulcs, név, szín,
  `szamozott` és `zart` jelző), semmi sincs beégetve.
- Munkalapszám: csak a `szamozott` állapotú lap kap számot (a Sablon és az
  Árajánlat nem), az első számozott állapotba lépéskor, zár alatt, egyedi
  kulccsal – két gép nem kaphatja ugyanazt. A Beállításokban a következő
  szám, az előtag és a kitöltés állítható. A MunkaLap 3-ból való átvétel
  után a kezdőérték: 34983.
- Hibasorok: egy munkalapon több hiba, mindegyik saját állapottal
  (Új, Folyamatban, Kész, Nem javítható – szerkeszthető) és javítási
  megjegyzéssel. A listában „x / y kész” látszik.
- Számozott állapotnál az ügyfél és az eszköz kötelező, és az eszköznek az
  ügyfélé kell lennie. Az eszközválasztó az ügyfél kiválasztása után töltődik.
- Munkalap nem törölhető: az érvénytelen lap az Érvénytelen állapotba kerül.
- Állapotszínek: `--sdh-allapot-*` változók az `assets/admin.css` tetején,
  sötét módra külön értékekkel.
- Az áttekintőn a „nyitott munkalap” kártya élő számot mutat; az ügyfél
  adatlapján új „Munkalapjai” gomb.
- Sémaverzió: 0.10.0 (`munkalap`, `munkalap_hiba` tábla).

### 0.15.0
- Arculat képernyő (SDH Műhely → Arculat): élő stíluskalauz az összes
  színnel és komponenssel, plusz a cég alapértelmezett megjelenése.
  A dolgozó saját beállítása a profilmenüben ezt felülírja.
- Minden beégetett szín kivezetve a PHP-ból és a JavaScriptből:
  az átszínezés egyetlen fájlból (`assets/admin.css`) megy.
- Külön `--sdh-accent-szoveg` változó: sötét módban világosabb, mint a
  gombszín. Enélkül a piros linkek 3,3:1 kontraszton álltak.
- A paletta minden párosa méréssel ellenőrizve: a legrosszabb érték
  világos módban 4,62:1, sötétben 4,60:1 – mindkettő WCAG AA fölött.

### 0.14.0
- Új felület: valódi CRM-váz. Bal oldalt összecsukható ikonos menü,
  felül morzsamenü, globális kereső és profilmenü.
- Formanyelv: világos semleges vászon, fehér felületek, sűrű 13 px-es
  táblázatok. Rendszerbetű – webfont nélkül, hogy internet nélkül is
  jól nézzen ki.
- Világos / sötét / rendszer megjelenés és hat választható kiemelő szín,
  böngészőben megjegyezve. A beállítás a stílusok előtt áll be, így
  sötét módban nincs felvillanás.
- Globális kereső a fejlécben: ügyfél és eszköz egyszerre, név, telefon,
  IMEI, gyári szám és sorozatszám alapján. A `/` billentyű ráugrik.

### 0.13.0
- Ellenőrző gombok a Feldolgozás mellett: imeicheck.com és iunlocker.com
  egy kattintással, az IMEI-vel a vágólapon.
- A lista a Beállításokban szerkeszthető (`Név | URL` soronként); ha egy
  oldal URL-ben fogadja az IMEI-t, az `{imei}` jelölővel kitöltve nyílik.

### 0.12.0
- Gyári adatok beillesztéssel: az ingyenes IMEI-ellenőrzés eredményét
  bemásolod, és a rendszer kitölti a mezőket. Beillesztéskor magától fut,
  gombot sem kell keresni.
- Nincs előfizetés-kényszer: a fizetős szolgáltató opcionális maradt,
  ugyanazt a feldolgozót használja.
- A készülékkép a TAC-adatbázisba is bekerül, így a következő ugyanolyan
  típusnál magától megjelenik.

### 0.11.0
- Teljes gyári adatlap: modellszám, garancia állapota, gyártási dátum,
  ország, szolgáltató, készülékkép – az eszköz-űrlapon és a listában.
- Külső IMEI-szolgáltató beköthető (SDH Műhely → Beállítások): végpont
  URL `{imei}` és `{kulcs}` jelölőkkel, API-kulcs, be/ki kapcsoló.
  Nincs szolgáltató beégetve – más szerviz más előfizetést használhat.
- A válasz feldolgozása JSON-t és „Címke: érték" szövegblokkot is ismer,
  a dátumokat egységes alakra hozza.
- Ez az egyetlen pont, ami internetet igényel; nélküle minden megy tovább.

### 0.10.0
- Feloldó minta rajzolható mező: 3×3 pöttyrács, egérrel behúzható piros
  vonal, nyilakkal az irányról és gyűrűvel a kezdőponton.
- Átlépett pötty automatikusan bekerül (1→3 esetén a 2 is), ahogy az
  Android is csinálja – különben nem azt vennénk fel, amit az ügyfél rajzol.
- A minta a pöttyök sorrendjeként tárolódik (`minta` oszlop), a zárkód
  mező ezentúl a PIN/jelszó helye.

### 0.9.0
- **A gyári szám az elsődleges azonosító**, nem a kereskedelmi név:
  `SM-A505F/DS`, nem `Galaxy A50`. A listák, a fejlécek és az IMEI-ből
  való kitöltés mind ezt használják.
- A kereskedelmi név külön mezőbe került (keresni lehet rá, azonosítani
  nem). A keresés mindkettőre megy.
- A TAC-adatbázis újragenerálva modellkóddal: 248 364 TAC, ebből
  131 802-nél (53%) van gyári szám is.

### 0.8.0
- A TAC-adatbázis tanul: ismeretlen TAC-ú készülék kézi felvitelekor a
  gyártó és a típus bekerül a saját táblába, és a következő ugyanolyan
  készüléknél már magától kitöltődik.
- A csomagolt lista 2025 végéig tart; a saját bejegyzéseket az
  újratöltés nem írja felül (`forras = 'sajat'`).
- A TAC képernyő külön mutatja a saját felvitelből tanult darabszámot.

### 0.7.0
- TAC-adatbázis: az IMEI első nyolc számjegyéből a gyártó és a típus
  soha nem látott készüléknél is kitöltődik. 248 364 készüléktípus,
  a plugin mellé csomagolva (`data/tac.csv.gz`, 1,8 MB).
- Helyi másolat, nem online API: a rendszernek internet nélkül is
  mennie kell az irodai szerveren.
- Betöltő képernyő (SDH Műhely → TAC adatbázis): darabokban fut,
  megszakítható, ismételt futtatás nem duplikál.

### 0.6.0
- IMEI-kikeresés: a 15. számjegy beírása után a rendszer megnézi, járt-e
  már nálunk a készülék. Ha igen, kitölti az adatait és az ügyfelet, és a
  mentés a meglévő rekordot frissíti – nem keletkezik duplikátum.
- IMEI ellenőrzőszám (Luhn) figyelmeztetésként: elgépelést jelez, de nem
  tiltja a mentést.
- Az IMEI mező csak számjegyet fogad (vonalkódolvasó szóközeit kiszűri).

### 0.5.0
- Eszköz-modul: ügyfélhez kötött készülék (gyártó, típus, IMEI,
  sorozatszám, zárkód, tartozékok, átvételkori állapot, garancia).
  Lista kereséssel, popupos felvitel, ügyfélre szűrve is.
- Ügyfélválasztó mező: gépelésre keres, mert 40 ezer rekordnál egy
  legördülő használhatatlan. Enter az első találatot veszi.
- Az ügyfél adatlapjáról egy gombbal átlépsz a készülékeire.

### 0.4.0
- Popup (modál) rendszer: a felvitel és a szerkesztés párbeszédablakban
  nyílik, így a lista és a keresés nem vész el. Natív `<dialog>`, nincs
  külső könyvtár.
- Az ügyfél-űrlap egy helyen íródik, és onnan megy a popupba és a teljes
  oldalas változatba is.
- JavaScript nélkül minden link a teljes oldalas űrlapra visz – a
  rendszer JS nélkül is használható.
- Az „Új ügyfél" gomb az áttekintőn is megjelenik.

### 0.3.0
- Saját műhely-felület a `/muhely/` útvonalon: nem használja a sablont,
  bejelentkezés nélkül átirányít, saját fejléccel és menüvel.
- Modul-nyilvántartás (`SDH_Muhely_Modulok`): a modulok egyszer íródnak
  meg, és mindkét felületen megjelennek. Az URL-építés kontextusfüggő.
- Az ügyfél-modul mindkét felületen fut; a mentés oda tér vissza,
  ahonnan a beküldés jött.

### 0.2.0
- Verziózott adatbázis-séma (`SDH_Muhely_Schema`), aktiváláskor és
  sémaverzió-váltáskor magától lefut.
- Közös admin arculat: menüszerkezet, oldalfejléc, értesítések, saját CSS.
  A modulok az `sdh_muhely_menupontok` szűrőn át jelentkeznek be.
- Ügyfél-modul: lista kereséssel és lapozással, felvitel/szerkesztés,
  inaktiválás törlés helyett.

### 0.1.0
- A plugin váza: admin nyitóképernyő, verzió- és környezet-kijelzés.
- A Local + Git munkafolyamat ellenőrzésére.
