<?php
declare(strict_types=1);

/** Isolated export regression: synthetic attendance only; no database or external API. */
namespace App\Infrastructure {
    final class Database {
        public int $transactions=0;
        public bool $active=false;
        public function transaction(callable $operation): mixed {
            if ($this->active) { throw new \RuntimeException('Export opened an overlapping transaction'); }
            $this->transactions++; $this->active=true;
            try { return $operation(); } finally { $this->active=false; }
        }
    }
}
namespace App\Service {
    final class Settings {
        public function get(string $key,mixed $default=null): mixed { return ['org_name'=>'Проверочная организация','report_max_days'=>366][$key]??$default; }
    }
    final class AttendanceService {
        public array $calls=[];
        public bool $large=false;
        public ?\App\Infrastructure\Database $database=null;
        public function period(string $from,string $to,?int $employeeId=null): array {
            if ($from!==$to) { throw new \RuntimeException('Export loaded more than one day into memory'); }
            if ($this->database!==null && !$this->database->active) { throw new \RuntimeException('Day is not in one transaction'); }
            $this->calls[]=[$from,$employeeId];
            if (!$this->large && $from==='2026-01-02') { return []; }
            $rows=[];
            $total=$this->large?62:2;
            for ($index=0;$index<$total;$index++) {
                if ($employeeId!==null && $employeeId!==$index+1) { continue; }
                $rows[]=['employee_id'=>$index+1,'work_date'=>$from,'employee'=>['id'=>$index+1,'pass_code'=>$index===0?'007':'VVE-0123-4567-89AB','name'=>$index===0?'=HYPERLINK("example")&<Тест>':"Сотрудник $index",'position'=>'Должность'],
                    'values'=>['arrival'=>'2026-01-01 22:00:00','departure'=>'18:00:00'],'raw_values'=>['arrival'=>'08:59:00'],'group_name'=>'09–18','is_working'=>true,
                    'flags'=>$index===0?[['kind'=>'LATE_ARRIVAL','label'=>'Опоздание'],['kind'=>'SECRET','label'=>'Не выдавать','private'=>true],['kind'=>'SUPPRESSED','label'=>'Снятый флаг','suppressed'=>true]]:[]];
            }
            if (!$this->large) { $rows[]=['private'=>true,'full_name'=>'Скрытая строка','work_date'=>$from]; }
            return $rows;
        }
    }
}
namespace Dompdf {
    final class Options { public function __construct(public array $values) {} }
    final class Dompdf {
        private string $html='';
        public function __construct(Options $options) {}
        public function loadHtml(string $html,string $encoding): void { $this->html=$html; }
        public function setPaper(string $paper,string $orientation): void {}
        public function render(): void {}
        public function output(): string { return $this->html; }
    }
}
namespace {
    require dirname(__DIR__).'/src/Service/IdentifierService.php';
    require dirname(__DIR__).'/src/Service/EmployeeService.php';
    require dirname(__DIR__).'/src/Service/AttendanceFilter.php';
    require dirname(__DIR__).'/src/Service/ExportService.php';
    $passed=0;
    $assert=static function(bool $condition,string $message) use (&$passed): void { if (!$condition) { throw new \RuntimeException($message); } $passed++; echo "PASS $message\n"; };
    $unzip=static function(string $content): array {
        $path=tempnam(sys_get_temp_dir(),'staff-export-test-'); file_put_contents($path,$content);
        $zip=new \ZipArchive();
        try {
            if ($zip->open($path)!==true) { throw new \RuntimeException('Invalid XLSX archive'); }
            $parts=[];
            for ($i=0;$i<$zip->numFiles;$i++) {
                $name=$zip->getNameIndex($i); $parts[$name]=$zip->getFromIndex($i);
                $document=new \DOMDocument();
                if (!$document->loadXML($parts[$name],LIBXML_NONET)) { throw new \RuntimeException('Invalid XML: '.$name); }
            }
            return $parts;
        } finally { $zip->close(); unlink($path); }
    };
    $sheets=static function(array $parts): array {
        $document=new \DOMDocument(); $document->loadXML($parts['xl/workbook.xml']); $list=[];
        foreach ($document->getElementsByTagName('sheet') as $sheet) { $list[]=$sheet->getAttribute('name'); }
        return $list;
    };
    $cell=static function(string $xml,string $reference): array {
        $document=new \DOMDocument(); $document->loadXML($xml); $xpath=new \DOMXPath($document); $xpath->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $node=$xpath->query('//s:c[@r="'.$reference.'"]')->item(0);
        return $node?['value'=>$node->textContent,'type'=>$node->getAttribute('t'),'formula'=>$node->getElementsByTagName('f')->length]:[];
    };
    $database=new \App\Infrastructure\Database();
    $attendance=new \App\Service\AttendanceService(); $attendance->database=$database; $export=new \App\Service\ExportService($attendance,new \App\Service\Settings(),$database);
    $initial=glob(sys_get_temp_dir().'/staff-export-*')?:[];
    $result=$export->export('2026-01-01','2026-01-03','xlsx'); $parts=$unzip($result['content']);
    $assert($database->transactions===3 && !$database->active,'One short transaction per day finishes before file rendering');
    $assert($sheets($parts)===['2026-01-01','2026-01-02','2026-01-03'],'Every inclusive date has a worksheet, including empty days');
    $assert($result['rows']===4 && $cell($parts['xl/worksheets/sheet2.xml'],'A1')['value']==='Дата' && $cell($parts['xl/worksheets/sheet2.xml'],'A2')===[],'Empty day has headers and does not copy another day');
    $assert($cell($parts['xl/worksheets/sheet1.xml'],'B2')===['value'=>'007','type'=>'inlineStr','formula'=>0] && $cell($parts['xl/worksheets/sheet1.xml'],'B3')['value']==='VVE-0123-4567-89AB','Legacy leading zero and new pass codes are text');
    $assert($cell($parts['xl/worksheets/sheet1.xml'],'C2')===['value'=>'=HYPERLINK("example")&<Тест>','type'=>'inlineStr','formula'=>0],'Formula-looking employee text remains literal');
    $assert(!str_contains(implode('',$parts),'Скрытая строка') && !str_contains(implode('',$parts),'Не выдавать') && !str_contains(implode('',$parts),'Снятый флаг'),'Private rows and suppressed/private flags are excluded');
    $assert($cell($parts['xl/worksheets/sheet1.xml'],'E2')['value']==='09:00:00','UTC attendance is converted to Sakhalin time');
    $filtered=$unzip($export->export('2026-01-01','2026-01-03','xlsx',1,'late')['content']);
    $assert($sheets($filtered)===['2026-01-01','2026-01-02','2026-01-03'] && $cell($filtered['xl/worksheets/sheet1.xml'],'B3')===[],'Employee and finding filters still keep an empty sheet for each date');
    $csv=$export->export('2026-01-01','2026-01-03','csv');
    $assert(str_starts_with($csv['content'],"\xEF\xBB\xBF") && str_contains($csv['content'],"'=HYPERLINK") && strpos($csv['content'],'2026-01-01')<strpos($csv['content'],'2026-01-03'),'CSV retains chronological rows, UTF-8 BOM and formula neutralization');
    $pdf=$export->export('2026-01-01','2026-01-03','pdf');
    $assert(substr_count($pdf['content'],'class="report-day"')===3 && substr_count($pdf['content'],'style="page-break-before:always"')===2 && str_contains($pdf['content'],'Нет записей по выбранным условиям.'),'PDF starts each day in a separate section with a page break');
    $attendance->large=true; $attendance->calls=[];
    $large=$export->export('2026-01-01','2026-06-30','xlsx'); $largeParts=$unzip($large['content']);
    $assert(count($sheets($largeParts))===181 && $large['rows']===11222 && count($attendance->calls)===181,'Six-month export supports more than 10,000 rows without dropping dates');
    $assert(memory_get_peak_usage(true)<128*1024*1024,'Six-month export remains below the production 128 MB memory limit');
    $output=getenv('STAFF_EXPORT_TEST_OUTPUT');
    if ($output!==false && $output!=='') { file_put_contents($output,$large['content']); }
    $attendance->large=false;
    $failed=false;
    try { $export->export('2026-01-01','2026-01-02','xlsx',null,'invalid'); } catch (\InvalidArgumentException) { $failed=true; }
    $assert($failed && (glob(sys_get_temp_dir().'/staff-export-*')?:[])===$initial,'Failed export removes temporary files as well');
    $failed=false;
    try { $export->export('2026-01-02','2026-01-01','xlsx'); } catch (\InvalidArgumentException) { $failed=true; }
    $assert($failed,'Reversed dates are rejected');
    echo json_encode(['passed'=>$passed,'large_rows'=>$large['rows'],'large_bytes'=>strlen($large['content']),'peak_mb'=>round(memory_get_peak_usage(true)/1048576,2)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
}
