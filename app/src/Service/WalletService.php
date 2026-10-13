<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Throwable;

final class WalletService
{
    private const HARD_LIMIT = 1000;
    private readonly Closure $clock;

    public function __construct(private readonly Database $db, private readonly WalletProvider $provider, private readonly WalletPassValidator $validator, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function displayId(int $id): string { return str_pad((string) $id, 3, '0', STR_PAD_LEFT); }

    /** Authorized card fields only: full name and pass code, with no employment metadata. */
    public static function passPayload(int $id, array $employee): array
    {
        $display = IdentifierService::codeOf(['id' => $id] + $employee);
        $name = trim(implode(' ', array_filter(array_map(static fn (string $field): string => trim((string) ($employee[$field] ?? '')), ['surname', 'given_name', 'patronymic']), static fn (string $part): bool => $part !== '')));
        return ['barcodeValue' => $display, 'barcodeFormat' => 'QR', 'barcodeAltText' => $display, 'logoText' => 'ПРОПУСК', 'organizationName' => 'ОГАУ ДО"СШ ВВЕ"', 'primaryFields' => [['label' => 'ФИО', 'value' => $name !== '' ? $name : $display]], 'secondaryFields' => [['label' => 'ID', 'value' => $display]], 'colorPreset' => 'blue', 'sharingProhibited' => true, 'credentials' => 'platform'];
    }

    public function cached(int $id): array
    {
        $this->employee($id);
        $row = $this->db->one('SELECT employee_id, display_id, display_name, state, request_id, serial_number, share_url, google_save_url, last_error, created_at, updated_at FROM wallet_passes WHERE employee_id = ?', [$id]);
        return $this->result($id, $row, true);
    }

    public function cachedShareUrl(int $id): ?string
    {
        $this->employee($id);
        return $this->db->scalar('SELECT share_url FROM wallet_passes WHERE employee_id = ? AND state = ?', [$id, 'ready']) ?: null;
    }

    public function issue(int $id, int $actorId): array
    {
        $employee = $this->employee($id);
        $existing = $this->db->one('SELECT * FROM wallet_passes WHERE employee_id = ?', [$id]);
        if ($existing !== null && $existing['state'] === 'ready') { return $this->result($id, $existing, true); }
        if ($existing !== null && in_array($existing['state'], ['issuing', 'ambiguous', 'revoking', 'revoke_unknown'], true)) { return $this->blockedResult($id, $existing); }
        if (!(bool) $employee['active']) { throw new DomainException('Новый Wallet-пропуск можно выдать только действующему сотруднику.', 409); }
        if (!$this->provider->configured()) { throw new DomainException('Wallet API пока не настроен.', 409); }
        $this->refreshUsage();
        // Commit the reservation before any network write: a process crash must never erase it.
        $reservation = $this->db->transaction(function () use ($id, $actorId): array {
            $this->db->one('SELECT id FROM wallet_provider_usage WHERE id = 1'.$this->lock());
            $employee = $this->employee($id, true);
            $payload = self::passPayload($id, $employee);
            $row = $this->db->one('SELECT * FROM wallet_passes WHERE employee_id = ?'.$this->lock(), [$id]);
            if ($row !== null && $row['state'] === 'ready') { return ['cached' => true, 'row' => $row]; }
            if ($row !== null && in_array($row['state'], ['issuing', 'ambiguous', 'revoking', 'revoke_unknown'], true)) { return ['blocked' => true, 'row' => $row]; }
            $quota = $this->usage();
            if ($quota['stale']) { throw new DomainException('Не удалось проверить квоту Wallet. Сохранённые пропуска доступны; новую выдачу повторите после восстановления связи.', 409); }
            if ($quota['remaining'] < 1) { throw new DomainException('Свободная квота Wallet исчерпана. Новые пропуска не создаются.', 429); }
            $requestId = bin2hex(random_bytes(20)); $now = $this->stamp();
            if ($row !== null && $row['state'] === 'revoked') {
                // A new issuance archives the entire previous signed pass, never just its serial.
                $this->db->insert('wallet_pass_history', ['employee_id' => $id, 'request_id' => $row['request_id'], 'display_name' => $row['display_name'], 'serial_number' => $row['serial_number'], 'share_url' => $row['share_url'], 'google_save_url' => $row['google_save_url'], 'apple_pass' => $row['apple_pass'], 'state' => 'revoked', 'actor_employee_id' => $actorId, 'created_at' => $row['created_at'], 'archived_at' => $now]);
            }
            $values = ['display_id' => $payload['barcodeValue'], 'display_name' => $payload['primaryFields'][0]['value'], 'state' => 'issuing', 'request_id' => $requestId, 'serial_number' => null, 'share_url' => null, 'google_save_url' => null, 'apple_pass' => null, 'last_error' => null, 'created_at' => $now, 'updated_at' => $now];
            if ($row === null) { $this->db->insert('wallet_passes', ['employee_id' => $id] + $values); }
            else { $this->update('wallet_passes', $values, 'employee_id = ?', [$id]); }
            $this->db->insert('wallet_attempts', ['request_id' => $requestId, 'employee_id' => $id, 'actor_employee_id' => $actorId, 'state' => 'issuing', 'payload_hash' => hash('sha256', $this->json($payload)), 'payload_json' => $this->json($payload), 'created_at' => $now, 'updated_at' => $now]);
            $this->audit($actorId, 'wallet.issue.start', $id, ['state' => 'issuing', 'request_id' => $requestId]);
            return ['request_id' => $requestId, 'payload' => $payload];
        });
        if (isset($reservation['cached'])) { return $this->result($id, $reservation['row'], true); }
        if (isset($reservation['blocked'])) { return $this->blockedResult($id, $reservation['row']); }
        $requestId = $reservation['request_id'];
        $payload = $reservation['payload'];
        try { $response = $this->provider->request('POST', '/api/passes', $payload); }
        catch (Throwable) { $response = ['status' => 0, 'body' => '']; }
        $status = (int) $response['status'];
        if ($status >= 200 && $status < 300) {
            try { $pass = $this->validatePass($id, json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR), $payload['barcodeValue']); }
            catch (Throwable) { return $this->finishFailure($id, $requestId, 'ambiguous', 'INVALID_PROVIDER_RESPONSE'); }
            $row = $this->db->transaction(function () use ($id, $requestId, $pass): array {
                $this->db->one('SELECT id FROM wallet_provider_usage WHERE id = 1'.$this->lock());
                $this->storeReady($id, $requestId, $pass);
                $this->db->exec('UPDATE wallet_provider_usage SET used_count = used_count + 1 WHERE id = 1');
                $actorId = (int) $this->db->scalar('SELECT actor_employee_id FROM wallet_attempts WHERE request_id = ?', [$requestId]);
                $this->audit($actorId, 'wallet.issue.ready', $id, ['state' => 'ready', 'serial_number' => $pass['serial_number']]);
                return $this->db->one('SELECT * FROM wallet_passes WHERE employee_id = ?', [$id]);
            });
            return $this->result($id, $row, false);
        }
        // These responses explicitly reject issuance. Network, 5xx and incomplete responses are ambiguous.
        $definite = in_array($status, [400, 401, 403, 404, 405, 422, 429], true);
        return $this->finishFailure($id, $requestId, $definite ? 'failed' : 'ambiguous', $definite ? 'PROVIDER_HTTP_'.$status : 'PROVIDER_RESULT_UNKNOWN');
    }

    public function issueBatch(array $ids, int $actorId): array
    {
        $ids = $this->ids($ids); $results = [];
        foreach ($ids as $id) {
            try { $results[] = $this->issue($id, $actorId); }
            catch (DomainException $error) { $results[] = ['success' => false, 'employee_id' => $id, 'display_id' => self::displayId($id), 'state' => 'failed', 'code' => $error->getCode(), 'error' => $error->getMessage()]; }
        }
        return ['success' => !array_filter($results, static fn (array $result): bool => !$result['success']), 'results' => $results, 'quota' => $this->usage()];
    }

    public function cachedBatch(array $ids): array
    {
        $results = [];
        foreach ($this->ids($ids) as $id) {
            try { $results[] = $this->cached($id); }
            catch (DomainException $error) { $results[] = ['success' => false, 'employee_id' => $id, 'state' => 'failed', 'code' => $error->getCode(), 'error' => $error->getMessage()]; }
        }
        return ['success' => true, 'results' => $results, 'quota' => $this->usage()];
    }

    public function listing(): array
    {
        $rows = $this->db->all('SELECT employee_id, display_id, display_name, state, request_id, serial_number, share_url, google_save_url, last_error, created_at, updated_at FROM wallet_passes ORDER BY employee_id');
        return array_map(fn (array $row): array => $this->result((int) $row['employee_id'], $row, true), $rows);
    }

    /** DELETE is documented as idempotent. Unknown revocation blocks replacement issuance. */
    public function revoke(int $id, int $actorId): array
    {
        $this->employee($id);
        if (!$this->provider->configured()) { throw new DomainException('Wallet API пока не настроен.', 409); }
        $reservation = $this->db->transaction(function () use ($id, $actorId): array {
            $this->db->one('SELECT id FROM wallet_provider_usage WHERE id = 1'.$this->lock());
            $row = $this->db->one('SELECT * FROM wallet_passes WHERE employee_id = ?'.$this->lock(), [$id]);
            if ($row === null || $row['serial_number'] === null || in_array($row['state'], ['issuing', 'ambiguous'], true)) { throw new DomainException('Нет подтверждённого serial для отзыва. Сначала восстановите ранее выданный пропуск.', 409); }
            if ($row['state'] === 'revoked') { return ['cached' => true, 'row' => $row]; }
            if ($row['state'] === 'revoking' && $this->now()->getTimestamp() - strtotime($row['updated_at'].' UTC') < 60) { return ['blocked' => true, 'row' => $row]; }
            $this->update('wallet_passes', ['state' => 'revoking', 'updated_at' => $this->stamp()], 'employee_id = ?', [$id]);
            $this->audit($actorId, 'wallet.revoke.start', $id, ['state' => 'revoking', 'serial_number' => $row['serial_number']]);
            return ['row' => $row];
        });
        if (isset($reservation['cached'])) { return $this->result($id, $reservation['row'], true) + ['success' => true]; }
        if (isset($reservation['blocked'])) { return $this->blockedResult($id, $reservation['row']); }
        $row = $reservation['row'];
        try { $response = $this->provider->request('DELETE', '/api/passes/'.$row['serial_number']); $data = json_decode($response['body'], true); }
        catch (Throwable) { $response = ['status' => 0]; $data = null; }
        $confirmed = (int) $response['status'] === 200 && is_array($data) && ($data['serialNumber'] ?? '') === $row['serial_number'] && ($data['deleted'] ?? false) === true && ($data['googleRevoked'] ?? true) !== false;
        if (($row['last_error'] ?? '') === 'GOOGLE_REVOKE_FAILED' && ($data['googleRevoked'] ?? null) !== true) { $confirmed = false; }
        $error = is_array($data) && ($data['googleRevoked'] ?? true) === false ? 'GOOGLE_REVOKE_FAILED' : ($confirmed ? null : 'REVOKE_RESULT_UNKNOWN');
        if (!$confirmed && ($row['last_error'] ?? '') === 'GOOGLE_REVOKE_FAILED') { $error = 'GOOGLE_REVOKE_FAILED'; }
        $state = $confirmed ? 'revoked' : 'revoke_unknown';
        $result = $this->db->transaction(function () use ($id, $actorId, $row, $state, $error): array {
            $this->db->one('SELECT id FROM wallet_provider_usage WHERE id = 1'.$this->lock());
            $this->update('wallet_passes', ['state' => $state, 'last_error' => $error, 'updated_at' => $this->stamp()], 'employee_id = ? AND request_id = ?', [$id, $row['request_id']]);
            $this->audit($actorId, 'wallet.revoke.'.$state, $id, ['state' => $state, 'serial_number' => $row['serial_number'], 'error_code' => $error]);
            return $this->result($id, $this->db->one('SELECT * FROM wallet_passes WHERE employee_id = ?', [$id]), false);
        });
        $result['success'] = $confirmed;
        if (!$confirmed) { $result += ['code' => 409, 'error' => 'Отзыв Wallet не подтверждён. Новый пропуск не создаётся; повторите безопасный отзыв после восстановления связи.']; }
        return $result;
    }

    public function usage(): array
    {
        $row = $this->db->one('SELECT * FROM wallet_provider_usage WHERE id = 1');
        $pending = (int) $this->db->scalar("SELECT COUNT(*) FROM wallet_passes WHERE state IN ('issuing', 'ambiguous')");
        $known = $row !== null && $row['fetched_at'] !== null;
        $stale = !$known || $this->now()->getTimestamp() - strtotime($row['fetched_at'].' UTC') > 300 || $row['reset_date'] <= $this->now()->format('Y-m-d');
        $limit = $known ? min(self::HARD_LIMIT, (int) $row['quota_limit']) : 0;
        $used = $known ? (int) $row['used_count'] : 0;
        return ['used' => $known ? $used : null, 'count' => $known ? $used : null, 'limit' => $known ? $limit : null, 'remaining' => $known ? max(0, $limit - $used - $pending) : null, 'reserved' => $pending, 'reset_date' => $known ? $row['reset_date'] : null, 'plan' => $known ? $row['plan'] : null, 'stale' => $stale, 'configured' => $this->provider->configured(), 'fetched_at' => $row['fetched_at'] ?? null];
    }

    public function refreshUsage(bool $force = false): array
    {
        if (!$this->provider->configured()) { return $this->usage(); }
        // Serialize safe usage refresh and issuance reservations. A write in flight stays reserved.
        return $this->db->transaction(function () use ($force): array {
            $row = $this->db->one('SELECT * FROM wallet_provider_usage WHERE id = 1'.$this->lock());
            if (!$force && $row['fetched_at'] !== null && $this->now()->getTimestamp() - strtotime($row['fetched_at'].' UTC') < 60 && !$this->usage()['stale']) { return $this->usage(); }
            try {
                $response = $this->provider->request('GET', '/api/auth/usage');
                if ((int) $response['status'] !== 200) { return $this->usage(); }
                $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !isset($data['count'], $data['limit'], $data['resetDate']) || !is_numeric($data['count']) || !is_numeric($data['limit'])) { return $this->usage(); }
                $reset = ScheduleService::validateDate((string) $data['resetDate']);
                if ((int) $data['count'] < 0 || (int) $data['limit'] < 0) { return $this->usage(); }
                $this->update('wallet_provider_usage', ['used_count' => (int) $data['count'], 'quota_limit' => min(self::HARD_LIMIT, (int) $data['limit']), 'reset_date' => $reset, 'plan' => substr((string) ($data['plan'] ?? ''), 0, 32), 'fetched_at' => $this->stamp()], 'id = 1', []);
            } catch (Throwable) { /* Do not expose provider bodies or headers to logs/UI. */ }
            return $this->usage();
        });
    }

    public function qr(int $id): string
    {
        $url = $this->cachedShareUrl($id) ?? throw new DomainException('Wallet-пропуск ещё не выдан.', 404);
        $qr = new QrCode(data: $url, encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 420, margin: 40, roundBlockSizeMode: RoundBlockSizeMode::Margin);
        return (new SvgWriter())->write($qr)->getString();
    }

    public function pkpass(int $id): string
    {
        $this->employee($id);
        $value = $this->db->scalar('SELECT apple_pass FROM wallet_passes WHERE employee_id = ? AND state = ?', [$id, 'ready']);
        if (!is_string($value) || $value === '') { throw new DomainException('Apple-пропуск ещё не выдан.', 404); }
        return $value;
    }

    private function validatePass(int $id, mixed $data, ?string $expectedCode = null): array
    {
        if (!is_array($data) || !isset($data['serialNumber'], $data['shareUrl'], $data['googleSaveUrl'], $data['applePass']) || !preg_match('/^[A-Za-z0-9_-]{8,128}$/D', (string) $data['serialNumber'])) { throw new DomainException('Неполный ответ Wallet API.'); }
        $serial = (string) $data['serialNumber'];
        if ((string) $data['shareUrl'] !== 'https://pass.walletwallet.dev/p/'.$serial || !str_starts_with((string) $data['googleSaveUrl'], 'https://pay.google.com/gp/v/save/') || strlen((string) $data['googleSaveUrl']) > 20000) { throw new DomainException('Некорректные ссылки Wallet API.'); }
        $bytes = base64_decode((string) $data['applePass'], true);
        if ($bytes === false || strlen($bytes) < 100 || strlen($bytes) > 10485760 || !str_starts_with($bytes, "PK\x03\x04")) { throw new DomainException('Некорректный Apple Wallet файл.'); }
        $this->validator->verify($bytes, $serial, $expectedCode ?? IdentifierService::codeOf($this->employee($id)));
        return ['serial_number' => $serial, 'share_url' => (string) $data['shareUrl'], 'google_save_url' => (string) $data['googleSaveUrl'], 'apple_pass' => $bytes];
    }

    /** Explicit operator recovery: no remote POST/PUT and no new quota consumption. */
    public function recover(int $id, int $actorId, array $data): array
    {
        $this->employee($id);
        $pass = $this->validatePass($id, $data);
        if (!$this->provider->configured()) { throw new DomainException('Для восстановления нужна проверка владельца в Wallet API.', 409); }
        try { $response = $this->provider->request('GET', '/api/passes/'.$pass['serial_number']); }
        catch (Throwable) { throw new DomainException('Не удалось проверить Wallet serial. Восстановление не применено.', 409); }
        $metadata = json_decode($response['body'], true);
        $currentGoogle = is_array($metadata) ? (string) ($metadata['googleSaveUrl'] ?? '') : '';
        if ($response['status'] !== 200 || !is_array($metadata) || ($metadata['serial'] ?? $metadata['serialNumber'] ?? '') !== $pass['serial_number'] || ($metadata['credentials'] ?? '') !== 'platform' || !str_starts_with($currentGoogle, 'https://pay.google.com/gp/v/save/') || strlen($currentGoogle) > 20000) { throw new DomainException('Wallet API не подтвердил владение serial или Google ссылку.', 409); }
        // The provider may mint a fresh install JWT when read. Ownership and signed barcode
        // are already verified; reuse that current link rather than comparing JWT bytes.
        $pass['google_save_url'] = $currentGoogle;
        $result = $this->db->transaction(function () use ($id, $actorId, $pass): array {
            $this->db->one('SELECT id FROM wallet_provider_usage WHERE id = 1'.$this->lock());
            $row = $this->db->one('SELECT * FROM wallet_passes WHERE employee_id = ?'.$this->lock(), [$id]);
            if ($row !== null && $row['state'] === 'ready') {
                if ($row['serial_number'] !== $pass['serial_number']) { throw new DomainException('У сотрудника уже есть другой сохранённый Wallet-пропуск.', 409); }
                return $this->result($id, $row, true);
            }
            if ($row !== null && in_array($row['state'], ['revoked', 'revoking', 'revoke_unknown'], true)) { throw new DomainException('Отзываемый или отозванный пропуск нельзя восстановить как действующий.', 409); }
            // An in-flight request must be allowed to finish before an operator can replace its state.
            if ($row !== null && $row['state'] === 'issuing' && $this->now()->getTimestamp() - strtotime($row['updated_at'].' UTC') < 60) { throw new DomainException('Выдача ещё выполняется. Восстановление пока недоступно.', 409); }
            $requestId = $row['request_id'] ?? bin2hex(random_bytes(20)); $now = $this->stamp();
            if ($row === null) {
                $this->db->insert('wallet_passes', ['employee_id' => $id, 'display_id' => IdentifierService::codeOf($this->employee($id)), 'request_id' => $requestId, 'state' => 'ambiguous', 'created_at' => $now, 'updated_at' => $now]);
                $this->db->insert('wallet_attempts', ['request_id' => $requestId, 'employee_id' => $id, 'actor_employee_id' => $actorId, 'state' => 'ambiguous', 'payload_hash' => hash('sha256', 'recovered:'.$pass['serial_number']), 'created_at' => $now, 'updated_at' => $now]);
            }
            $this->storeReady($id, $requestId, $pass);
            $this->audit($actorId, 'wallet.recover', $id, ['state' => 'ready', 'serial_number' => $pass['serial_number']]);
            return $this->result($id, $this->db->one('SELECT * FROM wallet_passes WHERE employee_id = ?', [$id]), true);
        });
        $this->refreshUsage(true);
        return $result;
    }

    private function storeReady(int $id, string $requestId, array $pass): void
    {
        $now = $this->stamp();
        $this->update('wallet_passes', $pass + ['state' => 'ready', 'last_error' => null, 'updated_at' => $now], 'employee_id = ? AND request_id = ?', [$id, $requestId]);
        $this->update('wallet_attempts', ['state' => 'ready', 'serial_number' => $pass['serial_number'], 'updated_at' => $now], 'request_id = ?', [$requestId]);
    }

    private function finishFailure(int $id, string $requestId, string $state, string $code): array
    {
        $this->db->transaction(function () use ($id, $requestId, $state, $code): void {
            $this->db->one('SELECT id FROM wallet_provider_usage WHERE id = 1'.$this->lock());
            $values = ['state' => $state, 'last_error' => $code, 'updated_at' => $this->stamp()];
            $this->update('wallet_passes', $values, 'employee_id = ? AND request_id = ?', [$id, $requestId]);
            $this->update('wallet_attempts', $values, 'request_id = ?', [$requestId]);
            $actorId = (int) $this->db->scalar('SELECT actor_employee_id FROM wallet_attempts WHERE request_id = ?', [$requestId]);
            $this->audit($actorId, 'wallet.issue.'.$state, $id, ['state' => $state, 'error_code' => $code]);
        });
        $row = $this->db->one('SELECT * FROM wallet_passes WHERE employee_id = ?', [$id]);
        return $state === 'ambiguous' ? $this->blockedResult($id, $row) : $this->result($id, $row, false) + ['code' => $code === 'PROVIDER_HTTP_429' ? 429 : 409, 'error' => 'Wallet API отклонил выдачу. Проверьте квоту и настройки; сохранённые пропуска доступны.'];
    }

    private function blockedResult(int $id, array $row): array
    {
        $result = $this->result($id, $row, true);
        if (in_array($result['state'], ['revoking', 'revoke_unknown'], true)) { return $result + ['code' => 409, 'error' => 'Отзыв ещё не подтверждён. Повторите отзыв; новый пропуск до подтверждения не создаётся.']; }
        return $result + ['code' => 409, 'recovery_required' => true, 'error' => $result['state'] === 'issuing' ? 'Выдача уже начата. Обновите статус; повторный запрос создания не отправляется.' : 'Результат выдачи неизвестен. Повторное создание заблокировано; администратор должен восстановить существующий пропуск по serial.'];
    }

    private function result(int $id, ?array $row, bool $cached): array
    {
        $state = $row['state'] ?? 'not_issued';
        // A crashed worker leaves its durable reservation intact; a read only reports recovery state.
        if ($state === 'issuing' && $this->now()->getTimestamp() - strtotime($row['updated_at'].' UTC') >= 60) { $state = 'ambiguous'; }
        $result = ['success' => in_array($state, ['ready', 'revoked'], true) || $row === null, 'employee_id' => $id, 'display_id' => $row['display_id'] ?? IdentifierService::codeOf($this->employee($id)), 'state' => $state, 'cached' => $cached, 'quota' => $this->usage(), 'created_at' => isset($row['created_at']) ? str_replace(' ', 'T', $row['created_at']).'Z' : null, 'updated_at' => isset($row['updated_at']) ? str_replace(' ', 'T', $row['updated_at']).'Z' : null];
        if (!empty($row['serial_number'])) { $result['serial_number'] = $row['serial_number']; }
        if (!empty($row['display_name'])) { $result['display_name'] = $row['display_name']; }
        if ($state === 'ready') { $result += ['serial_number' => $row['serial_number'], 'share_url' => $row['share_url'], 'google_save_url' => $row['google_save_url'], 'qr_url' => '/admin/wallet/qr/'.$id, 'pkpass_url' => '/admin/wallet/pkpass/'.$id]; }
        return $result;
    }

    private function employee(int $id, bool $lock = false): array
    {
        if ($id <= 0 || $id > 4294967295) { throw new DomainException('Некорректный ID сотрудника.', 400); }
        $row = $this->db->one('SELECT id, pass_code, active, surname, given_name, patronymic FROM employees WHERE id = ? AND is_service = 0'.($lock ? $this->lock() : ''), [$id]);
        if ($row === null) { throw new DomainException('Сотрудник не найден.', 404); }
        if ($lock && !(bool) $row['active']) { throw new DomainException('Сотрудник больше неактивен.', 409); }
        return $row;
    }

    private function ids(array $values): array
    {
        if ($values === [] || count($values) > 200) { throw new DomainException('Выберите от 1 до 200 пропусков.', 400); }
        $ids = [];
        foreach ($values as $value) { if (!is_scalar($value) || !preg_match('/^\d{1,10}$/D', trim((string) $value)) || (int) $value < 1 || (int) $value > 4294967295) { throw new DomainException('Некорректный ID в списке.', 400); } $ids[] = (int) $value; }
        return array_values(array_unique($ids));
    }

    private function update(string $table, array $values, string $where, array $params): void { $this->db->exec('UPDATE '.$table.' SET '.implode(', ', array_map(static fn (string $key): string => $key.' = ?', array_keys($values))).' WHERE '.$where, [...array_values($values), ...$params]); }
    private function audit(int $actorId, string $action, int $id, array $details): void { $this->db->insert('audit_log', ['actor_employee_id' => $actorId, 'action' => $action, 'entity_type' => 'employee', 'entity_id' => (string) $id, 'before_json' => null, 'after_json' => $this->json($details), 'created_at' => $this->stamp(), 'private' => 0]); }
    private function now(): DateTimeImmutable { return ($this->clock)()->setTimezone(new DateTimeZone('UTC')); }
    private function stamp(): string { return $this->now()->format('Y-m-d H:i:s'); }
    private function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private function lock(): string { return str_contains(strtolower(get_class($this->db->db->getDatabasePlatform())), 'sqlite') ? '' : ' FOR UPDATE'; }
}
