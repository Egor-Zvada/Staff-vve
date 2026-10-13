<?php
declare(strict_types=1);

namespace App\Controller;

use App\Input;
use App\Web;
use App\Infrastructure\Database;
use App\Security\Auth;
use App\Service\BadgeService;
use App\Service\EmployeeService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PrintController
{
    public function __construct(private Web $web,private Auth $auth,private BadgeService $badges,private EmployeeService $employees,private Database $database) {}

    public function index(Request $request): Response
    {
        $this->auth->requireRole('MODERATOR'); $filters=$request->query->all(); $ids=$request->query->get('ids');
        $selected=$ids!==null && $ids!=='' ? $this->badges->selected($ids) : [];
        return $this->web->render('admin/print.html.twig',['employees'=>$this->employees->list($filters+['active'=>'all']),'selected'=>$selected,'ids'=>array_column($selected,'id'),'groups'=>$this->database->all('SELECT id,name FROM schedule_groups WHERE active=1 ORDER BY name'),'templates'=>$this->badges->templates(),'presets'=>$this->badges->presets(),'template'=>$this->badges->defaults(),'sheet'=>$this->badges->sheet(),'filters'=>$filters]);
    }
    public function qr(Request $request): Response
    {
        $this->auth->requireRole('MODERATOR'); $id=EmployeeService::id($request->attributes->get('id')); $format=$request->query->get('format','svg')==='png' ? 'png' : 'svg';
        return new Response($this->badges->qr($id,$format),200,['Content-Type'=>$format==='png'?'image/png':'image/svg+xml','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function badgeSvg(Request $request): Response
    {
        $this->auth->requireRole('MODERATOR'); $id=EmployeeService::id($request->attributes->get('id'));
        if ($request->isMethod('POST')) { $this->auth->checkCsrf($request); $body=Input::data($request); $config=$body['template']??[]; if (is_string($config)) { $config=$this->config($config); } if (!is_array($config)) { throw new \InvalidArgumentException('Некорректный макет'); } }
        else { $config=$this->config((string)$request->query->get('template','{}')); }
        $employee=$this->employees->find($id) ?? throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Сотрудник не найден');
        return new Response($this->badges->svg($id,$config),200,['Content-Type'=>'image/svg+xml; charset=UTF-8','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','Content-Disposition'=>'inline; filename="badge_'.$employee['pass_code'].'.svg"']);
    }
    public function pdf(Request $request): Response
    {
        $actor=$this->auth->requireRole('MODERATOR'); $this->auth->checkCsrf($request); $data=Input::data($request);
        foreach(['template','sheet','placements','ids'] as $key) { if (isset($data[$key]) && is_string($data[$key]) && in_array($key,['template','sheet','placements'],true)) { $data[$key]=$this->config($data[$key]); } }
        if (($data['output']??'front')!=='front') { $this->auth->requireRole('ADMIN'); }
        $pdf=$this->badges->pdf($data,(int)$actor['id']);
        return new Response($pdf,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="staff_badges.pdf"','Cache-Control'=>'private, no-store']);
    }
    public function walletBack(Request $request): Response
    {
        $this->auth->requireRole('ADMIN'); $id=EmployeeService::id($request->attributes->get('id'));
        if ($request->isMethod('POST')) { $this->auth->checkCsrf($request); $body=Input::data($request); $config=$body['template']??[]; if (is_string($config)) { $config=$this->config($config); } if (!is_array($config)) { throw new \InvalidArgumentException('Некорректный макет'); } }
        else { $config=$this->config((string)$request->query->get('template','{}')); }
        $employee=$this->employees->find($id) ?? throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Сотрудник не найден');
        return new Response($this->badges->walletSvg($id,$config),200,['Content-Type'=>'image/svg+xml; charset=UTF-8','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','Content-Disposition'=>'inline; filename="badge_wallet_'.$employee['pass_code'].'.svg"']);
    }
    public function templateSave(Request $request): Response { $actor=$this->auth->requireRole('ADMIN'); $this->auth->checkCsrf($request); $data=Input::data($request); foreach(['config','template'] as $key) { if (isset($data[$key]) && is_string($data[$key])) { $data[$key]=$this->config($data[$key]); } } return $this->web->json(['success'=>true,'template'=>$this->badges->saveTemplate($data,(int)$actor['id'])]); }
    public function presetSave(Request $request): Response { $actor=$this->auth->requireRole('ADMIN'); $this->auth->checkCsrf($request); $data=Input::data($request); foreach(['config','sheet'] as $key) { if (isset($data[$key]) && is_string($data[$key])) { $data[$key]=$this->config($data[$key]); } } return $this->web->json(['success'=>true,'preset'=>$this->badges->savePreset($data,(int)$actor['id'])]); }
    private function config(string $json): array { if(strlen($json)>3000000) { throw new \InvalidArgumentException('Настройки слишком велики'); } try { $data=json_decode($json,true,64,JSON_THROW_ON_ERROR); } catch(\JsonException) { throw new \InvalidArgumentException('Некорректный JSON макета'); } if (!is_array($data)) { throw new \InvalidArgumentException('Ожидаются настройки макета'); } return $data; }
}
