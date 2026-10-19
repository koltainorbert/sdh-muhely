#!/usr/bin/env python3
"""
Rejtés-bejáró (REJTES.md): látogatóként végigjárja a webhelyet (sitemap + belső linkek), és minden oldalon
összegyűjti a HTML-t, a betöltött erőforrások URL-jeit, a válaszfejléceket, a sütiket és a konzol-üzeneteket, majd a
tiltott mintákra keres. Cél: 0 találat.

    python3 weboldal/tesztek/e2e/rejtes-bejaras.py http://127.0.0.1:8801/wp [max_oldal] [--mobil]

Kimenet: egy összegző sor („rejtés-bejárás: N oldal, M kérés, 0 találat OK”), hiba esetén alatta a találatok (legfeljebb 40).
Kilépési kód: 0, ha nincs találat.
"""
import re
import sys
from collections import deque
from urllib.parse import urljoin, urlparse, urldefrag

from playwright.sync_api import sync_playwright

TILTOTT = [
    r"wordpress", r"/wp-(content|includes|admin|json)/", r"wp-login\.php", r"xmlrpc\.php", r"admin-ajax\.php",
    r"rest_route=", r"\bsdh\b", r"sdh[-_]", r"[-_]sdh", r"api\.w\.org", r"<meta[^>]+name=[\"']generator",
    r"rank\s?math", r"wp\s?rocket", r"site\s?kit", r"class=[\"'][^\"']*\bwp-", r"id=[\"']wp-", r"\bw\.org",
]
TILTOTT_RE = [re.compile(m, re.I) for m in TILTOTT]
FEJLEC_TILT = re.compile(r"(wordpress|api\.w\.org|wp-json|sdh)", re.I)
FEJLEC_NEV_TILT = {"x-powered-by", "x-pingback", "x-redirect-by"}
SUTI_TILT = re.compile(r"(wordpress|^wp[-_]|sdh)", re.I)


def keres(szoveg, hol, talalatok):
    for r in TILTOTT_RE:
        m = r.search(szoveg)
        if m:
            k = max(0, m.start() - 50)
            reszlet = re.sub(r"\s+", " ", szoveg[k:m.end() + 50])
            talalatok.append(f"{hol}: /{r.pattern}/ … {reszlet}")


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(2)
    alap = sys.argv[1].rstrip("/")
    maximum = int(sys.argv[2]) if len(sys.argv) > 2 and sys.argv[2].isdigit() else 120
    mobil = "--mobil" in sys.argv
    host = urlparse(alap).netloc
    talalatok, keresek_db, latott = [], 0, set()
    sor = deque([alap + "/", alap + "/sitemap.xml", alap + "/robots.txt", alap + "/llms.txt",
                 alap + "/nincs-ilyen-oldal-404/", alap + "/?s=munkalap"])

    with sync_playwright() as p:
        b = p.chromium.launch()
        ctx = b.new_context(viewport={"width": 390, "height": 844} if mobil else {"width": 1366, "height": 900})
        oldal = ctx.new_page()
        konzol = []
        oldal.on("console", lambda m: konzol.append(m.text))

        def valasz_figyel(resp):
            nonlocal keresek_db
            keresek_db += 1
            url = resp.url
            keres(url, f"kérés {url}", talalatok)
            try:
                for nev, ertek in resp.headers.items():
                    if nev.lower() in FEJLEC_NEV_TILT or FEJLEC_TILT.search(f"{nev}: {ertek}"):
                        talalatok.append(f"fejléc ({url}): {nev}: {ertek[:120]}")
            except Exception:
                pass
            # A saját, szövegként kiszolgált erőforrások (css, js, json, txt, xml) tartalma is.
            ct = (resp.headers.get("content-type") or "").lower()
            if urlparse(url).netloc == host and any(t in ct for t in ("css", "javascript", "json", "text/plain", "xml")):
                try:
                    keres(resp.text(), f"tartalom ({url})", talalatok)
                except Exception:
                    pass

        oldal.on("response", valasz_figyel)

        while sor and len(latott) < maximum:
            url = urldefrag(sor.popleft())[0]
            if url in latott or urlparse(url).netloc != host:
                continue
            latott.add(url)
            konzol.clear()
            try:
                oldal.goto(url, wait_until="networkidle", timeout=30000)
            except Exception as e:
                talalatok.append(f"betöltés ({url}): {str(e)[:120]}")
                continue
            try:
                html = oldal.content()
            except Exception:
                html = ""
            keres(html, f"HTML {url}", talalatok)
            for k in konzol:
                keres(k, f"konzol {url}", talalatok)
            if url.endswith("sitemap.xml"):
                for loc in re.findall(r"<loc>([^<]+)</loc>", html):
                    sor.append(loc.strip())
                continue
            for href in oldal.eval_on_selector_all("a[href]", "els => els.map(e => e.href)"):
                h = urldefrag(urljoin(url, href))[0]
                if urlparse(h).netloc == host and h not in latott and not re.search(r"\.(zip|pdf|png|jpe?g|webp|avif|svg)$", h):
                    sor.append(h)

        for s in ctx.cookies():
            if SUTI_TILT.search(s.get("name", "")):
                talalatok.append(f"süti: {s.get('name')}")
        b.close()

    egyedi = list(dict.fromkeys(talalatok))
    allapot = "OK" if not egyedi else "HIBA"
    print(f"rejtés-bejárás{' (mobil)' if mobil else ''}: {len(latott)} oldal, {keresek_db} kérés, {len(egyedi)} találat {allapot}")
    for t in egyedi[:40]:
        print("   ✗ " + t[:300])
    sys.exit(0 if not egyedi else 1)


if __name__ == "__main__":
    main()
