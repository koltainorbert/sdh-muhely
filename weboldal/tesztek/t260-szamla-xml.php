<?php
/**
 * t260 – Számlázz.hu XML: díjbekérő és végszámla (xmlszamla) – elemsorrend az XSD szerint (a hivatalos
 * xmlszamla.xsd sorrendjéből újraépített részséma, mert a tesztkörnyezet nem töltheti le), kötelező mezők,
 * díjbekérő → végszámla kapcsolás (dijbekeroSzamlaszam), fizetve, rendelésszám, vevő adószám, áfa a beállításból.
 */
require __DIR__ . '/_t26-seged.php';

t26_teszt_kulcs();

// Az xmlszamla.xsd sorrendje (docs.szamlazz.hu, 2026-10-19), a mi által használt típusokkal.
$xsd = <<<'XSD'
<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://www.szamlazz.hu/xmlszamla" xmlns="http://www.szamlazz.hu/xmlszamla" elementFormDefault="qualified">
 <xs:element name="xmlszamla"><xs:complexType><xs:sequence>
  <xs:element name="beallitasok"><xs:complexType><xs:sequence>
   <xs:element name="felhasznalo" type="xs:string" minOccurs="0"/><xs:element name="jelszo" type="xs:string" minOccurs="0"/>
   <xs:element name="szamlaagentkulcs" type="xs:string" minOccurs="0"/><xs:element name="eszamla" type="xs:boolean"/>
   <xs:element name="szamlaLetoltes" type="xs:boolean"/><xs:element name="szamlaLetoltesPld" type="xs:int" minOccurs="0"/>
   <xs:element name="valaszVerzio" type="xs:int" minOccurs="0"/><xs:element name="aggregator" type="xs:string" minOccurs="0"/>
   <xs:element name="guardian" type="xs:boolean" minOccurs="0"/><xs:element name="cikkazoninvoice" type="xs:boolean" minOccurs="0"/>
   <xs:element name="szamlaKulsoAzon" type="xs:string" minOccurs="0"/>
  </xs:sequence></xs:complexType></xs:element>
  <xs:element name="fejlec"><xs:complexType><xs:sequence>
   <xs:element name="keltDatum" type="xs:date" minOccurs="0"/><xs:element name="teljesitesDatum" type="xs:date"/>
   <xs:element name="fizetesiHataridoDatum" type="xs:date"/><xs:element name="fizmod" type="xs:string"/>
   <xs:element name="penznem" type="xs:string"/><xs:element name="szamlaNyelve"><xs:simpleType><xs:restriction base="xs:string">
    <xs:enumeration value="hu"/><xs:enumeration value="en"/><xs:enumeration value="de"/></xs:restriction></xs:simpleType></xs:element>
   <xs:element name="megjegyzes" type="xs:string" minOccurs="0"/><xs:element name="arfolyamBank" type="xs:string" minOccurs="0"/>
   <xs:element name="arfolyam" type="xs:double" minOccurs="0"/><xs:element name="rendelesSzam" type="xs:string" minOccurs="0"/>
   <xs:element name="dijbekeroSzamlaszam" type="xs:string" minOccurs="0"/><xs:element name="elolegszamla" type="xs:boolean" minOccurs="0"/>
   <xs:element name="vegszamla" type="xs:boolean" minOccurs="0"/><xs:element name="elolegSzamlaszam" type="xs:string" minOccurs="0"/>
   <xs:element name="helyesbitoszamla" type="xs:boolean" minOccurs="0"/><xs:element name="helyesbitettSzamlaszam" type="xs:string" minOccurs="0"/>
   <xs:element name="dijbekero" type="xs:boolean" minOccurs="0"/><xs:element name="szallitolevel" type="xs:boolean" minOccurs="0"/>
   <xs:element name="logoExtra" type="xs:string" minOccurs="0"/><xs:element name="szamlaszamElotag" type="xs:string" minOccurs="0"/>
   <xs:element name="fizetendoKorrekcio" type="xs:double" minOccurs="0"/><xs:element name="fizetve" type="xs:boolean" minOccurs="0"/>
  </xs:sequence></xs:complexType></xs:element>
  <xs:element name="elado"><xs:complexType><xs:sequence>
   <xs:element name="bank" type="xs:string" minOccurs="0"/><xs:element name="bankszamlaszam" type="xs:string" minOccurs="0"/>
   <xs:element name="emailReplyto" type="xs:string" minOccurs="0"/><xs:element name="emailTargy" type="xs:string" minOccurs="0"/>
   <xs:element name="emailSzoveg" type="xs:string" minOccurs="0"/><xs:element name="alairoNeve" type="xs:string" minOccurs="0"/>
  </xs:sequence></xs:complexType></xs:element>
  <xs:element name="vevo"><xs:complexType><xs:sequence>
   <xs:element name="nev" type="xs:string"/><xs:element name="orszag" type="xs:string" minOccurs="0"/>
   <xs:element name="irsz" type="xs:string"/><xs:element name="telepules" type="xs:string"/><xs:element name="cim" type="xs:string"/>
   <xs:element name="email" type="xs:string" minOccurs="0"/><xs:element name="sendEmail" type="xs:boolean" minOccurs="0"/>
   <xs:element name="adoalany" type="xs:int" minOccurs="0"/><xs:element name="adoszam" type="xs:string" minOccurs="0"/>
   <xs:element name="adoszamEU" type="xs:string" minOccurs="0"/><xs:element name="postazasiNev" type="xs:string" minOccurs="0"/>
   <xs:element name="postazasiOrszag" type="xs:string" minOccurs="0"/><xs:element name="postazasiIrsz" type="xs:string" minOccurs="0"/>
   <xs:element name="postazasiTelepules" type="xs:string" minOccurs="0"/><xs:element name="postazasiCim" type="xs:string" minOccurs="0"/>
   <xs:element name="azonosito" type="xs:string" minOccurs="0"/><xs:element name="alairoNeve" type="xs:string" minOccurs="0"/>
   <xs:element name="telefonszam" type="xs:string" minOccurs="0"/><xs:element name="megjegyzes" type="xs:string" minOccurs="0"/>
  </xs:sequence></xs:complexType></xs:element>
  <xs:element name="tetelek"><xs:complexType><xs:sequence>
   <xs:element name="tetel" maxOccurs="unbounded"><xs:complexType><xs:sequence>
    <xs:element name="megnevezes" type="xs:string"/><xs:element name="azonosito" type="xs:string" minOccurs="0"/>
    <xs:element name="mennyiseg" type="xs:double"/><xs:element name="mennyisegiEgyseg" type="xs:string"/>
    <xs:element name="nettoEgysegar" type="xs:double"/><xs:element name="afakulcs" type="xs:string"/>
    <xs:element name="arresAfaAlap" type="xs:double" minOccurs="0"/><xs:element name="nettoErtek" type="xs:double"/>
    <xs:element name="afaErtek" type="xs:double"/><xs:element name="bruttoErtek" type="xs:double"/>
    <xs:element name="megjegyzes" type="xs:string" minOccurs="0"/>
   </xs:sequence></xs:complexType></xs:element>
  </xs:sequence></xs:complexType></xs:element>
 </xs:sequence></xs:complexType></xs:element>
</xs:schema>
XSD;

$valid = static function (string $xml) use ($xsd): bool {
    $d = new DOMDocument();
    $d->loadXML($xml);
    libxml_use_internal_errors(true);
    $ok = $d->schemaValidateSource($xsd);
    foreach (libxml_get_errors() as $e) {
        fwrite(STDERR, 'XSD: ' . trim($e->message) . "\n");
    }
    libxml_clear_errors();

    return $ok;
};

// --- díjbekérő ---
$id = t26_rendeles();
$r  = SDH_Sales_Szamlazz::rendeles($id);
$x  = SDH_Sales_Szamlazz::xml('dijbekero', $r);
$d  = t26_dom($x);

T::igaz($d !== null, 'díjbekérő: jól formált XML');
T::igaz($valid($x), 'díjbekérő: XSD-érvényes');
T::egyezik('http://www.szamlazz.hu/xmlszamla', $d->documentElement->namespaceURI, 'névtér');
T::egyezik(['beallitasok', 'fejlec', 'elado', 'vevo', 'tetelek'], t26_gyerekek($d, 'xmlszamla'), 'gyökér sorrend');
T::egyezik('true', $d->getElementsByTagName('dijbekero')->item(0)->textContent ?? '', 'dijbekero=true');
T::egyezik(0, $d->getElementsByTagName('dijbekeroSzamlaszam')->length, 'díjbekérőben nincs dijbekeroSzamlaszam');
T::egyezik('false', $d->getElementsByTagName('fizetve')->item(0)->textContent ?? '', 'díjbekérő: fizetve=false');
T::egyezik((string) $r['azon'], $d->getElementsByTagName('rendelesSzam')->item(0)->textContent ?? '', 'rendelesSzam');
T::egyezik('tesztkulcs123abc', $d->getElementsByTagName('szamlaagentkulcs')->item(0)->textContent ?? '', 'tesztkulcs megy');
T::egyezik('2', $d->getElementsByTagName('valaszVerzio')->item(0)->textContent ?? '', 'valaszVerzio=2');
T::tartalmaz($d->getElementsByTagName('megjegyzes')->item(0)->textContent ?? '', 'MINTA', 'tesztüzem: MINTA a megjegyzésben');
T::tartalmaz($d->getElementsByTagName('megjegyzes')->item(0)->textContent ?? '', (string) $r['kozlemeny'], 'díjbekérő: közlemény');
T::egyezik((string) $r['hatarido'], $d->getElementsByTagName('fizetesiHataridoDatum')->item(0)->textContent ?? '', 'határidő a rendelésből');

// vevő
T::egyezik('Próba Szerviz Kft.', $d->getElementsByTagName('nev')->item(0)->textContent ?? '', 'vevő név (cég)');
T::egyezik('8200', $d->getElementsByTagName('irsz')->item(0)->textContent ?? '', 'irsz a címből');
T::egyezik('Veszprém', $d->getElementsByTagName('telepules')->item(0)->textContent ?? '', 'település a címből');
T::egyezik('Fő utca 1.', $d->getElementsByTagName('cim')->item(0)->textContent ?? '', 'utca a címből');
T::egyezik('12345678-2-19', $d->getElementsByTagName('adoszam')->item(0)->textContent ?? '', 'adószám');
T::egyezik('1', $d->getElementsByTagName('adoalany')->item(0)->textContent ?? '', 'adoalany=1');

// tételek: áfa a beállításból, egységár × menny = nettó, nettó + áfa = bruttó
$tet = $d->getElementsByTagName('tetel');
T::egyezik(2, $tet->length, '2 tétel');
$jo = true;
foreach ($tet as $t) {
    $g = static fn (string $n): float => (float) $t->getElementsByTagName($n)->item(0)->textContent;
    $jo = $jo && abs($g('nettoEgysegar') * $g('mennyiseg') - $g('nettoErtek')) < 0.001
        && abs($g('nettoErtek') * 27 / 100 - $g('afaErtek')) < 0.001
        && abs($g('nettoErtek') + $g('afaErtek') - $g('bruttoErtek')) < 0.001
        && $t->getElementsByTagName('afakulcs')->item(0)->textContent === '27';
}
T::igaz($jo, 'tételek: Agent-szabályok (259–264) teljesülnek, kulcs 27');
SDH_Sales::b_ment(['afa' => 5]);
$d5 = t26_dom(SDH_Sales_Szamlazz::xml('dijbekero', $r));
T::egyezik('5', $d5->getElementsByTagName('afakulcs')->item(0)->textContent ?? '', 'áfakulcs a beállításból');
SDH_Sales::b_ment(['afa' => 27]);

// --- végszámla ---
global $wpdb;
$wpdb->update(SDH_Sales_Schema::t('rendeles'), ['szamla_dijbekero' => 'D-TESZT-2026-7'], ['id' => $id]);
t26_fizet($id, '2026-10-18 23:30:00'); // UTC → budapesti idő szerint már 10-19
$r  = SDH_Sales_Szamlazz::rendeles($id);
$x  = SDH_Sales_Szamlazz::xml('vegszamla', $r);
$d  = t26_dom($x);
T::igaz($valid($x), 'végszámla: XSD-érvényes');
T::egyezik('D-TESZT-2026-7', $d->getElementsByTagName('dijbekeroSzamlaszam')->item(0)->textContent ?? '', 'végszámla: dijbekeroSzamlaszam');
T::egyezik(0, $d->getElementsByTagName('dijbekero')->length, 'végszámla: nincs dijbekero elem');
T::egyezik('true', $d->getElementsByTagName('fizetve')->item(0)->textContent ?? '', 'végszámla: fizetve=true');
T::egyezik('2026-10-19', $d->getElementsByTagName('teljesitesDatum')->item(0)->textContent ?? '', 'teljesítés = fizetés napja (Budapest)');
T::egyezik('Átutalás', $d->getElementsByTagName('fizmod')->item(0)->textContent ?? '', 'fizetési mód');
T::tartalmaz($d->getElementsByTagName('megjegyzes')->item(0)->textContent ?? '', 'Fizetve: 2026-10-19', 'fizetés napja a megjegyzésben');
T::igaz(t26_sorrendben(['rendelesSzam', 'dijbekeroSzamlaszam', 'szamlaszamElotag', 'fizetve'], array_merge(t26_gyerekek($d, 'fejlec'), [])) || !in_array('szamlaszamElotag', t26_gyerekek($d, 'fejlec'), true), 'fejléc sorrend');
T::igaz(substr((string) $d->getElementsByTagName('szamlaKulsoAzon')->item(0)->textContent, -2) === '-v', 'külső azonosító (végszámla)');

// kártyás: SimplePay → Bankkártya; magánszemély: adoalany -1, adószám nélkül
$id2 = t26_rendeles(['fizmod' => 'simplepay', 'vevo' => wp_json_encode(['nev' => 'Nagy Béla', 'adoszam' => '8412345678', 'cim' => ['irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Kő u. 2.'], 'email' => 'bela@pelda.hu'])]);
t26_fizet($id2);
$d2 = t26_dom(SDH_Sales_Szamlazz::xml('vegszamla', SDH_Sales_Szamlazz::rendeles($id2)));
T::egyezik('Bankkártya', $d2->getElementsByTagName('fizmod')->item(0)->textContent ?? '', 'simplepay → Bankkártya');
T::egyezik('-1', $d2->getElementsByTagName('adoalany')->item(0)->textContent ?? '', 'magánszemély: adoalany=-1');
T::egyezik(0, $d2->getElementsByTagName('adoszam')->length, 'adóazonosító jel nem megy a számlára');
T::egyezik('1111', $d2->getElementsByTagName('irsz')->item(0)->textContent ?? '', 'cím tömbből');

// XML-injekció ellen
$id3 = t26_rendeles(['tetelek' => wp_json_encode([['nev' => 'A & B <c> "d"' . "\x01", 'mennyiseg' => 3, 'netto' => 1000]])]);
$x3  = SDH_Sales_Szamlazz::xml('dijbekero', SDH_Sales_Szamlazz::rendeles($id3));
T::igaz($valid($x3), 'különleges karakterek: XSD-érvényes');
$d3  = t26_dom($x3);
T::egyezik('A & B <c> "d" (3 db)', $d3->getElementsByTagName('megnevezes')->item(0)->textContent ?? '', 'escapelés + nem osztható nettó egy sorba');
T::egyezik('1000', $d3->getElementsByTagName('nettoErtek')->item(0)->textContent ?? '', 'nem osztható nettó megmarad');

// Rejtés: a Számlázz.hu-nak küldött XML (a vevő számláján is megjelenő mezőkkel) nem árulkodik.
t_rejtett(SDH_Sales_Szamlazz::xml('dijbekero', SDH_Sales_Szamlazz::rendeles($id)) . SDH_Sales_Szamlazz::xml('vegszamla', SDH_Sales_Szamlazz::rendeles($id)), 'számla-XML (díjbekérő + végszámla)');

// Független ellenőrzés (2026-10-20), K-3: a számla végösszege fillérre a fizetendő (rendelés) bruttó – az áfa soronként
// egész forint, legnagyobb maradékkal elosztva (P8: 9 900 + 1 333 nettó → 14 266, nem 14 265,91).
$k3 = t26_rendeles(['tetelek' => wp_json_encode([['nev' => 'Start csomag', 'mennyiseg' => 1, 'netto' => 9900], ['nev' => 'Címke modul', 'mennyiseg' => 1, 'netto' => 1333]]), 'netto' => 11233, 'afa' => 3033, 'brutto' => 14266]);
$s3 = SDH_Sales_Szamlazz::sorok(SDH_Sales_Szamlazz::rendeles($k3));
T::egyezik(14266.0, (float) array_sum(array_column($s3, 'brutto')), 'K-3: a számlasorok bruttó összege = a fizetendő 14 266 Ft');
T::egyezik([2673.0, 360.0], array_map('floatval', array_column($s3, 'afa')), 'K-3: áfa soronként egész forint (2 673 + 360)');
preg_match_all('#<bruttoErtek>([^<]+)</bruttoErtek>#', SDH_Sales_Szamlazz::xml('dijbekero', SDH_Sales_Szamlazz::rendeles($k3)), $bm);
T::egyezik(14266.0, (float) array_sum(array_map('floatval', $bm[1])), 'K-3: a díjbekérő XML bruttó értékeinek összege 14 266');
$hiba3 = 0;
mt_srand(20261020);
for ($i = 0; $i < 300; $i++) {
    $db = mt_rand(1, 6);
    $sor = [];
    $n3  = 0;
    for ($j = 0; $j < $db; $j++) {
        $x     = mt_rand(1, 250000);
        $sor[] = ['nev' => 'Tétel ' . $j, 'mennyiseg' => 1, 'netto' => $x];
        $n3   += $x;
    }
    $a3 = SDH_Sales_Ar::kerek($n3 * 27, 100);
    $ss = SDH_Sales_Szamlazz::sorok(['tetelek' => $sor, 'netto' => $n3, 'afa' => $a3, 'azon' => 'R-T260']);
    $ok = abs((float) array_sum(array_column($ss, 'brutto')) - ($n3 + $a3)) < 0.001;
    foreach ($ss as $q) {
        $ok = $ok && abs((float) $q['afa'] - (float) $q['netto'] * 0.27) < 1.0 && abs((float) $q['afa'] - round((float) $q['afa'])) < 0.001;
    }
    $hiba3 += $ok ? 0 : 1;
}
T::egyezik(0, $hiba3, 'K-3: 300 véletlen kosár: Σ számlasor-bruttó = rendelés-bruttó, soronként < 1 Ft eltérés, egész forint');

T::vege('t260 számla-XML');
