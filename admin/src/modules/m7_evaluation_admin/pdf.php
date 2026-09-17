<?php
/** Minimal dependency-free PDF writer for certificate downloads. */
function pdf_escape(string $text): string
{
    $text = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text) ?: $text;
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
}

function output_certificate_pdf(array $data): void
{
    $name = pdf_escape((string)$data['candidate']);
    $cert = pdf_escape((string)$data['certificate_number']);
    $assessment = pdf_escape((string)$data['assessment']);
    $level = pdf_escape('Level ' . $data['level']);
    $percentage = pdf_escape((string)$data['percentage'] . '%');
    $date = pdf_escape((string)$data['issue_date']);

    $stream = "BT\n/F1 24 Tf\n160 700 Td\n(INTERNBOOT) Tj\n/F1 18 Tf\n0 -55 Td\n(Certificate of Achievement) Tj\n/F1 14 Tf\n0 -65 Td\n(This certifies that) Tj\n/F1 25 Tf\n0 -55 Td\n($name) Tj\n/F1 14 Tf\n0 -55 Td\n(has successfully completed the assessment) Tj\n/F1 16 Tf\n0 -35 Td\n($assessment) Tj\n/F1 14 Tf\n0 -45 Td\n(Level: $level    Score: $percentage) Tj\n/F1 11 Tf\n0 -55 Td\n(Certificate No: $cert) Tj\n0 -22 Td\n(Issue Date: $date) Tj\nET\n";

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
    $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $objectNumber = $i + 1;
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= $objectNumber . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', $data['certificate_number']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
