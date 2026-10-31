<?php

namespace App\Support\SchoolClub;

use Illuminate\Support\Carbon;

/**
 * The printable School Club student list the principal signs: the club, the
 * school and the category, every student (First name, Surname, Email, DOB),
 * and spaces for the principal's signature, the school seal and the date.
 *
 * A minimal, self-contained PDF 1.4 writer (no PDF package): A4 pages, the
 * standard Helvetica fonts with WinAnsi (Windows-1252) encoding. Student
 * names and emails are validated as Windows-1252 text before they get here,
 * so the printed list matches what was uploaded exactly.
 */
class StudentListPdf
{
    private const PAGE_WIDTH = 595.28;

    private const PAGE_HEIGHT = 841.89;

    private const MARGIN = 40.0;

    private const BOTTOM = 56.0;

    private const FONT_SIZE = 9.0;

    private const LINE_HEIGHT = 11.5;

    /**
     * Table columns: [heading, width in points].
     *
     * @var list<array{0: string, 1: float}>
     */
    private const COLUMNS = [['No.', 30.0], ['First name', 105.0], ['Surname', 105.0], ['Email', 195.0], ['DOB', 80.28]];

    /**
     * @var list<string>
     */
    private array $pages = [];

    private string $content = '';

    private float $y = 0.0;

    /**
     * True when every character of `$text` can be printed by this writer.
     */
    public static function canPrint(string $text): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
            return false;
        }

        $encoded = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return mb_convert_encoding($encoded, 'UTF-8', 'Windows-1252') === $text;
    }

    /**
     * @param  array{club: ?string, school: ?string, category: string, number: string, reference: string}  $details
     * @param  list<array{first_name: string, surname: string, email: string, date_of_birth: string}>  $students
     */
    public function render(array $details, array $students, ?Carbon $generatedAt = null): string
    {
        $this->pages = [];
        $this->newPage();

        $this->text(self::MARGIN, $this->y, 'Aviation Club — School Club Student Membership List', 15, true);
        $this->y -= 26;

        foreach ([
            'School Club' => $details['club'] ?? '—',
            'School' => $details['school'] ?? '—',
            'Membership Category' => $details['category'],
            'School Club membership number' => $details['number'],
            'List reference' => $details['reference'],
            'Generated' => ($generatedAt ?? Carbon::now(config('membership.business_timezone')))->format('j F Y'),
        ] as $label => $value) {
            $this->text(self::MARGIN, $this->y, $label.':', 10, true);
            $this->text(self::MARGIN + 170, $this->y, $this->fit($value, self::PAGE_WIDTH - 2 * self::MARGIN - 170, 10), 10);
            $this->y -= 15;
        }

        $this->y -= 4;
        $this->text(self::MARGIN, $this->y, 'Number of students: '.count($students), 10, true);
        $this->y -= 18;

        $this->tableHeader();
        foreach (array_values($students) as $index => $student) {
            $cells = [(string) ($index + 1), $student['first_name'], $student['surname'], $student['email'], $student['date_of_birth']];
            $lines = [];
            foreach (self::COLUMNS as $column => [, $width]) {
                $lines[$column] = $this->wrap($cells[$column], $width - 8);
            }
            $height = max(array_map('count', $lines)) * self::LINE_HEIGHT + 6;

            if ($this->y - $height < self::BOTTOM) {
                $this->newPage();
                $this->tableHeader();
            }

            $x = self::MARGIN;
            foreach (self::COLUMNS as $column => [, $width]) {
                foreach ($lines[$column] as $line => $text) {
                    $this->text($x + 4, $this->y - 11 - $line * self::LINE_HEIGHT, $text, self::FONT_SIZE);
                }
                $x += $width;
            }
            $this->rowBorders($height);
            $this->y -= $height;
        }

        $this->signatureBlock();

        return $this->document();
    }

    private function tableHeader(): void
    {
        $height = 18.0;
        $this->content .= sprintf("0.93 0.94 0.96 rg %.2F %.2F %.2F %.2F re f 0 g\n", self::MARGIN, $this->y - $height, self::PAGE_WIDTH - 2 * self::MARGIN, $height);
        $x = self::MARGIN;
        foreach (self::COLUMNS as [$heading, $width]) {
            $this->text($x + 4, $this->y - 12.5, $heading, self::FONT_SIZE, true);
            $x += $width;
        }
        $this->rowBorders($height);
        $this->y -= $height;
    }

    private function rowBorders(float $height): void
    {
        $x = self::MARGIN;
        foreach (self::COLUMNS as [, $width]) {
            $this->content .= sprintf("0.6 G 0.5 w %.2F %.2F %.2F %.2F re S\n", $x, $this->y - $height, $width, $height);
            $x += $width;
        }
    }

    private function signatureBlock(): void
    {
        $needed = 190.0;
        if ($this->y - $needed < self::BOTTOM) {
            $this->newPage();
        }

        $this->y -= 24;
        $this->text(self::MARGIN, $this->y, 'Certification by the Principal', 11, true);
        $this->y -= 16;
        $this->text(self::MARGIN, $this->y, 'I confirm that the students listed above are students of this school and members of its School Club.', self::FONT_SIZE);
        $this->y -= 40;

        $left = self::MARGIN;
        $lineWidth = 230.0;
        foreach (["Principal's signature", "Principal's name", 'Date'] as $label) {
            $this->content .= sprintf("0 G 0.75 w %.2F %.2F m %.2F %.2F l S\n", $left, $this->y, $left + $lineWidth, $this->y);
            $this->text($left, $this->y - 12, $label, self::FONT_SIZE);
            $this->y -= 42;
        }

        // School seal box, to the right of the signature lines.
        $sealSize = 120.0;
        $sealX = self::PAGE_WIDTH - self::MARGIN - $sealSize - 20;
        $sealTop = $this->y + 3 * 42 + 4;
        $this->content .= sprintf("[3 3] 0 d 0 G 0.75 w %.2F %.2F %.2F %.2F re S [] 0 d\n", $sealX, $sealTop - $sealSize, $sealSize, $sealSize);
        $this->text($sealX + ($sealSize - $this->width('School seal', self::FONT_SIZE)) / 2, $sealTop - $sealSize - 12, 'School seal', self::FONT_SIZE);
    }

    private function newPage(): void
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
        }
        $this->content = '';
        $this->y = self::PAGE_HEIGHT - self::MARGIN - 10;
    }

    private function text(float $x, float $y, string $text, float $size, bool $bold = false): void
    {
        $this->content .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, $this->escape($text));
    }

    /**
     * Splits `$text` into lines no wider than `$width` (breaking long words, such as emails, anywhere).
     *
     * @return list<string>
     */
    private function wrap(string $text, float $width): array
    {
        $lines = [];
        $line = '';
        foreach (mb_str_split($text) as $character) {
            if ($line !== '' && $this->width($line.$character, self::FONT_SIZE) > $width) {
                $lines[] = $line;
                $line = '';
            }
            $line .= $character;
        }

        return [...$lines, $line];
    }

    private function fit(string $text, float $width, float $size): string
    {
        if ($this->width($text, $size) <= $width) {
            return $text;
        }
        while ($text !== '' && $this->width($text.'…', $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return $text.'…';
    }

    /**
     * An upper-bound estimate of Helvetica text width (wide glyphs rounded up), in points.
     */
    private function width(string $text, float $size): float
    {
        $units = 0;
        foreach (mb_str_split($text) as $character) {
            $units += match (true) {
                str_contains('ijl|', $character) => 240,
                str_contains(' .,:;!\'', $character) => 280,
                str_contains('ftrI-()/[]', $character) => 340,
                str_contains('mMW@', $character) => 1015,
                str_contains('wOQGCDHNU', $character) => 780,
                ctype_upper($character) => 722,
                default => 600,
            };
        }

        return $units * $size / 1000;
    }

    private function escape(string $text): string
    {
        $encoded = (string) @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return strtr($encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
    }

    private function document(): string
    {
        $this->pages[] = $this->content;
        $pageCount = count($this->pages);

        // 1 catalog, 2 page tree, 3–4 fonts, then a page and its content stream per page.
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($this->pages as $index => $stream) {
            $pageId = 5 + $index * 2;
            $kids[] = $pageId.' 0 R';
            $footer = sprintf("BT /F1 8.0 Tf %.2F %.2F Td (%s) Tj ET\n", self::MARGIN, 28.0, $this->escape('Page '.($index + 1).' of '.$pageCount));
            $stream .= $footer;
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::PAGE_WIDTH, self::PAGE_HEIGHT, $pageId + 1);
            $objects[$pageId + 1] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n '."\n", $offset);
        }
        $pdf .= 'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF'."\n";

        return $pdf;
    }
}
