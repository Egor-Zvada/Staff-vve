<?php
declare(strict_types=1);
namespace App;
use App\Security\Auth;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Twig\Environment;
final class Web
{
    public function __construct(private Environment $twig,private Auth $auth,private Settings $settings){}
    public function render(string $template,array $data=[]):Response{return new Response($this->twig->render($template,$data+['user'=>$this->auth->user(),'csrf'=>$this->auth->csrf(),'settings'=>$this->settings->all(),'asset_version'=>$this->assetVersion()]));}
    private function assetVersion():string
    {
        $hash=hash_init('sha256');
        foreach(['app.css','app.js','print.js','dashboard.js','employee-search.js']as$file){hash_update_file($hash,dirname(__DIR__).'/public/assets/'.$file);}
        return substr(hash_final($hash),0,16);
    }
    public function json(array $data,int $status=200):JsonResponse{return new JsonResponse($data,$status,[],false);}
    public function redirect(string $url):RedirectResponse{return new RedirectResponse($url);}
}
