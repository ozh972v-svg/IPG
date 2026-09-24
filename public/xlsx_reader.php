<?php
/**
 * Минимальный парсер XLSX без PhpSpreadsheet.
 * Читает все листы, возвращает массив строк: [rowNum => [colLetter => value]].
 *
 * Использование:
 *   $x = new XlsxReader('/path/to/file.xlsx');
 *   foreach ($x->sheetNames() as $name) {
 *       $rows = $x->readSheet($name);
 *   }
 */
class XlsxReader
{
    private ZipArchive $zip;
    private array $sharedStrings = [];
    private array $sheets = []; // name => path in zip

    public function __construct(string $filePath)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive не установлен на хостинге. Нужно расширение php-zip.');
        }
        $this->zip = new ZipArchive();
        if ($this->zip->open($filePath) !== true) {
            throw new RuntimeException('Не удалось открыть XLSX: ' . $filePath);
        }
        $this->loadSharedStrings();
        $this->loadWorkbook();
    }

    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    public function readSheet(string $name): array
    {
        if (!isset($this->sheets[$name])) {
            throw new RuntimeException('Лист не найден: ' . $name);
        }
        $xml = $this->zip->getFromName($this->sheets[$name]);
        if ($xml === false) return [];

        $doc = new SimpleXMLElement($xml);
        $out = [];

        foreach ($doc->sheetData->row as $row) {
            $rowNum = (int)$row['r'];
            $cells = [];
            foreach ($row->c as $c) {
                $ref  = (string)$c['r'];
                $col  = preg_replace('/[0-9]+/', '', $ref);
                $type = (string)$c['t'];

                if ($type === 's') {
                    $idx = (int)$c->v;
                    $val = $this->sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string)($c->is->t ?? '');
                } elseif ($type === 'str') {
                    $val = (string)$c->v;
                } else {
                    $val = (string)$c->v;
                }
                if ($val !== '') $cells[$col] = $val;
            }
            if ($cells) $out[$rowNum] = $cells;
        }
        return $out;
    }

    private function loadSharedStrings(): void
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return;
        $doc = new SimpleXMLElement($xml);
        foreach ($doc->si as $si) {
            $text = '';
            foreach ($si->xpath('.//t') as $t) {
                $text .= (string)$t;
            }
            $this->sharedStrings[] = $text;
        }
    }

    private function loadWorkbook(): void
    {
        $wbXml   = $this->zip->getFromName('xl/workbook.xml');
        $relsXml = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wbXml === false || $relsXml === false) {
            throw new RuntimeException('Повреждённый XLSX: нет workbook.xml или rels.');
        }

        $relsDoc = new SimpleXMLElement($relsXml);
        $relMap = [];
        foreach ($relsDoc->Relationship as $rel) {
            $relMap[(string)$rel['Id']] = (string)$rel['Target'];
        }

        $wbDoc = new SimpleXMLElement($wbXml);
        $wbDoc->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        foreach ($wbDoc->sheets->sheet as $sheet) {
            $name = (string)$sheet['name'];
            $r    = $sheet->attributes('r', true);
            $rid  = (string)$r->id;
            $target = $relMap[$rid] ?? null;
            if (!$target) continue;

            if (strpos($target, '/xl/') === 0) {
                $target = ltrim($target, '/');
            } elseif (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . ltrim($target, '/');
            }
            $this->sheets[$name] = $target;
        }
    }
}
