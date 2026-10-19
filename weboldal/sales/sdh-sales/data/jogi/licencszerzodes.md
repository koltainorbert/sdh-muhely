---
cim: Licencszerződés (végfelhasználói feltételek)
leiras: A saját szerveren futó szoftver felhasználási joga: terjedelem, frissítés, lejárat, modulok, tilalmak, támogatási hozzáférés és a központnak küldött műszaki adatok.
verzio: "1.0"
hatalyos: "2026-11-01"
szulo: jogi
---
**TERVEZET – jogásszal ellenőriztetni.**

Hatályos: 2026. november 1-jétől. Verzió: 1.0. Ez a szerződés az [Általános szerződési feltételek](/jogi/aszf/) elválaszthatatlan része.

## 1. Felek, tárgy

Licencadó: **{ceg}** ({ceg_cim}, adószám: {ceg_adoszam}). Licencvevő: a megrendelő vállalkozás („Vevő”).

A {termeknev} saját fejlesztésű, **PHP / MySQL alapú webes alkalmazás**, amely a Vevő szerverén fut.

A licencadó a {termeknev} szoftverre (a hozzá tartozó dokumentációval és frissítésekkel együtt, a továbbiakban: Szoftver) **nem kizárólagos, át nem ruházható, harmadik személynek tovább nem engedélyezhető** felhasználási jogot ad (a szerzői jogról szóló 1999. évi LXXVI. törvény (Szjt.) szerint), a megrendelt licenc terjedelmében és időtartamára.

## 2. A licenc terjedelme

A licenc (a licenckulcshoz tartozó adatok szerint) meghatározza:

- **a példányok számát** – hány telepítésen (webhelyen) használható ugyanaz a kulcs;
- **a felhasználók számát** – hány felhasználói fiók dolgozhat a rendszerben;
- **a modulokat** – mely funkciócsoportok (például Házipénztár, SMS, Naptár, Számlázás) járnak a licenchez;
- **az időtartamot** – a lejárat napját;
- **a jogokat** – frissítés, támogatás, távoli karbantartás.

A Vevő a Szoftvert saját belső üzleti céljára, a saját vagy általa bérelt szerveren telepítheti és használhatja. Ha a felhasználók száma meghaladja a licencet, a Szoftver az adminisztrátornak figyelmeztetést mutat; a munka nem áll le, de a Vevő köteles a licencet bővíteni.

## 3. Frissítések

Frissítést (új verziót) a Szoftver **csak érvényes, aktív licenccel** kap. A frissítést az adminisztrátor egy kattintással telepítheti; telepítés előtt a Szoftver teljes mentést készít.

## 4. Lejárat után

Lejárt (vagy a licencadó által letiltott) licenc esetén a Szoftver **tovább működik**, az adatok megmaradnak és elérhetők, de **frissítést és támogatást nem kap**. Az adminisztrátor erről a képernyő tetején figyelmeztetést lát. A licencadó a lejárt licencű példányt nem kapcsolja le és az adatokhoz való hozzáférést nem zárja el.

## 5. Nem licencelt modul

Az a modul, amely nincs a licencben, **nem fut**: eltűnik a menüből, a Modulok oldalon a kapcsolója szürke. Az addig rögzített **adatai megmaradnak**; ha a licencet bővítik, a modul a következő kapcsolódáskor (legfeljebb 5 perc) ott folytatódik, ahol abbamaradt.

## 6. Tilalmak

A Vevő nem jogosult:

- a Szoftvert vagy a licenckulcsot harmadik személynek eladni, bérbe adni, kölcsönadni, allicencbe adni, vagy szolgáltatásként (SaaS) harmadik feleknek nyújtani;
- a licencben meghatározottnál több példányon vagy felhasználóval használni;
- a licencellenőrzést, a modul- vagy felhasználókorlátozást megkerülni, kikapcsolni vagy módosítani;
- a Szoftvert visszafejteni (dekompilálni), szétbontani, abból származékos művet készíteni – kivéve, ha ezt az Szjt. kógens szabályai (különösen a 59–60. §: együttműködési képesség, hibajavítás) kifejezetten megengedik;
- a szerzői jogi és a licencadóra utaló megjelöléseket eltávolítani.

## 7. A központtal való kapcsolat és a küldött műszaki adatok

A licenc ellenőrzéséhez és a frissítésekhez a Szoftver a licencadó központjához kapcsolódik: **5 percenként** jelentkezik (a régebbi központ felé naponta). A központ válasza digitálisan aláírt (Ed25519); a Szoftver csak az első kapcsolatkor megjegyzett kulccsal aláírt választ fogadja el.

**Amit a Szoftver küld** (telemetria):

- a licenckulcsot, a példány véletlen azonosítóját és egy ujjlenyomatot (a webhely címéből képzett, sózott lenyomat – magát a címet nem);
- a verziót és a műszaki állapotot: szerver, PHP- és adatbázis-verzió, memória, válaszidő (átlag, 95%-os érték, leglassabb), az adatbázis, a feltöltések és a lemez mérete, az utolsó mentés ideje, az ütemezett feladatok állapota, a végzetes hibák helye (az üzenetből az e-mail-címek és a számsorok kitakarva), a bekapcsolt kiegészítők és modulok;
- darabszámokat: hány munkalap, ügyfél, eszköz, termék és felhasználó van, és hányan dolgoznak éppen.

**Amit soha nem küld:** az ügyfelek nevét, telefonszámát, e-mail-címét, címét, a munkalapok tartalmát és a forgalom összegét.

Saját példánynál (licenckulcs és központ nélkül) semmi nem megy ki.

## 8. Távoli karbantartás

A licencadó a központból karbantartást indíthat: mentés, diagnosztika (sebességmérés), gyorsítótár-ürítés, internetkapcsolat-ellenőrzés, frissítés keresése és telepítése, valamint üzenet küldése a Vevő adminisztrátorainak. Ezek egyike sem küld adatot és egyike sem nyit belépést. A Vevő a **Beállítások › Rendszer › Licenc** lapon a távoli karbantartást kikapcsolhatja.

## 9. Támogatási hozzáférés (csak beleegyezéssel, időkorláttal)

A licencadó támogatója **csak a Vevő adminisztrátorának kifejezett engedélyével** léphet be a Vevő rendszerébe:

- az engedélyt a Vevő adja meg a Licenc lapon (vagy a támogatási kérés sávjában az „Engedélyezem” gombbal), **2 órára, 1 napra vagy 3 napra**;
- ilyenkor ideiglenes adminisztrátori fiók jön létre; a belépő linket a rendszer csak lenyomatként tárolja;
- a hozzáférés lejáratkor (óránkénti ellenőrzéssel) vagy visszavonáskor **törlődik**;
- minden engedélyezés, belépés és megszűnés a Vevő naplójába kerül;
- a támogatási kérés 24 óra után magától eltűnik, ha a Vevő nem válaszol.

A támogató a hozzáférés ideje alatt mindent lát, amit egy adminisztrátor. Az ilyenkor megismert személyes adatokra az [Adatfeldolgozási szerződés](/jogi/adatfeldolgozasi-szerzodes/) vonatkozik.

## 10. Szavatosság, felelősség

A licencadó szavatolja, hogy a Szoftver a dokumentációban leírt funkciókat lényegében biztosítja. A Szoftver hibáit a licencadó frissítéssel javítja. A felelősség korlátozására az ÁSZF 10. pontja irányadó.

A Vevő felel a szerver üzemeltetéséért, a biztonsági mentésekért, a hozzáférések védelméért, valamint azért, hogy a Szoftverben kezelt személyes adatokat jogszerűen kezelje (a Szoftverben rögzített adatok adatkezelője a Vevő).

## 11. Megszűnés

A licencadó a licencet azonnali hatállyal letilthatja, ha a Vevő a 6. pontot súlyosan megsérti. A letiltás után a Szoftver a 4. pont szerint működik tovább (frissítés és támogatás nélkül). [DÖNTENDŐ: súlyos jogsértésnél (pl. jogosulatlan terjesztés) további jogkövetkezmények]

## 12. Irányadó jog

A magyar jog, különösen a Ptk. és az Szjt. Jogvita esetén az ÁSZF 14. pontja szerint járunk el.
