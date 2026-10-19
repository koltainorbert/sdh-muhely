---
cim: Süti-tájékoztató
leiras: Milyen sütiket és böngészőtárolót használ a webhely, mire, meddig, és hogyan változtathatod meg a döntésedet.
verzio: "1.0"
hatalyos: "2026-11-01"
szulo: jogi
---
**TERVEZET – jogásszal ellenőriztetni.**

Hatályos: 2026. november 1-jétől. Verzió: 1.0.

## Mi a süti?

A süti (cookie) kis szöveges fájl, amelyet a webhely a böngésződben tárol. Hasonló a böngésző helyi tárolója (localStorage, sessionStorage). Az elektronikus hírközlésről szóló 2003. évi C. törvény (Eht.) 155. § (4) bekezdése szerint ilyen adatot csak világos és teljes körű tájékoztatás után, a hozzájárulásoddal tárolhatunk – kivéve, ha az a szolgáltatás nyújtásához feltétlenül szükséges.

## A döntésed

Az első látogatáskor a lap alján sáv jelenik meg három, egyforma gombbal: **Elfogadom**, **Elutasítom**, **Beállítások**. A Beállításokban kategóriánként döntheted el, mit engedsz. A döntésed előtt **semmilyen** statisztikai vagy marketingkód nem fut: a Google-mérés „megtagadva” állapotban indul (Google Consent Mode v2).

A döntésedet **bármikor** megváltoztathatod a lábléc **„Süti-beállítások”** linkjével. A döntés 6 hónapig érvényes; utána – és ha a szabályzatunk lényegesen változik – újra megkérdezünk.

Ha a böngésződ **Global Privacy Control** jelzést küld, a marketing-sütiket alapból kikapcsoljuk, és az „Elfogadom” sem kapcsolja be őket (a Beállításokban kifejezetten bekapcsolhatod).

## Kategóriák

### Szükséges (mindig aktív)

Nélkülük az oldal nem működne; ezekhez nem kell hozzájárulás.

| Név | Tároló | Mire | Meddig |
|---|---|---|---|
| suti | süti | a süti-döntésed (kategóriák, a szabályzat verziója, időpont) | 6 hónap |
| suti_id | süti | véletlen azonosító, amellyel a döntésed a hozzájárulás-naplóban igazolható | 6 hónap |
| belépési sütik | süti | belépés az ügyfélfiókba (csak belépés után jönnek létre) | a munkamenet végéig, legfeljebb 14 nap |
| sütiteszt | süti | annak ellenőrzése, hogy a böngésző fogad-e sütit (belépéskor) | munkamenet |
| AI-csevegő munkamenete | sessionStorage | az AI-csevegő aktuális beszélgetése, amíg a lap nyitva van | a böngészőlap bezárásáig |
| kosár | localStorage | a kosár tartalma (csak ha terméket teszel bele) | amíg ki nem üríted |
| Cloudflare (__cf_bm, cf_clearance, Turnstile) | süti | robot- és visszaélésszűrés (ha a webhely Cloudflare mögött fut) | 30 perc – 1 év |

### Preferencia (kényelmi)

| Név | Tároló | Mire | Meddig |
|---|---|---|---|
| megjelenés | localStorage | a választott világos / sötét megjelenés | amíg nem törlöd |
| oktatási haladás | localStorage | az Oktatás oldal leckéinek haladása | amíg nem törlöd |

[DÖNTENDŐ: a sötét/világos mód és az oktatási haladás a látogató kifejezett kérésére tárolódik – jogász döntse el, „szükséges” vagy „preferencia” kategória. A kód jelenleg a kategóriától függetlenül tárolja őket.]

### Statisztika (csak hozzájárulással)

Csak akkor, ha a webhely üzemeltetője bekapcsolta a Google Analyticset.

| Név | Szolgáltató | Mire | Meddig |
|---|---|---|---|
| _ga | Google | látogatók megkülönböztetése (névtelen statisztika) | 2 év |
| _ga_&lt;azonosító&gt; | Google | a munkamenet állapota | 2 év |

### Marketing (csak hozzájárulással)

A webhely jelenleg nem használ hirdetési sütit. Ha a jövőben használna, itt soroljuk fel, és a döntésedet újra kérjük.

## A Google Consent Mode

A Google-szolgáltatások a „megtagadva” alapállapotból indulnak: `ad_storage`, `ad_user_data`, `ad_personalization`, `analytics_storage`, `functionality_storage`, `personalization_storage` = megtagadva; csak a `security_storage` (biztonság) engedélyezett. A döntésed után ezt a kategóriáid szerint frissítjük. A döntést a webhely többi mérőkódja felé is jelezzük, így azok is követik.

## A hozzájárulás igazolása

A döntésedet a szerveren naplózzuk: a véletlen süti-azonosító, a kategóriák, a szabályzat verziója, az időpont és az IP-cím sózott lenyomata (nem maga az IP-cím). Ez szükséges ahhoz, hogy igazolni tudjuk a hozzájárulást (GDPR 7. cikk (1)). A napló legfeljebb 5 évig őrződik.

## Sütik törlése a böngészőben

A sütiket a böngésző beállításaiban is törölheted vagy tilthatod. Ha a szükséges sütiket tiltod, a belépés és a kosár nem fog működni.

## Kapcsolódó

- [Adatkezelési tájékoztató](/jogi/adatkezeles/)
- Kérdés: {ceg_email}
