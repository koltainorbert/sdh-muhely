---
cim: Kétlépcsős azonosítás és titkosított mentés: alapbiztonság egy kis szerviz rendszerében
leiras: A két leggyakoribb kár egy szervizben az ellopott jelszó és az elveszett adat. Megmutatjuk, hogyan véd a kétlépcsős belépés és a titkosított külső mentés, és hogyan vezesd be.
kategoria: Biztonság és üzemeltetés
cimkek: [kétlépcsős azonosítás, 2FA, titkosított mentés, adatbiztonság]
datum: 2026-09-07
kulcsszo: kétlépcsős azonosítás
seo_cim: Kétlépcsős azonosítás és titkosított mentés szervizeknek
---

**Röviden:**

- A jelszó önmagában kevés: kiszivároghat, kitalálható, megosztják. A **kétlépcsős belépés** egy második, telefonon generált kódot is kér.
- A szerveren maradó mentés nem véd lemezhiba, betörés vagy zsarolóvírus ellen. Kell egy **külső, titkosított másolat** is.
- Mindkettő bevezethető egy délután alatt, és utána szinte semmi teendő nincs vele.
- A mentés csak akkor ér valamit, ha a **visszaállítást is kipróbáltad**.

## Miért kell egy kis szerviznek ezzel foglalkoznia?

Egy szervizben sok személyes adat van: nevek, telefonszámok, címek, feloldó kódok, fotók. Ha egy jelszó kiszivárog, illetéktelen férhet hozzá. Ha a szerver lemeze tönkremegy, évek munkája veszhet el. Egy kis cégnél nincs informatikai osztály, ezért a védelemnek egyszerűnek és automatikusnak kell lennie.

## Kétlépcsős belépés (2FA)

### Hogyan működik?

A belépéshez a jelszó mellett egy hatjegyű kód is kell, amelyet a telefonon futó hitelesítő alkalmazás generál. A kód 30 másodpercenként változik. A módszer neve TOTP, az RFC 6238 szabvány írja le ([datatracker.ietf.org](https://datatracker.ietf.org/doc/html/rfc6238)), amely a 30 másodperces időlépést ajánlja alapértelmezésnek. Bármelyik elterjedt hitelesítő alkalmazás működik vele.

Ha valaki megszerzi a jelszót, a telefon nélkül nem tud belépni.

### Kinek legyen kötelező?

Egy jó rendszerben ez beállítható:

- **választható**: aki akarja, bekapcsolja,
- **adminisztrátoroknak kötelező**: akik a beállításokhoz, a mentéshez és a kollégákhoz férnek hozzá,
- **mindenkinek kötelező**.

Kis szervizben jó kiindulás, hogy az adminisztrátoroknak legyen kötelező. Ha a rendszer kívülről is elérhető (például otthonról dolgoztok), érdemes mindenkire kiterjeszteni.

### Tartalék kódok

Mi van, ha elveszik a telefon? A bekapcsoláskor a rendszer tartalék kódokat ad, mindegyik egyszer használható. Ezeket nyomtasd ki, és tedd el biztos helyre (nem a pult alá). Ha nincs tartalék kód, az adminisztrátor visszaállíthatja a fiók kétlépcsős belépését.

### Megbízható gép

Hogy a pultgépen ne kelljen minden belépésnél kódot írni, beállítható, hogy egy gép néhány napig megbízható legyen. Ha a gépet lecserélitek vagy ellopják, a megbízható gépek visszavonhatók.

### Bevezetés lépésről lépésre

1. Az adminisztrátor bekapcsolja a saját fiókján, kipróbálja.
2. Beállítja a szabályt (például adminisztrátoroknak kötelező).
3. A kötelezett kollégák a következő belépéskor beolvassák a QR-kódot a telefonjukkal.
4. Mindenki elteszi a tartalék kódjait.
5. Egy hét múlva ellenőrzés: mindenki be tud lépni?

A {termeknev} [biztonsági funkciói](/funkciok/biztonsag/) között a kétlépcsős belépés a fenti szabályokkal működik, és sorozatos hibás kódnál a fiók átmenetileg zárolódik.

## Közös fiók: mégis látszódjon, ki volt

Sok szervizben egy közös pultgép van, egy közös fiókkal. Ez kényelmes, de nem látszik, ki mit csinált. Egy megoldás: a közös fiókon a felület indulásakor mindenki kiválasztja a nevét, és beír egy rövid PIN-kódot. Így a napló, a kassza és a munkalap a valódi személyt mutatja. Ügyelj arra, hogy a közös fiók ne az adminisztrátori fiók legyen.

## Mentés: helyben és kívül

### A napi mentés

A rendszer naponta készítsen teljes mentést az adatbázisról és a beállításokról. Ha a szerver éjjel ki van kapcsolva, a nap első bejelentkezése után a háttérben. Tartsd meg az utolsó néhány hetet; a {termeknev} alapból az utolsó 20 mentést őrzi meg, és ezen felül mindig az előző napit.

### Miért nem elég a helyi mentés?

Ha a mentés ugyanazon a lemezen van, mint az adatbázis, egy lemezhiba mindkettőt viszi. Ha a gépet ellopják, a mentés is megy vele. Ezért kell egy **külső hely**:

| Külső hely | Előny | Mire figyelj |
|---|---|---|
| Google Drive | Egyszerű, ingyenes kerettel | Egyszeri összekapcsolás a saját Google-fiókkal |
| NAS (mappa vagy WebDAV) | Helyben, gyors | A NAS is legyen védve, lehetőleg más helyen |
| FTP / FTPS | Bármilyen tárhelyre | FTPS-t használj, ne titkosítatlan FTP-t |
| Csatolt meghajtó | Egyszerű | Ha mindig csatolva van, egy vírus azt is elérheti |

### Titkosítás

A külső másolat legyen **titkosított**, egy általad megadott jelszóval. Így ha a Drive-fiókot vagy a NAS-t feltörik, a mentés olvashatatlan. A jelszót tedd el biztos helyre: nélküle a külső másolat nem nyitható meg.

### Mi van, ha a külső hely épp nem elérhető?

A jó rendszer ilyenkor nem hibázik, hanem a helyi mentést elkészíti, a külső másolatot pedig később újrapróbálja.

## A visszaállítás próbája

Egy mentés, amelyet soha nem próbáltál visszaállítani, csak remény. Évente legalább egyszer:

1. Állíts fel egy próbapéldányt (nem az éleset!).
2. Állítsd vissza rá a legutóbbi külső mentést, a titkosító jelszóval.
3. Nézd meg: megvannak-e az ügyfelek, a munkalapok, a kassza?
4. Írd fel, mennyi ideig tartott.

Ha ez működik, egy valódi baj esetén tudni fogod, mit kell tenni.

## Gyakori kifogások

### „Lassítja a belépést.”

A megbízható gép beállítással a pultgépen csak néhány naponta kell kódot írni. Egy hatjegyű kód beírása néhány másodperc.

### „A kollégák elveszítik a telefonjukat.”

Erre valók a tartalék kódok, és az adminisztrátor egy kattintással visszaállíthatja egy fiók kétlépcsős belépését. A visszaállítás naplózódik.

### „Nálunk úgysem törnek be.”

A legtöbb jelszólopás nem célzott: automatizált próbálkozások, kiszivárgott jelszólisták, ugyanaz a jelszó több helyen. A kis cég épp azért célpont, mert kevesebb védelme van.

### „A tárhelyszolgáltató úgyis ment.”

Lehet, de érdemes megkérdezni, milyen gyakran, meddig őrzi, és hogyan állítható vissza. Egy saját, titkosított, általad ellenőrzött másolat akkor is megvan, ha a tárhelyszolgáltatóval gond van.

## Zsarolóvírus ellen

Ha egy zsarolóvírus titkosítja a szerver fájljait, a helyi mentés is érintett lehet. Az ellenszer a **külső, elkülönített** másolat: olyan helyen, amelyet a szerver nem tud felülírni vagy törölni (például egy felhős tár verzióelőzménnyel, vagy egy NAS, amely csak írási jogot ad). A megőrzési szabály (legfrissebb N mentés + az előző napi) segít, hogy egy fertőzött mentés ne szorítsa ki az utolsó jót.

## Ellenőrzőlista

- Kétlépcsős belépés adminisztrátoroknak kötelező
- Tartalék kódok kinyomtatva, biztos helyen
- Közös fióknál „ki vagy?” választó PIN-nel
- A közös fiók nem adminisztrátori
- Napi mentés bekapcsolva
- Legalább egy külső, titkosított mentési hely
- A titkosító jelszó biztos helyen
- Évente egy próba-visszaállítás

## Összegzés

A kétlépcsős belépés és a titkosított külső mentés a két legfontosabb védelem egy kis szerviz rendszerében. Mindkettő egyszer beállítandó, utána a háttérben működik. A legnagyobb hiba, ha a mentést soha nem próbálod ki.

A {termeknev} biztonsági beállításait a [demóban](/demo/) is megnézheted. A gyakori kérdések között a [kétlépcsős belépésről](/gyik/#gyik-ketlepcsos-belepes) és a [külső mentésről](/gyik/#gyik-titkositott-kulso-mentes) is írtunk. Az árak az [Árak](/arak/) oldalon vannak.
