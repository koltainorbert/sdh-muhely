<?php
/**
 * A helyi nyomtatvány PDF-osztálya: a tFPDF kiegészítve a lábléccel.
 * Csak PDF-készítéskor töltődik be (SDH_Muhely_Szamla::nyomtatvany_pdf).
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TTFontFile')) {
    require_once __DIR__ . '/tfpdf/font/unifont/ttfonts.php';
}

if (!class_exists('tFPDF')) {
    require_once __DIR__ . '/tfpdf/tfpdf.php';
}

class SDH_Muhely_Pdf extends tFPDF
{
    /** @var array<int, string> A lábléc sorai a vonal fölött (levelezés, telefon). */
    public $sdh_lab = [];

    /** @var string A lábléc alsó sora (ki készítette, és hogy nem számla). */
    public $sdh_keszitette = '';

    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- a tFPDF hívja ezen a néven.
    public function Footer()
    {
        $this->SetTextColor(40, 40, 40);
        $this->SetFont('DejaVu', '', 7.5);
        $y = 268.5;

        foreach ($this->sdh_lab as $sor) {
            $this->SetXY(12.4, $y);
            $this->Cell(120, 3.4, $sor, 0, 0, 'L');
            $y += 3.4;
        }

        $this->SetDrawColor(40, 40, 40);
        $this->SetLineWidth(0.2);
        $this->Line(12.4, 278.2, 45.5, 278.2);

        $this->SetXY(12.4, 279);
        $this->Cell(150, 3.6, $this->sdh_keszitette, 0, 0, 'L');
        $this->SetXY(168, 279);
        $this->Cell(30, 3.6, 'Oldal ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}
