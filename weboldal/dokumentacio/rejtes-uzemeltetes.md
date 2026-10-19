# Rejtés – szerver- és telepítési beállítások (élesítés előtt kötelező)

A webhely kódja a kimenetből eltünteti a rendszer nyomait (`includes/class-sdh-sales-rejtes.php`). A szerver szintjén
ezeket kell beállítani, különben a webszerver maga árulja el, mi fut alatta. Ellenőrzés: Admin › Beállítások › Rejtés ›
**Ellenőrzés most**, és fejlesztői gépről `python3 weboldal/tesztek/e2e/rejtes-bejaras.py https://<webhely> 200` – a cél 0 találat.

## 1. Telepítés semleges nevekkel
- A bővítményt és a témát a csomagoló zipjeiből telepítsd (`sh weboldal/eszkozok/csomagol.sh` → `portal-<v>.zip`,
  `kirakat-<v>.zip`, a demóhoz `bemutato-<v>.zip`). A mappanevek így semlegesek (`/static/m/portal/…`, `/static/t/kirakat/…`).
- A webhely neve (Beállítások › Általános) a termék neve legyen; a „Hello world!” és a mintaoldal az aktiváláskor törlődik.
- Az első admin-felhasználó neve ne legyen `admin`, és ne egyezzen a nyilvános névvel.

## 2. wp-config.php (a `require_once ABSPATH . 'wp-settings.php';` sor ELÉ)
```php
// Semleges sütinevek (a rendszer alap sütinevei árulkodnának).
define('COOKIEHASH', md5('portal-' . 'IDE-EGY-VELETLEN-SZOVEG'));
define('USER_COOKIE',        'p_u_'  . COOKIEHASH);
define('PASS_COOKIE',        'p_p_'  . COOKIEHASH);
define('AUTH_COOKIE',        'p_a_'  . COOKIEHASH);
define('SECURE_AUTH_COOKIE', 'p_sa_' . COOKIEHASH);
define('LOGGED_IN_COOKIE',   'p_li_' . COOKIEHASH);
define('TEST_COOKIE',        'p_t');
define('RECOVERY_MODE_COOKIE', 'p_r_' . COOKIEHASH);

define('DISABLE_WP_CRON', true);     // valódi cron (lent), így a wp-cron.php kívülről 404
define('WP_DEBUG', false);           // élesben soha ne írjon ki hibát
define('WP_DEBUG_DISPLAY', false);
define('DISALLOW_FILE_EDIT', true);
define('WP_POST_REVISIONS', 10);
```
Valódi cron (a szerver crontabjában, 5 percenként): `*/5 * * * * php /var/www/webhely/wp-cron.php >/dev/null 2>&1`
(parancssorból fut, a webes 404 nem érinti).

Fatális hiba esetére a semleges hibaoldal: másold a `weboldal/eszkozok/php-error.php`-t a tartalommappába
(`wp-content/php-error.php`) – ha nincs ilyen fájl, a rendszer saját, árulkodó hibaoldala jelenne meg.

## 3. Apache (.htaccess)
A bővítmény a saját blokkját a permalink-szabályok elé írja (Beállítások › Közvetlen hivatkozások › Mentés). Ellenőrizd,
hogy a `# Rejtés: útvonal-álnevek` blokk ott van. Kiegészítés a szerver-konfigurációba:
```apache
ServerTokens Prod
ServerSignature Off
Header always unset X-Powered-By
<FilesMatch "^(readme\.html|license\.txt|wp-config-sample\.php|xmlrpc\.php|wp-links-opml\.php|wp-trackback\.php)$">
  Require all denied
</FilesMatch>
```
php.ini: `expose_php = Off`.

## 4. Nginx
```nginx
server_tokens off;
fastcgi_hide_header X-Powered-By;
more_clear_headers 'X-Pingback' 'X-Redirect-By';   # headers-more modul esetén

# Útvonal-álnevek (a kódban: /static/m/ = bővítmények, /static/t/ = témák, /media/ = feltöltések, /static/ = tartalom, /lib/ = rendszerfájlok)
location /static/m/ { alias /var/www/webhely/wp-content/plugins/; }
location /static/t/ { alias /var/www/webhely/wp-content/themes/; }
location /media/    { alias /var/www/webhely/wp-content/uploads/; }
location /static/   { alias /var/www/webhely/wp-content/; }
location /lib/      { alias /var/www/webhely/wp-includes/; }
# Sima prefix-location: így a lenti regex-szabályok (PHP, belső mappák) elsőbbséget kapnak.
# A PHP-fájlok az álneveken át soha nem futnak.
location ~ ^/(static|media|lib)/.*\.php$ { return 404; }

# A valódi útvonalak közvetlen elérése 404 (az álneveken át működnek).
location ~ ^/wp-(content|includes)/ { return 404; }
location ~ ^/(readme\.html|license\.txt|wp-config-sample\.php|xmlrpc\.php|wp-links-opml\.php|wp-trackback\.php|wp-cron\.php)$ { return 404; }
# A bővítmény belső mappái (includes, data) soha nem szolgálhatók ki.
location ~ ^/static/m/[^/]+/(includes|data)/ { return 404; }
location ~ /portal-(szamla|mentes)/ { return 404; }
```
A permalink-szabály a szokásos: `location / { try_files $uri $uri/ /index.php?$args; }`.

## 5. Cloudflare
- Gyorsítótár-kizárás: `/kosar/*`, `/penztar/*`, `/fiok/*`, `/api/*`, és a titkos belépő címe (Cache Rule: Bypass).
- „Server” fejléc: a Cloudflare saját értéket ad (nem árulkodó).
- A ChatGPT-User / Perplexity-User tiltása, ha kell, csak Cloudflare-szabállyal lehetséges (a robots.txt-t nem mindig tartják be).

## 6. Rank Math / WP Rocket / Site Kit (ha telepítve vannak)
- A bővítmény eltávolítja a lábnyomaikat (HTML-megjegyzés, generator-meta), de a beállításaikban is kapcsold ki:
  Rank Math › Általános › „Rank Math credit” ki; WP Rocket: `define('WP_ROCKET_WHITE_LABEL_FOOTPRINT', true);` a wp-configban.
- WP Rocket gyorsítótár-kizárás: a fenti URL-ek (a bővítmény a `rocket_cache_reject_uri` szűrőn át is beállítja).

## 7. Mit NEM rejtünk (és miért)
- A forráskód a szerveren látható annak, aki fájlokhoz fér – ez nem titok, a kód GPL alatt áll (`REJTES.md`, utolsó rész).
- A titkos belépő oldal kinézete a rendszer saját belépője (logó nélkül) – csak az látja, aki tudja a címét.
