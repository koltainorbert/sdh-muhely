---
kulcs: riportok
cim: Riportok
csoport: Rendszer
modul: riportok
szakaszok:
kulcsszavak: riport, riportok, kimutatás, statisztika, átfutási idő, átfutás, mennyi idő alatt, technikus, szerelő teljesítménye, teljesítmény, javítási arány, sikeres javítás, visszajött, garanciális visszatérés, bevétel, forgalom, eszközkategória, termékkategória, szolgáltatás, árajánlat elfogadás, havi bontás, idei, előző hónap
osszefoglalo: A Riportok menüpont a lezárt lapokból mutatja az átfutási időt, a technikusok teljesítményét, a javítási arányt, a bevételt fajta, eszközkategória és termékkategória szerint, az árajánlatokat és az utolsó 12 hónapot.
---
## Mire való

A **Riportok** egy oldalon mutatja, hogyan megy a szerviz: mennyi idő alatt készülnek el a javítások, ki mennyit javított, mennyi jön vissza garanciában, és miből jön a bevétel. Csak adminisztrátor látja.

Minden szám a **lezárt** lapokból jön, a **lezárás napja** szerint: ami egy időszakban lezárult, az abba az időszakba számít. A még nyitott lapok nincsenek benne.

## Hol találod

A bal oldali menüben a **Riportok** menüpont (adminisztrátornak). Az oldal tetején választod az időszakot: **Ez a hónap**, **Előző hónap**, **Utolsó 30 nap**, **Idén** – vagy a **Tól** és **Ig** mezővel egyénit, a **Mutasd** gombbal.

## A számok

| Csempe | Mit mutat |
|---|---|
| **Lezárt javítás** | Hány javítási munkalap zárult le az időszakban (az érvénytelen nem számít). |
| **Átlagos átfutás** | A felvétel (Készült) és a lezárás között eltelt napok átlaga; alatta a medián (a „tipikus” érték – egy-egy nagyon hosszú javítás nem húzza el). |
| **Sikeres javítás** | A lezárt javítások hány százaléka NEM jött vissza garanciában (Javítási garancia). Alatta: hány jött vissza. |
| **Bevétel (bruttó)** | A lezárt lapok végösszege (javítás, árukiadás, rendelés). |
| **Árajánlat** | Az online árajánlatok: elfogadva / elküldve, és hány lett elutasítva. |
| **Elégedettség** | Az ügyfelek értékeléseinek átlaga (1–5 csillag), hány értékelés jött az időszakban, és ebből hány elégedett (Elégedettségi kérdés). |

## Technikusonként

A **felelős** szerint: hány javítása zárult le, mennyi volt nála az átlagos átfutás, mekkora bevétel, és hány javítása jött vissza garanciában (és ez hány százalék). A sáv a legtöbbet javítóhoz méri a többieket.

Jó tudni: a felelős a munkalap **Felelős** mezője. Ha egy lapnak nincs felelőse, „(nincs felelős)” sorban áll.

## Bevétel kategóriák szerint

- **Fajtánként:** javítás, napi árukiadás, rendelés – lapszám és bevétel.
- **Tételek szerint:** a szolgáltatások és a termékek összege, a termékek a **kategóriájuk** szerint is (a legnagyobb nyolc).
- **Javítás eszközkategóriánként:** telefon, tablet, laptop… – hány javítás és mekkora bevétel. Alatta: hány javítási lap lett érvénytelen, és hány garanciális visszatérés érkezett az időszakban.

## Az utolsó 12 hónap

Havonta a lezárt javítások száma és a bevétel, sávval – így látszik a szezon és a trend.

## Szabályok, amiket a rendszer betart

- Csak lezárt lap számít, a **lezárás napja** szerint.
- Az **érvénytelen** lap nem számít lezárt javításnak, és bevételt sem hoz.
- A bevétel a lap **végösszege** – nem a pénztár. A pénztár forgalmát a Pénztár riportja mutatja.
- A garanciális visszatérés az eredeti lapnál számít (amelyiket a lap visszatérésként jelöl).

## Tippek

- A hónap végén nézd meg az **Előző hónap** számait: átfutás, sikeres javítás, technikusok.
- Ha az átlagos átfutás sokkal nagyobb a mediánnál, van néhány nagyon elhúzódó javítás – keresd meg őket a Munkalapok listában.
- A **Sikeres javítás** csak akkor pontos, ha a visszajött készüléket **Garanciális visszatérésként** rögzítitek.

## Ha valami nem megy

- **Minden nulla:** az időszakban nem zárult le lap, vagy a lapoknak nincs lezárás-napja. Válassz hosszabb időszakot (Idén).
- **Hiányzik egy technikus:** csak az látszik, akinek az időszakban volt lezárt javítása.
- **Nem látom a menüpontot:** a Riportok csak adminisztrátornak jelenik meg.

## GYIK

### Hogyan nézem meg, mennyi idő alatt készülnek el a javítások?

A **Riportok** menüpontban az **Átlagos átfutás** csempe mutatja (alatta a medián). Technikusonként is látszik.

### Ki javított a legtöbbet a hónapban?

A **Riportok › Technikusonként** táblázat a lezárt javítások száma szerint rendez.

### Mi a sikeres javítás aránya?

A lezárt javítások közül azok aránya, amelyek nem jöttek vissza garanciában.

### Miből jön a legtöbb bevétel?

A **Bevétel** dobozban fajtánként és tételek szerint, a **Javítás eszközkategóriánként** dobozban készüléktípus szerint látszik.

### Hogyan nézek meg egy régebbi időszakot?

Az oldal tetején válaszd az **Egyéni** időszakot, add meg a kezdő és a záró napot, majd **Mutasd**.

### Miért nem látom a Riportok menüpontot?

Csak adminisztrátor látja. Kérd meg az adminisztrátort, hogy nézze meg ő, vagy adjon neked adminisztrátori szerepkört.

### Beleszámít az érvénytelenített munkalap?

Nem. Az érvénytelen lapot külön számolja, a bevételbe és az átfutásba nem kerül bele.

### Mitől számít egy javítás az időszakba?

A lezárás napjától. A felvétel lehet korábbi is; az átfutás a felvételtől a lezárásig eltelt napok száma.

### Mi a különbség az átlag és a medián között?

Az átlagot egy-egy nagyon hosszú javítás felhúzza. A medián a középső érték: a javítások fele ennél gyorsabban készül el.

### Kinek számít a javítás, ha többen dolgoztak rajta?

A munkalap felelősének. Ha nincs felelős, a „(nincs felelős)” sorba kerül.

### Látszik az árajánlatok elfogadási aránya?

Igen, az **Árajánlat** csempén: hányat fogadtak el az elküldöttekből, és hányat utasítottak el.

## Túra: riportok

- `.sdh-riport__szuro` | Időszak | Válaszd ki, melyik időszakot nézed – vagy adj meg egyénit.
- `.sdh-riport__csempek` | A fő számok | Lezárt javítás, átfutás, sikeres javítás, bevétel, árajánlat.
- `.sdh-riport__racs` | Részletek | Technikusonként, bevétel kategóriák szerint és az utolsó 12 hónap.
