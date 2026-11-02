<?php
declare(strict_types=1);

namespace App\Core;

class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    public static function success($data = null, string $message = 'OK', int $status = 200, ?array $meta = null): void
    {
        $payload = ['success' => true, 'message' => $message, 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        self::json($payload, $status);
    }

    public static function error(string $message, int $status = 400, array $errors = []): void
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $status);
    }

    /** Stream rows as a CSV download (used by the reports). */
    public static function csv(string $filename, array $rows): void
    {
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
        if ($rows) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                // Neutralise spreadsheet formula injection
                $row = array_map(static function ($v) {
                    return (is_string($v) && $v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
                }, $row);
                fputcsv($out, $row);
            }
        } else {
            fputcsv($out, ['No data']);
        }
        fclose($out);
        exit;
    }
}
