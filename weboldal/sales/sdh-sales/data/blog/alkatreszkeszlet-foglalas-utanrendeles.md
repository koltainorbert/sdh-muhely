---
cim: Alkatrészkészlet szervizben: foglalás munkalapra, minimumkészlet, utánrendelési javaslat
leiras: Hogyan tarts annyi alkatrészt, amennyi kell, se többet, se kevesebbet? Foglalás, minimum- és célkészlet, rendelési javaslat és leltár egy javítóműhelyben.
kategoria: Raktár és pénzügy
cimkek: [alkatrész nyilvántartás, raktárkezelő program szerviznek, készlet, utánrendelés]
datum: 2026-07-27
kulcsszo: alkatrész nyilvántartás
seo_cim: Alkatrészkészlet szervizben: foglalás és utánrendelés
---

**Röviden:**

- Egy szervizben a készlet két részből áll: ami a polcon szabad, és ami már egy nyitott munkalapra **foglalt**. Ha ezt nem különíted el, kétszer adod el ugyanazt az alkatrészt.
- Minden gyakran használt alkatrésznek legyen **minimumkészlete** és **célkészlete**. A rendelési javaslat ebből számolható.
- A munkalapra tett alkatrész azonnal jöjjön le a készletről, ne a nap végén, kézzel.
- Rendelést beszállítónként érdemes összeállítani, a bevételezést pedig a rendelésből indítani.

## Miért nehéz a szervizes készlet?

Egy bolt készlete egyszerű: ami a polcon van, az eladható. Egy szervizben bonyolultabb. A kijelző, amit tegnap rendeltél egy konkrét ügyfél telefonjához, fizikailag a polcon van, de már nem szabad. Ha a pultos egy másik ügyfélnek odaígéri, holnap az első ügyfél vár feleslegesen.

A másik gond a túlkészlet. A szervizes alkatrészek egy része gyorsan elavul: egy telefontípus két-három év után kikopik, és a hozzá vett kijelzők a fiókban maradnak. Ezért nem az a cél, hogy minden legyen raktáron, hanem hogy a gyakran használt alkatrészekből legyen elég, a ritkábbakat pedig gyorsan rendeld.

## Az alapfogalmak

| Fogalom | Mit jelent? |
|---|---|
| Készlet | Ami fizikailag a polcon van |
| Foglalt | Ami nyitott munkalapra van téve, de a lap még nincs lezárva |
| Szabad | Készlet mínusz foglalt |
| Minimumkészlet | Ha ez alá fogy, rendelni kell |
| Célkészlet | Rendeléskor eddig érdemes feltölteni |
| Beszállító | Akitől az alkatrész jön; a rendelés ehhez igazodik |

## Foglalás munkalapra

A legfontosabb szabály: amikor egy alkatrész a munkalapra kerül, **azonnal jöjjön le a készletről**, és amíg a lap nyitott, látszódjon foglaltként. A {termeknev} [raktárkezelése](/funkciok/raktar/) így működik: a termék űrlapján látszik, hány darab van foglalva nyitott munkalapokon, és melyiken. Ha egy lap miatt a készlet nulla alá menne, a munkalapon „Nincs elég raktáron” jelzés áll.

Mi történik árajánlatnál? Ha a lap még csak árajánlat, nem foglal: az ügyfél még nem döntött. Amikor elfogadja, és a lap munkalappá válik, a foglalás életbe lép.

Mi történik érvénytelenített lapnál? A foglalás megszűnik, a darab visszakerül a szabad készletbe.

## Minimum- és célkészlet beállítása

Ne próbáld minden termékre egyszerre beállítani. Kezdd a leggyakoribbakkal:

1. Nézd meg az elmúlt három hónap munkalapjait: melyik alkatrész fogyott a legtöbbet?
2. Ezekre állíts be minimumkészletet: annyit, amennyi a beszállító szállítási ideje alatt elfogyhat.
3. A célkészlet legyen annyi, amennyit egy rendeléssel érdemes hozatni. Ha nem tudod, a minimum kétszerese jó kiindulás.
4. Egy hónap múlva nézd meg újra: hol fogytál ki, hol maradt túl sok?

A ritka alkatrészekre nem kell minimum. Ezeket rendelésre hozd, konkrét munkalaphoz.

## Rendelési javaslat

Ha minden gyakori alkatrésznek van minimuma, a rendelési lista kiszámolható: ami a minimum alá fogyott (a foglalásokat is figyelembe véve), azt a célkészletig kell feltölteni. A {termeknev} Termékek menüjében a **Rendelési javaslat** nézet ezt beszállítónként mutatja, a javasolt mennyiséggel és a becsült nettó értékkel. A lista kimásolható rendelési szövegként, vagy CSV-be menthető a táblázatkezelőhöz.

### Miért beszállítónként?

Mert a rendelést is beszállítónként adod le. Ha a lista vegyes, kézzel kell szétválogatni. Ha a termékeknél be van állítva a beszállító, a javaslat eleve csoportosítva készül.

## Bevételezés

Amikor megjön a csomag, a bevételezés a rendelésből induljon: a javasolt sorok mellett egy „Megérkezett” gomb a beírt mennyiséget a készletre teszi, és a készletmozgás megjegyzést kap. Így nem kell külön bevételezési bizonylatot írni, és a mozgás visszakövethető.

Figyelj arra, hogy a bevételezésnél a **beérkezett darabszámot** írd be, ne az új összkészletet. Ez gyakori elírás.

## Leltár és korrekció

Időnként szükség van leltárra: a valós készlet eltérhet a nyilvántartottól (sérült alkatrész, elveszett darab, elírás). Leltárkor:

1. Nyomtass készletlistát a nyilvántartott darabszámokkal.
2. Számold meg a polcot.
3. Az eltéréseket készletkorrekcióként rögzítsd, megjegyzéssel (pl. „leltár, sérült”).
4. Nézd meg, melyik terméknél volt a legnagyobb eltérés, és miért.

A változásnaplóból utólag is kiderül, ki és mikor módosította a készletet.

## Árazás: beszerzési ár és haszonkulcs

A szervizes alkatrész ára gyakran változik. Ha a terméknél a beszerzési ár és a haszonkulcs is rögzítve van, az eladási ár egyszerűen frissíthető, amikor új áron érkezik a szállítmány. A készletérték is látszik: beszerzési nettó és eladási bruttó áron, a szűrt listára összesítve.

## Ügyfélnek rendelt alkatrész

Külön eset, amikor egy alkatrészt kifejezetten egy ügyfél kérésére rendelsz: egy ritka kijelzőt, egy régi típus akkumulátorát, vagy egy tartozékot, amit az ügyfél megvesz. Ezt nem a raktári utánrendelés kezeli, hanem egy rendelés, amely az ügyfélhez kötődik:

- látszik, kinek, mit, melyik beszállítótól rendeltél,
- látszik, hol tart: megrendelve, úton, megérkezett,
- ha megjött, az ügyfél értesíthető (SMS vagy e-mail),
- az előleg és a fizetés is a rendeléshez kerül.

Így a polcon álló, névre rendelt alkatrész nem keveredik a szabad készlettel, és nem marad elfeledve egy fiókban.

## Ki mit láthat?

A beszerzési ár és a haszonkulcs üzleti adat. Nem minden kollégának kell látnia. Ha a szerepkörökben beállítható, ki írhatja át az árakat és ki láthatja a pénzügyi riportokat, a pultos és a szerelő a munkájához szükséges adatokat látja, a beszerzési árakat nem feltétlenül. A készletmozgások naplója pedig megmutatja, ki és mikor korrigált.

## Ellenőrzőlista

- A munkalapra tett alkatrész azonnal lejön a készletről
- A foglalt darabszám külön látszik
- A 20–30 leggyakoribb alkatrésznek van minimuma és célkészlete
- Minden terméknél ki van töltve a beszállító
- A rendelési javaslatot hetente egyszer átnézed
- A bevételezés a beérkezett darabszámmal történik
- Negyedévente leltár a gyakori alkatrészekből

## Gyakori hibák

### Készlet csak a nap végén

Ha a pultos este írja át a készletet, napközben senki nem tudja, mi szabad. Egy forgalmas napon ez dupla ígérethez vezet.

### Minden termékre minimum

A ritka alkatrészekre beállított minimum feleslegesen köti le a pénzt. Csak a gyakoriakra állíts.

### Elavult készlet

Nézd át félévente, melyik alkatrész nem mozdult hónapok óta. Ezek egy részét még el lehet adni vagy vissza lehet küldeni, mielőtt végleg elavulnak.

### Vonalkód nélkül

Ha a termékeknek van vonalkódja, a munkalapra tétel egy beolvasás és egy Enter. Ez gyorsabb és pontosabb, mint a névre keresés.

## Összegzés

A szervizes készletkezelés kulcsa a foglalás: ha látszik, mi szabad és mi van már odaígérve, a dupla eladás és a feleslegesen várakozó ügyfél megszűnik. A minimum- és célkészletből számolt rendelési javaslat pedig heti tíz percre csökkenti a rendelés összeállítását.

A {termeknev} készletkezelését a [demóban](/demo/) kipróbálhatod mintatermékekkel és rendelési javaslattal. A modulok és árak az [Árak](/arak/) oldalon vannak.
