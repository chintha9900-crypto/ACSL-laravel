<?php

namespace Tests\Concerns;

use App\Actions\Membership\ActivateMembership;
use App\Models\Membership;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * School Club fixtures: an activated main School Club membership (through the
 * real activation action) and student list uploads (CSV and genuine .xlsx).
 * Requires `CreatesReviewableApplications` and `CreatesUploads`.
 */
trait CreatesSchoolClubs
{
    /**
     * @param  array<string, mixed>  $applicationAttributes
     */
    protected function schoolClub(array $applicationAttributes = []): Membership
    {
        $application = $this->application('approved', [
            'full_name' => 'Rep Person',
            'company_name' => 'Example College',
            'company_email' => 'office@example-college.test',
            'company_phone' => '+94112345678',
            'aviation_role' => 'Example College Aviation Club',
            'aviation_organisation' => 'Club coordinator',
            ...$applicationAttributes,
        ], 'SC');

        $membership = app(ActivateMembership::class)->handle($application, $this->admin())->membership;
        $membership->user->forceFill(['status' => 'active'])->save();

        return $membership->fresh(['category', 'terms', 'user']);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $students  [full name, email]
     */
    protected function studentCsv(array $students, string $header = 'full_name,email'): UploadedFile
    {
        $lines = [$header];
        foreach ($students as [$name, $email]) {
            $lines[] = '"'.str_replace('"', '""', $name).'",'.$email;
        }

        return $this->realUpload('students.csv', implode("\n", $lines)."\n", 'text/csv');
    }

    /**
     * A minimal but genuine .xlsx workbook (shared strings, first sheet).
     *
     * @param  list<array{0: string, 1: string}>  $students
     */
    protected function studentXlsx(array $students): UploadedFile
    {
        $rows = [['full_name', 'email'], ...$students];
        $strings = [];
        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach ($row as $c => $value) {
                $strings[] = htmlspecialchars($value, ENT_XML1);
                $cells .= '<c r="'.chr(65 + $c).($r + 1).'" t="s"><v>'.(count($strings) - 1).'</v></c>';
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$cells.'</row>';
        }

        $path = tempnam(sys_get_temp_dir(), 'aci-xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn (string $s): string => '<si><t>'.$s.'</t></si>', $strings)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, 'students.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
