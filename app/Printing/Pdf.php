<?php

namespace App\Printing;

/**
 * A minimal PDF writer, enough for printable business documents.
 *
 * Pages use the PDF "standard 14" Helvetica fonts, which every conforming
 * reader has built in, so no font program is embedded; only their advance
 * widths (PdfMetrics) are needed to measure text. Coordinates are in points
 * with the origin at the bottom left. Text is WinAnsi (CP1252), and
 * characters outside it render as '?'.
 */
final class Pdf
{
    public const REGULAR = '/F1';

    public const BOLD = '/F2';

    public const A4 = [595.28, 841.89];

    /** @var list<array{float, float, string}> width, height, content stream */
    private array $pages = [];

    public static function winAnsi(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    public static function width(string $font, float $size, string $s): float
    {
        $widths = $font === self::BOLD ? PdfMetrics::HELVETICA_BOLD : PdfMetrics::HELVETICA;
        $total = 0;
        foreach (str_split(self::winAnsi($s)) as $c) {
            $total += $widths[ord($c)];
        }

        return $total * $size / 1000;
    }

    /** A number without trailing zeros. */
    public static function num(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private static function string(string $s): string
    {
        $b = strtr(self::winAnsi($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\n" => '\\n', "\r" => '\\r']);

        return "($b)";
    }

    /** Start a new page and return its index. */
    public function addPage(float $w = self::A4[0], float $h = self::A4[1]): int
    {
        $this->pages[] = [$w, $h, ''];

        return count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function text(int $page, string $font, float $size, float $x, float $y, string $s, float $gray = 0): void
    {
        $this->pages[$page][2] .= sprintf('BT %s %s Tf %s g %s %s Td ', $font, self::num($size), self::num($gray),
            self::num($x), self::num($y)).self::string($s)." Tj ET\n";
    }

    public function line(int $page, float $x1, float $y1, float $x2, float $y2, float $w, float $gray): void
    {
        $this->pages[$page][2] .= sprintf("%s w %s G %s %s m %s %s l S\n", self::num($w), self::num($gray),
            self::num($x1), self::num($y1), self::num($x2), self::num($y2));
    }

    /** Serialize: 1 catalog, 2 page tree, 3/4 fonts, then a page and a compressed content stream per page. */
    public function bytes(): string
    {
        $buf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [];
        $obj = function (string $body) use (&$buf, &$offsets) {
            $offsets[] = strlen($buf);
            $buf .= count($offsets)." 0 obj\n$body\nendobj\n";
        };

        $kids = implode(' ', array_map(fn ($i) => (5 + 2 * $i).' 0 R', array_keys($this->pages)));
        $obj('<< /Type /Catalog /Pages 2 0 R >>');
        $obj("<< /Type /Pages /Kids [$kids] /Count ".count($this->pages).' >>');
        $obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        foreach ($this->pages as $i => [$w, $h, $content]) {
            $obj(sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::num($w), self::num($h), 6 + 2 * $i));
            $data = gzcompress($content);
            $obj('<< /Length '.strlen($data)." /Filter /FlateDecode >>\nstream\n$data\nendstream");
        }
        $xref = strlen($buf);
        $buf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($offsets) + 1);
        foreach ($offsets as $off) {
            $buf .= sprintf("%010d 00000 n \n", $off);
        }
        $buf .= sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($offsets) + 1, $xref);

        return $buf;
    }
}
