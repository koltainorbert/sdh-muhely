---
kulcs: licenc
cim: Licenc és támogatási hozzáférés
csoport: Rendszer
modul:
szakaszok: licenc
kulcsszavak: licenc, licenckulcs, kulcs, központ, forgalmazó, vásárlás, lejárt licenc, érvényesség, frissítés, támogatás, támogató, távsegítség, hozzáférés, belépő link, ideiglenes fiók, visszavonás, adatvédelem, mit küld, távoli karbantartás, figyelés, üzenet a forgalmazótól, licencelt modul, felhasználószám
osszefoglalo: Ha a rendszert forgalmazótól vásároltátok, a Beállítások › Licenc lapon adod meg a központ címét és a licenckulcsot; ugyanitt engedélyezheted időkorláttal, hogy a forgalmazó támogatója belépjen.
---

## Mire való

Ha a rendszert forgalmazótól vásároltátok, a licenc mondja meg, melyik modulok járnak hozzá, hány gépre és hány felhasználóra szól, és jár-e frissítés. A rendszer 5 percenként jelentkezik a forgalmazó központjánál: onnan kapja az új verziót, és a forgalmazó így előre látja, ha valami baj van (lassú, elfogy a tárhely, elmaradt a mentés).

Ugyanitt engedélyezheted, hogy a forgalmazó támogatója **meghatározott időre** belépjen, ha segítséget kértél.

Saját példánynál (amit nem forgalmazótól vettetek) a lapot üresen hagyod: semmi nem változik.

## Hol találod

**Beállítások › Rendszer › Licenc**. Csak adminisztrátor látja.

## Lépésről lépésre: a licenckulcs megadása

1. Nyisd meg: **Beállítások › Rendszer › Licenc**.
2. **A központ címe**: a forgalmazótól kapott cím (például `https://kozpont.pelda.hu/`).
3. **Licenckulcs**: `XXXX-XXXX-XXXX-XXXX` alakú (elöl a forgalmazó rövid előtagjával), a forgalmazótól kaptad.
4. **Mentés**. A rendszer azonnal jelentkezik a központnál.
5. Az **Állapot** sorban látod: *Aktív*, a vevő neve, és meddig érvényes.

## Mit küld a rendszer a központnak

5 percenként:

- a licenckulcsot, a példány véletlen azonosítóját és egy ujjlenyomatot (a webhely címéből képzett lenyomat; magát a címet nem küldi),
- a verziót és a műszaki állapotot: a szerver adatai, a válaszidő, a memória, az adatbázis és a szabad tárhely mérete, az utolsó mentés ideje, az ütemezett feladatok, a végzetes hibák helye (az üzenetből az e-mail-címek és a számsorok kitakarva), a bekapcsolt kiegészítők és modulok,
- darabszámokat: hány munkalap, ügyfél, eszköz, termék és felhasználó van, és hányan dolgoznak éppen.

Az ügyfelek nevét, telefonját, e-mail-címét, címét, a munkalapok tartalmát és a forgalom összegét **soha** nem küldi.

A központ válasza digitálisan aláírt: a rendszer az első kapcsolatkor megjegyzi a központ kulcsát, és utána csak az azzal aláírt választ fogadja el.

## Az állapotok

| Állapot | Mit jelent |
|---|---|
| **Saját példány** | Nincs megadva központ. Nincs licencfeltétel. |
| **Még nem volt kapcsolat** | Megadtad a kulcsot, de a központ még nem válaszolt. |
| **Aktív** | Minden rendben, a frissítések jönnek. |
| **Lejárt** | A licenc lejárt. A rendszer tovább működik, de frissítést nem kap. |
| **Letiltva** | A forgalmazó letiltotta. A rendszer tovább működik, frissítés nincs. |
| **Túl sok példány** | Ezzel a kulccsal több helyre telepítettétek, mint amennyit a licenc enged. |
| **Ismeretlen licenckulcs** | A központ nem ismeri a kulcsot – nézd meg, jól írtad-e be. |
| **Nincs kapcsolat a központtal** | 30 napja nem sikerült jelentkezni (például nincs internet). |

Ha az állapot nem *Aktív* (és nem *Saját példány*), az adminisztrátor a képernyő tetején figyelmeztetést lát. Az adatok, a munkalapok és minden funkció ilyenkor is megmarad és működik.

## Licencelt modulok és felhasználók

A licenc megmondja, melyik modulok járnak (például SMS, Házipénztár, Naptár). Ami nincs a licencben, az nem fut: eltűnik a menüből, a Modulok oldalon a kapcsolója szürke, és ott áll: *a licenc nem tartalmazza*. Az adatai megmaradnak – ha a forgalmazó bővíti a licencet, a következő jelentkezéskor (legfeljebb 5 perc) visszajön, ahol abbamaradt.

Ha a licenc meghatározott számú felhasználóra szól, és többen vannak, az adminisztrátor figyelmeztetést lát a képernyő tetején. A munka ilyenkor sem áll le.

## Távoli karbantartás

A forgalmazó a központból karbantartást indíthat: **mentést**, **diagnosztikát** (sebességmérés), **gyorsítótár-ürítést**, **internetkapcsolat-ellenőrzést**, **frissítés keresését** és **telepítését** (előtte mindig teljes mentés készül). Ezek egyike sem küld el adatot, és egyike sem nyit belépést.

A **Beállítások › Rendszer › Licenc** lapon a *Távoli karbantartás* kapcsolóval kikapcsolhatod; ilyenkor a kért művelet nem fut le, és a forgalmazó látja, hogy kikapcsoltad.

## Üzenet és kérés a forgalmazótól

- **Üzenet**: a forgalmazó üzenetet küldhet (például „ma este frissítünk”). Az adminisztrátorok sávként látják a képernyő tetején; a **Rendben** gombbal leveheted.
- **Támogatási kérés**: ha a forgalmazó be szeretne nézni, a képernyő tetején sáv jelenik meg az okkal és az időtartammal. **Engedélyezem** (kétszeri kattintás) – ideiglenes belépés jön létre, és a link magától eljut a támogatóhoz. **Elutasítom** – nem jön létre semmi, a forgalmazó értesül. A kérés 24 óra után magától eltűnik.

## Támogatási hozzáférés

Ha a forgalmazó támogatójának be kell néznie a rendszerbe, te engedélyezed – és csak addig, amíg kell.

### Lépésről lépésre: engedélyezés

1. **Beállítások › Rendszer › Licenc**, a **Támogatási hozzáférés** doboz.
2. Válaszd ki, meddig: **2 óra**, **1 nap** vagy **3 nap**.
3. Kattints az **Engedélyezem** gombra, majd még egyszer (megerősítés).
4. Megjelenik a belépő link. Ha be van állítva a központ, a link magától eljut a támogatóhoz; ha nem, másold ki és küldd el neki.

### Visszavonás

A doboz alján a táblázat mutatja az élő hozzáféréseket. A **Visszavonás** gomb (kétszeri kattintás) azonnal törli az ideiglenes fiókot. Lejáratkor ez magától megtörténik (óránként ellenőrzi).

### Amit tudnod kell

- A támogató ebben az időben **mindent lát**, mint egy adminisztrátor. Csak akkor engedélyezd, ha kérted.
- Minden engedélyezés, belépés és megszűnés a **Naplóba** kerül.
- Lejárat után a link nem működik, a fiók nincs meg.

## Szabályok, amiket a rendszer betart

- Lejárt vagy letiltott licencnél a rendszer **nem áll le** és nem zárja el az adatokat – csak frissítést nem kap.
- A központ csak aláírt választ adhat; hamis vagy régi választ a rendszer elutasít.
- A támogatási fiók mindig időkorlátos, és lejáratkor törlődik.
- Központ nélkül (saját példány) semmi nem megy ki.

## Tippek

- A licenckulcsot kis- vagy nagybetűvel is beírhatod.
- Ha a központ címe változik, csak írd át – az állapot az új címről frissül.
- Támogatáshoz elég a legrövidebb idő (2 óra); ha kevés, újra engedélyezheted.

## Ha valami nem megy

- **„A központ nem érhető el”** – nincs internet, vagy rossz a cím. Nézd meg a címet, és próbáld újra a **Kapcsolat ellenőrzése most** gombbal.
- **„A központ kulcsa megváltozott”** – a forgalmazó új kulcsot kezdett használni. Szólj a forgalmazónak; a cserét a rendszer gazdája hagyhatja jóvá.
- **Ismeretlen licenckulcs** – elírás a kulcsban. Másold be újra a forgalmazótól kapott kulcsot.
- **Túl sok példány** – a kulcsot egy másik telepítés is használja. Kérj a forgalmazótól több példányra szóló licencet.

## GYIK

### Hol adom meg a licenckulcsot?

**Beállítások › Rendszer › Licenc**, a **Licenckulcs** mezőben, a központ címével együtt.

### Mit küld rólunk a rendszer a forgalmazónak?

Műszaki adatot (verzió, sebesség, tárhely, mentés, hibák helye) és darabszámokat. Az ügyfelek nevét, elérhetőségét, a munkalapok tartalmát és a forgalom összegét soha.

### Leáll a rendszer, ha lejár a licenc?

Nem. Minden működik tovább, az adatok a tieitek; csak frissítést nem kap a rendszer.

### Miért látok figyelmeztetést a képernyő tetején?

A licenc lejárt, letiltották, vagy 30 napja nincs kapcsolat a központtal. A pontos ok a Licenc lapon áll.

### Hogyan engedem, hogy a támogató belépjen?

**Beállítások › Licenc › Támogatási hozzáférés**: válassz időtartamot, és kattints kétszer az **Engedélyezem** gombra.

### Meddig fér hozzá a támogató?

Amíg a választott idő (2 óra, 1 nap vagy 3 nap) le nem jár, vagy amíg vissza nem vonod.

### Hogyan vonom vissza a hozzáférést?

A Támogatási hozzáférés doboz táblázatában a **Visszavonás** gombbal (kétszeri kattintás). A fiók azonnal törlődik.

### Látja a támogató az ügyfeleinket?

Igen, amíg a hozzáférés él, mindent lát, mint egy adminisztrátor. Ezért csak akkor engedélyezd, ha segítséget kértél.

### Honnan tudom, hogy a támogató belépett?

A **Naplóban** minden támogatási belépés és megszűnés megjelenik.

### Kell licenckulcs a saját példányhoz?

Nem. Ha nem forgalmazótól vettétek a rendszert, hagyd üresen a Licenc lapot.

### Kikapcsolhatom a távoli karbantartást?

Igen: **Beállítások › Rendszer › Licenc**, a *Távoli karbantartás* kapcsoló. Belépni a forgalmazó így sem tud, csak ha te engedélyezed a támogatási hozzáférést.

### Miért szürke egy modul kapcsolója?

Mert a licenc nem tartalmazza. Az adatai megmaradnak; a bővítést a forgalmazótól kérheted.

### Hogyan jön a frissítés licenccel?

A központ mondja meg, van-e újabb verzió, és onnan töltődik le. A telepítést a rendszer gazdája indítja (aki a rendszert nálatok beállította).
