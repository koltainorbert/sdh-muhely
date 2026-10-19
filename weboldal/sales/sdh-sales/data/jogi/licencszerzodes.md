---
cim: Licencszerződés (végfelhasználói feltételek)
leiras: A forráskód GPL-licence, és amire a fizetős licenckulcs jogosít: frissítés, támogatás, távoli karbantartás, központi szolgáltatások, modulok, lejárat, támogatási hozzáférés, telemetria.
verzio: "1.0"
hatalyos: "2026-11-01"
szulo: jogi
---
**TERVEZET – jogásszal ellenőriztetni.**

Hatályos: 2026. november 1-jétől. Verzió: 1.0. Ez a szerződés az [Általános szerződési feltételek](/jogi/aszf/) elválaszthatatlan része.

## 1. Felek, tárgy

Licencadó: **{ceg}** ({ceg_cim}, adószám: {ceg_adoszam}). Licencvevő: a megrendelő vállalkozás („Vevő”).

A {termeknev} saját fejlesztésű, **PHP / MySQL alapú webes alkalmazás**, amely a Vevő szerverén fut.

## 1/A. A forráskód licence (GPL) és a fizetős licenc

[ellenőrizendő – jogász]

**A szoftver forráskódja a GNU General Public License 2. (vagy későbbi) változata alatt áll; a licencszöveg a csomagban található.** A forráskódot a Vevő a GPL feltételei szerint szabadon használhatja, tanulmányozhatja, módosíthatja és továbbadhatja. E szerződés egyetlen pontja sem korlátozza a GPL által biztosított jogokat; ha bármely pont ezzel ellentétesnek tűnne, a forráskód tekintetében a GPL az irányadó.

**A fizetős licenc (licenckulcs) a frissítésekre, a támogatásra, a távoli karbantartásra és a központi szolgáltatásokra jogosít; a licenc nélküli modulok a rendszerben nem kapcsolódnak be, ennek kikerülése a frissítésekre és a támogatásra való jogosultságot megszünteti.**

A fizetős licenc tehát **szolgáltatásra szóló jogosultság**, nem a forráskód felhasználási joga. A {termeknev} név, a logók, az arculati elemek és a licencadó szolgáltatásai (a központ, a frissítési csatorna, a támogatás) a licencadóé; a GPL ezekre nem terjed ki. A név és a logók a licencadó engedélye nélkül nem használhatók úgy, hogy azt a látszatot keltsék, mintha egy módosított vagy továbbadott változat a licencadótól származna, vagy azt a licencadó támogatná. A dokumentáció és a webhely tartalma szerzői jogi védelem alatt áll, kivéve, ha kifejezetten más licenc vonatkozik rá.

A továbbiakban: **Szoftver** – a {termeknev} a hozzá tartozó dokumentációval és frissítésekkel; **Szolgáltatások** – a licenckulccsal igénybe vehető frissítés, támogatás, távoli karbantartás és központi szolgáltatások.

## 2. A licenc terjedelme

A licenckulcs (a hozzá tartozó adatok szerint) meghatározza, milyen terjedelemben jár a Szolgáltatás:

- **a példányok számát** – hány telepítésen (webhelyen) használható ugyanaz a kulcs;
- **a felhasználók számát** – hány felhasználói fiók dolgozhat a rendszerben;
- **a modulokat** – mely funkciócsoportok (például Házipénztár, SMS, Naptár, Számlázás) járnak a licenchez;
- **az időtartamot** – a lejárat napját;
- **a jogokat** – frissítés, támogatás, távoli karbantartás.

Ha a felhasználók vagy a példányok száma meghaladja a licencet, a Szoftver az adminisztrátornak figyelmeztetést mutat; a munka nem áll le, de a Szolgáltatások a licenc szerinti terjedelemre szólnak, ezért a Vevő a licenc bővítésével tarthatja meg őket.

## 3. Frissítések

Frissítést (új verziót) a Szoftver **csak érvényes, aktív licenccel** kap. A frissítést az adminisztrátor egy kattintással telepítheti; telepítés előtt a Szoftver teljes mentést készít.

## 4. Lejárat után

Lejárt (vagy a licencadó által letiltott) licenc esetén a Szoftver **tovább működik**, az adatok megmaradnak és elérhetők, de **frissítést és támogatást nem kap**. Az adminisztrátor erről a képernyő tetején figyelmeztetést lát. A licencadó a lejárt licencű példányt nem kapcsolja le és az adatokhoz való hozzáférést nem zárja el.

## 5. Nem licencelt modul

Az a modul, amely nincs a licencben, **nem fut**: eltűnik a menüből, a Modulok oldalon a kapcsolója szürke. Az addig rögzített **adatai megmaradnak**; ha a licencet bővítik, a modul a következő kapcsolódáskor (legfeljebb 5 perc) ott folytatódik, ahol abbamaradt.

## 6. A licenckulcs és a Szolgáltatások használata

A forráskódra a GPL vonatkozik (1/A. pont). A **licenckulcsra és a Szolgáltatásokra** a következők érvényesek:

- a licenckulcs a Vevőhöz szól; harmadik személynek nem adható tovább, és a licencben meghatározottnál több példányhoz vagy felhasználóhoz nem használható – ez a Szolgáltatásokra való jogosultságot érinti, nem a forráskód GPL szerinti használatát;
- ha a Vevő a licencellenőrzést vagy a modul- / felhasználókorlátozást kikerüli, a frissítésekre és a támogatásra való jogosultsága megszűnik (a már megfizetett díj nem jár vissza [DÖNTENDŐ]);
- a Szolgáltatások (a központ, a frissítési csatorna, a támogatás) nem terhelhetők túl, és nem használhatók más szoftverhez vagy harmadik személy javára;
- a licencadó a módosított forráskódú példányhoz támogatást csak akkor köteles nyújtani, ha a hiba a módosítatlan változatban is jelentkezik.

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

A licencadó a licenckulcsot azonnali hatállyal letilthatja, ha a Vevő a 6. pontot súlyosan megsérti. A letiltás csak a Szolgáltatásokat érinti: a Szoftver a 4. pont szerint működik tovább (frissítés és támogatás nélkül), a GPL szerinti jogokat nem érinti. [ellenőrizendő – jogász]

## 12. Irányadó jog

A magyar jog, különösen a Ptk. és a szerzői jogról szóló 1999. évi LXXVI. törvény (Szjt.); a forráskód licencére a GNU General Public License 2. (vagy későbbi) változata. Jogvita esetén az ÁSZF 14. pontja szerint járunk el.
