<?php
/** t201 – tartalom-tár: elemek, napló + visszaállítás, szekciók, oldal-betöltés, frontmatter, Markdown. */
require __DIR__ . '/_keret.php';

$id = SDH_Sales_Tartalom::ment('gyik', 't201-a', ['kerdes' => 'Mi a {termeknev}?', 'valasz' => '<p>X</p>']);
T::igaz($id > 0, 'mentés');
T::egyezik('Mi a {termeknev}?', SDH_Sales_Tartalom::egy('gyik', 't201-a')['cim'], 'cím a kérdésből');
SDH_Sales_Tartalom::ment('gyik', 't201-a', ['kerdes' => 'Új kérdés', 'valasz' => '<p>Y</p>']);
$l = SDH_Sales_Naplo::lista(['ref_tipus' => 'tartalom', 'ref_id' => $id]);
T::egyezik('', SDH_Sales_Naplo::visszaallit((int) $l['sorok'][0]['id']), 'visszaállítás');
T::egyezik('Mi a {termeknev}?', SDH_Sales_Tartalom::egy('gyik', 't201-a')['adat']['kerdes'], 'visszaállt a régi');
SDH_Sales_Tartalom::ment('gyik', 't201-b', ['kerdes' => 'B'], null, 5, false);
T::egyezik(1, count(array_filter(SDH_Sales_Tartalom::lista('gyik'), fn ($e) => strpos($e['kulcs'], 't201') === 0)), 'csak aktív a listában');
T::egyezik(2, count(array_filter(SDH_Sales_Tartalom::lista('gyik', false), fn ($e) => strpos($e['kulcs'], 't201') === 0)), 'minden');
T::igaz(SDH_Sales_Tartalom::torol('gyik', 't201-b'), 'törlés');

T::igaz(SDH_Sales_Tartalom::oldal_betolt(['slug' => 't201-oldal', 'cim' => 'Próba', 'szekciok' => [['tipus' => 'hero', 'cim' => 'Szia']], 'seo' => ['title' => 'T', 'description' => 'D']]), 'oldal létrejött');
$p = get_page_by_path('t201-oldal');
T::egyezik('hero', SDH_Sales_Tartalom::szekciok((int) $p->ID)[0]['tipus'] ?? '', 'szekciók');
T::igaz(!SDH_Sales_Tartalom::oldal_betolt(['slug' => 't201-oldal', 'cim' => 'Más']), 'meglévőt nem írja felül');
SDH_Sales_Tartalom::szekciok_ment((int) $p->ID, [['tipus' => 'cta']]);
$l = SDH_Sales_Naplo::lista(['ref_tipus' => 'szekciok', 'ref_id' => (int) $p->ID]);
T::egyezik('', SDH_Sales_Naplo::visszaallit((int) $l['sorok'][0]['id']), 'szekció-visszaállítás');
T::egyezik('hero', SDH_Sales_Tartalom::szekciok((int) $p->ID)[0]['tipus'] ?? '', 'visszaállt a hero');

[$f, $md] = SDH_Sales_Tartalom::frontmatter("---\ncim: \"Cím\"\ncimkek: [a, b]\n---\n# Fej\n\nSzöveg **vastag** és [link](https://example.com).\n\n- egy\n- kettő\n\n| A | B |\n|---|---|\n| 1 | 2 |\n");
T::egyezik('Cím', $f['cim'] ?? '', 'frontmatter');
T::egyezik(['a', 'b'], $f['cimkek'] ?? [], 'frontmatter lista');
$h = SDH_Sales_Tartalom::md_html($md);
foreach (['<h2 id="fej">Fej</h2>', '<strong>vastag</strong>', 'rel="noopener"', '<li>kettő</li>', '<td>2</td>'] as $r) {
    T::tartalmaz($h, $r, 'md');
}
T::nem_tartalmaz(SDH_Sales_Tartalom::md_html('<script>x</script> [a](javascript:alert(1))'), '<script', 'md: nincs script');
T::nem_tartalmaz(SDH_Sales_Tartalom::md_html('[a](javascript:alert(1))'), 'javascript:', 'md: nincs javascript-link');
T::vege('t201 tartalom');
