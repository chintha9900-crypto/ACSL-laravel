<?php

namespace Tests\Concerns;

use App\Actions\Membership\ActivateMembership;
use App\Models\Membership;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * School Club fixtures: an activated main School Club membership (through the
 * real activation action) and completed student templates (CSV and genuine
 * .xlsx) with the First name | Surname | Email | DOB columns.
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
            'aviation_organisation' => 'Teacher in Charge',
            ...$applicationAttributes,
        ], 'SC');

        $membership = app(ActivateMembership::class)->handle($application, $this->admin())->membership;
        $membership->user->forceFill(['status' => 'active'])->save();

        return $membership->fresh(['category', 'terms', 'user']);
    }

    /**
     * A student row as the template expects it. The shorthand [full name, email]
     * splits the name at its first space (a one-word name gets the surname
     * "Student") and uses a valid date of birth.
     *
     * @param  list<string>  $student  [first name, surname, email, DOB] or [full name, email]
     * @return list<string>
     */
    protected function studentRow(array $student): array
    {
        if (count($student) !== 2) {
            return $student;
        }

        [$name, $email] = $student;
        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? ($name === '' ? '' : 'Student'), $email, '15/04/2010'];
    }

    /**
     * @param  list<list<string>>  $students
     */
    protected function studentCsv(array $students, string $header = 'First name,Surname,Email,DOB'): UploadedFile
    {
        $lines = [$header];
        foreach ($students as $student) {
            $lines[] = implode(',', array_map(fn (string $value): string => '"'.str_replace('"', '""', $value).'"', $this->studentRow($student)));
        }

        return $this->realUpload('students.csv', implode("\n", $lines)."\n", 'text/csv');
    }

    /**
     * A minimal but genuine .xlsx workbook (shared strings, first sheet). A
     * numeric DOB (an Excel date serial) is written as a number cell.
     *
     * @param  list<list<string|int>>  $students
     * @param  list<string>  $header
     */
    protected function studentXlsx(array $students, array $header = ['First name', 'Surname', 'Email', 'DOB']): UploadedFile
    {
        $rows = [$header, ...array_map(fn (array $student): array => $this->studentRow($student), $students)];
        $strings = [];
        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach ($row as $c => $value) {
                if (is_int($value)) {
                    $cells .= '<c r="'.chr(65 + $c).($r + 1).'"><v>'.$value.'</v></c>';

                    continue;
                }
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
