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

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

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

    /** Отладочная информация: сколько shared strings и первые N */
    public function debugInfo(int $limit = 30): array
    {
        return [
            'shared_count'   => count($this->sharedStrings),
            'shared_sample'  => array_slice($this->sharedStrings, 0, $limit),
            'sheets'         => $this->sheets,
        ];
    }

    public function readSheet(string $name): array
    {
        if (!isset($this->sheets[$name])) {
            throw new RuntimeException('Лист не найден: ' . $name);
        }
        $xml = $this->zip->getFromName($this->sheets[$name]);
        if ($xml === false) return [];

        $doc = @simplexml_load_string($xml);
        if ($doc === false) return [];
        $doc->registerXPathNamespace('x', self::NS_MAIN);

        $out = [];
        $rowNodes = $doc->xpath('//x:sheetData/x:row');
        if (!$rowNodes) return [];

        foreach ($rowNodes as $row) {
            $row->registerXPathNamespace('x', self::NS_MAIN);
            $rowNum = (int)$row['r'];
            $cells = [];

            $cellNodes = $row->xpath('x:c');
            foreach ($cellNodes as $c) {
                $c->registerXPathNamespace('x', self::NS_MAIN);

                $ref  = (string)$c['r'];
                $col  = preg_replace('/[0-9]+/', '', $ref);
                $type = (string)$c['t'];

                $val = '';
                if ($type === 's') {
                    $v = $c->xpath('x:v');
                    $idx = $v ? (int)$v[0] : -1;
                    $val = $this->sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $tNodes = $c->xpath('.//x:t');
                    foreach ($tNodes as $t) $val .= (string)$t;
                } elseif ($type === 'str') {
                    $v = $c->xpath('x:v');
                    $val = $v ? (string)$v[0] : '';
                } else {
                    $v = $c->xpath('x:v');
                    $val = $v ? (string)$v[0] : '';
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
        if ($xml === false) {
            // sharedStrings отсутствует — это нормально, если все строки inline
            return;
        }

        $doc = @simplexml_load_string($xml);
        if ($doc === false) return;
        $doc->registerXPathNamespace('x', self::NS_MAIN);

        $siNodes = $doc->xpath('//x:si');
        if (!$siNodes) return;

        foreach ($siNodes as $si) {
            $si->registerXPathNamespace('x', self::NS_MAIN);
            $text = '';
            // Собираем все <t> внутри <si> (учитывая вложенные <r><t>)
            $tNodes = $si->xpath('.//x:t');
            foreach ($tNodes as $t) {
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

        $relsDoc = @simplexml_load_string($relsXml);
        if ($relsDoc === false) throw new RuntimeException('Не читается rels.');
        $relsDoc->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relMap = [];
        foreach ($relsDoc->xpath('//r:Relationship') as $rel) {
            $relMap[(string)$rel['Id']] = (string)$rel['Target'];
        }

        $wbDoc = @simplexml_load_string($wbXml);
        if ($wbDoc === false) throw new RuntimeException('Не читается workbook.xml.');
        $wbDoc->registerXPathNamespace('x', self::NS_MAIN);
        $wbDoc->registerXPathNamespace('r', self::NS_REL);

        foreach ($wbDoc->xpath('//x:sheets/x:sheet') as $sheet) {
            $name = (string)$sheet['name'];
            $r    = $sheet->attributes(self::NS_REL);
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
