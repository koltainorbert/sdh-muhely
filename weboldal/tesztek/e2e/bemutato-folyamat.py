"""
Bemutató – böngészős végigjárás (Playwright, Chromium).

  php -S 127.0.0.1:8793 -t /home/claude/env/demo &          # a bemutató-példány (siteurl: http://127.0.0.1:8793/wp)
  python3 weboldal/tesztek/e2e/bemutato-folyamat.py [kimeneti-mappa]

Lépések: indítás (?bemutato=indit) → a műhely felülete a BEMUTATÓ sávval → új ügyfél a popupban → a keret csökken →
Frissítés (AJAX) tiltva 403-mal → tiltott Beállítások-lapfül visszairányít, nem látszik → a keret elfogyása után a
következő mentés érthető magyar hibát ad a popupban → képernyőkép. A keret kimerítéséhez a parancssori segéd a
bemutató naplójába ír (ugyanaz az IP-kivonat).
Kimenet: lépésenként egy sor, a végén összegzés; kilépési kód 0, ha minden rendben.
"""
import subprocess
import sys
import time

from playwright.sync_api import sync_playwright

ALAP = 'http://127.0.0.1:8793/wp/'
WP_LOAD = '/home/claude/env/demo/wp/wp-load.php'
KI = (sys.argv[1] if len(sys.argv) > 1 else '/tmp').rstrip('/') + '/'
eredmeny = []


def lepes(nev, ok, reszlet=''):
    eredmeny.append(ok)
    print(('OK  ' if ok else 'HIBA') + ' ' + nev + (' – ' + reszlet if reszlet else ''))


def php(kod):
    teljes = ("$_SERVER['HTTP_HOST']='127.0.0.1:8793'; $_SERVER['REQUEST_URI']='/wp/'; require '" + WP_LOAD + "'; " + kod)
    return subprocess.run(['php', '-r', teljes], capture_output=True, text=True, timeout=60).stdout


def keret(pg):
    return int(pg.inner_text('[data-bm-keret]').strip())


def mentes(r):
    return r.request.method == 'POST' and 'sdh_muhely_ugyfelek_ment' in (r.request.post_data or '')


# Tiszta kezdés: visszaállítás a pillanatképből (a korábbi futás látogatói adata és kerete törlődik).
php('SDH_Demo_Pillanatkep::visszaallit();')

with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={'width': 1440, 'height': 900}, locale='hu-HU')
    hibak = []
    pg.on('pageerror', lambda e: hibak.append(str(e)))

    # 1. Indítás egy kattintással.
    pg.goto(ALAP + '?bemutato=indit')
    lepes('indító oldal', 'Bemutató indítása' in pg.title())
    time.sleep(2.2)
    pg.click('button.bm-gomb')
    pg.wait_for_load_state('networkidle')
    lepes('belépve a műhelybe', 'muhely=' in pg.url and pg.locator('[data-bm-sav]').count() == 1, pg.url)
    kezdo = keret(pg)
    lepes('sáv: teljes keret', kezdo == 10, str(kezdo))

    # 2. Új ügyfél a popupban.
    pg.goto(ALAP + '?muhely=ugyfelek')
    pg.wait_for_load_state('networkidle')
    pg.click('a.sdh-gomb--elsodleges[data-sdh-urlap="ugyfelek"]')
    pg.wait_for_selector('form[data-sdh-ajax-action] input[name="nev"]', timeout=15000)
    pg.fill('form[data-sdh-ajax-action] input[name="nev"]', 'Böngésző Béla')
    with pg.expect_response(mentes) as v:
        pg.click('form[data-sdh-ajax-action] button[type="submit"]')
    pg.wait_for_load_state('networkidle')
    van = php("echo (int) $GLOBALS['wpdb']->get_var(\"SELECT COUNT(*) FROM \" . SDH_Muhely_Schema::tabla('ugyfel') . \" WHERE nev = 'Böngésző Béla'\");").strip()
    lepes('ügyfél mentve', v.value.status == 200 and van == '1', f'HTTP {v.value.status}, sor: {van}')
    time.sleep(1.5)
    utana = keret(pg)
    lepes('a keret csökkent', utana == kezdo - 1, f'{kezdo} → {utana}')

    # 3. Frissítés: a szerver 403-mal tiltja (a felület gombja nélkül is, közvetlen kéréssel).
    tiltas = pg.evaluate("""async () => {
        const fd = new FormData(); fd.set('action', 'sdh_muhely_frissites_ellenorzes');
        const r = await fetch(window.SDH_MUHELY.ajax, {method: 'POST', body: fd, credentials: 'same-origin'});
        return {kod: r.status, adat: await r.json()};
    }""")
    lepes('Frissítés tiltva (403)', tiltas['kod'] == 403 and 'nem érhető el' in tiltas['adat']['data']['uzenet'], str(tiltas['kod']) + ' ' + tiltas['adat']['data']['uzenet'][:60])
    modulok = pg.evaluate("""async () => {
        const fd = new FormData(); fd.set('action', 'sdh_muhely_mentes_most');
        const r = await fetch(window.SDH_MUHELY.ajax, {method: 'POST', body: fd, credentials: 'same-origin'});
        return r.status;
    }""")
    lepes('Mentés most tiltva (403)', modulok == 403, str(modulok))

    # 4. Tiltott lapfül: nem látszik, a címe visszairányít.
    pg.goto(ALAP + '?muhely=beallitasok&ful=licenc')
    pg.wait_for_load_state('networkidle')
    pillek = pg.eval_on_selector_all('a[href*="ful="]', 'els => els.map(e => e.textContent.trim())')
    lepes('licenc lapfül → Beállítások', 'ful=licenc' not in pg.url and 'Licenc' not in pillek and 'Mentés' not in pillek and 'Állapotok' in pillek, pg.url)

    # 5. A keret elfogy: a következő új ügyfél érthető hibát kap a popupban.
    php("for ($i = 0; $i < 20; $i++) { SDH_Demo::naplo('rekord', SDH_Demo::ip_hash('127.0.0.1'), 'ugyfel'); } echo SDH_Demo_Keret::maradek(SDH_Demo::ip_hash('127.0.0.1'));")
    pg.goto(ALAP + '?muhely=ugyfelek')
    pg.wait_for_load_state('networkidle')
    lepes('sáv: elfogyott (0)', keret(pg) == 0)
    pg.click('a.sdh-gomb--elsodleges[data-sdh-urlap="ugyfelek"]')
    pg.wait_for_selector('form[data-sdh-ajax-action] input[name="nev"]', timeout=15000)
    pg.fill('form[data-sdh-ajax-action] input[name="nev"]', 'Tizenegyedik Teréz')
    with pg.expect_response(mentes) as v:
        pg.click('form[data-sdh-ajax-action] button[type="submit"]')
    lepes('11. rekord: 429', v.value.status == 429, str(v.value.status))
    pg.wait_for_selector('text=Elérted a bemutató napi keretét', timeout=10000)
    lepes('érthető magyar hiba a popupban', pg.locator('text=Elérted a bemutató napi keretét').first.is_visible())
    pg.screenshot(path=KI + 'bemutato-keret-hiba.png')

    # 6. Kimenő üzenetek oldal.
    pg.goto(ALAP + '?muhely=kimeno')
    pg.wait_for_load_state('networkidle')
    lepes('Kimenő üzenetek oldal', 'Kimenő üzenetek' in pg.title())
    lepes('nincs JS-hiba', hibak == [], '; '.join(hibak)[:200])
    b.close()

print(f"bemutató e2e: {sum(eredmeny)}/{len(eredmeny)} {'OK' if all(eredmeny) else 'HIBA'}")
sys.exit(0 if all(eredmeny) else 1)
