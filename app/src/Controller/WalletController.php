<?php
declare(strict_types=1);

namespace App\Controller;

use App\Input;
use App\Security\Auth;
use App\Service\EmployeeService;
use App\Service\WalletService;
use App\Web;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WalletController
{
    public function __construct(private readonly WalletService $wallet, private readonly Auth $auth, private readonly Web $web, private readonly EmployeeService $employees) {}

    public function index(Request $request): Response { $this->auth->requireRole('ADMIN'); return $this->web->render('admin/wallet.html.twig', ['employees' => $this->employees->list(['active' => 'all']), 'wallets' => $this->wallet->listing(), 'quota' => $this->wallet->usage()]); }
    public function revoke(Request $request): Response { $actor = $this->auth->requireRole('ADMIN'); $this->auth->checkCsrf($request); $data = Input::data($request); $result = $this->wallet->revoke(EmployeeService::id($data['employee_id'] ?? ''), (int) $actor['id']); return $this->web->json($result, isset($result['code']) ? (int) $result['code'] : 200); }

    public function enrollmentStatus(Request $request): Response { $this->enrollmentActor($request); return $this->web->json($this->enrollmentUrls($this->wallet->cached($this->id($request)))); }
    public function enrollmentIssue(Request $request): Response { $actor = $this->enrollmentActor($request); $this->auth->checkCsrf($request); $result = $this->enrollmentUrls($this->wallet->issue($this->id($request), (int) $actor['id'])); return $this->web->json($result, isset($result['code']) ? (int) $result['code'] : 200); }
    public function enrollmentQr(Request $request): Response { $this->enrollmentActor($request); return $this->svg($this->wallet->qr($this->id($request))); }
    public function usage(Request $request): Response { $this->auth->requireRole('ADMIN'); return $this->web->json(['success' => true, 'quota' => $this->wallet->refreshUsage(true)]); }
    public function status(Request $request): Response { $this->auth->requireRole('ADMIN'); return $this->web->json($this->wallet->cachedBatch($this->ids($request->query->get('ids', '')))); }
    public function issue(Request $request): Response { $actor = $this->auth->requireRole('ADMIN'); $this->auth->checkCsrf($request); return $this->web->json($this->wallet->issueBatch($this->ids(Input::data($request)['ids'] ?? []), (int) $actor['id'])); }
    public function qr(Request $request): Response { $this->auth->requireRole('ADMIN'); return $this->svg($this->wallet->qr($this->id($request))); }
    public function pkpass(Request $request): Response { $this->auth->requireRole('ADMIN'); $id = $this->id($request); $display = $this->wallet->cached($id)['display_id']; return new Response($this->wallet->pkpass($id), 200, ['Content-Type' => 'application/vnd.apple.pkpass', 'Content-Disposition' => 'attachment; filename="staff_'.$display.'.pkpass"', 'Cache-Control' => 'private, no-store']); }
    public function recover(Request $request): Response
    {
        $actor = $this->auth->requireRole('ADMIN'); $this->auth->checkCsrf($request); $data = Input::data($request);
        $id = EmployeeService::id($data['employee_id'] ?? '');
        if (isset($data['response']) && is_array($data['response'])) { $pass = $data['response']; }
        else {
            $file = $request->files->get('pkpass');
            if (!$file || !$file->isValid() || $file->getSize() > 10485760) { throw new \InvalidArgumentException('Загрузите ранее выданный .pkpass или сохранённый JSON ответа API.'); }
            $pass = ['serialNumber' => $data['serial_number'] ?? '', 'shareUrl' => $data['share_url'] ?? '', 'googleSaveUrl' => $data['google_save_url'] ?? '', 'applePass' => base64_encode($file->getContent())];
        }
        return $this->web->json($this->wallet->recover($id, (int) $actor['id'], $pass));
    }

    private function enrollmentActor(Request $request): array { $user = $this->auth->user(); return $user !== null && $user['admin_role'] === 'ADMIN' ? $user : $this->auth->enrollment($request); }
    private function id(Request $request): int { return EmployeeService::id($request->attributes->get('id')); }
    private function ids(mixed $value): array { if (is_string($value)) { return preg_split('/[,;\s]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []; } if (!is_array($value)) { throw new \InvalidArgumentException('Ожидается список ID.'); } return $value; }
    private function svg(string $bytes): Response { return new Response($bytes, 200, ['Content-Type' => 'image/svg+xml; charset=UTF-8', 'Cache-Control' => 'private, no-store']); }
    private function enrollmentUrls(array $result): array { if ($result['state'] === 'ready') { $result['qr_url'] = '/api/enrollment/wallet/'.$result['employee_id'].'/qr'; unset($result['pkpass_url']); } return $result; }
}
