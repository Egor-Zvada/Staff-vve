<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use Dompdf\Dompdf;
use Dompdf\Options;

final class ExportService
{
    private const HEADERS=['Дата','ID','ФИО','Должность','Приход','Уход','Обед туда','Обед обратно','Уважительный уход','Признаки','Исходный приход','Исходный уход','График','Рабочий день'];
    private const WIDTHS=['A'=>13,'B'=>25,'C'=>34,'D'=>25,'E'=>13,'F'=>13,'G'=>13,'H'=>13,'I'=>18,'J'=>35,'K'=>18,'L'=>18,'M'=>24,'N'=>16];

    public function __construct(private AttendanceService $attendance, private Settings $settings, private ?Database $database=null) {}

    public function export(string $from,string $to,string $format,?int $employeeId=null,string $finding=''): array
    {
        $from=EmployeeService::date($from); $to=EmployeeService::date($to);
        $maxDays=max(1,(int)$this->settings->get('report_max_days',366));
        if ($to<$from || (int)(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days+1>$maxDays) { throw new \InvalidArgumentException('Период: не более '.$maxDays.' дней включительно, начало не позже конца'); }
        if (!in_array($format,['xlsx','csv','pdf'],true)) { throw new \InvalidArgumentException('Неизвестный формат'); }
        $filename='attendance_'.$from.'_'.$to.'.'.$format;
        $days=$this->dayRows($from,$to,$employeeId,$finding);
        if ($format==='xlsx') { return $this->xlsx($days,$filename); }
        if ($format==='csv') { return $this->csv($days,$filename); }
        return $this->pdf($days,$filename,$from,$to);
    }

    /** Query and format one day at a time: a six-month workbook must fit the PHP worker. */
    private function dayRows(string $from,string $to,?int $employeeId,string $finding): \Generator
    {
        $end=(new \DateTimeImmutable($to))->modify('+1 day');
        foreach (new \DatePeriod(new \DateTimeImmutable($from),new \DateInterval('P1D'),$end) as $date) {
            $day=$date->format('Y-m-d');
            // Attendance rechecks keep their own transaction boundaries. Nest them in one
            // daily transaction so a long report does not fsync once for every employee.
            $period=$this->database!==null
                ? $this->database->transaction(fn(): array=>$this->attendance->period($day,$day,$employeeId))
                : $this->attendance->period($day,$day,$employeeId);
            $source=AttendanceFilter::apply($period['rows']??$period['days']??$period,$finding);
            $rows=[];
            foreach ($source as $row) {
                if (!is_array($row) || !empty($row['private'])) { continue; }
                $values=$row['values']??$row['day']??$row;
                $employee=$row['employee']??[];
                $name=$employee['name']??$row['employee_name']??$row['full_name']??$row['name']??trim(implode(' ',array_filter([$row['surname']??'',$row['given_name']??'',$row['patronymic']??''])));
                $flags=[]; foreach (($row['flags']??[]) as $flag) { if (is_array($flag) && empty($flag['suppressed']) && empty($flag['private'])) { $flags[]=(string)($flag['label']??$flag['kind']??''); } }
                $raw=$row['raw_values']??[];
                $passCode=(string)($employee['pass_code']??$employee['display_id']??$row['pass_code']??$row['display_id']??IdentifierService::numericLegacy((int)($employee['id']??$row['employee_id']??0)));
                $rows[]=[(string)($row['work_date']??$row['date']??$day),$passCode,$name,(string)($employee['position']??$row['position']??''),$this->time($values,'arrival'),$this->time($values,'departure'),$this->time($values,'lunch_out'),$this->time($values,'lunch_in'),$this->time($values,'excused_out'),implode('; ',$flags),$this->time($raw,'arrival'),$this->time($raw,'departure'),(string)($row['group_name']??''),!empty($row['is_working']) ? 'Да' : 'Нет'];
            }
            yield $day=>$rows;
        }
    }

    private function csv(iterable $days,string $filename): array
    {
        $handle=fopen('php://temp/maxmemory:5242880','w+');
        if ($handle===false) { throw new \RuntimeException('Не удалось подготовить CSV'); }
        $count=0;
        try {
            fwrite($handle,"\xEF\xBB\xBF"); fputcsv($handle,self::HEADERS,';','"','');
            foreach ($days as $rows) {
                foreach ($rows as $row) { fputcsv($handle,array_map($this->csvText(...),$row),';','"',''); $count++; }
            }
            rewind($handle); $content=stream_get_contents($handle);
        } finally { fclose($handle); }
        return ['content'=>$content,'mime'=>'text/csv; charset=UTF-8','filename'=>$filename,'rows'=>$count];
    }

    private function pdf(iterable $days,string $filename,string $from,string $to): array
    {
        $html='<!doctype html><html lang="ru"><meta charset="UTF-8"><style>@page{size:A4 landscape;margin:10mm}body{font:8pt "DejaVu Sans"}table{width:100%;border-collapse:collapse}th,td{border:0.3pt solid #aaa;padding:3pt}thead{display:table-header-group}tr{page-break-inside:avoid}h3{margin:0 0 8pt}</style><h2>'.$this->html((string)$this->settings->get('org_name','Staff')).'</h2><p>'.$from.' — '.$to.'</p>';
        $count=0; $first=true;
        foreach ($days as $date=>$rows) {
            $count+=count($rows);
            if ($count>1500) { throw new \InvalidArgumentException('Для PDF выберите не более 1500 строк; большой период доступен в CSV/XLSX'); }
            $html.='<section class="report-day"'.($first?'':' style="page-break-before:always"').'><h3>'.$this->html($date).'</h3>'; $first=false;
            if ($rows===[]) { $html.='<p>Нет записей по выбранным условиям.</p>'; }
            else {
                $html.='<table><thead><tr>'; foreach (self::HEADERS as $h) { $html.='<th>'.$this->html($h).'</th>'; } $html.='</tr></thead><tbody>';
                foreach ($rows as $row) { $html.='<tr>'; foreach ($row as $value) { $html.='<td>'.$this->html((string)$value).'</td>'; } $html.='</tr>'; } $html.='</tbody></table>';
            }
            $html.='</section>';
        }
        $html.='</html>';
        $options=new Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans']); $pdf=new Dompdf($options); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4','landscape'); $pdf->render();
        return ['content'=>$pdf->output(),'mime'=>'application/pdf','filename'=>$filename,'rows'=>$count];
    }

    /** Write OOXML sheets to temporary files rather than keeping every cell in memory. */
    private function xlsx(iterable $days,string $filename): array
    {
        $directory=sys_get_temp_dir().'/staff-export-'.bin2hex(random_bytes(12));
        if (!mkdir($directory,0700)) { throw new \RuntimeException('Не удалось подготовить Excel'); }
        $files=[]; $archive=$directory.'/report.xlsx'; $zip=new \ZipArchive(); $opened=false; $count=0;
        try {
            if ($zip->open($archive,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) { throw new \RuntimeException('Не удалось создать Excel'); }
            $opened=true; $sheets=[];
            foreach ($days as $date=>$rows) {
                $number=count($sheets)+1; $sheets[]=$date;
                $path=$directory.'/sheet'.$number.'.xml'; $files[]=$path;
                $handle=fopen($path,'wb');
                if ($handle===false) { throw new \RuntimeException('Не удалось записать лист Excel'); }
                try {
                    $last=count($rows)+1;
                    if ($last>1048576) { throw new \InvalidArgumentException('На один день приходится слишком много строк Excel'); }
                    $this->write($handle,$this->xmlHeader().'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:N'.$last.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>');
                    $column=1; foreach (self::WIDTHS as $width) { $this->write($handle,'<col min="'.$column.'" max="'.$column.'" width="'.$width.'" customWidth="1"/>'); $column++; }
                    $this->write($handle,'</cols><sheetData>'.$this->xlsxRow(self::HEADERS,1,true));
                    foreach ($rows as $index=>$row) { $this->write($handle,$this->xlsxRow($row,$index+2)); $count++; }
                    $this->write($handle,'</sheetData><autoFilter ref="A1:N'.$last.'"/><printOptions horizontalCentered="1"/><pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>');
                } finally { fclose($handle); }
                if (!$zip->addFile($path,'xl/worksheets/sheet'.$number.'.xml')) { throw new \RuntimeException('Не удалось добавить лист Excel'); }
            }
            $types=$this->xmlHeader().'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook=$this->xmlHeader().'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
            $relationships=$this->xmlHeader().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $names='';
            foreach ($sheets as $index=>$date) {
                $number=$index+1;
                $types.='<Override PartName="/xl/worksheets/sheet'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $workbook.='<sheet name="'.$date.'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
                $relationships.='<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$number.'.xml"/>';
                $names.='<definedName name="_xlnm.Print_Titles" localSheetId="'.$index.'">\''.$date.'\'!$1:$1</definedName>';
            }
            $workbook.='</sheets><definedNames>'.$names.'</definedNames></workbook>';
            $relationships.='<Relationship Id="rId'.(count($sheets)+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
            foreach ([
                '[Content_Types].xml'=>$types.'</Types>',
                '_rels/.rels'=>$this->xmlHeader().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml'=>$workbook,'xl/_rels/workbook.xml.rels'=>$relationships,
                'xl/styles.xml'=>$this->xlsxStyles(),
            ] as $name=>$xml) { if (!$zip->addFromString($name,$xml)) { throw new \RuntimeException('Не удалось завершить Excel'); } }
            if (!$zip->close()) { throw new \RuntimeException('Не удалось сохранить Excel'); }
            $opened=false; $content=file_get_contents($archive);
            if ($content===false) { throw new \RuntimeException('Не удалось прочитать Excel'); }
            return ['content'=>$content,'mime'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','filename'=>$filename,'rows'=>$count];
        } finally {
            if ($opened) { $zip->close(); }
            foreach ($files as $path) { if (is_file($path)) { unlink($path); } }
            if (is_file($archive)) { unlink($archive); }
            rmdir($directory);
        }
    }

    private function xlsxRow(array $values,int $number,bool $header=false): string
    {
        $xml='<row r="'.$number.'">'; $columns=array_keys(self::WIDTHS);
        foreach ($values as $index=>$value) { $xml.='<c r="'.$columns[$index].$number.'" t="inlineStr" s="'.($header?'1':'0').'"><is><t xml:space="preserve">'.$this->xml((string)$value).'</t></is></c>'; }
        return $xml.'</row>';
    }

    private function xlsxStyles(): string
    {
        return $this->xmlHeader().'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCEBFA"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function xmlHeader(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'; }
    /** @param resource $handle */
    private function write($handle,string $value): void
    {
        while ($value!=='') {
            $written=fwrite($handle,$value);
            if ($written===false || $written===0) { throw new \RuntimeException('Не удалось записать Excel: проверьте свободное место'); }
            $value=substr($value,$written);
        }
    }
    private function xml(string $value): string
    {
        $escaped=htmlspecialchars($value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$escaped)??'';
    }

    private function time(array $row,string $key): string
    {
        $value=$row[$key]??$row[$key.'_at']??'';
        if (is_array($value)) { $value=$value['time']??$value['value']??''; }
        if (!$value) { return ''; }
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/D',(string)$value)) { return (string)$value; }
        try { return (new \DateTimeImmutable((string)$value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Sakhalin'))->format('H:i:s'); } catch (\Exception) { return ''; }
    }
    private function csvText(mixed $value): string { $value=(string)$value; return preg_match('/^[\s]*[=+\-@]/u',$value) ? "'".$value : $value; }
    private function html(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
