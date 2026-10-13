<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use DomainException;

final class IdentifierService
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public function __construct(private readonly Database $db) {}

    public static function randomCode(): string
    {
        $bytes = random_bytes(12); $code = '';
        // 256 is divisible by 32, so masking gives an unbiased 60-bit code.
        for ($i = 0; $i < 12; $i++) { $code .= self::ALPHABET[ord($bytes[$i]) & 31]; }
        return 'VVE-'.substr($code, 0, 4).'-'.substr($code, 4, 4).'-'.substr($code, 8, 4);
    }
    public static function generateCode(): string { return self::randomCode(); }
    public static function numericLegacy(int $id): string { return str_pad((string) $id, 3, '0', STR_PAD_LEFT); }
    public static function legacyCode(int $id): string { return self::numericLegacy($id); }
    public static function codeOf(array $employee): string { return !empty($employee['pass_code']) ? strtoupper((string) $employee['pass_code']) : self::numericLegacy((int) $employee['id']); }

    /** HID scanners type physical QWERTY keys into the active Windows keyboard layout. */
    public static function normalizeInput(string $input): string
    {
        $input = mb_strtoupper(trim($input), 'UTF-8');
        // Only our known machine prefixes enable keyboard-layout conversion. This is not
        // generic Cyrillic transliteration and does not relax subsequent strict code checks.
        if (str_starts_with($input, 'ММУ-') || preg_match('/^ТАС[:Ж]/u', $input)) {
            $input = strtr($input, [
                'Й'=>'Q', 'Ц'=>'W', 'У'=>'E', 'К'=>'R', 'Е'=>'T', 'Н'=>'Y', 'Г'=>'U',
                'Ш'=>'I', 'Щ'=>'O', 'З'=>'P', 'Х'=>'[', 'Ъ'=>']', 'Ф'=>'A', 'Ы'=>'S',
                'В'=>'D', 'А'=>'F', 'П'=>'G', 'Р'=>'H', 'О'=>'J', 'Л'=>'K', 'Д'=>'L',
                'Ж'=>':', 'Э'=>"'", 'Я'=>'Z', 'Ч'=>'X', 'С'=>'C', 'М'=>'V', 'И'=>'B',
                'Т'=>'N', 'Ь'=>'M', 'Б'=>',', 'Ю'=>'.', 'Ё'=>'`',
            ]);
        }
        return $input;
    }

    /** Internal primary keys are accepted only while that person's current pass remains numeric. */
    public function resolveCode(string $code, bool $activeOnly = true, bool $includeService = false): array
    {
        $code = self::normalizeInput($code); $row = null;
        if (preg_match('/^\d{1,10}$/D', $code) && ((int) $code > 0 || $includeService) && (int) $code <= 4294967295) {
            $candidate = $this->db->one('SELECT * FROM employees WHERE id = ?', [(int) $code]);
            if ($candidate !== null && ctype_digit(self::codeOf($candidate)) && (int) self::codeOf($candidate) === (int) $code) { $row = $candidate; }
        } elseif (preg_match('/^VVE-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/D', $code)) {
            $row = $this->db->one('SELECT * FROM employees WHERE pass_code = ?', [$code]);
        } else {
            throw new DomainException('Некорректный код пропуска.', 400);
        }
        if ($row === null || (!$includeService && (int) $row['is_service'] === 1)) { throw new DomainException('Пропуск не найден или заменён.', 404); }
        if ($activeOnly && !(bool) $row['active']) { throw new DomainException('Сотрудник неактивен; текущие отметки недоступны.', 409); }
        $row['pass_code'] = self::codeOf($row);
        $row['name'] = trim(implode(' ', array_filter([(string) $row['surname'], (string) $row['given_name'], (string) $row['patronymic']], static fn (string $part): bool => $part !== '')));
        return $row;
    }
}
