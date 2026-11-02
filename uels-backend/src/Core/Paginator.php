<?php
declare(strict_types=1);

namespace App\Core;

class Paginator
{
    /** @return array{0: array, 1: array} [items, meta] */
    public static function run(Request $req, string $columns, string $from, string $where, array $params, string $orderBy): array
    {
        $page   = max(1, (int) $req->get('page', 1));
        $per    = min(100, max(1, (int) $req->get('per_page', 20)));
        $total  = (int) Database::scalar("SELECT COUNT(*) FROM {$from} {$where}", $params);
        $offset = ($page - 1) * $per;

        $items = Database::all("SELECT {$columns} FROM {$from} {$where} ORDER BY {$orderBy} LIMIT {$per} OFFSET {$offset}", $params);

        return [$items, [
            'page'        => $page,
            'per_page'    => $per,
            'total'       => $total,
            'total_pages' => (int) ceil($total / $per),
        ]];
    }

    public static function where(array $conditions): string
    {
        return $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    }

    /** ?sort=name or ?sort=-name (descending). $allowed maps public names to SQL columns. */
    public static function order(Request $req, array $allowed, string $default): string
    {
        $s = (string) $req->get('sort', '');
        if ($s === '') {
            return $default;
        }
        $dir = 'ASC';
        if ($s[0] === '-') {
            $dir = 'DESC';
            $s = substr($s, 1);
        }
        return isset($allowed[$s]) ? $allowed[$s] . ' ' . $dir : $default;
    }
}
