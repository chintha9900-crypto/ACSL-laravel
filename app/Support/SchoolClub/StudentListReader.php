<?php

namespace App\Support\SchoolClub;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads a completed School Club student template — Excel (.xlsx) or CSV —
 * using only PHP's bundled zip/XML extensions (no spreadsheet package).
 *
 * The first row must be exactly the template's headings, in order:
 * First name | Surname | Email | DOB (surrounding spaces and letter case are
 * ignored), with no other columns. Blank rows are skipped, and so is the
 * template's unchanged sample row. Values are returned as entered (trimmed);
 * an Excel date typed into the DOB column is turned back into DD/MM/YYYY.
 * Row-level checks (required values, email, date) are the caller's job.
 * Throws a RuntimeException with a message safe to show the representative
 * when the file itself cannot be used.
 */
class StudentListReader
{
    /**
     * Largest number of students one list may contain.
     */
    public const MAX_ROWS = 999;

    /**
     * Upper bound on an .xlsx part's uncompressed size (guards against zip bombs).
     */
    private const MAX_XLSX_PART_BYTES = 10 * 1024 * 1024;

    /**
     * @return list<array{row: int, first_name: string, surname: string, email: string, date_of_birth: string, extra_columns: bool}>
     */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $rows = $extension === 'xlsx' ? $this->xlsxRows($file->getRealPath()) : $this->csvRows($file->getRealPath());

        return $this->mapRows($rows);
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<array{row: int, first_name: string, surname: string, email: string, date_of_birth: string, extra_columns: bool}>
     */
    private function mapRows(array $rows): array
    {
        if ($rows === []) {
            throw new RuntimeException('The student list is empty.');
        }

        $header = array_map(fn (string $cell): string => $this->normalise($cell), $rows[0]);
        while ($header !== [] && end($header) === '') {
            array_pop($header);
        }

        $expected = array_map(fn (string $heading): string => $this->normalise($heading), StudentListTemplate::HEADERS);
        if ($header !== $expected) {
            throw new RuntimeException('The first row must contain exactly these column headings, in this order: '.implode(' | ', StudentListTemplate::HEADERS).'. Please download the template and use it unchanged.');
        }

        $sample = array_map(fn (string $value): string => $this->normalise($value), StudentListTemplate::SAMPLE_ROW);
        $columns = count(StudentListTemplate::HEADERS);

        $students = [];
        foreach (array_slice($rows, 1, null, true) as $index => $row) {
            $values = array_map(fn ($cell): string => trim((string) $cell), array_pad(array_slice($row, 0, $columns), $columns, ''));
            $extra = array_filter(array_slice($row, $columns), fn ($cell): bool => trim((string) $cell) !== '') !== [];

            if (! $extra && implode('', $values) === '') {
                continue;
            }

            if (! $extra && array_map(fn (string $value): string => $this->normalise($value), $values) === $sample) {
                continue; // the template's example row, left in place
            }

            [$firstName, $surname, $email, $dateOfBirth] = $values;
            $students[] = [
                'row' => $index + 1,
                'first_name' => $firstName,
                'surname' => $surname,
                'email' => $email,
                'date_of_birth' => $dateOfBirth,
                'extra_columns' => $extra,
            ];

            if (count($students) > self::MAX_ROWS) {
                throw new RuntimeException('A student list can contain at most '.self::MAX_ROWS.' students.');
            }
        }

        if ($students === []) {
            throw new RuntimeException('The student list contains no students. Add one row per student below the headings (the John Perera example row is ignored).');
        }

        return $students;
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The student list could not be read.');
        }

        $rows = [];
        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($rows === [] && isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) ?? ''; // UTF-8 BOM
                }
                $rows[] = array_map(fn ($cell): string => (string) $cell, $row);

                if (count($rows) > self::MAX_ROWS + 1 + 100) {
                    break; // far past the limit; mapRows reports it
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * The first worksheet of an .xlsx workbook, as rows of cell text.
     *
     * @return list<list<string>>
     */
    private function xlsxRows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The Excel file could not be opened. Please save it as .xlsx or CSV and try again.');
        }

        try {
            $sheetPath = $this->firstSheetPath($zip);
            $shared = $this->sharedStrings($zip);
            $sheet = $this->xml($zip, $sheetPath);

            $rows = [];
            foreach ($sheet->sheetData->row ?? [] as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    $column = $this->columnIndex((string) $cell['r']);
                    $type = (string) $cell['t'];
                    $value = match ($type) {
                        's' => $shared[(int) $cell->v] ?? '',
                        'inlineStr' => $this->text($cell->is),
                        default => (string) $cell->v,
                    };
                    if ($column === StudentListTemplate::DOB_COLUMN && in_array($type, ['', 'n', 'd'], true)) {
                        $value = $this->excelDate($type, $value);
                    }
                    $cells[$column] = $value;
                }
                $filled = [];
                if ($cells !== []) {
                    for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) {
                        $filled[] = $cells[$i] ?? '';
                    }
                }
                $rows[] = $filled;

                if (count($rows) > self::MAX_ROWS + 1 + 100) {
                    break;
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $this->xml($zip, 'xl/workbook.xml');
        $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');

        $sheet = $workbook->sheets->sheet[0] ?? null;
        if ($sheet === null) {
            throw new RuntimeException('The Excel file contains no worksheet.');
        }

        $relationId = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        foreach ($rels->Relationship as $relationship) {
            if ((string) $relationship['Id'] === $relationId) {
                $target = ltrim((string) $relationship['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $strings = [];
        foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $item) {
            $strings[] = $this->text($item);
        }

        return $strings;
    }

    private function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }

        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    private function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > self::MAX_XLSX_PART_BYTES) {
            throw new RuntimeException('The Excel file could not be read. Please save it as .xlsx or CSV and try again.');
        }

        $contents = $zip->getFromName($name);
        $xml = $contents === false ? false : simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('The Excel file could not be read. Please save it as .xlsx or CSV and try again.');
        }

        return $xml;
    }

    /**
     * Excel stores a date typed into a cell as a serial day number (1900 date
     * system) or, rarely, an ISO date cell; either becomes DD/MM/YYYY. Any
     * other value is returned unchanged for validation to report.
     */
    private function excelDate(string $type, string $value): string
    {
        if ($type === 'd' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match) === 1) {
            return $match[3].'/'.$match[2].'/'.$match[1];
        }

        if (preg_match('/^\d{1,6}(\.\d+)?$/', $value) === 1 && (float) $value >= 61) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $value))->format('d/m/Y');
        }

        return $value;
    }

    /**
     * "C12" → 2 (zero-based column index).
     */
    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
