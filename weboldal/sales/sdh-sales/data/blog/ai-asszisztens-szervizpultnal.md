---
cim: AI-asszisztens a szervizpultnál: mire jó ténylegesen, és mire nem?
leiras: Józan áttekintés arról, mit tud egy beépített AI-asszisztens egy szerviz programban: keresés, magyarázat, végigvezetés, javaslat jóváhagyással. És arról is, mire ne használd.
kategoria: AI
cimkek: [AI asszisztens szerviznek, mesterséges intelligencia, Claude, ügyfélszolgálat]
datum: 2026-10-05
kulcsszo: AI asszisztens szerviznek
seo_cim: AI-asszisztens szerviznek: mire jó, és mire nem?
---

**Röviden:**

- Egy szerviz programba épített AI-asszisztens leginkább **keresésben, magyarázatban és végigvezetésben** segít: „hol tartanak a mai lejárt határidejű lapok?”, „hogyan veszek fel garanciális visszatérést?”.
- Adatot csak **javaslatként** módosítson, amelyet egy ember hagy jóvá. A fontos módosításoknál megerősítő kóddal.
- Ne lássa a feloldó kódokat, jelszavakat és kulcsokat.
- A szabad szöveges válaszok egy külső AI-szolgáltatáson futnak, és pénzbe kerülnek. Kerettel kordában tartható.
- Nem helyettesíti a szerelőt, a jogi tanácsot vagy a döntést.

## Miért beszél mindenki AI-ról?

Az utóbbi években a nagy nyelvi modellek (mint a Claude, a ChatGPT vagy a Gemini) a mindennapi szoftverekbe is bekerültek. Egy szerviz programban az AI-asszisztens azt ígéri, hogy kevesebbet kell keresgélni, kattintgatni és a súgót olvasni. Ez igaz lehet, de csak akkor, ha az asszisztens jól van beépítve, és tudod, mire való.

## Mire jó egy beépített asszisztens?

### Kérdés–válasz a program használatáról

„Hogyan állítok ki céges számlát magánszemély ügyfélnek?” Ahelyett, hogy a súgóban keresnél, megkérdezed, és az asszisztens a súgó fejezeteiből válaszol. Ha a cégednek vannak saját szabályai (például garanciális feltételek), azokat is megtaníthatod neki.

### Végigvezetés

„Mutasd meg, hogyan zárom le a kasszát.” Az asszisztens lépésről lépésre végigvezet a képernyőn, kiemeli, hová kell kattintani. Új kollégák betanításánál ez sok időt spórol.

### Adatok lekérdezése

„Melyik nyitott munkalapok határideje járt le?” „Mennyi volt a tegnapi készpénzforgalom?” „Mit javíttatott eddig Kovács Péter?” Az asszisztens a rendszer adataiból válaszol, olvasási joggal, és megnyitja a kért lapot.

### Napló értelmezése

„Ki írta át ennek a lapnak a határidejét, és mikor?” A változásnapló mezőnként rögzíti a módosításokat; az asszisztens ebből idővonalszerűen összefoglalja, mi történt egy lappal.

### Figyelmeztetések

Az asszisztens magától is szólhat: lejárt határidő, lezáratlan kassza, új verzió. Ezek a jelzések a rendszer belső szabályaiból jönnek, nem kell hozzájuk AI-hívás.

## Javaslat és jóváhagyás

A legfontosabb kérdés: **módosíthat-e az asszisztens adatot?** A biztonságos válasz: csak javaslatként, amelyet ember hagy jóvá.

A {termeknev} [AI-asszisztense](/funkciok/asszisztens/) így működik:

| Művelet | Hogyan? |
|---|---|
| Olvasás (keresés, lekérdezés) | Azonnal |
| Hozzáadás (megjegyzés a munkalapra, csapatüzenet) | Javaslat, egy gombnyomásos jóváhagyással |
| Átírás (állapot, határidő, ügyféladat, ár) | Csak ha kifejezetten kéred, javaslat **megerősítő kóddal** |
| Törlés | Csak ha kifejezetten kéred, megerősítő kóddal |

A javaslat egy kártyán jelenik meg, a régi és az új értékkel. Ha elutasítod, semmi nem változik. Az első módosítás előtt a rendszer ellenőrzi, hogy van-e aznapi mentés.

## Mit ne lásson az asszisztens?

A szervizben vannak adatok, amelyeknek semmi keresnivalójuk egy AI-szolgáltatásnál:

- feloldó kódok és minták,
- jelszavak,
- API-kulcsok.

A mi asszisztensünk ezeket nem látja. Az adminisztrátor ezen felül egyenként kikapcsolhatja az asszisztens eszközeit (például ha nem szeretnéd, hogy a pénztárhoz hozzáférjen), vagy az egész modult.

## Adatvédelem

A szabad szöveges kérdésekhez az asszisztens a szükséges adatokat a kérdéssel együtt elküldi az AI-szolgáltatónak (a {termeknev} esetében az Anthropic Claude szolgáltatásának), a te API-kulcsoddal. Ezt az adatkezelési tájékoztatóban fel kell tüntetni, és érdemes elolvasni az AI-szolgáltató adatkezelési feltételeit. Ha ez nem fér bele a cég adatkezelési gyakorlatába, a szabad szöveges rész kikapcsolható; a túrák és a súgó API-kulcs nélkül, helyben működnek.

## Mennyibe kerül?

A szabad szöveges válaszok az AI-szolgáltató díjszabása szerint kerülnek pénzbe, a saját API-kulcsod terhére. A költség kollégánkénti napi kérdéskerettel kordában tartható. A túrák, a súgó és a magától jövő jelzések nem használnak AI-hívást.

## Mire ne használd?

### Diagnózisra

Az AI nem látja a készüléket. Adhat ötletet egy hibajelenséghez, de a döntés a szerelőé. Egy rossz tipp alapján megrendelt alkatrész pénzbe kerül.

### Jogi kérdésekre

„Jár-e garancia erre a javításra?” Az asszisztens elmondhatja, mit állítottál be a rendszerben, de a jogi megítélés nem az ő dolga.

### Ügyfélnek szóló üzenetek ellenőrzés nélküli küldésére

Egy AI által írt üzenet lehet pontatlan vagy rossz hangvételű. Mindig olvasd el, mielőtt elküldöd.

### Ha nincs internet

A szabad szöveges válaszokhoz internet kell. Kimaradáskor a túrák és a súgó működnek, a kérdés–válasz nem.

## Példakérdések a napi munkából

Hogy kézzelfogható legyen, néhány kérdés, amelyre egy beépített asszisztens jól tud válaszolni:

- „Melyik nyitott munkalapok határideje jár le ma?”
- „Mi volt a tegnapi kasszazárás eltérése, és mi okozhatta?”
- „Mely termékek fogytak a minimum alá?”
- „Ki módosította a 4521-es lap állapotát, és mikor?”
- „Hogyan rögzítek előleget egy rendelésnél?”
- „Vezess végig a nyomtatványok szerkesztésén.”

És néhány, amelyre ne várj megbízható választ:

- „Mi a baja ennek a telefonnak, ha nem kapcsol be?” (nem látja a készüléket)
- „Kötelező garanciát adnom erre?” (jogi kérdés)
- „Mennyit kérjek ezért a javításért?” (üzleti döntés)

## Az új kolléga betanítása

Az asszisztens talán legnagyobb haszna a betanításnál van. Egy új pultos az első hetekben sokat kérdez, és a tapasztalt kollégák ideje drága. Ha az új kolléga a rendszerben kérdezhet, és az asszisztens lépésről lépésre megmutatja, hol mi van, a tapasztalt kollégáknak csak a valóban nehéz kérdésekre kell válaszolniuk. Az oldaltúrák ráadásul AI-hívás nélkül, a súgóból működnek.

## Hogyan vezesd be?

1. Kapcsold be a saját fiókodon, és tegyél fel tíz valódi kérdést a napi munkából.
2. Nézd meg, melyik válasz volt pontos, és melyik nem.
3. A cég saját szabályait (garancia, árazás, nyitvatartás) tanítsd meg neki.
4. Állítsd be a napi kérdéskeretet.
5. Kapcsold ki azokat az eszközeit, amelyekre nincs szükség.
6. Mutasd meg a csapatnak, mire jó, és mire ne használják.

## Ellenőrzőlista

- Az asszisztens csak javaslatot tesz, ember hagy jóvá
- Átíráshoz és törléshez megerősítő kód kell
- Feloldó kódot, jelszót, kulcsot nem lát
- Adatkezelési tájékoztató kiegészítve
- Napi kérdéskeret beállítva
- A csapat tudja: diagnózisra és jogi kérdésre nem való

## Összegzés

Az AI-asszisztens egy szerviz programban nem csodaszer, hanem egy gyors, mindig elérhető kolléga, aki ismeri a súgót és az adatokat, de semmit nem dönt el helyetted. Ha a jogosultságai jól vannak beállítva, időt spórol, különösen a betanításnál és a keresésnél.

A {termeknev} asszisztensét a [demóban](/demo/) is kipróbálhatod. A modulok és árak az [Árak](/arak/) oldalon vannak, az adatvédelmi kérdésekre a [GYIK](/gyik/#gyik-ai-adatvedelem) válaszol.
