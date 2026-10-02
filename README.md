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
- *(később)* raktár, számla, pénztár, határidő, statisztika

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
