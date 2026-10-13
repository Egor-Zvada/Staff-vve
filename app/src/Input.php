<?php
declare(strict_types=1);
namespace App;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
final class Input
{
    public static function data(Request $request):array
    {
        if(str_contains($request->headers->get('Content-Type',''),'application/json')){
            try{$data=json_decode($request->getContent(),true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new BadRequestHttpException('Некорректный JSON');}
            if(!is_array($data))throw new BadRequestHttpException('Ожидается объект');return $data;
        }
        return $request->request->all();
    }
}
