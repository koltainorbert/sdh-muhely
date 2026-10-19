# Döntésnapló és feltételezések – értékesítő webhely (vezető vezeti)

## Feltételezések (2026-10-19, a munka elején)
1. A tároló marad a `koltainorbert/sdh-muhely`; a webhely a `weboldal/` alatt, a Műhely telepítőjéből kizárva (`.gitattributes`).
2. Helyőrző terméknév: „SDH CRM” – egyetlen beállítás (`termeknev`), a szövegekben `{termeknev}` token.
3. Saját, könnyű shop (nem WooCommerce): kevesebb lekérdezés és szkript a fronton; a kosár a böngészőben, az ár a szerveren.
4. A Központ 2.0 marad a licenc-backend; csak egy aláírt kiszolgálási végpontot kap (Központ 2.1.0) – a meglévő Ed25519-re építve.
   Két mód: ugyanazon a webhelyen (közvetlen hívás) vagy külön webhelyen (aláírt HTTP).
5. A vevői zip letöltése az új vevőnek egyszer használható, 14 napig érvényes linkkel (a régi `csomag` végpont példányt kér).
6. Ügyfélfiók jelszó nélkül (e-mailes belépő link) – kevesebb támadási felület, nincs jelszó-visszaállítás.
7. Kártyás kapu (Barion / SimplePay) kódban kész, de alapból kikapcsolva; éles kulcsot Norbi ad meg.
8. Számlázz.hu: tesztüzem alapból; élesre kapcsolás Norbi döntése (visszafordíthatatlan: valódi számla).
9. Éles domain, éles fizetés, éles számla: nem kapcsolom be – ezek Norbi döntései.
10. A demó egy külön WordPress-példány (Műhely + demó-korlátozó + 10 000 rekord), soha nem az éles Műhely.

## Döntések
- 2026-10-19 · Norbi: az értékesítő oldalon sikeres fizetés után a végszámla **automatikusan** megy ki (`szamlazz.automatikus` = igaz).
  A Műhely „valódi számla csak gombra” döntése a CRM-re érvényes marad.
- 2026-10-19 21:18 · Norbi: **REJTÉS** – sehol nem derülhet ki, hogy WordPress az alap, és hogy az SDH áll mögötte (`REJTES.md`).
  Ennek következményei: helyőrző terméknév „Munkora” (SDH CRM helyett), REST `/api/portal/v1`, semleges azonosítók,
  útvonal-álnevek, titkos belépő, a Műhely semleges vevői buildje, licenckulcs-előtag a Központ beállításából.
- 2026-10-19 · Vezető (jogi ügynök javaslatára): a webshop **csak vállalkozásoknak** árul (kötelező „vállalkozásként vásárolok”
  jelölés + adószám), mert a fogyasztói digitális tartalomra vonatkozó elállási szabályok (45/2014. Korm. r. 29. § (1) m),
  22. § (1a) online elállási gomb) külön folyamatot kívánnának. Ha Norbi fogyasztóknak is el akar adni, ezt ki kell építeni.
