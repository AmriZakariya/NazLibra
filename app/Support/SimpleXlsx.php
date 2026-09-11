<?php

namespace App\Support;

use ZipArchive;

/**
 * The minimal .xlsx the import screens hand out and read back.
 *
 * Deliberately hand-rolled rather than a spreadsheet library: the only
 * consumer is our own importer, the sheet is a title row, a header row and
 * plain inline strings, and adding a dependency for that would be the larger
 * commitment.
 *
 * The first row is the TITLE, not the headers. The reader takes the first row
 * with two or more filled cells as its header row, so the single-cell title is
 * skipped — anything generated here has to keep that shape.
 */
class SimpleXlsx
{
    /**
     * Writes a workbook to a temporary file and returns its path.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float>>  $rows
     */
    public static function write(string $title, array $headers, array $rows, ?string $path = null): string
    {
        $path ??= tempnam(sys_get_temp_dir(), 'librairepro-import-').'.xlsx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $declaration = '<'.'?xml version="1.0" encoding="UTF-8" standalone="yes"?'.'>';
        $zip->addFromString('[Content_Types].xml', $declaration.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', $declaration.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $declaration.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $declaration.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Import" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/worksheets/sheet1.xml', self::worksheetXml($title, $headers, $rows));
        $zip->close();

        return $path;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float>>  $rows
     */
    private static function worksheetXml(string $title, array $headers, array $rows): string
    {
        $sheetRows = [[$title], $headers, ...$rows];
        $xmlRows = [];

        foreach ($sheetRows as $rowIndex => $row) {
            $cells = [];
            foreach (array_values($row) as $columnIndex => $value) {
                $reference = self::columnLetters($columnIndex + 1).($rowIndex + 1);
                $cells[] = '<c r="'.$reference.'" t="inlineStr"><is><t>'.self::xmlEscape((string) $value).'</t></is></c>';
            }
            $xmlRows[] = '<row r="'.($rowIndex + 1).'">'.implode('', $cells).'</row>';
        }

        return '<'.'?xml version="1.0" encoding="UTF-8" standalone="yes"?'.'><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>';
    }

    private static function columnLetters(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
