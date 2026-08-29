<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

trait CreatesMinimalShopeeWorkbook
{
    private const WORKSHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    protected function createMinimalShopeeWorkbook(string $path, array $rows, int $headerRows = 6): void
    {
        File::ensureDirectoryExists(dirname($path));

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create test workbook: '.$path);
        }

        $lastRow = max($headerRows, $headerRows + count($rows));
        $sheetRows = [];
        for ($rowIndex = 1; $rowIndex <= $headerRows; $rowIndex++) {
            $cells = [$this->inlineStringCell('A', $rowIndex, 'Header '.$rowIndex, '1')];
            if ($rowIndex === 1) {
                $cells[] = '<c r="B1" s="1"><f>1+1</f><v>2</v></c>';
            }
            $sheetRows[] = '<row r="'.$rowIndex.'">'.implode('', $cells).'</row>';
        }
        foreach (array_values($rows) as $offset => $values) {
            $rowIndex = $headerRows + 1 + $offset;
            $cells = [];
            foreach ($values as $column => $value) {
                $cells[] = $this->inlineStringCell((string) $column, $rowIndex, (string) $value);
            }
            $sheetRows[] = '<row r="'.$rowIndex.'">'.implode('', $cells).'</row>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::WORKSHEET_NS.'">'
            .'<dimension ref="A1"/>'
            .'<sheetData>'.implode('', $sheetRows).'</sheetData>'
            .'<autoFilter ref="A'.$headerRows.':F'.$lastRow.'"/>'
            .'</worksheet>';

        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="'.self::WORKSHEET_NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="Mass Update" sheetId="1" r:id="rId1"/></sheets>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="'.self::WORKSHEET_NS.'">'
                .'<fonts count="2"><font/><font><b/></font></fonts>'
                .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
                .'<borders count="1"><border/></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
                .'</styleSheet>',
            'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<sst xmlns="'.self::WORKSHEET_NS.'" count="0" uniqueCount="0"/>',
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ];

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
    }

    protected function readMinimalShopeeWorkbookRows(string $path, int $startRow = 7): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to read test workbook: '.$path);
        }

        $dom = new \DOMDocument();
        $dom->loadXML((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('x', self::WORKSHEET_NS);
        $rows = [];
        foreach ($xpath->query('//x:sheetData/x:row') as $rowNode) {
            if ((int) $rowNode->getAttribute('r') < $startRow) {
                continue;
            }

            $values = [];
            foreach ($xpath->query('./x:c', $rowNode) as $cell) {
                $column = preg_replace('/\d+/', '', $cell->getAttribute('r'));
                $values[$column] = $cell->getAttribute('t') === 'inlineStr'
                    ? (string) $xpath->evaluate('string(x:is/x:t)', $cell)
                    : (string) $xpath->evaluate('string(x:v)', $cell);
            }
            $rows[] = $values;
        }

        return $rows;
    }

    private function inlineStringCell(string $column, int $rowIndex, string $value, ?string $style = null): string
    {
        $styleAttribute = $style === null ? '' : ' s="'.$style.'"';

        return '<c r="'.htmlspecialchars(strtoupper($column).$rowIndex, ENT_XML1).'"'.$styleAttribute.' t="inlineStr">'
            .'<is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
    }
}
