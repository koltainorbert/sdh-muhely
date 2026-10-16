---
kulcs: mentes-biztonsag
cim: Mentés, jogosultság, biztonság
csoport: Rendszer
modul:
szakaszok: mentes
kulcsszavak: külső mentés, Google Drive, FTP, NAS, WebDAV, export, import, költözés, élesítés, jelszavas mentés, előző napi mentés, napi mentés, automatikus mentés, mentés, biztonsági mentés, backup, visszaállítás, visszatöltés, jogosultság, jog, szerep, szerepkör, adminisztrátor, admin, kolléga, közös fiók, letiltás, törlés, inaktív, érvénytelen, titkosítás, jelszó, kulcs, API-kulcs, napló, műveletnapló, pillanatkép, biztonság
osszefoglalo: Ki mit lát a rendszerben, hogyan és mikor készül mentés (a gépen és külső helyre: Google Drive, FTP, NAS, saját gép), hogyan állítható vissza, hogyan költöztethető a teljes beállítás élesbe, mit nem töröl a rendszer soha, mit nem töröl a rendszer soha, és hogyan tárolja a jelszavakat és kulcsokat.
---

## Mire való

Ez a fejezet arról szól, ki mihez fér hozzá, mi történik az adatokkal, ha valami elromlik, és hogyan vigyáz a rendszer a jelszavakra.

Röviden:

- Két szerep van: **adminisztrátor** és **kolléga**.
- A rendszer **ügyfelet és munkalapot nem töröl**.
- **Teljes mentés** minden nap magától készül, és kézzel is bármikor indítható (**Beállítások › Mentés**).
- Az asszisztens minden módosítása előtt **pillanatkép** készül, és a művelet visszaállítható.
- A mentés **külső helyre is másolódik**, jelszóval titkosítva: **Google Drive**, **FTP**, **NAS** vagy **saját gép** mappájába.
- A mentésből a **Beállítások › Mentés** lapon **visszaállíthatsz** – előtte a rendszer biztonsági mentést készít a mostani állapotról.
- A **beállítások külön exportálhatók** és importálhatók: élesbe költözéskor nem kell mindent újra beállítani.
- A szerver saját, teljes mentése (fájlok is) továbbra is kell.

## Ki mit lát: adminisztrátor és kolléga

A rendszert az adminisztrátor és a **Kolléga** szerepű fiók használhatja.

| | Kolléga | Adminisztrátor |
|---|---|---|
| A napi menüpontok (Áttekintéstől a Súgóig), számla kiállítása | igen | igen |
| Beszélgetés az asszisztenssel, javaslat jóváhagyása | igen | igen |
| **Beállítások** menüpont | nem | igen |
| Kollégák és csoportok felvétele, letiltása | nem | igen |
| Az asszisztens naplója, tudástára, a mentések | nem | igen |
| A naptár neveinek szerkesztése | nem | igen |
| A WordPress vezérlőpultja (wp-admin) | nem | igen |

Amit még tudni kell a kollégáról:

- **Belépés után egyenesen a műhely-felületre kerül.** Ha a wp-admin címét nyitná meg, a rendszer visszaviszi a műhelybe. A WordPress felső eszköztárát sem látja.
- **A csak adminisztrátornak szóló oldalak** a menüjében meg sem jelennek. Ha a címet kézzel írja be, az **Ehhez nincs jogosultságod.** oldalt kapja.
- **Kollégánkénti modul-jogosultság nincs.** Aki kolléga, az minden napi menüpontot lát. Az egyetlen finomabb zár a postafiókoké (lásd lejjebb).

A szerepet az adminisztrátor a **Csapat** menüpontban, a kolléga űrlapján állítja: **Kolléga – napi munka a CRM-ben** vagy **Adminisztrátor – beállítások, kollégák, WordPress**. Részletek: [Csapat](sugo:csapat).

A saját szerepedet és állapotodat nem módosíthatod – így nem tudod kizárni magad.

## Letiltás törlés helyett

A kollégát nem lehet törölni, csak letiltani. A **Csapat** menüpontban, a kolléga űrlapján az **Állapot** legyen **Letiltva (nem léphet be)**.

- A letiltott kolléga azonnal kilép mindenhonnan. Belépéskor ezt látja: **Ez a fiók le van tiltva. Szólj az adminisztrátornak.**
- A régi üzenetei és a munkalapjai megmaradnak.
- Visszaállítani ugyanott lehet: **Állapot › Aktív**.

## Közös fiókos használat

Ha a cég egyetlen közös fiókkal dolgozik, a **Beállítások › Csapat** lapfülön válaszd az **Üzenőfal (egy közös fiók)** módot.

- A **Csapat** helyén ekkor üzenőfal van cetlikkel; minden cetlin ott áll, kinek szól és ki írta.
- A kollégák neve belépés nélküli névlista (**Kollégák az üzenőfalhoz**, soronként egy név).
- A rendszer szemében ilyenkor egyetlen felhasználó dolgozik: nem tudja megkülönböztetni, ki rögzítette a munkalapot.

> **Figyelem:** Ha a közös fiók adminisztrátor, mindenki eléri a Beállításokat és a mentéseket is. Közös használatra hozz létre külön, **Kolléga** szerepű fiókot.

## Teljes mentés: mikor készül és mit tartalmaz

A teljes mentés a rendszer összes saját adatáról készült másolat, egyetlen tömörített fájlban.

**Mikor készül:**

- **Magától, minden nap egyszer.** Ha a szerver éjjel is megy, az időzítő indítja. Ha a gép éjjel ki van kapcsolva, a nap **első bejelentkezése után** készül el, a háttérben – a munkát nem lassítja.
- **Kézzel:** bármikor, a **Mentés most** gombbal.
- **Az asszisztens első módosítása előtt** is ellenőrzi a rendszer, hogy megvan-e az aznapi mentés; ha valamiért nincs, előbb ment.

A napi mentés nem függ az asszisztenstől: akkor is elkészül, ha az asszisztens ki van kapcsolva. A mentés ideiglenes néven íródik, és csak a hibátlanul elkészült fájl kerül a listába – a félbeszakadt (megtelt lemez) mentés nem szorít ki egy jót.

**Mit tartalmaz:**

- a rendszer minden saját adattábláját (ügyfelek, eszközök, munkalapok, pénztár, számlák, üzenetek, naptár és a többi),
- a rendszer minden beállítását (az `sdh_muhely_` kezdetű opciókat).

**Mit nem tartalmaz:**

- a TAC-adatbázist és a levelek gyorsítótárát – ezek újra előállíthatók,
- a csatolt fájlokat, a logót és a feltöltött képeket (ezek a szerver feltöltési mappájában vannak),
- a felhasználói fiókokat és a jelszavakat (ezek a WordPress saját tábláiban vannak),
- magukat a leveleket (azok a levelezőszerveren vannak).

**Hol tárolódik:** a szerveren, a `wp-content/uploads/sdh-muhely-mentes/` mappában. A fájl neve `sdh-mentes-` kezdetű, benne a dátum, az idő és a mentés oka (`napi` vagy `kezi`); a kiterjesztése `.jsonl.gz`. A mappát `.htaccess` fájl zárja le, kívülről nem olvasható.

**Hány marad meg:** alapból az utolsó 20; a szám a **Beállítások › Mentés** lapfülön állítható (3 és 365 között). Amikor eggyel több készül, a legrégebbi törlődik – a kézi és a napi mentések együtt számítanak. Ezen felül **mindig megmarad**:

- az **előző napi mentés** (a legutóbbi, nem mai mentés) – ha ma valami elromlik, a tegnapi állapot biztosan megvan,
- a legutóbbi **visszaállítás előtti** biztonsági mentés.

Ez a szabály a külső helyeken is ugyanígy érvényes.

## Lépésről lépésre: kézi mentés és letöltés

A mentésekhez adminisztrátori jog kell.

1. Nyisd meg a **Beállítások › Mentés** lapfület (a **Rendszer** csoportban).
2. A **Mentések** dobozban nyomd meg a **Mentés most** gombot. A gomb mellett megjelenik az eredmény, például: *Mentés kész: sdh-mentes-….jsonl.gz (1 MB, 5200 sor).*
3. A listában minden mentés mellett ott a dátuma, a fájl neve és a mérete. A **Letöltés** gombbal a saját gépedre mented.

Ugyanez a lista a **Beállítások › Asszisztens** lapfül **Mentések** gombja mögött is megvan.

> **Figyelem:** A mentések a szerveren maradnak: ha a gép lemeze meghibásodik, velük együtt vesznek el. Időnként tölts le egyet, és tedd el a szerveren kívül (pendrive, felhő). A fájl minden ügyféladatot és beállítást tartalmaz, ezért kezeld bizalmasan.

## A Beállítások „Mentés" lapfüle

A Beállítások **Rendszer** csoportjában van; csak adminisztrátor látja.

- **Napi mentés bekapcsolva** – alapból be van kapcsolva. Kikapcsolva magától nem készül mentés; a **Mentés most** gomb akkor is működik.
- **Megőrzött mentések száma** – ennyi marad meg (alapból 20). Napi egy mentésnél a 20 nagyjából három hét.
- **Mentés most** – azonnal készít egy teljes mentést.
- **Titkosító jelszó** – legalább 8 karakter; a külső helyekre kerülő másolatok ezzel vannak titkosítva. Külső hely bekapcsolásához kötelező.
- **Külső mentési helyek** – négy kártya (Saját gép, NAS, FTP / FTPS, Google Drive), mindegyiken **Kapcsolat próbája** és **Mentések listázása**.
- A lista a legutóbbi mentéseket mutatja, **Letöltés**, **Letöltés (titkosítva)** és **Visszaállítás…** gombbal.
- **Export és import** – a beállítások jelszavas fájlba, illetve fájlból.

Ha a legutóbbi mentés nem sikerült (például megtelt a lemez, vagy a mappa nem írható), a lapfül tetején piros sáv írja ki az okát.

## Külső mentés: Google Drive, FTP, NAS, saját gép

A szerveren maradó mentés nem véd a lemez meghibásodása ellen, ezért a rendszer minden mentés után **titkosított másolatot** tölt fel a bekapcsolt helyekre. Egyszerre több helyre is.

**A másolat titkosított.** A Beállítások › Mentés lapon megadott **titkosító jelszóval** készül, így a Drive-on, az FTP-n vagy a NAS-on lévő fájlt jelszó nélkül senki nem tudja elolvasni. **A jelszót tedd el biztos helyre** – elvesztése esetén a külső másolatok nem nyithatók meg.

| Hely | Mit kell megadni |
|---|---|
| **Saját gép** | Egy mappa útvonala (pl. csatolt vagy hálózati meghajtó). A mappa nem lehet a rendszer saját mentési mappája. |
| **NAS** | Mappa (csatolt vagy `\\szerver\mappa` hálózati út), vagy **WebDAV** cím, felhasználó, jelszó. |
| **FTP / FTPS** | Szerver, port, titkosítás (FTPS ajánlott), felhasználó, jelszó, mappa. |
| **Google Drive** | Saját Google-kliens azonosítója és titka, majd egyszeri összekapcsolás (lent). |

### Lépésről lépésre: külső hely bekapcsolása

1. Nyisd meg a **Beállítások › Mentés** lapfület.
2. A **Titkosítás** dobozban adj meg jelszót (kétszer), és mentsd a lapot.
3. A kívánt kártyán kapcsold be az **Aktív** kapcsolót, és töltsd ki a mezőket.
4. Nyomd meg a **Kapcsolat próbája** gombot: a rendszer megpróbál írni a helyre, és kiírja az eredményt vagy az okot.
5. **Mentsd a lapot.** Az első másolat a következő mentéskor (vagy a **Mentés most** gombra) készül.

Ha a külső hely éppen nem elérhető, a mentés **nem lesz hibás**: a helyi mentés elkészül, a másolat pedig **várakozik**, és 15 percenként újrapróbálkozik (legfeljebb hatszor, a napi mentés pedig mindig újra megpróbálja). A kártya állapotsora mutatja az utolsó sikeres másolatot és a hibát.

### Google Drive összekapcsolása

A Google-nak egyszeri beállítás kell (kb. 5 perc, a saját Google-fiókodban):

1. A [Google Cloud Console](https://console.cloud.google.com/)-ban hozz létre egy projektet, és kapcsold be a **Google Drive API**-t.
2. **OAuth-hozzájárulási képernyő**: típus *Külső*, add meg az alkalmazás nevét és az e-mail-címedet. **Állítsd az állapotot „Éles" (Production) értékre.** Ha „Tesztelés" marad, a Google 7 naponta lejáratja a kapcsolatot.
3. **Hitelesítő adatok › OAuth-kliensazonosító létrehozása**, típusa *Televíziók és korlátozott bemenetű eszközök*. Másold ki a **kliensazonosítót** és a **kliens titkát**.
4. A rendszerben a Google Drive kártyán írd be mindkettőt, és nyomd meg az **Összekapcsolás a Google-fiókkal** gombot.
5. Megjelenik egy kód és egy cím. Nyisd meg a címet, írd be a kódot, és engedélyezd a hozzáférést. A rendszer magától észreveszi, és kiírja az összekapcsolt fiókot.

A rendszer csak a **saját maga által létrehozott** fájlokhoz és mappához fér hozzá (`drive.file` jogosultság), a többi Drive-fájlodat nem látja. A mentések a **SDH Műhely mentés** nevű mappába kerülnek. Ha a Google később visszavonja a hozzáférést, a kártya kiírja, hogy a kapcsolat lejárt – nyomd meg újra az **Összekapcsolás** gombot.

## Visszaállítás mentésből

A visszaállítás mindig **két lépésből** áll: előbb **előnézet**, utána a tényleges visszaállítás. Csak adminisztrátor végezheti.

### Lépésről lépésre: visszaállítás

1. A **Beállítások › Mentés** lapon válaszd ki a forrást:
   - egy helyi mentés mellett **Visszaállítás…**,
   - egy külső hely kártyáján **Mentések listázása**, majd a kívánt sor **Visszaállítás…** gombja,
   - vagy az **Import fájlból** részben válassz fájlt (és add meg a jelszavát, ha eltér a beállítottól).
2. Az **előnézet** megmutatja a mentés idejét, verzióját, a táblák és sorok számát.
3. Válassz módot:
   - **Adatok és beállítások visszaállítása** – a mostani adatok a mentés állapotára cserélődnek,
   - **Csak a beállítások visszaállítása** – az adatokhoz (ügyfelek, lapok, pénztár) nem nyúl.
4. Mindkét gomb **kétlépcsős**: az első kattintás megerősítést kér („Biztosan? Kattints újra"), a második indítja.

**Mit csinál a rendszer közben:**

- **Először biztonsági mentést készít** a mostani állapotról (a neve `…-visszaallitas-elott-…`). Ha az nem sikerül, nem állít vissza semmit.
- Az egész visszaállítás **egyetlen tranzakció**: ha bármi hiba van (sérült fájl), minden visszagördül, és az adatok nem változnak.
- Az **újabb verzióból** készült mentést nem fogadja el – előbb frissíteni kell a plugint.
- Utána újraépíti a keresőt és a gyorsítótárakat.

**Visszavonás:** ha a visszaállítás nem azt hozta, amit vártál, a **visszaállítás előtti** biztonsági mentést ugyanígy visszaállíthatod – az megmarad a listában.

> **Figyelem:** A visszaállítás a mentés óta felvett adatokat (új ügyfelek, lapok, pénztártételek) törli. Éles adaton csak akkor használd, ha biztos vagy benne; előtte nézd meg az előnézetet.

## Export, import és költözés élesbe

**Beállítások exportja** (Beállítások › Mentés › Export és import): a rendszer **minden beállítása** egy jelszavas fájlba kerül – arculat, számlázás, SMS, levelezés, nyomtatványok, kulcsok, mentési helyek –, **adatok nélkül**. Új telepítésen (például az éles szerveren) az **Import fájlból** résznél betöltöd, és nem kell mindent újra beállítani.

**A kulcsok és jelszavak utaznak.** A rendszer a titkosított kulcsokat (Számla Agent, IMEI, levelezés, SMS…) a jelszavas fájlon belül nyíltan viszi át, és az új gépen az **ottani** kulcsokkal titkosítja újra. A fájl ezért érzékeny: erős jelszóval készül, és tárold biztonságosan.

A **teljes mentés** (adatok + beállítások) a mentések listájából tölthető le titkosítva (**Letöltés (titkosítva)**), és másik gépen ugyanígy visszaállítható.

### Lépésről lépésre: költözés a próbarendszerből élesbe

1. A próbarendszeren: **Beállítások › Mentés › Beállítások exportja**. Mentsd el a fájlt.
2. Az éles szerveren telepítsd a pluginet.
3. Ott: **Beállítások › Mentés › Import fájlból** – válaszd ki a fájlt, add meg a jelszavát, nyisd meg az előnézetet.
4. Nyomd meg a **Csak a beállítások visszaállítása** gombot (és erősítsd meg).
5. Ellenőrizd a külső mentési helyeket (**Kapcsolat próbája**). A Google Drive kapcsolat a másik gépről is érvényes marad, de ha ugyanazt a mappát a két rendszer használja, érdemes az élesen újra összekapcsolni.

## Az asszisztens módosításai: pillanatkép és visszaállítás

Az asszisztens adatot soha nem módosít magától. Minden módosítás előbb **javaslat**, amelyet egy kártyán látsz.

1. A kártya megmutatja, mi változna: a mező nevét, a régi és az új értéket. A kártya típusa **Hozzáadás**, **Átírás**, **Törlés** vagy **Adatlekérés**.
2. Te döntesz: **Engedélyezem** vagy **Elutasítom**.
3. Átírásnál és törlésnél a kártya egy négyjegyű kódot is kér: **Biztonsági megerősítés – írd be a kódot**. Amíg nem írtad be, az **Engedélyezem** gomb nem nyomható meg.
4. Engedélyezés után a rendszer előbb elmenti a rekord teljes, előző állapotát (ez a pillanatkép), és csak utána hajtja végre a változást.

A javaslat hat óra után lejár. Az döntheti el, akinek készült, vagy egy adminisztrátor.

### Lépésről lépésre: egy módosítás visszaállítása

1. Keresd meg a végrehajtott művelet kártyáját a beszélgetésben. Adminisztrátorként a **Beállítások › Asszisztens › Javaslatok és napló** oldalon az összes művelet ott van, tizenkettesével lapozva.
2. Nyisd le a kártyán a **Visszaállítás…** részt.
3. Írd be a művelet számát – a kártya kiírja, melyik az.
4. Nyomd meg a **Visszaállítom** gombot.

Ha a rekord a művelet óta megváltozott, a rendszer nem írja felül vakon: szól, és csak a **Mégis visszaállítom** gombbal megy tovább.

Csak a saját műveletedet állíthatod vissza; adminisztrátor bárkiét. A kártya állapota: **Jóváhagyásra vár**, **Végrehajtva**, **Elutasítva**, **Visszaállítva**, **Nem sikerült** vagy **Lejárt**.

Részletek az asszisztensről: [Asszisztens](sugo:asszisztens).

## Szabályok, amiket a rendszer betart

- **Ügyfelet nem töröl.** Az ügyfél űrlapján az **Inaktívra állít** gomb kiveszi a napi listából, az **Újra aktív** visszateszi. Az inaktív ügyfelet a fejléc keresője nem hozza, de a régi munkalapjain megmarad.
- **Munkalapot nem töröl.** A téves munkalap az érvénytelen állapotba kerül; a száma és a története megmarad.
- **Kollégát nem töröl.** Csak letiltani lehet.
- **A munkalapon használt terméket nem törli:** törlés helyett inaktív lesz. A szolgáltatás törölhető a törzsből, de a munkalapokra már felvett tételei megmaradnak.
- **Az asszisztens ügyfél vagy munkalap törlésére javaslatot sem tesz.** Számlát kiállítani, levelet küldeni, pénztárba írni sem tud.
- **Belépés nélkül semmi nem érhető el.** A csatolt fájlokat is csak bejelentkezett, jogosult felhasználó kapja meg.

## Titkosítva tárolt kulcsok és jelszavak

A rendszer a külső szolgáltatások jelszavait nem tárolja olvasható formában.

| Adat | Hogyan tárolódik |
|---|---|
| E-mail-fiók alkalmazásjelszava (**Beállítások › Levelezés**) | Titkosítva. Soha nem kerül vissza a képernyőre. |
| A levélügynök AI-kulcsa (**Beállítások › Levelezés**) | Titkosítva. |
| Az asszisztens Claude API-kulcsa (**Beállítások › Asszisztens**) | Titkosítva. A legbiztonságosabb, ha a `wp-config.php` fájlban adod meg – akkor az adatbázisba be sem kerül. |
| A postafiók külön jelszava (**Külön jelszó a CRM-ben**) | Lenyomatként: maga a jelszó nem olvasható vissza. |
| A letöltött levelek gyorsítótára | Titkosított fájlokban. |
| A Számla Agent kulcs (**Beállítások › Számlázás**) | Titkosítva. A képernyőre nem kerül vissza. |
| A fizetős IMEI-szolgáltató kulcsa (**Beállítások › IMEI-lekérdezés**) | Titkosítva. A mezője mentés után üres marad; törölni a **Kulcs törlése** pipával lehet. |

A titkosítás kulcsa a WordPress saját titkos kulcsaiból (a `wp-config.php` *salt* értékeiből) képződik. A kulcsok a **mentésfájlba is titkosítva** kerülnek: aki csak a mentést vagy az adatbázist szerzi meg, nem tudja kiolvasni őket.

A korábbi verzióban titkosítatlanul eltárolt Számla Agent kulcsot és IMEI-kulcsot a rendszer a frissítés után magától titkosítja – nem kell újra beírni.

> **Figyelem:** Ha ezek megváltoznak – például költözéskor –, a titkosítva tárolt jelszavak és kulcsok nem fejthetők vissza. Ilyenkor az alkalmazásjelszót, az API-kulcsokat, a Számla Agent kulcsot és az IMEI-kulcsot újra be kell írni; a Számlázás és az IMEI-lekérdezés lapfülén piros sáv figyelmeztet rá.

### A postafiók külön jelszava

A **Beállítások › Levelezés** lapfülön egy postafióknak külön jelszó adható. Amíg valaki be nem írja, annak a fióknak semmilyen adata nem jut el a böngészőjébe.

- Öt hibás próbálkozás után 15 perc szünet következik.
- A megnyitott fiók a beállított idő után újra bezár (alapból 480 perc; 5 és 1440 perc között állítható).

## Amit a rendszer nem tud

- **A felhasználói fiókok nincsenek a mentésben, ezért a visszaállítás sem érinti őket.** A kollégák és a közös fiók dolgozói (WordPress-felhasználók) a rendszeren kívül maradnak; új gépen őket újra létre kell hozni.
- **A külső hely beállításai a beállításokkal együtt utaznak.** Másik gépre importálva a Drive-kapcsolat és a mentési helyek is átkerülnek – ellenőrizd őket (**Kapcsolat próbája**), mielőtt a régi rendszert kikapcsolod.
- **A Google Drive csak akkor marad összekapcsolva, ha az OAuth-állapot „Éles".** Tesztelés állapotban a Google 7 naponta lejáratja.
- **Nincs kollégánkénti modul-jogosultság.**
- **A csatolt fájlok és a felhasználói fiókok nincsenek a mentésben.** Ezekhez a szerver teljes mentése kell.
- **A kézzel végzett módosításnak nincs visszaállító gombja.** Visszaállítani csak az asszisztens műveletét lehet.

Élesítés előtt ezért egyeztess a tárhely üzemeltetőjével: legyen napi, teljes szervermentés (fájlok és adatbázis), és tudd, hogyan kérhető vissza.

## Ha valami nem megy

- **„A szerveren nincs gzip – a mentés nem készülhet el."** A szerver PHP-jéből hiányzik a tömörítés. Kérd a tárhely üzemeltetőjét, hogy kapcsolja be.
- **„A mentési mappa nem írható: …"** A mappa jogosultságait az üzemeltető állítja be.
- **„Ez a javaslat lejárt – kérd újra az asszisztenstől."** Hat óránál régebbi javaslat.

További elakadások: [Hibaelhárítás](sugo:hibaelharitas).

## GYIK

### Készül magától mentés minden nap?

Igen. Naponta egy teljes mentés készül – ha a gép éjjel ki van kapcsolva, akkor a nap első bejelentkezése után, a háttérben. Az asszisztens használatától nem függ. A beállítása: **Beállítások › Mentés**.

### Hány mentés marad meg, és hol állítom?

Alapból az utolsó 20. A **Beállítások › Mentés** lapfülön, a **Megőrzött mentések száma** mezőben állíthatod.

### Benne van a mentésben a Számla Agent kulcs?

Igen, de titkosítva – ugyanúgy, ahogy az adatbázisban áll. Aki csak a mentésfájlt szerzi meg, nem tudja kiolvasni.

### Ki tudom kapcsolni a napi mentést?

Igen, a **Beállítások › Mentés** lapfülön – de nem ajánlott. Kikapcsolva csak a **Mentés most** gombra készül mentés.

### Hol találom a mentéseket?

A **Beállítások › Mentés** lapfülön. Csak adminisztrátor látja.

### Hogyan állítok vissza egy mentésből?

A **Beállítások › Mentés** lapon: a mentés mellett nyomd meg a **Visszaállítás…** gombot, nézd meg az előnézetet, és válaszd az **Adatok és beállítások** vagy a **Csak a beállítások** módot (kétlépcsős megerősítéssel). Előtte a rendszer biztonsági mentést készít a mostani állapotról, így a visszaállítás visszavonható. Részletek: lásd a **Visszaállítás mentésből** részt fent.

### Benne vannak a mentésben a csatolt fotók és a logó?

Nem. A mentés az adatbázis adatait és a beállításokat tartalmazza. A fájlokhoz a szerver fájlmentése kell.

### Véletlenül töröltem egy ügyfelet. Vissza lehet hozni?

Ügyfelet nem lehet törölni, csak inaktívvá tenni. Az **Ügyfelek** listában nyomd meg az **Inaktívak** gombot, nyisd meg az ügyfelet, és nyomd meg az **Újra aktív** gombot.

### Miért nem tudok törölni egy munkalapot?

Mert a rendszer elvből nem töröl munkalapot. A téves lapot tedd érvénytelen állapotba: így a sorszáma és a története megmarad.

### Mit lát egy kolléga, és mit nem?

Minden napi menüpontot lát. Nem látja a Beállításokat, nem kezelhet kollégákat, nem éri el a mentéseket és a WordPress vezérlőpultját.

### Be lehet állítani, hogy egy kolléga ne lássa a Pénztárat?

Nem. Kollégánkénti modul-jogosultság nincs. Külön zárni csak postafiókot lehet, jelszóval.

### Kilépett egy kolléga a cégtől. Mit csináljak a fiókjával?

A **Csapat** menüpontban tiltsd le. Azonnal kilép mindenhonnan, és nem tud újra belépni. A munkalapjai és az üzenetei megmaradnak.

### Biztonságos, ha mindenki egy fiókot használ?

Működik, erre való az üzenőfal mód. De ne az adminisztrátori fiók legyen a közös, és nem látszik majd, ki mit rögzített.

### Az asszisztens átírhat valamit a tudtom nélkül?

Nem. Minden módosítás javaslat, amelyet neked kell engedélyezned. Átíráshoz és törléshez kódot is be kell írnod.

### Engedélyeztem egy javaslatot, de rossz lett. Mit tegyek?

A művelet kártyáján nyisd le a **Visszaállítás…** részt, írd be a művelet számát, és nyomd meg a **Visszaállítom** gombot.

### Látja valaki a levelezés jelszavát?

Nem. Titkosítva tárolódik, és soha nem kerül vissza a képernyőre. A mezője mentés után üres marad.

### Költöztettük a szervert, és nem megy a levelezés. Miért?

Ha a `wp-config.php` titkos kulcsai megváltoztak, a titkosított jelszavak és kulcsok nem fejthetők vissza. Írd be újra az alkalmazásjelszót, az API-kulcsokat, a Számla Agent kulcsot és az IMEI-kulcsot a Beállításokban.

### Elég a rendszer saját mentése?

Nem teljesen. Az adatokat és a beállításokat a külső helyre másolt, titkosított mentés védi, és a **Beállítások › Mentés** lapról vissza is állítható. A csatolt fájlok és a felhasználói fiókok viszont nincsenek benne, ezért a szerver teljes, rendszeres mentése nélkül ne élesíts.

### Hova érdemes külső mentést beállítani?

Legalább egy, a szervertől **független** helyre: Google Drive, vagy a saját gép / NAS. Ha az irodában van NAS, az ingyenes és gyors; a Drive a másik telephelyről is elérhető. Két hely együtt a legbiztonságosabb.

### Mit jelent az „előző napi" jelvény?

A lista legutóbbi, **nem mai** mentését jelöli. Ez a mentés a megőrzött darabszámtól függetlenül mindig megmarad, hogy mindig legyen egy tegnapi állapotod.

### Elfelejtettem a titkosító jelszót. Mit tegyek?

A régi külső másolatok nem nyithatók meg a jelszó nélkül – ez a titkosítás lényege. A szerveren lévő (nem titkosított) helyi mentések viszont megvannak. Állíts be új jelszót a Beállítások › Mentés lapon: az új másolatok már azzal készülnek.

### Költözés élesbe: mit vigyek át?

A **Beállítások exportját**, ami a kulcsokat és minden beállítást tartalmaz. Lépések: lásd az **Export, import és költözés élesbe** részt.
