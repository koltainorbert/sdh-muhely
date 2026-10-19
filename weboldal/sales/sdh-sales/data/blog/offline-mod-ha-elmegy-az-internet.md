---
cim: Ha elmegy az internet: így dolgozik tovább a pult offline módban
leiras: Mi működik egy helyi szerveren futó szerviz programban internetkimaradáskor, mi vár a kapcsolat visszatéréséig, és hogyan próbáld ki élesítés előtt?
kategoria: Biztonság és üzemeltetés
cimkek: [offline munkalap program, internetkimaradás, helyi szerver, üzembiztonság]
datum: 2026-09-21
kulcsszo: offline munkalap program
seo_cim: Offline munkalap program: ha elmegy az internet a szervizben
---

**Röviden:**

- Ha a szerviz programja a bolt helyi hálózatán lévő szerveren fut, internetkimaradáskor a belső munka megy tovább.
- Ami külső szolgáltatást igényel (számla, e-mail, AI, felhős mentés), az vár, de a jó rendszer ezt azonnal jelzi, és nem várakoztat.
- A pultgépek a **helyi címen** érjék el a szervert, különben internet nélkül a gépek sem találják meg.
- Élesítés előtt húzd ki a kábelt, és nézd meg, mi működik.

## Miért fontos ez?

Egy szervizben az internetkimaradás nem ritka: szolgáltatói hiba, kábelszakadás az utcán, egy elromlott router. Ha a program felhőben fut, ilyenkor a pult áll: nem lehet munkalapot felvenni, nem lehet keresni, nem lehet kiadni a kész készüléket. Az ügyfél vár, a csapat papírra ír, és este mindent át kell vinni.

Ha a program a boltban, a helyi hálózaton fut, a helyzet más. A pultgépek a helyi szervert érik el, amihez nem kell internet. A belső munka folytatódik.

## Mi működik internet nélkül?

Egy helyi szerveren futó {termeknev} példányban internet nélkül is működik:

- munkalapok, ügyfelek, eszközök felvétele, keresése, szerkesztése,
- állapotváltás,
- házipénztár, napi árukiadás, rendelések,
- nyomtatás helyi nyomtatóra, matrica, PDF,
- naptár, csapatüzenetek, napló,
- helyi mentés,
- SMS, ha az SMS-átjáró telefon a helyi hálózaton van és van térereje,
- IMEI-ből típusfelismerés (a típusadatbázis helyben van),
- a súgó és az oldaltúrák.

## Mi vár a kapcsolat visszatéréséig?

Ami külső szolgáltatást igényel:

| Funkció | Mi történik kimaradáskor? |
|---|---|
| Számlázz.hu-számla | Nem állítható ki; a kapcsolat után a lapról újra |
| E-mail | Nem megy ki |
| AI-asszisztens szabad szöveges válaszai | Nem elérhető (a túrák mennek) |
| IMEI-lekérdezés szolgáltatótól | Nem elérhető |
| Google Drive / távoli FTP mentés | A másolat később megy |
| Frissítés-ellenőrzés | Később |
| Ügyféloldal, online foglalás, értékelés kívülről | Az ügyfél nem éri el |
| Elégedettségi kérdés | Később pótolja |

A lényeg, hogy ezek **ne várakoztassanak**. Egy rosszul megírt rendszer kimaradáskor 40–60 másodpercig próbálkozik minden külső kéréssel, és közben a felület lefagy. A {termeknev} percenként ellenőrzi a kapcsolatot, és ha nincs internet, a külső kérés azonnal hibát ad: „most nem megy ki”. A fejlécben „Nincs internet (óra:perc óta) – a helyi munka megy” felirat jelzi az állapotot.

## Számla internetkimaradáskor

Ez a leggyakoribb kérdés. A Számlázz.hu online szolgáltatás, így valódi számla internet nélkül nem készül. Ilyenkor két lehetőség van:

1. Az ügyfél fizet, a kassza rögzíti, a számlát a kapcsolat visszatérése után állítod ki a munkalapról, és elküldöd neki e-mailben.
2. Ha az ügyfél azonnal papírt kér, a számla-előkészítő PDF (amely nem minősül számlának) helyben elkészül, a valódi számla később megy.

A könyvelőddel érdemes egyeztetni, hogyan kezeled az ilyen helyzeteket, például ha online pénztárgépet is használsz.

## A beállítás feltételei

### Helyi szerver

A rendszernek a bolt hálózatán lévő gépen kell futnia. Ez lehet egy kis asztali gép vagy egy erre a célra vett mini szerver.

### Helyi cím

A pultgépek a szervert a **helyi címén** érjék el (például `http://192.168.1.10/`), ne a kívülről elérhető domainnéven. Ha a domainnevet használják, internet nélkül a névfeloldás is elakadhat, és a gépek nem találják a szervert.

### Szünetmentes táp

Az internetkimaradás gyakran áramszünettel jár. Ha a szerver és a router szünetmentes tápon van, egy rövid áramszünet sem állítja meg a munkát. A pultgépek laptopok esetén akkuról mennek tovább.

### SMS-telefon a helyi hálózaton

Ha az SMS-átjáró telefon a helyi Wi-Fi-n van, és a rendszer a helyi címén éri el, az SMS internetkimaradáskor is kimegy, a mobilhálózaton.

## Próba élesítés előtt

1. Győződj meg róla, hogy a pultgépek a helyi címen érik el a szervert.
2. Húzd ki az internetkábelt a routerből (a helyi hálózat maradjon).
3. Egy percen belül meg kell jelennie a „Nincs internet” jelzésnek.
4. Próbáld ki: új munkalap, állapotváltás, pénztármozgás, nyomtatás, mentés. Ezeknek menniük kell.
5. Próbálj számlát kiállítani: azonnal jeleznie kell, hogy most nem megy.
6. Dugd vissza a kábelt: egy percen belül el kell tűnnie a jelzésnek.
7. Állítsd ki a számlát.

Ha valamelyik lépés nem így megy, még élesítés előtt derül ki.

## Mi a helyzet a felhős mentéssel?

Ha a rendszer a helyi szerveren fut, a külső mentés különösen fontos: ha a gép tönkremegy, csak a külső másolat marad. Kimaradáskor a felhőbe menő másolat várakozik, és a kapcsolat visszatérése után feltöltődik. A helyi mentés közben is elkészül.

## Mit mondj az ügyfélnek kimaradáskor?

Egy rövid, egységes mondat sokat segít. Például: „Most nincs internetünk, a javítást felvettük, a számlát e-mailben küldjük, amint visszajön a kapcsolat.” Az ügyfél ilyenkor nem érzi, hogy valami nincs rendben, és a csapat is egyformán kommunikál. Érdemes ezt a mondatot a pultnál kint tartani.

## A kimaradás után

Amikor a kapcsolat visszajön, néhány teendő marad:

1. A kimaradás alatt kiadott, fizetett lapokon állítsd ki a számlákat.
2. Ha az ügyfél e-mailben kéri, küldd el.
3. Nézd meg, elkészült-e a külső mentés másolata (a rendszer újrapróbálja, de érdemes ránézni).
4. Ha a kimaradás alatt valaki papírra írt, vidd fel a rendszerbe.

Jó, ha a rendszer kiírja, mióta nincs internet: így tudod, melyik időszak lapjait kell átnézni.

## Ha a szerver áll le, nem az internet

Más a helyzet, ha nem az internet megy el, hanem maga a szerver: lemezhiba, áramszünet szünetmentes nélkül. Ilyenkor a pultgépek sem érik el a rendszert. Erre a külső mentés és egy kéznél lévő bianko munkalap a válasz: a papíron felvett ügyeket a szerver helyreállítása után viszed fel.

## Ellenőrzőlista

- A szerver a bolt hálózatán van
- A pultgépek a helyi címet használják
- Szerver és router szünetmentes tápon
- SMS-telefon a helyi Wi-Fi-n
- Külső, titkosított mentés beállítva
- A csapat tudja: kimaradáskor a számla később megy
- Próba a kábel kihúzásával megtörtént

## Összegzés

Az offline működés nem extra funkció, hanem a választott telepítési mód következménye. Ha a program a boltban fut, és a gépek helyben érik el, egy internetkimaradás csak a külső szolgáltatásokat érinti. A pult tovább dolgozik, az ügyfél nem vár.

Az [offline működésről](/funkciok/offline/) részletesebben is írtunk. A {termeknev} felületét a [demóban](/demo/) kipróbálhatod, a csomagokat az [Árak](/arak/) oldalon találod.
