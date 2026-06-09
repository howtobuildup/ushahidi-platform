<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use ZipArchive;

class XlsFormReader
{
    public function read($path)
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Unable to open the XLSForm workbook.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPaths = $this->readSheetPaths($zip);
            $workbook = [];

            foreach ($sheetPaths as $name => $sheetPath) {
                $xml = $zip->getFromName($sheetPath);
                if ($xml === false) {
                    continue;
                }
                $workbook[$name] = $this->readSheet($xml, $sharedStrings);
            }

            return $workbook;
        } finally {
            $zip->close();
        }
    }

    private function readSheetPaths(ZipArchive $zip)
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relationshipsXml === false) {
            throw new InvalidArgumentException('The workbook metadata is incomplete.');
        }

        $relationships = [];
        $relationshipsDocument = $this->loadXml($relationshipsXml);
        $relationshipsXpath = new DOMXPath($relationshipsDocument);
        foreach ($relationshipsXpath->query('//*[local-name()="Relationship"]') as $relationship) {
            $relationships[$relationship->getAttribute('Id')] = $relationship->getAttribute('Target');
        }

        $paths = [];
        $workbookDocument = $this->loadXml($workbookXml);
        $workbookXpath = new DOMXPath($workbookDocument);
        foreach ($workbookXpath->query('//*[local-name()="sheet"]') as $sheet) {
            $relationshipId = $sheet->getAttributeNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id'
            );
            if (!isset($relationships[$relationshipId])) {
                continue;
            }

            $paths[$sheet->getAttribute('name')] = 'xl/' . ltrim($relationships[$relationshipId], '/');
        }

        return $paths;
    }

    private function readSharedStrings(ZipArchive $zip)
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);
        $strings = [];

        foreach ($xpath->query('//*[local-name()="si"]') as $stringNode) {
            $value = '';
            foreach ($xpath->query('.//*[local-name()="t"]', $stringNode) as $textNode) {
                $value .= $textNode->textContent;
            }
            $strings[] = $value;
        }

        return $strings;
    }

    private function readSheet($xml, array $sharedStrings)
    {
        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);
        $rows = [];

        foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowNode) {
            $row = [];
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) as $cell) {
                $column = $this->columnIndex($cell->getAttribute('r'));
                $row[$column] = $this->readCellValue($xpath, $cell, $sharedStrings);
            }
            if (!empty($row)) {
                ksort($row);
                $rows[] = $row;
            }
        }

        if (empty($rows)) {
            return [];
        }

        $headerRow = array_shift($rows);
        $headers = [];
        foreach ($headerRow as $column => $value) {
            $headers[$column] = trim((string) $value);
        }

        $records = [];
        foreach ($rows as $row) {
            $record = [];
            foreach ($headers as $column => $header) {
                if ($header !== '') {
                    $record[$header] = $row[$column] ?? '';
                }
            }
            if (count(array_filter($record, function ($value) {
                return trim((string) $value) !== '';
            }))) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function readCellValue(DOMXPath $xpath, DOMElement $cell, array $sharedStrings)
    {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $value = '';
            foreach ($xpath->query('.//*[local-name()="t"]', $cell) as $textNode) {
                $value .= $textNode->textContent;
            }
            return $value;
        }

        $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
        if (!$valueNode) {
            return '';
        }

        $value = $valueNode->textContent;
        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }
        if ($type === 'b') {
            return $value === '1';
        }

        return $value;
    }

    private function columnIndex($cellReference)
    {
        preg_match('/^([A-Z]+)/i', $cellReference, $matches);
        $letters = strtoupper($matches[1] ?? 'A');
        $index = 0;
        for ($position = 0; $position < strlen($letters); $position++) {
            $index = ($index * 26) + (ord($letters[$position]) - 64);
        }
        return $index - 1;
    }

    private function loadXml($xml)
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new InvalidArgumentException('The workbook contains invalid XML.');
        }

        return $document;
    }
}
