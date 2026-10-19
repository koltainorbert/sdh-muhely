# Magnific-költség – értékesítő webhely képei

Készítette: tervező ügynök, 2026-10-19. Minden egységár a `simulate_cost` mért (exact) értéke, az egyenleg az
`account_balance` mért értéke.

## Egyenleg
| | Kredit |
|---|---|
| Egyenleg a generálás előtt | 155 363 |
| Keret (≤ 60%) | 93 217 |
| Tervezett összesen | 3 600 (2,3%) |
| **Ténylegesen elköltve** | **1 050** |
| Egyenleg utána | 154 313 |

## Mért egységárak (simulate_cost)
| Modell, beállítás | Kredit / kép |
|---|---|
| Nano Banana Pro (`imagen-nano-banana-2`) 4k | 150 |
| Nano Banana Pro 2k | 75 |
| Nano Banana 2 Lite | 60 |
| Recraft V4.1 mid | 60 |
| Seedream 5 Pro 2k | 100 |
| Magnific One (Quality) 16:9 | 180 |
| Magnific One Draft, 16 csempe | 250 |
| `images_split_grid` | 0 (ingyenes) |

## Terv és tény
| Tétel | Modell | Terv | Tény | Állapot |
|---|---|---|---|---|
| Hero 16:9, 2 változat | Nano Banana Pro 4k | 300 | 300 | elkészült a Magnificben, **nem letölthető** |
| OG-kép 1200×630 | a hero-kompozícióból | 0 | 0 | kódból |
| 8 iparági kép 3:2 | Nano Banana Pro 2k | 600 | 600 | elkészült a Magnificben, **nem letölthető** |
| 12 funkció-illusztráció (3 db 2×2 rács + split) | Nano Banana Pro 4k | 450 | 0 | leállítva |
| Demó: 240 kép (15 db 4×4 rács + split) | Nano Banana Pro 4k | 2 250 | 150 | 1 próbarács elkészült, **nem letölthető**; a többi leállítva |
| **Összesen** | | **3 600** | **1 050** | |

## Miért álltam le
A generált képek a `pikaso.cdnpk.net` CDN-ről tölthetők le (a `creations_deliver` is oda mutat). Ezt a gépet a
környezet kimenő proxyja **házirend alapján tiltja** (CONNECT 403, a proxy állapotlapja szerint is). Megkerülni nem
szabad, ezért a további generálást leállítottam, hogy ne költsünk letölthetetlen képekre.

**Tartalék (beépítve):** kódból generált, saját rajzú SVG-illusztrációk (licencmentes), sharp-pal AVIF + WebP-re
optimalizálva – 22 webhely-kép és 250 demókép. Ezek a manifestekben szerepelnek, a téma ezekkel teljes.

## Ha Norbi a Magnifices képeket szeretné
Az elkészült 11 kép a Magnific „Personal” projektjében van (hero ×2, szerviz, ipar, KKV, ZRT, állami, egészségügy,
kereskedelem, egyéb, telefonrács). Lehetőségek:
1. Böngészőből letölti őket, és bemásolja a `/home/claude/kepek-eredeti/site/` mappába (fájlnév = manifest-kulcs, pl.
   `iparag-szerviz.png`); a képépítő (`/home/claude/kepek-eredeti/eszkoz/epit.js`) erre bővíthető, a manifest-kulcsok nem változnak.
2. A vezető engedélyezteti a `pikaso.cdnpk.net` hosztot a környezetben; utána a hiányzó tételek a fenti terv szerint
   (+ 2 550 kredit) legenerálhatók és letölthetők.
