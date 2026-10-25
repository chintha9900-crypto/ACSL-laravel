<?php

namespace App\Support\SchoolClub;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads a School Club student list — CSV or Excel (.xlsx) — into rows of
 * `full_name` and `email`, using only PHP's bundled zip/XML extensions (no
 * spreadsheet package). The first row must be a header naming both columns
 * (case- and spacing-insensitive: "Full name", "full_name", "Email", …).
 * Blank rows are skipped. Throws a RuntimeException with a message safe to
 * show the representative when the file cannot be read.
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
     * @return list<array{row: int, full_name: string, email: string}>
     */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $rows = $extension === 'xlsx' ? $this->xlsxRows($file->getRealPath()) : $this->csvRows($file->getRealPath());

        return $this->mapRows($rows);
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<array{row: int, full_name: string, email: string}>
     */
    private function mapRows(array $rows): array
    {
        if ($rows === []) {
            throw new RuntimeException('The student list is empty.');
        }

        $header = array_map(fn (string $cell): string => preg_replace('/[^a-z]/', '', strtolower($cell)) ?? '', $rows[0]);
        $nameColumn = array_search('fullname', $header, true);
        $emailColumn = array_search('email', $header, true);

        if ($nameColumn === false || $emailColumn === false) {
            throw new RuntimeException('The first row of the student list must contain the column headings "full_name" and "email".');
        }

        $students = [];
        foreach (array_slice($rows, 1, null, true) as $index => $row) {
            $name = trim((string) ($row[$nameColumn] ?? ''));
            $email = trim((string) ($row[$emailColumn] ?? ''));

            if ($name === '' && $email === '') {
                continue;
            }

            $students[] = ['row' => $index + 1, 'full_name' => $name, 'email' => $email];

            if (count($students) > self::MAX_ROWS) {
                throw new RuntimeException('A student list can contain at most '.self::MAX_ROWS.' students.');
            }
        }

        if ($students === []) {
            throw new RuntimeException('The student list contains no students.');
        }

        return $students;
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
