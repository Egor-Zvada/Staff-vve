<?php
declare(strict_types=1);

namespace App\Controller;

use App\Input;
use App\Security\Auth;
use App\Service\EmployeeService;
use App\Service\LeaveService;
use App\Web;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LeaveController
{
    public function __construct(private readonly LeaveService $leaves, private readonly EmployeeService $employees, private readonly Auth $auth, private readonly Web $web) {}

    public function index(Request $request): Response
    {
        $actor = $this->auth->requireRole('MODERATOR');
        $employeeId = $request->query->get('employee_id', '') === '' ? null : EmployeeService::id($request->query->get('employee_id'));
        $state = (string) $request->query->get('state', 'active');
        if (!in_array($state, ['active', 'revoked', 'all'], true)) {
            throw new \DomainException('Неизвестный фильтр отпусков.', 400);
        }
        $edit = null;
        if ($actor['admin_role'] === 'ADMIN' && $request->query->get('edit', '') !== '') {
            $edit = $this->leaves->find(EmployeeService::id($request->query->get('edit')));
        }
        return $this->web->render('admin/leaves.html.twig', ['employees' => $this->employees->list(['active' => 'all']), 'leaves' => $this->leaves->listing(['employee_id' => $employeeId, 'state' => $state]), 'edit' => $edit, 'filter_employee_id' => $employeeId, 'filter_state' => $state]);
    }

    public function save(Request $request): Response
    {
        $actor = $this->auth->requireRole('ADMIN');
        $this->auth->checkCsrf($request);
        $leave = $this->leaves->save(Input::data($request), (int) $actor['id']);
        return $this->web->json(['success' => true, 'leave' => $leave, 'redirect' => '/admin/leaves?employee_id='.$leave['employee_id']]);
    }

    public function revoke(Request $request): Response
    {
        $actor = $this->auth->requireRole('ADMIN');
        $this->auth->checkCsrf($request);
        $leave = $this->leaves->revoke(Input::data($request), (int) $actor['id']);
        return $this->web->json(['success' => true, 'leave' => $leave, 'redirect' => '/admin/leaves?employee_id='.$leave['employee_id'].'&state=all']);
    }
}
