---
kulcs: modulok
cim: Modulok
csoport: Rendszer
modul: modulok
szakaszok:
kulcsszavak: modulok, modul, funkció, funkciók, bekapcsolás, kikapcsolás, ki-be kapcsolás, kapcsoló, licenc, előfizetés, csomag, alapcsomag, külön díj, eltűnt menüpont, nincs menüpont, telepítői zár, telepítői kód, zár, függőség
osszefoglalo: A Modulok menüpontban a rendszer fő funkciói modulonként ki- és bekapcsolhatók; a kikapcsolt modul eltűnik a menüből, az Áttekintésről és a Beállításokból, az adatai megmaradnak.
---

## Mire való

A **Modulok** menüpontban a rendszer fő funkcióit egyenként ki- és bekapcsolhatod. Ha egy cégnek nincs szüksége például a Naptárra vagy az SMS-értesítésekre, ott egyszerűen kikapcsolod – így a rendszer csak azt mutatja, amit használnak, és a modulok külön is értékesíthetők.

A kikapcsolt modul:

- eltűnik a bal oldali menüből és az Áttekintés csempéi közül,
- a Beállításokban sem látszik a lapfüle,
- a más oldalakon lévő gombjai sem jelennek meg (például kikapcsolt Nyomtatványok modulnál nincs **Nyomtatás** gomb a munkalapon),
- az időzített munkái nem futnak (kikapcsolt SMS-nél semmi nem megy ki, kikapcsolt Naptárnál nincs emlékeztető),
- a súgóból és az asszisztens tudásából is kimarad a fejezete.

Az adatai **nem törlődnek**. Ha a modult később visszakapcsolod, minden ott folytatódik, ahol abbamaradt.

## Hol találod

A bal oldali menü legalján, a **Beállítások** alatt áll a **Modulok** menüpont. [Modulok megnyitása](modul:modulok)

- **Csak adminisztrátor látja.** A kolléga menüjében nem jelenik meg.
- A wp-adminból megnyitva ugyanez az oldal jelenik meg.

## Lépésről lépésre: modul ki- vagy bekapcsolása

1. Nyisd meg a **Modulok** menüpontot.
2. Keresd meg a modul kártyáját. A kártyák csoportokban állnak: Értékesítés, Pénzügy, Ügyfélkapcsolat, Csapat és idő, Mesterséges intelligencia, Dokumentumok és készülékadatok.
3. Kattints a kártya jobb felső sarkában a kapcsolóra (vagy bárhová a kártyán).
4. Ha be van kapcsolva a telepítői zár, írd be a **Telepítői kód** mezőbe a kódot.
5. Kattints a **Mentés** gombra. Az oldal újratölt, és a menü már az új modulokkal jelenik meg.

> **Tipp:** Egyszerre több modult is átkapcsolhatsz, egy mentéssel.

## A modulok

| Modul | Mit tud | Ha kikapcsolod |
|---|---|---|
| Termékek és készlet | termékkatalógus, készlet, termék-tételek | eltűnik a Termékek menüpont és a munkalap Termékek lapfüle (ha a lapon már van termék, a fül marad) |
| Napi árukiadás | eladás javítás nélkül | eltűnik a menüpont, és az árukiadások a munkalapok rácsából is |
| Rendelések | beszállítói rendelések értesítéssel | eltűnik a menüpont |
| Házipénztár | kassza, zárás, riport, import, egyeztető ügynök | a munkalap **Fizetés** ablaka megmarad, de a fizetés nem kerül a pénztárba |
| Számlázz.hu számlázás | valódi számla gombnyomásra | eltűnik a **Számlázz.hu számla** gomb; a helyi számla-előkészítő (PDF) megmarad |
| Ügyfélüzenetek és RMA-oldal | QR-kódos ügyféloldal, csevegő, Üzenetek menü | az ügyféloldal nem érhető el; a munkalap RMA lapfüléből **Címke** lesz (QR-kód nélküli címke és nyomtatása) |
| SMS-értesítések | SMS a bolti telefonról | semmi nem megy ki, a sor nem fut |
| Levelezés | e-mail-fiókok a rendszerben | eltűnik a menüpont; a nyomtatványok e-mailje a szerver levélküldőjével megy |
| Csapat-üzenetek | belső csevegés vagy üzenőfal | a csevegés és a fal eltűnik; a kollégák felvétele a **Beállítások › Csapat** lapfülről továbbra is elérhető |
| Naptár | névenkénti naptár, emlékeztetők | eltűnik a menüpont, nincs emlékeztető és e-mail |
| AI-asszisztens | a figura és a chat | a figura és a chat eltűnik |
| Nyomtatványok | PDF, nyomtatás, e-mail | eltűnik minden **Nyomtatás** gomb és a felajánlás |
| IMEI-lekérdezés | szolgáltató, ellenőrző oldalak, beillesztés | eltűnik az eszköz űrlapjáról az Ellenőrzés és a Gyári adatok sor |
| TAC-adatbázis | gyártó és típus felismerése az IMEI-ből | nincs felismerés, és nem tanul |
| Eszköz-mockupok | termékkép az eszközök mellett | nincs termékkép |

Az **Alaprendszer** – Áttekintés, Munkalapok, Ügyfelek, Eszközök, Szolgáltatások, Beállítások, Súgó, Mentés – mindig fut, nem kapcsolható ki. Az oldal alján tájékoztatásul ezek is látszanak.

## Szabályok, amiket a rendszer betart

- **Függőség:** az SMS-értesítések és a Rendelések az Ügyfélüzenetek és RMA-oldal modulra épülnek. Ha azt kikapcsolod, ezek is kikapcsolnak – a mentés után az üzenet felsorolja, mi kapcsolt még ki. A kártyán az **Ehhez kell:** sor mutatja, mire épül a modul.
- Ha egy modul be van kapcsolva, de nem fut, mert amire épül, ki van kapcsolva, a kártyája szaggatott keretet kap, és ezt írja: **Nem fut – ki van kapcsolva, amire épül**.
- A kikapcsolt modul végpontjai elutasítanak: ha egy régen megnyitott ablakból mégis hívnák, a rendszer kiírja, hogy a modul ki van kapcsolva.
- A kikapcsolt modul címét kézzel beírva a **Kikapcsolt modul** oldal jelenik meg.
- Kikapcsolással semmi nem törlődik.

## Telepítői zár

Ha a rendszert más cégnél telepíted, az oldal alján, a **Telepítői zár** dobozban megadhatsz egy legalább 6 karakteres kódot (**Új kód**, **Új kód még egyszer**, majd **Zár bekapcsolása**). Utána a modulok csak ezzel a kóddal kapcsolhatók át – a cég saját adminisztrátora sem kapcsol be magának olyan modult, amit nem rendelt meg.

- A kód cseréjéhez és a zár feloldásához (**Kód cseréje**, **Zár feloldása**) a **Mostani kód** kell.
- A kódot a rendszer nem tárolja olvasható formában.

> **Figyelem:** Ez szervezési zár, nem kódvédelem: aki a szerver fájljaihoz hozzáfér, kikerülheti.

## Ha valami nem megy

- **Eltűnt egy menüpont.** Valószínűleg ki van kapcsolva a modulja. Nézd meg a **Modulok** menüpontban.
- **Bekapcsoltam, mégsem látszik.** Nézd meg a kártya **Ehhez kell:** sorát: ha amire épül, ki van kapcsolva, azt is kapcsold be.
- **A mentés azt írja, hogy a telepítői kód nem jó.** A modulok nem változtak. Kérd el a kódot attól, aki a rendszert telepítette.

## GYIK

### Elvesznek az adatok, ha kikapcsolok egy modult?

Nem. A kikapcsolás csak elrejti a modult és leállítja a működését. Visszakapcsolva minden adat ott van, ahol volt.

### Miért nem tudom kikapcsolni a Munkalapokat vagy az Ügyfeleket?

Mert ezek az alaprendszer részei: nélkülük a rendszer nem működik. Az Alaprendszer kártyái tájékoztatásul látszanak az oldal alján.

### Kikapcsoltam a Házipénztárt. Fizetni lehet még a munkalapon?

Igen. A **Fizetés** ablak megmarad, a „Fizetett” összeg és a „Fizetve” jelző ugyanúgy rögzül – csak a pénztárba nem kerül be semmi.

### Kikapcsoltam az Üzenetek modult. Hogyan nyomtatok címkét?

A munkalapon az RMA lapfül helyén **Címke** lapfül áll: ott van a címke (QR-kód nélkül, vonalkóddal) és a **Címke nyomtatása** gomb.

### Ki kapcsolhatja át a modulokat?

Csak adminisztrátor. Ha be van kapcsolva a telepítői zár, ahhoz a telepítői kód is kell.

### Eltűnt a Naptár (vagy más) menüpont. Mi történt?

Valószínűleg kikapcsolták a modulját. Az adminisztrátor a **Modulok** menüpontban látja, mi fut, és ott vissza is kapcsolhatja.

### Kikapcsoltam az Ügyfélüzenetek modult, és az SMS is kikapcsolt. Miért?

Mert az SMS-értesítések (és a Rendelések) arra épülnek: az SMS linkje és a bejövő válaszok az ügyféloldalhoz és az üzenetekhez kötődnek. A mentés utáni üzenet felsorolja, mi kapcsolt még ki.

### Kikapcsoltam a Számlázz.hu-t. Megmarad a számla-előkészítő?

Igen. A munkalapon a helyi számla-előkészítő (PDF) gombja megmarad, csak a **Számlázz.hu számla** gomb tűnik el. A korábban kiállított számlák PDF-je továbbra is megnyitható.

### Kikapcsoltam a Csapat modult. Hogyan veszek fel új kollégát?

A **Beállítások › Csapat** lapfülön a **Kollégák kezelése** gombbal. A csevegés és az üzenőfal nem látszik, a kollégák és a csoportok kezelése megmarad.

### Elfelejtettem a telepítői kódot. Mit tegyek?

A kódot a rendszer nem tárolja olvasható formában, ezért visszanézni sem lehet. Kérd meg azt, aki a rendszert telepítette: a szerveren törölheti a zárat (a `sdh_muhely_modulok_zar` beállítást), utána új kódot adhatsz meg.

## Túra: modulok

- `.sdh-fejlec` | A Modulok oldal | Itt kapcsolhatod ki és be a rendszer fő funkcióit. Csak adminisztrátor látja.
- `.sdh-modulok__osszegzo` | Hány modul fut | Ennyi fő modul fut most az összesből.
- `.sdh-modulkartya` | Egy modul | A kártyán a modul neve, mit tud, és fut-e. A kapcsolóval vagy a kártyára kattintva váltasz.
- `.sdh-modulok__lablec` | Mentés | A változás csak a mentéssel lép életbe. Utána az oldal újratölt, és a menü már az új modulokkal jelenik meg.
- `.sdh-modulok__zar` | Telepítői zár | Ha más cégnél telepíted a rendszert, itt adhatsz meg kódot: utána csak azzal lehet modult kapcsolni.
