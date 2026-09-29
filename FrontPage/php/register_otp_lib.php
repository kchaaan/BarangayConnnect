<?php
declare(strict_types=1);

function bc_regotp_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function bc_regotp_rate_limit(string $purpose, string $email, int $limit, int $windowSeconds): array
{
    $identity = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower(trim($email));
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bc-regotp-' . hash('sha256', $purpose . '|' . $identity) . '.json';
    $handle = @fopen($file, 'c+');
    if (!$handle) return ['allowed' => false, 'retry_after' => $windowSeconds];

    flock($handle, LOCK_EX);
    $state = json_decode(stream_get_contents($handle) ?: '{}', true);
    if (!is_array($state) || (int) ($state['expires'] ?? 0) <= time()) {
        $state = ['count' => 0, 'expires' => time() + $windowSeconds];
    }
    if ((int) ($state['count'] ?? 0) >= $limit) {
        $retryAfter = max(1, (int) $state['expires'] - time());
        flock($handle, LOCK_UN);
        fclose($handle);
        return ['allowed' => false, 'retry_after' => $retryAfter];
    }

    $state['count'] = (int) ($state['count'] ?? 0) + 1;
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['allowed' => true, 'retry_after' => 0];
}

function bc_regotp_clear_rate_limit(string $purpose, string $email): void
{
    $identity = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower(trim($email));
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bc-regotp-' . hash('sha256', $purpose . '|' . $identity) . '.json';
    if (is_file($file)) @unlink($file);
}
