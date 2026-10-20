# Bemutató-adat

- `general.php` – parancssori generátor: `php general.php --wp=/ut/wp-load.php [--db=10000] [--kepek=/ut] [--pillanatkep]`.
  A generátor kódja a Bemutató bővítményben van (`SDH_Demo_Generator`), így az admin gombja is ugyanazt futtatja.
- `kepek/` – kitalált készülék- és alkatrészképek (`manifest.json` + avif / webp). A generátor a webp-változatot használja
  a készülékfotókhoz és az átvételi fotókhoz. A bővítmény a `kepek/` mappát a saját könyvtárában, a `BEMUTATO_KEPEK`
  állandóban, vagy itt keresi.

Részletek: `weboldal/dokumentacio/demo.md`.
