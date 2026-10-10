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
- **Házipénztár** – napi kassza, címletes zárás, napló, riport, statisztika, egyeztető ügynök, Excel-import
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
- **Kivét:** mindig negatív, és csak a Beállításokban megadott személyek közül (alap: Koltai Norbert, Légman Péter;
  becenevekkel, amelyekből az import is felismeri).
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
