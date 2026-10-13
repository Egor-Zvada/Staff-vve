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
    public function render(string $template,array $data=[]):Response{return new Response($this->twig->render($template,$data+['user'=>$this->auth->user(),'csrf'=>$this->auth->csrf(),'settings'=>$this->settings->all()]));}
    public function json(array $data,int $status=200):JsonResponse{return new JsonResponse($data,$status,[],false);}
    public function redirect(string $url):RedirectResponse{return new RedirectResponse($url);}
}
