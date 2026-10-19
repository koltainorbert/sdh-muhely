---
cim: Házipénztár szervizben: napi zárás, címletes számolás, és mit tegyél, ha nem egyezik a kassza
leiras: Hogyan vezesd a szerviz napi kasszáját úgy, hogy este percek alatt lezárható legyen, és ha hiány vagy többlet van, gyorsan kiderüljön az oka? Gyakorlati útmutató.
kategoria: Raktár és pénzügy
cimkek: [házipénztár program, kasszazárás, pénztárnyilvántartás, szerviz pénzügy]
datum: 2026-09-02
kulcsszo: házipénztár program
seo_cim: Házipénztár szervizben: napi zárás és kasszaegyeztetés
---

**Röviden:**

- A kassza egyenlegét csak a **készpénz** adja; a kártyás és az utalásos fizetés a forgalomban látszik, a fiókban nem.
- Minden pénzmozgás egy tétel: bevétel, kivét, kifizetés, befizetés. A kivét mindig névvel.
- A munkalap fizetése egy lépésben kerüljön a kasszába, ne este, emlékezetből.
- Napi zárás címletes számolással: a rendszer kiírja, egyezik-e, és ha nem, mennyivel.
- Eltérésnél ne találgass: nézd meg, melyik fizetett munkalap hiányzik a pénztárból, mi lett rossz módon rögzítve, mi lett kétszer beírva.

## Miért gond a szervizes kassza?

Egy szervizben a pénz több úton jön: készpénz a pultnál, bankkártya a terminálon, utalás díjbekérő alapján, előleg alkatrészrendeléskor, bevizsgálási díj. Közben pénz is megy ki: futár, posta, egy sürgős alkatrész készpénzért, egy kolléga kivétje. Ha mindezt egy füzetben vagy egy táblázatban vezeted, és a munkalapok egy másik programban vannak, este nehéz megmondani, miért hiányzik ötezer forint.

## Az alapfogalmak

| Tétel típusa | Mit jelent? | Hatása a fiókra |
|---|---|---|
| Bevétel | Az ügyfél fizetett (készpénz, kártya, utalás) | Csak a készpénz növeli |
| Kivét | Valaki pénzt vett ki, névvel | Csökkenti |
| Kifizetés | A kasszából fizettél valakinek (beszállító, futár) | Csökkenti |
| Befizetés | Pénz került a kasszába, ami nem bevétel (váltópénz) | Növeli |

A nyitó összeg az előző nap záró készpénze. Ha az előző napot megszámoltad, a megszámolt összeg, különben a várható.

## A munkalap fizetése és a kassza

A leggyakoribb hiba, hogy a munkalapon a fizetés rögzül, de a kasszába nem kerül be, vagy fordítva. A megoldás, ha a fizetés rögzítése a munkalapon **egyben pénztártétel is**: a fizetési mód (készpénz, kártya, utalás, vegyes) szerint, egy lépésben. Vegyes fizetésnél (részben készpénz, részben kártya) a két rész külön kerüljön rögzítésre, egy tételben.

Előlegnél ugyanez: a munkalapon „előleg / részfizetés”, a pénztárban „Előleg –” jelöléssel. A végső fizetésnél a fizetendő összeg már az előleggel csökkentett.

A {termeknev} [számlázási és pénztár funkciói](/funkciok/szamlazas/) így kapcsolják össze a munkalapot, a kasszát és a számlát: a számla kiállítása után a számlaszám a munkalap pénztártételeire is felkerül.

## Napi zárás lépésről lépésre

1. Zárás előtt nézd meg, van-e még nyitott, fizetett munkalap, amelynek a fizetése nincs rögzítve.
2. Számold meg a fiókot **címletenként**: hány darab 20 000-es, 10 000-es, és így tovább.
3. A rendszer kiszámolja a megszámolt összeget, és mellé a levezetést: nyitó + készpénzes bevétel + befizetés − kifizetés − kivét = várható.
4. Az eredmény: **egyezik**, **többlet** vagy **hiány**, az összeggel.
5. Ha van mit hozzáfűzni, írj megjegyzést a záráshoz.
6. Zárd le a napot. Ha a bankkártya-terminált is zárni kell, ne felejtsd el; jó, ha a rendszer emlékeztet rá.
7. Nyomtasd ki vagy mentsd PDF-be a zárólapot.

## Ha nem egyezik a kassza

Ne kezdd a pénzt újraszámolni ötödször. Nézd meg rendszeresen ezeket az okokat:

| Lehetséges ok | Hogyan ismered fel? |
|---|---|
| Fizetett munkalap, de nincs a pénztárban | A munkalap „fizetve”, a pénztárban nincs tétele |
| Számla kiállítva, de nincs a pénztárban | Van számlaszám, nincs pénztártétel |
| Készpénznek rögzítve, de kártyás volt (vagy fordítva) | A terminál zárása és a pénztár kártyás összege eltér |
| Kétszer rögzítve | Két egyforma tétel ugyanarra a lapra |
| Kivét név nélkül | Kivét, akinek a neve hiányzik |
| Elgépelés | Egy tétel összege nagyságrenddel eltér a laptól |
| Az előző nap nem volt megszámolva | A nyitó összeg csak becslés |

A {termeknev} Házipénztár moduljában egy **egyeztető ügynök** ezeket automatikusan végignézi: összeveti a pénztárat a munkalapokkal és a számlákkal, és ha talál olyan tételeket, amelyek együtt pontosan kiadják az eltérést, megmondja. A javítást mindig te hagyod jóvá egy gombbal; az ügynök magától nem módosít.

## Lezárt nap javítása

Előfordul, hogy másnap derül ki egy hiba. A lezárt napot ne írd át csendben: nyisd újra, javítsd, és zárd le újra. A változásnapló megmutatja, ki, mikor és mit módosított a zárás után. Ez nem bizalmatlanság, hanem védelem annak, aki a kasszát kezeli.

## Ki mit lát?

A napi kassza adatai érzékenyek. Nem minden kollégának kell látnia a havi bevételt vagy a kivéteket. Szerepkörökkel beállítható, hogy a pénztár riportját csak az lássa, akinek ez a dolga, a pultos pedig rögzíthessen, de ne lásson mindent.

## Régi kassza-táblázat

Ha eddig Excelben vezetted a zárásokat, nem kell elveszítened a múltat. A {termeknev} Házipénztára a régi „Zárás” táblázatot .xlsx-ből vagy CSV-ből be tudja tölteni, visszavonhatóan. Erről az [adatátvétel](/adatatvetel/) oldalon is írunk.

## Ellenőrzőlista

- A munkalap fizetése egy lépésben pénztártétel
- Vegyes fizetésnél a két rész külön összeggel
- Előleg jelölve
- Kivét mindig névvel
- Napi zárás címletes számolással
- Kártyaterminál zárása emlékeztetővel
- Eltérésnél egyeztetés a munkalapokkal és a számlákkal
- Lezárt nap módosítása csak újranyitással, naplózva
- Szerepkörök: ki látja a pénztár riportját

## Összegzés

A kassza akkor zárható le gyorsan, ha napközben minden pénzmozgás a helyére kerül: a munkalap fizetése egy lépésben a pénztárba, a kivét névvel, a számlaszám a tételre. Ha mégis eltérés van, a munkalapokkal és a számlákkal való összevetés általában percek alatt megtalálja az okát.

A {termeknev} házipénztárát és az egyeztető ügynököt a [demóban](/demo/) kipróbálhatod. A modulok és árak az [Árak](/arak/) oldalon vannak.
