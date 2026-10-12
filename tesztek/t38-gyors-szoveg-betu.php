<?php
/**
 * t38 – 0.53.0: a Számla-előkészítő PDF betűhibája („Undefined font: dejavu B”)
 * és az RMA csevegő gyors szövegei (lista, mentés, gomb, beállítás).
 *
 * A hiba oka: a tFPDF betű-gyorsítótára (unifont/*.mtx.php) annak a betűkulcsát
 * tárolta, aki először regisztrálta a TTF-et (a nyomtatványok 'F' családja),
 * és a később más családnévvel ('DejaVu') regisztrált betű az idegen kulcsra került.
 */

require __DIR__ . '/_keret.php';

$R = 'SDH_Muhely_Rma';
$P = 'SDH_Muhely_Nyomtatvany';

/* --- Betű-gyorsítótár: ugyanaz a TTF két családnévvel, mindkét sorrendben ---- */

require_once SDH_MUHELY_DIR . 'includes/lib/sdh-pdf.php';

$betumappa = SDH_MUHELY_DIR . 'includes/lib/tfpdf/font/unifont/';
$gyorsitotar_torol = static function () use ($betumappa): void {
    foreach (array_merge(glob($betumappa . '*.mtx.php') ?: [], glob($betumappa . '*.cw.dat') ?: []) as $f) {
        @unlink($f);
    }
};

$pdf_csalad = static function (string $csalad): string {
    $pdf = new tFPDF();
    $pdf->AddFont($csalad, '', 'DejaVuSansCondensed.ttf', true);
    $pdf->AddFont($csalad, 'B', 'DejaVuSansCondensed-Bold.ttf', true);
    $pdf->AddPage();
    $pdf->SetFont($csalad, 'B', 9);
    $pdf->Cell(20, 5, 'Árvíztűrő tükörfúrógép');
    $pdf->SetFont($csalad, '', 9);
    $pdf->Cell(20, 5, 'őŐűŰ');

    return $pdf->Output('S');
};

foreach ([['F', 'DejaVu'], ['DejaVu', 'F']] as [$elso, $masodik]) {
    $gyorsitotar_torol();
    $pdf_csalad($elso);

    try {
        $ki = $pdf_csalad($masodik);
        T::igaz(strpos($ki, '%PDF') === 0, "betű: előbb „{$elso}”, utána „{$masodik}” – a második PDF is elkészül");
    } catch (\Throwable $hiba) {
        T::igaz(false, "betű: előbb „{$elso}”, utána „{$masodik}” – hiba: " . $hiba->getMessage());
    }
}

/* --- Számla-előkészítő PDF egy 0.52-es nyomtatvány UTÁN (a valódi sorrend) --- */

$gyorsitotar_torol();

$u  = t_ugyfel('Betű Bella');
$e  = t_eszkoz($u, 'Apple', 'iPhone 11');
$id = t_munkalap($u, [$e]);

$ny = $P::keszit('munkalap', ['id' => $id], 1);
T::igaz(is_array($ny) && strpos((string) $ny['pdf'], '%PDF') === 0, 'előbb a 0.52-es munkalap-nyomtatvány készül el');

$_POST = [
    'munkalap_id' => (string) $id,
    'vevo'        => ['nev' => 'Betű Bella'],
    'uj'          => [['megnevezes' => 'iPhone 11 töltőcsatlakozó flex kábel', 'brutto_ar' => '11900', 'mennyiseg' => '1', 'tipus' => 'termek']],
];

$kerelem = new ReflectionMethod('SDH_Muhely_Szamla', 'kerelem');
$kerelem->setAccessible(true);
$k = $kerelem->invoke(null, false);
T::igaz(is_array($k), 'számla-előkészítő: a kérés összeáll' . (is_string($k) ? ' – ' . $k : ''));

if (is_array($k)) {
    try {
        $szamla_pdf = SDH_Muhely_Szamla::nyomtatvany_pdf($k);
        T::igaz(strpos($szamla_pdf, '%PDF') === 0, 'számla-előkészítő PDF a nyomtatvány után is elkészül (nincs „Undefined font: dejavu B”)');
    } catch (\Throwable $hiba) {
        T::igaz(false, 'számla-előkészítő PDF: ' . $hiba->getMessage());
    }
}

$_POST = [];
$gyorsitotar_torol();

/* --- Gyors szövegek: gyári lista, tisztítás, mentés -------------------------- */

delete_option('sdh_muhely_rma_gyors_szovegek');

$lista = $R::gyors_szovegek();
T::egyezik('Kedves Ügyfelünk! Megrendelése megérkezett, mai naptól átvehető üzletünkben, nyitvatartási időben.', $lista[0] ?? '', 'a gyári lista első tétele a rendelés-megérkezett szöveg');
T::igaz(count($lista) >= 3, 'van gyári lista');

$r = t_ajax('sdh_muhely_rma_gyors_ment', ['lista' => wp_json_encode(['Második', '  Első  ', 'MÁSODIK', '', "Sor\ntörés"])]);
T::igaz(!empty($r['success']), 'a lista menthető a popupból');
T::egyezik(['Második', 'Első', 'Sor törés'], $R::gyors_szovegek(), 'mentéskor: levágás, üres ki, ismétlés (kis-/nagybetű) ki, egysoros');

t_ajax('sdh_muhely_rma_gyors_ment', ['lista' => wp_json_encode([])]);
T::egyezik([], $R::gyors_szovegek(), 'kiürítve üres marad (nem tér vissza a gyári lista)');

$r = t_ajax('sdh_muhely_rma_gyors_ment', ['lista' => wp_json_encode(array_map(static fn ($i) => 'Szöveg ' . $i, range(1, $R::GYORS_MAX + 1)))]);
T::igaz(empty($r['success']), 'a felső korlátnál több szöveg nem menthető');

$r = t_ajax('sdh_muhely_rma_gyors_ment', ['lista' => 'nem json']);
T::igaz(empty($r['success']), 'hibás adat nem írja felül a listát');

T::egyezik(500, mb_strlen($R::gyors_tisztit([str_repeat('á', 800)])[0]), 'egy szöveg legfeljebb 500 karakter');

/* --- Gomb a csevegőben és mező a Beállításokban ------------------------------ */

$R::gyors_szovegek_ment(['Kedves Ügyfelünk! „Idézőjeles” 100% szöveg']);

ob_start();
$R::munkalap_ful(t_lap($id));
$ful = (string) ob_get_clean();

T::tartalmaz($ful, 'data-sdh-gyors-szoveg', 'az RMA fülön ott a Gyors szövegek gomb');
T::tartalmaz($ful, esc_attr((string) wp_json_encode(['Kedves Ügyfelünk! „Idézőjeles” 100% szöveg'], JSON_UNESCAPED_UNICODE)), 'a gomb viszi a listát (idézőjel és % is épen)');

ob_start();
$R::beallitas_doboz();
$doboz = (string) ob_get_clean();
T::tartalmaz($doboz, 'name="rma_gyors_szovegek"', 'a Beállításokban szerkeszthető a lista');

$_POST = ['rma_gyors_szovegek' => "Egy\nKettő\n\nEgy"];
$R::beallitas_mentes();
T::egyezik(['Egy', 'Kettő'], $R::gyors_szovegek(), 'a Beállítások mentése soronként menti a listát');

$_POST = [];
$R::beallitas_mentes();
T::egyezik(['Egy', 'Kettő'], $R::gyors_szovegek(), 'mező nélküli mentés nem nyúl a listához');

delete_option('sdh_muhely_rma_gyors_szovegek');

T::vege('t38 Számla-előkészítő betűhiba, RMA gyors szövegek');
