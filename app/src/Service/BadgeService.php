<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use FontLib\BinaryStream;
use FontLib\Font;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BadgeService
{
    public function __construct(private Database $database, private Settings $settings, private EmployeeService $employees, private WalletService $wallet) {}

    public function defaults(): array
    {
        $landscape=$this->settings->get('badge_orientation','portrait')==='landscape';
        return ['width_mm'=>$landscape?85.6:53.98,'height_mm'=>$landscape?53.98:85.6,'qr_mm'=>28,'logo_mm'=>12,'padding_mm'=>4,'org_name'=>(string)$this->settings->get('org_name','Школа'),'org_sub'=>'','bg_color'=>'#ffffff','bg_image'=>'','colors'=>['org'=>'#0756a0','sub'=>'#475569','name'=>'#172033','position'=>'#475569','id'=>'#0756a0'],'header'=>['on'=>false,'color'=>'#dbeafe','opacity'=>0.9,'radius'=>3],'font_size'=>10,'name_mode'=>'full'];
    }

    public function template(array $input=[]): array
    {
        $cfg=array_replace_recursive($this->defaults(),$input);
        foreach (['width_mm'=>[35,150],'height_mm'=>[35,150],'qr_mm'=>[16,40],'logo_mm'=>[6,24],'padding_mm'=>[2,10],'font_size'=>[7,16]] as $key=>$bounds) { $cfg[$key]=$this->number($cfg[$key],...$bounds); }
        if ($cfg['qr_mm']+2*$cfg['padding_mm']>min($cfg['width_mm'],$cfg['height_mm'])) { throw new \InvalidArgumentException('QR не помещается в карточке'); }
        foreach (['org_name','org_sub'] as $key) { $cfg[$key]=trim((string)$cfg[$key]); if (mb_strlen($cfg[$key])>150) { throw new \InvalidArgumentException('Слишком длинное название организации'); } }
        $cfg['bg_color']=$this->color((string)$cfg['bg_color']);
        foreach (['org','sub','name','position','id'] as $key) { $cfg['colors'][$key]=$this->color((string)$cfg['colors'][$key]); }
        $cfg['header']['color']=$this->color((string)$cfg['header']['color']); $cfg['header']['opacity']=$this->number($cfg['header']['opacity'],0,1); $cfg['header']['radius']=$this->number($cfg['header']['radius'],0,10); $cfg['header']['on']=EmployeeService::bool($cfg['header']['on'])===1;
        if (!in_array($cfg['name_mode'],['full','initials'],true)) { throw new \InvalidArgumentException('Неизвестный формат ФИО'); }
        $cfg['bg_image']=(string)$cfg['bg_image'];
        if ($cfg['bg_image']!=='') { $this->imageData($cfg['bg_image']); }
        return $cfg;
    }

    public function qr(int $id, string $format='svg'): string
    {
        $employee=$this->employees->find($id) ?? throw new NotFoundHttpException('Сотрудник не найден');
        // The canonical public code may be legacy numeric or a new cryptographic code.
        $qr=new QrCode(data:IdentifierService::codeOf($employee),encoding:new Encoding('ISO-8859-1'),errorCorrectionLevel:ErrorCorrectionLevel::High,size:560,margin:112,roundBlockSizeMode:RoundBlockSizeMode::Margin);
        return ($format==='png' ? new PngWriter() : new SvgWriter())->write($qr)->getString();
    }

    public function walletSvg(int $id,array $input=[],bool $embedFonts=true): string
    {
        $employee=$this->employees->find($id) ?? throw new NotFoundHttpException('Сотрудник не найден');
        if (!(int)$employee['active']) { throw new \InvalidArgumentException('Wallet выдаётся только действующим сотрудникам'); }
        $url=$this->wallet->cachedShareUrl($id);
        if (!$url) { throw new \InvalidArgumentException('Сначала подготовьте Wallet для кода '.IdentifierService::codeOf($employee)); }
        if (strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https') { throw new \RuntimeException('Некорректная ссылка подготовленного Wallet'); }
        $cfg=$this->template($input); $w=$cfg['width_mm']; $h=$cfg['height_mm']; $p=$cfg['padding_mm']; $q=$cfg['qr_mm']; $landscape=$w>$h;
        $leftWidth=$landscape?$w-$q-3*$p:$w-2*$p; $center=$p+$leftWidth/2;
        if ($leftWidth<max(15,$cfg['logo_mm'])) { throw new \InvalidArgumentException('Недостаточно места для оформления обратной стороны'); }
        $logo=dirname(__DIR__,2).'/public/assets/logo.png';
        if (!is_readable($logo)) { throw new \RuntimeException('Логотип не установлен'); }
        $idLabel='ID '.IdentifierService::codeOf($employee);
        $svg='<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="'.$w.'mm" height="'.$h.'mm" viewBox="0 0 '.$w.' '.$h.'"><rect width="'.$w.'" height="'.$h.'" fill="'.$cfg['bg_color'].'"/>';
        if ($embedFonts) { $svg.=$this->fontStyles($cfg['org_name'].' Добавить пропуск в Wallet Сканируйте камерой телефона '.$idLabel); }
        if ($cfg['bg_image']!=='') { $svg.=$this->image($cfg['bg_image'],0,0,$w,$h,'xMidYMid slice'); }
        $svg.=$this->image($this->logoUri(),$center-$cfg['logo_mm']/2,$p,$cfg['logo_mm'],$cfg['logo_mm']);
        $svg.=$this->textBlock($cfg['org_name'],$center,$p+$cfg['logo_mm']+3.5,$leftWidth,2.6,2,$cfg['colors']['org'],true,$orgEnd);
        $svg.=$this->textBlock('Добавить пропуск в Wallet',$center,$orgEnd+5,$leftWidth,2.8,2,$cfg['colors']['name'],true,$titleEnd);
        $svg.=$this->textBlock('Сканируйте камерой телефона',$center,$titleEnd+4,$leftWidth,2.1,2,$cfg['colors']['sub'],false,$hintEnd);
        $qx=$landscape?$w-$p-$q:($w-$q)/2; $qy=$landscape?($h-$q)/2:$h-$p-7-$q;
        if ((!$landscape && $hintEnd>$qy-2) || ($landscape && $hintEnd>$h-$p-5)) { throw new \InvalidArgumentException('Текст обратной стороны не помещается: уменьшите логотип/QR или увеличьте карточку'); }
        $qr=new QrCode(data:$url,encoding:new Encoding('UTF-8'),errorCorrectionLevel:ErrorCorrectionLevel::Medium,size:560,margin:112,roundBlockSizeMode:RoundBlockSizeMode::Margin);
        $qrSvg=(new SvgWriter())->write($qr)->getString();
        if (!preg_match('/<svg\b[^>]*\bviewBox="0 0 ([0-9.]+) ([0-9.]+)"/s',$qrSvg,$box) || (float)$box[1]<=0 || $box[1]!==$box[2]) { throw new \RuntimeException('Некорректная геометрия QR Wallet'); }
        $svg.='<rect x="'.($qx-0.8).'" y="'.($qy-0.8).'" width="'.($q+1.6).'" height="'.($q+1.6).'" fill="#fff"/><g transform="translate('.$qx.' '.$qy.') scale('.($q/(float)$box[1]).')">'.$this->svgInner($qrSvg).'</g>';
        $svg.=$this->textBlock($idLabel,$landscape?$center:$w/2,$h-$p,$leftWidth,2.6,1,$cfg['colors']['id'],true,$idEnd);
        return $svg.'</svg>';
    }

    public function svg(int $id, array $input=[],bool $embedFonts=true): string
    {
        $employee=$this->employees->find($id) ?? throw new NotFoundHttpException('Сотрудник не найден');
        $passCode=IdentifierService::codeOf($employee);
        $cfg=$this->template($input); $w=$cfg['width_mm']; $h=$cfg['height_mm']; $p=$cfg['padding_mm']; $q=$cfg['qr_mm']; $landscape=$w>$h;
        $leftWidth=$landscape ? $w-$q-3*$p : $w-2*$p; $center=$p+$leftWidth/2;
        if ($leftWidth<max(15,$cfg['logo_mm'])) { throw new \InvalidArgumentException('Недостаточно места для логотипа и текста; увеличьте карточку или уменьшите QR/поля'); }
        $logo=dirname(__DIR__,2).'/public/assets/logo.png';
        if (!is_file($logo)) { throw new \RuntimeException('Логотип не установлен'); }
        $logoUri=$this->logoUri();
        $svg='<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="'.$w.'mm" height="'.$h.'mm" viewBox="0 0 '.$w.' '.$h.'"><rect width="'.$w.'" height="'.$h.'" fill="'.$cfg['bg_color'].'"/>';
        $glyphs=$cfg['org_name'].' '.$cfg['org_sub'].' '.$employee['name'].' '.$employee['position'].' ID '.IdentifierService::codeOf($employee).'.';
        if ($embedFonts) { $svg.=$this->fontStyles($glyphs); }
        if ($cfg['bg_image']!=='') { $svg.=$this->image($cfg['bg_image'],0,0,$w,$h,'xMidYMid slice'); }
        if ($cfg['header']['on']) { $svg.='<rect x="1" y="1" width="'.($w-2).'" height="'.($p+$cfg['logo_mm']+11).'" rx="'.$cfg['header']['radius'].'" fill="'.$cfg['header']['color'].'" fill-opacity="'.$cfg['header']['opacity'].'"/>'; }
        $svg.=$this->image($logoUri,$center-$cfg['logo_mm']/2,$p,$cfg['logo_mm'],$cfg['logo_mm']);
        $orgY=$p+$cfg['logo_mm']+3.5;
        $svg.=$this->textBlock($cfg['org_name'],$center,$orgY,$leftWidth,2.6,2,$cfg['colors']['org'],true,$orgEnd);
        if ($cfg['org_sub']!=='') { $svg.=$this->textBlock($cfg['org_sub'],$center,$orgEnd+2.8,$leftWidth,2.1,1,$cfg['colors']['sub'],false,$orgEnd); }
        $name=$employee['name'];
        if ($cfg['name_mode']==='initials') { $name=$employee['surname'].' '.mb_substr($employee['given_name'],0,1).'.'.($employee['patronymic']!=='' ? mb_substr($employee['patronymic'],0,1).'.' : ''); }
        $nameY=$orgEnd+5;
        $qrY=$landscape ? ($h-$q)/2-1 : $h-$p-6-$q;
        $maxNameY=$landscape ? $h-$p-9 : $qrY-9;
        $font=min($cfg['font_size']*25.4/72, max(2.0,($maxNameY-$nameY)/2.5));
        $svg.=$this->textBlock($name,$center,$nameY,$leftWidth,$font,3,$cfg['colors']['name'],true,$nameEnd);
        $position=$this->settings->get('badge_position',true) ? $employee['position'] : '';
        if ($position!=='') { $svg.=$this->textBlock($position,$center,$nameEnd+3.7,$leftWidth,2.3,2,$cfg['colors']['position'],false,$positionEnd); }
        else { $positionEnd=$nameEnd; }
        if (!$landscape && $positionEnd>$qrY-1.5) { throw new \InvalidArgumentException('ФИО/должность не помещаются: измените шаблон или используйте инициалы'); }
        if ($landscape && $positionEnd>$h-$p-(ctype_digit($passCode)?0:5)) { throw new \InvalidArgumentException('Текст выходит за карточку или занимает строку кода пропуска'); }
        $qx=$landscape ? $w-$p-$q : ($w-$q)/2;
        $svg.='<rect x="'.($qx-0.8).'" y="'.($qrY-0.8).'" width="'.($q+1.6).'" height="'.($q+1.6).'" fill="#fff"/>';
        $qrSvg=$this->qr($id);
        if (!preg_match('/<svg\b[^>]*\bviewBox="0 0 ([0-9.]+) ([0-9.]+)"/s',$qrSvg,$qrBox) || (float)$qrBox[1]<=0 || $qrBox[1]!==$qrBox[2]) { throw new \RuntimeException('Некорректная геометрия QR'); }
        $inner=$this->svgInner($qrSvg); $svg.='<g transform="translate('.$qx.' '.$qrY.') scale('.($q/(float)$qrBox[1]).')">'.$inner.'</g>';
        $numericFooter=$landscape && ctype_digit($passCode);
        $idX=$numericFooter ? $qx+$q/2 : $w/2;
        $svg.=$this->textBlock('ID '.$passCode,$idX,$h-$p,$numericFooter?$q:$w-2*$p,3.2,1,$cfg['colors']['id'],true,$idEnd);
        return $svg.'</svg>';
    }

    public function selected(array|string|null $ids, array $filters=[]): array
    {
        if ($ids===null || $ids==='' || $ids===[]) { return $this->employees->list($filters+['active'=>'active']); }
        $raw=is_array($ids)?$ids:preg_split('/[,;\s]+/',trim($ids),-1,PREG_SPLIT_NO_EMPTY);
        if (count($raw)>200) { throw new \InvalidArgumentException('Не более 200 карточек за одну выдачу'); }
        $out=[];
        foreach (array_unique(array_map(EmployeeService::id(...),$raw)) as $id) { $out[]=$this->employees->find($id) ?? throw new NotFoundHttpException('Не найден ID '.$id); }
        return $out;
    }

    public function pdf(array $data,int $actorId): string
    {
        $employees=$this->selected($data['ids']??null); if (!$employees) { throw new \InvalidArgumentException('Выберите сотрудников'); }
        if (count($employees)>200) { throw new \InvalidArgumentException('В партии '.count($employees).' сотрудников; для одного PDF выберите не более 200. Никто не был исключён автоматически.'); }
        $cfg=$this->template($data['template']??[]); $sheet=$this->sheet($data['sheet']??[]); $placements=$data['placements']??[];
        $output=(string)($data['output']??'front'); $flip=(string)($data['duplex_flip']??'long');
        if (!in_array($output,['front','wallet_back','duplex'],true) || !in_array($flip,['long','short'],true)) { throw new \InvalidArgumentException('Неизвестный режим сторон/переворота'); }
        $byId=[]; foreach ($employees as $employee) { $byId[(int)$employee['id']]=$employee; }
        if (!$placements) { $placements=$this->automatic(array_keys($byId),$cfg,$sheet); }
        if (!is_array($placements) || count($placements)>200) { throw new \InvalidArgumentException('Не более 200 карточек'); }
        $pages=[]; $backs=[]; $cache=[]; $walletCache=[]; $placed=[];
        foreach ($placements as $item) {
            if (!is_array($item)) { throw new \InvalidArgumentException('Некорректное размещение карточки'); }
            $id=EmployeeService::id($item['id']??''); if (!isset($byId[$id])) { throw new \InvalidArgumentException('Размещение содержит не выбранного сотрудника'); }
            if (isset($placed[$id])) { throw new \InvalidArgumentException('ID '.$id.' размещён повторно'); } $placed[$id]=true;
            $page=filter_var($item['page']??0,FILTER_VALIDATE_INT); if ($page===false || $page<0 || $page>49) { throw new \InvalidArgumentException('Некорректный номер страницы (начиная с нуля)'); }
            $rotation=filter_var($item['rotation']??0,FILTER_VALIDATE_INT); if (!in_array($rotation,[0,90],true)) { throw new \InvalidArgumentException('Поворот: 0 или 90 градусов'); }
            $x=$this->number($item['x']??0,0,1000)+$sheet['offset_x']; $y=$this->number($item['y']??0,0,1000)+$sheet['offset_y'];
            $cw=$rotation ? $cfg['height_mm'] : $cfg['width_mm']; $ch=$rotation ? $cfg['width_mm'] : $cfg['height_mm'];
            if ($x<0 || $y<0 || $x+$cw>$sheet['width_mm']+0.001 || $y+$ch>$sheet['height_mm']+0.001) { throw new \InvalidArgumentException('Карточка выходит за физический лист'); }
            foreach ($pages[$page]??[] as $other) { if ($x<$other['x']+$other['w']-0.01 && $x+$cw>$other['x']+0.01 && $y<$other['y']+$other['h']-0.01 && $y+$ch>$other['y']+0.01) { throw new \InvalidArgumentException('Карточки перекрываются на странице '.($page+1)); } }
            // Dompdf already has DejaVu; embedding duplicate subsets in every SVG wastes memory.
            $svg=$output==='wallet_back' ? ($walletCache[$id]??=$this->walletSvg($id,$cfg,false)) : ($cache[$id]??=$this->svg($id,$cfg,false));
            if ($rotation) { $svg='<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="'.$cw.'mm" height="'.$ch.'mm" viewBox="0 0 '.$cw.' '.$ch.'"><g transform="translate('.$cfg['height_mm'].' 0) rotate(90)">'.$this->svgInner($svg).'</g></svg>'; }
            $pages[$page][]=['id'=>$id,'x'=>$x,'y'=>$y,'w'=>$cw,'h'=>$ch,'svg'=>$svg];
            if ($output==='duplex') {
                $back=$walletCache[$id]??=$this->walletSvg($id,$cfg,false);
                if ($rotation) { $back='<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="'.$cw.'mm" height="'.$ch.'mm" viewBox="0 0 '.$cw.' '.$ch.'"><g transform="translate('.$cfg['height_mm'].' 0) rotate(90)">'.$this->svgInner($back).'</g></svg>'; }
                $mirrorX=($sheet['width_mm']<=$sheet['height_mm'] && $flip==='long') || ($sheet['width_mm']>$sheet['height_mm'] && $flip==='short');
                // Mirror physical positions AFTER printer calibration offsets were applied.
                $backs[$page][]=['id'=>$id,'x'=>$mirrorX?$sheet['width_mm']-$x-$cw:$x,'y'=>$mirrorX?$y:$sheet['height_mm']-$y-$ch,'w'=>$cw,'h'=>$ch,'svg'=>$back];
            }
        }
        if (count($placed)!==count($byId)) { throw new \InvalidArgumentException('Не все выбранные сотрудники размещены на листах'); }
        if ($output==='duplex') { $paired=[]; for($page=0,$last=max(array_keys($pages));$page<=$last;$page++) { $paired[2*$page]=$pages[$page]??[]; $paired[2*$page+1]=$backs[$page]??[]; } $pages=$paired; }
        $directory=dirname(__DIR__,2).'/var/print';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) { throw new \RuntimeException('Не удалось подготовить PDF'); }
        $files=[];
        try {
        $html='<!doctype html><html><meta charset="UTF-8"><style>@page{margin:0}body{margin:0;padding:0;font-size:0}.page{position:relative;page-break-after:always}.page:last-child{page-break-after:auto}.badge{position:absolute;margin:0;padding:0;border:0}</style><body>';
        $last=max(array_keys($pages));
        for($page=0;$page<=$last;$page++) {
            $html.='<div class="page" style="width:'.$sheet['width_mm'].'mm;height:'.($sheet['height_mm']-0.05).'mm">';
            foreach ($pages[$page]??[] as $item) { $path=$this->pdfSvg($item['svg'],$directory,$files); $html.='<img class="badge" style="left:'.$item['x'].'mm;top:'.$item['y'].'mm;width:'.$item['w'].'mm;height:'.$item['h'].'mm" src="'.$this->escape($path).'">'; }
            if ($sheet['crop_marks']) { $marks=$this->cropMarks($pages[$page]??[],$sheet); $path=$this->pdfSvg($marks,$directory,$files); $html.='<img class="badge" style="left:0;top:0;width:'.$sheet['width_mm'].'mm;height:'.$sheet['height_mm'].'mm" src="'.$this->escape($path).'">'; }
            $html.='</div>';
        } $html.='</body></html>';
        $options=new Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans','chroot'=>dirname(__DIR__,2)]); $pdf=new Dompdf($options); $pdf->setPaper([0,0,$sheet['width_mm']*72/25.4,$sheet['height_mm']*72/25.4]); $pdf->loadHtml($html,'UTF-8'); $pdf->render();
        $this->employees->audit($actorId,'badges.export','print',null,null,['ids'=>array_keys($byId),'cards'=>count($placements),'pages'=>$last+1,'output'=>$output,'duplex_flip'=>$flip,'sheet'=>$sheet,'template'=>$this->safeTemplate($cfg)]);
        return $pdf->output();
        } finally { foreach($files as $file) { if (is_file($file)) { unlink($file); } } }
    }

    /** Small HTML references avoid base64 copies of every SVG in Dompdf's HTML parser. */
    private function pdfSvg(string $svg,string $directory,array &$files): string
    {
        // php-svg-lib does not apply the root viewBox scale for mm dimensions.
        $svg=preg_replace_callback('/^<svg\b[^>]*>/',static fn(array $root):string=>preg_replace('/\b(width|height)="([0-9.]+)mm"/','$1="$2"',$root[0]),$svg);
        // Its font surface requires the actual Unicode metrics path, not the CSS family alias.
        $font=dirname(__DIR__,2).'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans';
        if (!is_readable($font.'.ufm') || !is_readable($font.'-Bold.ufm')) { throw new \RuntimeException('Шрифты PDF не установлены'); }
        $svg=preg_replace_callback('/<text\b[^>]*>/',fn(array $tag):string=>str_replace('font-family="DejaVu Sans"','font-family="'.$this->escape($font.(str_contains($tag[0],'font-weight="bold"')?'-Bold':'')).'"',$tag[0]),$svg);
        $path=$directory.'/'.bin2hex(random_bytes(16)).'.svg';
        $files[]=$path;
        if (file_put_contents($path,$svg,LOCK_EX)!==strlen($svg)) { throw new \RuntimeException('Не удалось подготовить изображение PDF'); }
        chmod($path,0600);
        return $path;
    }

    /** At most 512 pixels gives over 500 dpi even at the largest 24 mm logo setting. */
    private function logoUri(): string
    {
        static $uri=null;
        if ($uri!==null) { return $uri; }
        $source=dirname(__DIR__,2).'/public/assets/logo.png';
        $image=imagecreatefrompng($source);
        if (!$image) { throw new \RuntimeException('Не удалось прочитать логотип'); }
        $width=imagesx($image); $height=imagesy($image); $scale=min(1,512/max($width,$height));
        $small=imagecreatetruecolor(max(1,(int)round($width*$scale)),max(1,(int)round($height*$scale)));
        imagealphablending($small,false); imagesavealpha($small,true);
        imagecopyresampled($small,$image,0,0,0,0,imagesx($small),imagesy($small),$width,$height);
        ob_start(); try { imagepng($small,null,6); $bytes=ob_get_contents(); } finally { ob_end_clean(); imagedestroy($small); imagedestroy($image); }
        if (!is_string($bytes) || $bytes==='') { throw new \RuntimeException('Не удалось подготовить логотип'); }
        return $uri='data:image/png;base64,'.base64_encode($bytes);
    }

    public function sheet(array $input=[]): array
    {
        $cfg=array_replace_recursive(['width_mm'=>210,'height_mm'=>297,'mode'=>'paper','margins'=>['left'=>10,'right'=>10,'top'=>10,'bottom'=>10],'gap_x'=>4,'gap_y'=>4,'offset_x'=>0,'offset_y'=>0,'crop_marks'=>false],$input);
        if (!in_array($cfg['mode'],['paper','labels'],true)) { throw new \InvalidArgumentException('Режим листа: paper или labels'); }
        foreach (['width_mm','height_mm'] as $key) { $cfg[$key]=$this->number($cfg[$key],50,1000); }
        foreach (['gap_x','gap_y'] as $key) { $cfg[$key]=$this->number($cfg[$key],0,50); }
        foreach (['offset_x','offset_y'] as $key) { $cfg[$key]=$this->number($cfg[$key],-20,20); }
        foreach (['left','right','top','bottom'] as $key) { $cfg['margins'][$key]=$this->number($cfg['margins'][$key],0,100); }
        if ($cfg['margins']['left']+$cfg['margins']['right'] >= $cfg['width_mm'] || $cfg['margins']['top']+$cfg['margins']['bottom'] >= $cfg['height_mm']) { throw new \InvalidArgumentException('Поля закрывают всю полезную область листа'); }
        $cfg['crop_marks']=EmployeeService::bool($cfg['crop_marks'])===1;
        if (isset($cfg['labels'])) {
            if (!is_array($cfg['labels'])) { throw new \InvalidArgumentException('Некорректная сетка этикеток'); }
            $labels=array_replace(['cell_width'=>60,'cell_height'=>90,'start_x'=>10,'start_y'=>10,'step_x'=>64,'step_y'=>94,'columns'=>3,'rows'=>3,'skip'=>[]],$cfg['labels']);
            foreach (['cell_width','cell_height','step_x','step_y'] as $key) { $labels[$key]=$this->number($labels[$key],1,1000); }
            foreach (['start_x','start_y'] as $key) { $labels[$key]=$this->number($labels[$key],0,1000); }
            foreach (['columns','rows'] as $key) { $number=filter_var($labels[$key],FILTER_VALIDATE_INT); if ($number===false || $number<1 || $number>100) { throw new \InvalidArgumentException('Число рядов/колонок этикеток: 1–100'); } $labels[$key]=$number; }
            if ($labels['columns']*$labels['rows']>400) { throw new \InvalidArgumentException('Не более 400 ячеек на листе'); }
            if (!is_array($labels['skip'])) { throw new \InvalidArgumentException('Пропущенные ячейки задаются массивом номеров с нуля'); }
            $skip=[]; foreach ($labels['skip'] as $index) { $index=filter_var($index,FILTER_VALIDATE_INT); if ($index===false || $index<0 || $index>=$labels['columns']*$labels['rows']) { throw new \InvalidArgumentException('Пропуск относится только к ячейкам первого листа'); } $skip[]=$index; }
            $labels['skip']=array_values(array_unique($skip)); $cfg['labels']=$labels;
        }
        if ($cfg['mode']==='labels' && !isset($cfg['labels'])) { throw new \InvalidArgumentException('Укажите сетку этикеток'); }
        return $cfg;
    }

    public function automatic(array $ids,array $template,array $sheet): array
    {
        if (($sheet['mode']??'paper')==='labels') {
            $l=$sheet['labels']; $slots=$l['columns']*$l['rows']; $skip=array_fill_keys($l['skip'],true); $slot=0; $out=[];
            if ($template['width_mm']>$l['cell_width'] || $template['height_mm']>$l['cell_height']) { throw new \InvalidArgumentException('Размер карточки больше ячейки этикетки'); }
            if ($l['step_x']<$l['cell_width'] || $l['step_y']<$l['cell_height']) { throw new \InvalidArgumentException('Шаг этикеток меньше размера ячейки'); }
            if ($l['start_x']+($l['columns']-1)*$l['step_x']+$l['cell_width']>$sheet['width_mm'] || $l['start_y']+($l['rows']-1)*$l['step_y']+$l['cell_height']>$sheet['height_mm']) { throw new \InvalidArgumentException('Сетка этикеток выходит за лист'); }
            foreach ($ids as $id) { while ($slot<$slots && isset($skip[$slot])) { ++$slot; } $local=$slot%$slots; $out[]=['id'=>$id,'page'=>intdiv($slot,$slots),'x'=>$l['start_x']+($local%$l['columns'])*$l['step_x']+($l['cell_width']-$template['width_mm'])/2,'y'=>$l['start_y']+intdiv($local,$l['columns'])*$l['step_y']+($l['cell_height']-$template['height_mm'])/2,'rotation'=>0]; ++$slot; }
            return $out;
        }
        $m=$sheet['margins']; $cols=(int)floor(($sheet['width_mm']-$m['left']-$m['right']+$sheet['gap_x'])/($template['width_mm']+$sheet['gap_x'])); $rows=(int)floor(($sheet['height_mm']-$m['top']-$m['bottom']+$sheet['gap_y'])/($template['height_mm']+$sheet['gap_y']));
        if ($cols<1 || $rows<1) { throw new \InvalidArgumentException('Карточка не помещается в полезной области листа'); }
        $out=[]; foreach ($ids as $i=>$id) { $local=$i%($cols*$rows); $out[]=['id'=>$id,'page'=>intdiv($i,$cols*$rows),'x'=>$m['left']+($local%$cols)*($template['width_mm']+$sheet['gap_x']),'y'=>$m['top']+intdiv($local,$cols)*($template['height_mm']+$sheet['gap_y']),'rotation'=>0]; } return $out;
    }

    public function templates(): array { return $this->stored('badge_templates'); }
    public function presets(): array { return $this->stored('sheet_presets'); }
    private function stored(string $table): array { return array_map(static function($row) { $row['config']=json_decode($row['config_json'],true,512,JSON_THROW_ON_ERROR); return $row; },$this->database->all('SELECT * FROM '.$table.' ORDER BY name')); }
    public function saveTemplate(array $data,int $actorId): array { return $this->save('badge_templates',$data,$this->template($data['template']??$data['config']??[]),$actorId); }
    public function savePreset(array $data,int $actorId): array { return $this->save('sheet_presets',$data,$this->sheet($data['sheet']??$data['config']??[]),$actorId); }
    private function save(string $table,array $data,array $config,int $actorId): array
    {
        $name=trim((string)($data['name']??'')); if ($name==='' || mb_strlen($name)>100) { throw new \InvalidArgumentException('Название шаблона: 1–100 символов'); }
        return $this->database->transaction(function() use($table,$data,$name,$config,$actorId) {
            $id=empty($data['id']) ? null : EmployeeService::id($data['id']); $before=$id ? $this->database->one('SELECT * FROM '.$table.' WHERE id=? FOR UPDATE',[$id]) : null;
            if ($id && !$before) { throw new NotFoundHttpException('Шаблон не найден'); }
            $values=['name'=>$name,'config_json'=>json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'updated_at'=>EmployeeService::utc()];
            if ($id) { $this->database->db->update($table,$values,['id'=>$id]); } else { $this->database->insert($table,$values); $id=(int)$this->database->db->lastInsertId(); }
            $this->employees->audit($actorId,'print.config.save',$table,$id,$before?['name'=>$before['name']]:null,['name'=>$name,'config'=>$this->safeTemplate($config)]);
            return ['id'=>$id,'name'=>$name,'config'=>$config];
        });
    }

    private function safeTemplate(array $cfg): array { if (!empty($cfg['bg_image'])) { $cfg['bg_image']='sha256:'.hash('sha256',$cfg['bg_image']); } return $cfg; }
    private function number(mixed $value,float $min,float $max): float { if (!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value<$min || (float)$value>$max) { throw new \InvalidArgumentException('Параметр вне диапазона '.$min.'–'.$max); } return round((float)$value,3); }
    private function color(string $value): string { if (!preg_match('/^#[0-9a-f]{6}$/iD',$value)) { throw new \InvalidArgumentException('Цвет задаётся как #RRGGBB'); } return strtolower($value); }
    private function imageData(string $uri): void { if (strlen($uri)>2800000 || !preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=\r\n]+)$#D',$uri,$match)) { throw new \InvalidArgumentException('Фон: локальный PNG/JPEG/WebP не более 2 MiB'); } $bytes=base64_decode($match[2],true); $size=$bytes===false?false:@getimagesizefromstring($bytes); if (!$size || $size[0]>2048 || $size[1]>2048 || !in_array($size['mime'],['image/png','image/jpeg','image/webp'],true)) { throw new \InvalidArgumentException('Некорректное изображение либо размер больше 2048 px'); } }
    private function image(string $uri,float $x,float $y,float $w,float $h,string $aspect='xMidYMid meet'): string { $uri=$this->escape($uri); return '<image x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" preserveAspectRatio="'.$aspect.'" href="'.$uri.'" xlink:href="'.$uri.'"/>'; }
    private function svgInner(string $svg): string { return preg_replace('/<\/svg>\s*$/','',preg_replace('/^.*?<svg\b[^>]*>/s','',$svg)); }
    private function cropMarks(array $items,array $sheet): string
    {
        $w=$sheet['width_mm']; $h=$sheet['height_mm']; $svg='<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'mm" height="'.$h.'mm" viewBox="0 0 '.$w.' '.$h.'"><g stroke="#64748b" stroke-width="0.15">';
        foreach ($items as $item) {
            foreach ([$item['x'],$item['x']+$item['w']] as $x) { foreach ([$item['y'],$item['y']+$item['h']] as $y) {
                $dx=$x===$item['x'] ? -1 : 1; $dy=$y===$item['y'] ? -1 : 1;
                $x1=max(0,min($w,$x+$dx*0.7)); $x2=max(0,min($w,$x+$dx*2)); $y1=max(0,min($h,$y+$dy*0.7)); $y2=max(0,min($h,$y+$dy*2));
                $svg.='<line x1="'.$x1.'" y1="'.$y.'" x2="'.$x2.'" y2="'.$y.'"/><line x1="'.$x.'" y1="'.$y1.'" x2="'.$x.'" y2="'.$y2.'"/>';
            } }
        } return $svg.'</g></svg>';
    }
    private function escape(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_XML1|ENT_SUBSTITUTE,'UTF-8'); }
    private function textBlock(string $text,float $x,float $y,float $width,float $font,int $maxLines,string $color,bool $bold,?float &$end): string
    {
        $lines=[];
        for($attempt=0;$attempt<40;$attempt++) {
            $lines=[]; $current=''; $overflow=false;
            foreach (preg_split('/\s+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if ($this->textWidth($word,$font,$bold)>$width) { $overflow=true; break; }
                if ($current!=='' && $this->textWidth($current.' '.$word,$font,$bold)>$width) { $lines[]=$current; $current=$word; } else { $current=trim($current.' '.$word); }
            }
            if ($current!=='') { $lines[]=$current; }
            if (!$overflow && count($lines)<= $maxLines && ($text==='' || $lines)) { break; }
            $font-=0.15; if ($font<1.8) { throw new \InvalidArgumentException('Текст не помещается в шаблон: '.$text); }
        }
        if ($overflow || count($lines)>$maxLines || ($text!=='' && !$lines)) { throw new \InvalidArgumentException('Текст не помещается в шаблон'); }
        $svg=''; $end=$y;
        foreach ($lines as $i=>$line) { $end=$y+$i*$font*1.28; $length=round($this->textWidth($line,$font,$bold),3); $svg.='<text x="'.$x.'" y="'.$end.'" text-anchor="middle" font-family="DejaVu Sans" font-size="'.$font.'" textLength="'.$length.'" lengthAdjust="spacingAndGlyphs"'.($bold?' font-weight="bold"':'').' fill="'.$color.'">'.$this->escape($line).'</text>'; }
        return $svg;
    }

    /** High resolution metrics avoid clipping wide Cyrillic names; SVG fixes the same line width in browser images/canvas. */
    private function textWidth(string $text,float $font,bool $bold): float
    {
        static $metrics=[];
        $key=($bold?'bold:':'regular:').$text;
        if (!isset($metrics[$key])) {
            $file=dirname(__DIR__,2).'/public/assets/DejaVuSans'.($bold?'-Bold':'').'.ttf';
            if (!is_readable($file) || !function_exists('imagettfbbox')) { throw new \RuntimeException('Шрифт печати DejaVu Sans или модуль GD не установлен'); }
            $box=imagettfbbox(300,0,$file,$text);
            if ($box===false) { throw new \RuntimeException('Не удалось измерить текст пропуска'); }
            // GD interprets font size in points at 96 dpi, whereas SVG uses its viewBox units.
            $metrics[$key]=(max($box[0],$box[2],$box[4],$box[6])-min($box[0],$box[2],$box[4],$box[6]))/400;
        }
        return $metrics[$key]*$font;
    }

    /** Embed only used glyphs: the same local typeface is available to SVG preview, PNG and PDF. */
    private function fontStyles(string $text): string
    {
        $characters=array_unique(mb_str_split($text)); sort($characters,SORT_STRING); $glyphs=implode('',$characters);
        $directory=dirname(__DIR__,2).'/var/fonts/badges';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) { throw new \RuntimeException('Не удалось создать кэш шрифтов пропуска'); }
        $css='<style>';
        foreach ([false,true] as $bold) {
            $source=dirname(__DIR__,2).'/public/assets/DejaVuSans'.($bold?'-Bold':'').'.ttf';
            if (!is_readable($source)) { throw new \RuntimeException('Шрифт пропуска не установлен'); }
            // Versioned keys leave earlier write-only/corrupt cache files untouched and unused.
            $cache=$directory.'/'.hash('sha256','subset-rw-v2|'.$source.'|'.filemtime($source).'|'.$glyphs).'.ttf';
            $lock=fopen($cache.'.lock','c');
            if ($lock===false) { throw new \RuntimeException('Не удалось открыть кэш шрифта'); }
            $temporary=null;
            try {
                if (!flock($lock,LOCK_EX)) { throw new \RuntimeException('Не удалось заблокировать кэш шрифта'); }
                $limit=min((int)filesize($source),262144);
                if (!$this->validFontSubset($cache,$limit)) {
                    $temporary=tempnam($directory,'subset_');
                    if ($temporary===false) { throw new \RuntimeException('Не удалось создать поднабор шрифта'); }
                    $font=null;
                    set_error_handler(static function(int $severity,string $message):never { throw new \RuntimeException('Не удалось построить поднабор шрифта'); },E_WARNING);
                    try {
                        $font=Font::load($source);
                        if (!$font) { throw new \RuntimeException('Некорректный файл шрифта'); }
                        $font->parse(); $font->setSubset($glyphs); $font->reduce();
                        // encode() rereads generated tables for their checksums; wb is not sufficient.
                        if (!$font->open($temporary,BinaryStream::modeReadWrite)) { throw new \RuntimeException('Не удалось открыть поднабор шрифта'); }
                        $font->encode(['OS/2']);
                    } finally { if ($font) { $font->close(); } restore_error_handler(); unset($font); gc_collect_cycles(); }
                    clearstatcache(true,$temporary);
                    if (!$this->validFontSubset($temporary,$limit) || !rename($temporary,$cache)) { throw new \RuntimeException('Не удалось сохранить корректный поднабор шрифта'); }
                    $temporary=null; chmod($cache,0600);
                }
                $bytes=file_get_contents($cache);
                if ($bytes===false) { throw new \RuntimeException('Не удалось прочитать поднабор шрифта'); }
                $css.='@font-face{font-family:"DejaVu Sans";font-style:normal;font-weight:'.($bold?'700':'400').';src:url("data:font/ttf;base64,'.base64_encode($bytes).'") format("truetype");}';
            } finally { if (is_string($temporary) && is_file($temporary)) { unlink($temporary); } flock($lock,LOCK_UN); fclose($lock); }
        }
        return $css.'</style>';
    }

    /** Bounds and checksums are checked before embedding, including cache hits. */
    private function validFontSubset(string $path,int $limit): bool
    {
        clearstatcache(true,$path);
        if (!is_file($path) || ($size=filesize($path))===false || $size<172 || $size>$limit) { return false; }
        $bytes=file_get_contents($path);
        if ($bytes===false || strlen($bytes)!==$size || substr($bytes,0,4)!=="\x00\x01\x00\x00") { return false; }
        $count=unpack('n',substr($bytes,4,2))[1];
        if ($count<1 || $count>64 || 12+$count*16>$size) { return false; }
        $tags=[];
        for($index=0;$index<$count;$index++) {
            $entry=unpack('a4tag/Nchecksum/Noffset/Nlength',substr($bytes,12+$index*16,16));
            if (isset($tags[$entry['tag']]) || $entry['offset']<12+$count*16 || $entry['offset']%4!==0 || $entry['length']>$size || $entry['offset']>$size-$entry['length']) { return false; }
            $table=substr($bytes,$entry['offset'],$entry['length']);
            if ($entry['tag']==='head') { if (strlen($table)<54 || substr($table,12,4)!=="\x5f\x0f\x3c\xf5") { return false; } $table=substr_replace($table,"\0\0\0\0",8,4); }
            $padded=str_pad($table,(int)ceil(strlen($table)/4)*4,"\0");
            $checksum=$padded===''?0:(array_sum(unpack('N*',$padded)) & 0xffffffff);
            if ($checksum!==$entry['checksum']) { return false; }
            $tags[$entry['tag']]=true;
        }
        foreach(['head','hhea','maxp','hmtx','cmap','loca','glyf','name','post','OS/2'] as $required) { if (!isset($tags[$required])) { return false; } }
        return true;
    }
}
