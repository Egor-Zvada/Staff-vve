<?php
declare(strict_types=1);
namespace App;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'=>'%env(APP_SECRET)%', 'default_locale'=>'ru', 'router'=>['utf8'=>true],
            'trusted_hosts'=>['^staff\\.vve\\.local$','^staff$','^192\\.168\\.110\\.202$','^192\\.168\\.110\\.70$','^localhost$','^127\\.0\\.0\\.1$'],
            'session'=>['name'=>$this->getEnvironment()==='qa'?'STAFFV2QASESSID':'STAFFV2SESSID','storage_factory_id'=>'session.storage.factory.native','save_path'=>'%kernel.project_dir%/var/sessions','cookie_secure'=>true,'cookie_httponly'=>true,'cookie_samesite'=>'lax','gc_maxlifetime'=>28800],
            'php_errors'=>['log'=>true], 'http_method_override'=>false,
        ]);
        $container->extension('twig',['default_path'=>'%kernel.project_dir%/templates','strict_variables'=>false]);
        $services=$container->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\', '../src/')->exclude('../src/{Kernel.php,Input.php}');
        $services->load('App\\Controller\\','../src/Controller/')->tag('controller.service_arguments')->public();
        $services->set(Infrastructure\ExceptionListener::class)->tag('kernel.event_subscriber');
        $services->set(Infrastructure\Database::class)->arg('$url','%env(DATABASE_URL)%');
    }
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $definitions=[
            ['scanner','/',['GET'],'RootController','scanner'],['login','/login',['GET','POST'],'RootController','login'],['logout','/logout',['POST'],'RootController','logout'],
            ['mark','/api/mark',['POST'],'RootController','mark'],['manual','/manual',['GET'],'RootController','manual'],['day','/api/day',['GET'],'RootController','day'],['manual_save','/api/manual',['POST'],'RootController','manualSave'],['clock','/api/clock',['GET'],'RootController','clock'],
            ['enroll_login','/api/enrollment/login',['POST'],'RootController','enrollmentLogin'],['enroll_people','/api/enrollment/employees',['GET'],'RootController','enrollmentEmployees'],['enroll_bind','/api/enrollment/bind',['POST'],'RootController','enrollmentBind'],
            ['enroll_wallet_status','/api/enrollment/wallet/{id}',['GET'],'WalletController','enrollmentStatus'],['enroll_wallet_issue','/api/enrollment/wallet/{id}',['POST'],'WalletController','enrollmentIssue'],['enroll_wallet_qr','/api/enrollment/wallet/{id}/qr',['GET'],'WalletController','enrollmentQr'],
            ['wallet_page','/admin/wallet',['GET'],'WalletController','index'],['wallet_revoke','/admin/wallet/revoke',['POST'],'WalletController','revoke'],['wallet_usage','/admin/wallet/usage',['GET'],'WalletController','usage'],['wallet_status','/admin/wallet/status',['GET'],'WalletController','status'],['wallet_issue','/admin/wallet/issue',['POST'],'WalletController','issue'],['wallet_qr','/admin/wallet/qr/{id}',['GET'],'WalletController','qr'],['wallet_pkpass','/admin/wallet/pkpass/{id}',['GET'],'WalletController','pkpass'],['wallet_recover','/admin/wallet/recover',['POST'],'WalletController','recover'],
            ['admin','/admin',['GET'],'AdminController','index'],['employees','/admin/employees',['GET'],'AdminController','employees'],['employee_save','/admin/employees/save',['POST'],'AdminController','employeeSave'],['employee_deactivate','/admin/employees/deactivate',['POST'],'AdminController','employeeDeactivate'],
            ['employee_rotate_passcode','/admin/employees/rotate-passcode',['POST'],'AdminController','employeeRotatePasscode'],
            ['schedules','/admin/schedules',['GET'],'AdminController','schedules'],['schedule_save','/admin/schedules/save',['POST'],'AdminController','scheduleSave'],['assignment_save','/admin/schedules/assign',['POST'],'AdminController','assignmentSave'],['calendar_save','/admin/schedules/calendar',['POST'],'AdminController','calendarSave'],
            ['leaves','/admin/leaves',['GET'],'LeaveController','index'],['leave_save','/admin/leaves/save',['POST'],'LeaveController','save'],['leave_revoke','/admin/leaves/revoke',['POST'],'LeaveController','revoke'],
            ['settings','/admin/settings',['GET'],'AdminController','settings'],['settings_save','/admin/settings',['POST'],'AdminController','settingsSave'],
            ['credentials','/admin/credentials',['GET'],'AdminController','credentials'],['credential_save','/admin/credentials/save',['POST'],'AdminController','credentialSave'],['credential_revoke','/admin/credentials/revoke',['POST'],'AdminController','credentialRevoke'],
            ['imports','/admin/import',['GET'],'AdminController','imports'],['import_preview','/admin/import/preview',['POST'],'AdminController','importPreview'],['import_commit','/admin/import/commit',['POST'],'AdminController','importCommit'],['export','/admin/export',['GET'],'AdminController','export'],['help','/admin/help',['GET'],'AdminController','help'],['status','/admin/status',['GET'],'AdminController','status'],
            ['attendance','/admin/attendance',['GET'],'RootController','attendance'],['admin_day','/api/admin/day',['GET'],'RootController','adminDay'],['admin_manual','/api/admin/manual',['POST'],'RootController','adminManual'],
            ['print','/admin/print',['GET'],'PrintController','index'],['qr','/admin/print/qr/{id}',['GET'],'PrintController','qr'],['badge','/admin/print/badge/{id}',['GET','POST'],'PrintController','badgeSvg'],['print_pdf','/admin/print/pdf',['POST'],'PrintController','pdf'],['print_template','/admin/print/template',['POST'],'PrintController','templateSave'],['print_preset','/admin/print/preset',['POST'],'PrintController','presetSave'],
            ['wallet_back','/admin/print/wallet-back/{id}',['GET','POST'],'PrintController','walletBack'],
        ];
        foreach($definitions as [$name,$path,$methods,$controller,$method])$routes->add($name,$path)->controller(['App\\Controller\\'.$controller,$method])->methods($methods);
        $private=$_SERVER['PRIVATE_PATH'] ?? $_ENV['PRIVATE_PATH'] ?? '/_owner-disabled';
        if(!preg_match('~^/[a-zA-Z0-9_-]{12,80}$~',$private))$private='/_owner-disabled';
        $routes->add('owner',$private)->controller([Controller\OwnerController::class,'index'])->methods(['GET','POST']);
        $routes->add('owner_adjust',$private.'/adjust')->controller([Controller\OwnerController::class,'adjust'])->methods(['POST']);
        $routes->add('owner_week',$private.'/week')->controller([Controller\OwnerController::class,'weekSave'])->methods(['POST']);
        $routes->add('owner_automation_save',$private.'/automation/save')->controller([Controller\OwnerController::class,'automationSave'])->methods(['POST']);
        $routes->add('owner_automation_toggle',$private.'/automation/toggle')->controller([Controller\OwnerController::class,'automationToggle'])->methods(['POST']);
        $routes->add('owner_logout',$private.'/logout')->controller([Controller\OwnerController::class,'logout'])->methods(['POST']);
        $routes->add('health','/health')->controller([Controller\RootController::class,'health'])->methods(['GET']);
    }
}
