<?php
declare(strict_types=1);
namespace App\Infrastructure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
final class ExceptionListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents():array{return ['kernel.exception'=>['error',-10],'kernel.response'=>'headers'];}
    public function error(ExceptionEvent $event):void
    {
        $error=$event->getThrowable();$request=$event->getRequest();$status=$error instanceof HttpExceptionInterface?$error->getStatusCode():($error instanceof \InvalidArgumentException?422:500);
        if(($error instanceof \RuntimeException||$error instanceof \DomainException)&&in_array($error->getCode(),[400,403,404,409,422,429],true))$status=$error->getCode();
        $message=$status===500?'Не удалось выполнить запрос. Повторите позже.':$error->getMessage();
        if($status===500)error_log(get_class($error).': '.$error->getMessage().' in '.$error->getFile().':'.$error->getLine());
        if(str_starts_with($request->getPathInfo(),'/api/')||str_contains($request->headers->get('Accept',''),'application/json')||str_contains($request->headers->get('Content-Type',''),'application/json')){$event->setResponse(new JsonResponse(['success'=>false,'error'=>$message],$status));return;}
        if($status===403&&str_starts_with($request->getPathInfo(),'/admin')){$event->setResponse(new RedirectResponse('/login'));return;}
        $safe=htmlspecialchars($message,ENT_QUOTES,'UTF-8');$event->setResponse(new Response('<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Staff</title><body style="font:16px system-ui;padding:3rem;max-width:700px"><h1>Запрос не выполнен</h1><p>'.$safe.'</p><p><a href="/admin">Админка</a> · <a href="/">Отметка</a></p></body></html>',$status));
    }
    public function headers(ResponseEvent $event):void
    {
        $h=$event->getResponse()->headers;$h->set('X-Content-Type-Options','nosniff');$h->set('Referrer-Policy','same-origin');$h->set('X-Frame-Options','DENY');$h->set('Permissions-Policy','camera=(), microphone=(), geolocation=()');$h->set('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");$h->set('Cache-Control','no-store, private');
    }
}
