<?php

namespace App\Support\SchoolClub;

use RuntimeException;
use ZipArchive;

/**
 * The downloadable School Club student template (`template.xlsx`): one sheet,
 * exactly four headings and one example row, nothing else. Built with PHP's
 * bundled zip extension (no spreadsheet package). The DOB column is formatted
 * as text so Excel keeps a typed DD/MM/YYYY date as entered.
 */
class StudentListTemplate
{
    /**
     * @var list<string>
     */
    public const HEADERS = ['First name', 'Surname', 'Email', 'DOB'];

    /**
     * The example row. Left unchanged in an upload, it is ignored.
     *
     * @var list<string>
     */
    public const SAMPLE_ROW = ['John', 'Perera', 'john@example.com', '15/04/2010'];

    /**
     * Zero-based index of the DOB column.
     */
    public const DOB_COLUMN = 3;

    public const FILENAME = 'template.xlsx';

    /**
     * The workbook's bytes.
     */
    public function contents(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aci-template-');
        if ($path === false) {
            throw new RuntimeException('The template could not be created.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The template could not be created.');
            }

            foreach ($this->parts() as $name => $xml) {
                $zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".$xml);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, string>
     */
    private function parts(): array
    {
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relationships = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $package = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml';

        return [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="'.$type.'.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="'.$type.'.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="'.$type.'.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<Relationships xmlns="'.$package.'">'
                .'<Relationship Id="rId1" Type="'.$relationships.'/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<workbook xmlns="'.$main.'" xmlns:r="'.$relationships.'">'
                .'<sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="'.$package.'">'
                .'<Relationship Id="rId1" Type="'.$relationships.'/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="'.$relationships.'/styles" Target="styles.xml"/>'
                .'</Relationships>',
            // Styles: 0 default, 1 bold heading, 2 text (number format "@").
            'xl/styles.xml' => '<styleSheet xmlns="'.$main.'">'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="3">'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                .'</cellXfs>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="'.$main.'">'
                .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                .'<cols>'
                .'<col min="1" max="2" width="22" customWidth="1"/>'
                .'<col min="3" max="3" width="34" customWidth="1"/>'
                .'<col min="4" max="4" width="14" style="2" customWidth="1"/>'
                .'</cols>'
                .'<sheetData>'
                .$this->row(1, self::HEADERS, 1)
                .$this->row(2, self::SAMPLE_ROW, 0)
                .'</sheetData>'
                .'</worksheet>',
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private function row(int $number, array $values, int $style): string
    {
        $cells = '';
        foreach ($values as $index => $value) {
            $cellStyle = $style === 0 && $index === self::DOB_COLUMN ? 2 : $style;
            $cells .= '<c r="'.chr(65 + $index).$number.'" t="inlineStr"'.($cellStyle > 0 ? ' s="'.$cellStyle.'"' : '').'>'
                .'<is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
        }

        return '<row r="'.$number.'">'.$cells.'</row>';
    }
}
