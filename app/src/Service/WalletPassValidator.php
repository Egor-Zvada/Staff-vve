<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use ZipArchive;

/** Verifies the signed manifest and exact employee barcode; extraction never touches a public path. */
final class WalletPassValidator
{
    public function verify(string $bytes, string $serial, string $displayId): void
    {
        if (!class_exists(ZipArchive::class)) { throw new DomainException('Для проверки Wallet требуется PHP ZIP.'); }
        $directory = sys_get_temp_dir().'/staff-wallet-'.bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) { throw new DomainException('Не удалось создать каталог проверки Wallet.'); }
        $zip = new ZipArchive();
        try {
            $archive = $directory.'/pass.pkpass'; file_put_contents($archive, $bytes); chmod($archive, 0600);
            if ($zip->open($archive, ZipArchive::RDONLY) !== true || $zip->numFiles > 100) { throw new DomainException('Некорректный архив Wallet.'); }
            $content = []; $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); $name = $entry['name']; $total += $entry['size'];
                if (!preg_match('~^[A-Za-z0-9_@./-]+$~D', $name) || str_contains($name, '..') || str_starts_with($name, '/') || $entry['size'] > 10485760 || $total > 20000000 || isset($content[$name])) { throw new DomainException('Недопустимое содержимое Wallet архива.'); }
                $value = $zip->getFromIndex($i);
                if (!is_string($value)) { throw new DomainException('Не удалось прочитать Wallet архив.'); }
                $content[$name] = $value;
            }
            $zip->close();
            if (!isset($content['pass.json'], $content['manifest.json'], $content['signature'])) { throw new DomainException('В Wallet архиве отсутствует подпись.'); }
            $pass = json_decode($content['pass.json'], true, 32, JSON_THROW_ON_ERROR);
            $barcodes = $pass['barcodes'] ?? (isset($pass['barcode']) ? [$pass['barcode']] : []);
            if (($pass['serialNumber'] ?? '') !== $serial || $barcodes === [] || !is_array($barcodes)) { throw new DomainException('Wallet serial не совпадает с ответом.'); }
            foreach ($barcodes as $barcode) { if (($barcode['message'] ?? '') !== $displayId || ($barcode['format'] ?? '') !== 'PKBarcodeFormatQR') { throw new DomainException('Wallet QR не совпадает с ID сотрудника.'); } }
            $manifest = json_decode($content['manifest.json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || !isset($manifest['pass.json'])) { throw new DomainException('Некорректный манифест Wallet.'); }
            foreach ($manifest as $name => $digest) { if (!isset($content[$name]) || !is_string($digest) || !hash_equals($digest, sha1($content[$name]))) { throw new DomainException('Wallet манифест не подтверждает содержимое.'); } }
            foreach ($content as $name => $value) { if (!in_array($name, ['signature', 'manifest.json'], true) && !isset($manifest[$name])) { throw new DomainException('Неподписанный файл внутри Wallet архива.'); } }
            file_put_contents($directory.'/signature', $content['signature']); chmod($directory.'/signature', 0600);
            file_put_contents($directory.'/manifest.json', $content['manifest.json']); chmod($directory.'/manifest.json', 0600);
            $binary = (string) ($_SERVER['STAFF_WALLET_OPENSSL'] ?? $_ENV['STAFF_WALLET_OPENSSL'] ?? 'openssl');
            $process = proc_open([$binary, 'cms', '-verify', '-inform', 'DER', '-binary', '-in', $directory.'/signature', '-content', $directory.'/manifest.json', '-noverify', '-out', $directory.'/verified'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) { throw new DomainException('Не удалось проверить подпись Wallet.'); }
            fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
            if (proc_close($process) !== 0) { throw new DomainException('Криптографическая подпись Wallet не подтверждена.'); }
        } finally {
            // The directory was created here and contains only files written above.
            foreach (['pass.pkpass', 'signature', 'manifest.json', 'verified'] as $name) { if (is_file($directory.'/'.$name)) { unlink($directory.'/'.$name); } }
            rmdir($directory);
        }
    }
}
