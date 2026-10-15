# Hardveres gyorsítás kikényszerítése (Windows, Chrome és Edge)

Weboldal nem tudja bekapcsolni a böngésző hardveres gyorsítását, ezt csak a gép rendszergazdája teheti meg házirenddel. Ez a mappa ezt adja.

1. `hardveres-gyorsitas-be.reg` – dupla kattintás, **Igen** (rendszergazdaként). Beállítja a Chrome és az Edge házirendjét: a gyorsítás be van kapcsolva, és a felhasználó a böngésző beállításában sem tudja kikapcsolni.
2. A böngészőt zárd be teljesen (az értesítési területről is), nyisd meg újra.
3. Ellenőrzés: `chrome://policy` (Edge: `edge://policy`) – látszik a *HardwareAccelerationModeEnabled = true*; és `chrome://gpu` – a „Graphics Feature Status" sorai *Hardware accelerated* feliratúak. A CRM Beállítások › Rendszer › Sebesség lapja is kiírja az állapotot.
4. Visszavonás: `hardveres-gyorsitas-vissza.reg`.

Fontos: a házirend a *beállítást* kényszeríti. Ha a videokártya-illesztő hibás vagy a böngésző letiltotta (tiltólista), a gyorsítás ettől még nem indul – ilyenkor a videokártya-illesztőt kell frissíteni. Firefoxban ilyen házirendet nem használunk: ott kézzel (Beállítások › Általános › Teljesítmény) kapcsolható; a CRM figyelmeztet, ha ki van kapcsolva.
Linux/macOS: Chrome `/etc/opt/chrome/policies/managed/` JSON-ban `{"HardwareAccelerationModeEnabled": true}`.
