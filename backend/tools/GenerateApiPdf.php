<?php

// Small dependency-free PDF writer for the API handoff document.
$source = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'API_ENDPOINTS_REFERENCE.md';
$output = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'API_ENDPOINTS_REFERENCE.pdf';
$lines = file($source, FILE_IGNORE_NEW_LINES);
$pages = [];
$page = [];
$inCode = false;
foreach ($lines as $line) {
    if (trim($line) === '```') { $inCode = !$inCode; continue; }
    $trimmed = trim($line);
    if ($trimmed === '') { $page[] = ['text' => '', 'size' => 4, 'font' => 'F1']; continue; }
    $isHeading = str_starts_with($trimmed, '#');
    $text = $isHeading ? trim(ltrim($trimmed, '#')) : $line;
    $font = $isHeading ? 'F2' : 'F1';
    $size = $isHeading ? (str_starts_with($trimmed, '##') ? 11 : 15) : ($inCode ? 7.5 : 9);
    $width = $inCode ? 105 : 112;
    foreach (explode("\n", wordwrap($text, $width, "\n", true)) as $wrapped) {
        $page[] = ['text' => $wrapped, 'size' => $size, 'font' => $font];
        if (count($page) >= 49) { $pages[] = $page; $page = []; }
    }
}
if ($page) $pages[] = $page;

$objects = [];
$add = function (string $body) use (&$objects): int { $objects[] = $body; return count($objects); };
$fontRegular = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
$fontBold = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>');
$pageIds = [];
foreach ($pages as $pageLines) {
    $stream = "q\n0.22 0.22 0.32 rg\n";
    $y = 805;
    foreach ($pageLines as $entry) {
        if ($entry['text'] === '') { $y -= 6; continue; }
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $entry['text']);
        $stream .= sprintf("BT /%s %.1f Tf 40 %.1f Td (%s) Tj ET\n", $entry['font'], $entry['size'], $y, $safe);
        $y -= $entry['size'] >= 14 ? 22 : ($entry['size'] >= 11 ? 17 : 12);
    }
    $stream .= "Q\n";
    $content = $add('<< /Length '.strlen($stream).' >>\nstream\n'.$stream.'endstream');
    $pageIds[] = $add('<< /Type /Page /Parent PAGES /MediaBox [0 0 595 842] /Resources << /Font << /F1 '.$fontRegular.' 0 R /F2 '.$fontBold.' 0 R >> >> /Contents '.$content.' 0 R >>');
}
$kids = implode(' ', array_map(fn (int $id): string => $id.' 0 R', $pageIds));
$pagesId = $add('<< /Type /Pages /Kids ['.$kids.'] /Count '.count($pageIds).' >>');
$catalogId = $add('<< /Type /Catalog /Pages '.$pagesId.' 0 R >>');
$objects = array_map(fn (string $body): string => str_replace('PAGES', $pagesId.' 0 R', $body), $objects);
$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $number => $body) {
    $id = $number + 1; $offsets[$id] = strlen($pdf);
    $pdf .= $id." 0 obj\n".$body."\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
for ($id = 1; $id <= count($objects); $id++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
$pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root ".$catalogId." 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
file_put_contents($output, $pdf);
echo $output.PHP_EOL;
