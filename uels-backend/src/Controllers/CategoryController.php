<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{ApiException, Audit, Database, Request, Response, Validator};

class CategoryController
{
    public static function index(Request $req): void
    {
        Response::success(Database::all(
            'SELECT c.category_id, c.name, c.description, COUNT(e.equipment_id) AS equipment_count
             FROM categories c LEFT JOIN equipment e ON e.category_id = c.category_id
             GROUP BY c.category_id, c.name, c.description ORDER BY c.name'
        ));
    }

    public static function store(Request $req, array $p, array $user): void
    {
        $d = Validator::validate($req->input(), [
            'name'        => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
        ]);
        Database::execute('INSERT INTO categories (name, description) VALUES (?,?)', [$d['name'], $d['description'] ?? null]);
        $id = Database::lastId();
        Audit::log($user['user_id'], 'category.create', 'category', $id, $d['name']);
        Response::success(self::find($id), 'Category created', 201);
    }

    public static function update(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        self::find($id);
        $d = Validator::validate($req->input(), [
            'name'        => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
        ], true);
        if (!$d) {
            throw new ApiException('No fields to update', 422);
        }
        $sets = []; $vals = [];
        foreach ($d as $k => $v) { $sets[] = "{$k} = ?"; $vals[] = $v; }
        $vals[] = $id;
        Database::execute('UPDATE categories SET ' . implode(', ', $sets) . ' WHERE category_id = ?', $vals);
        Audit::log($user['user_id'], 'category.update', 'category', $id);
        Response::success(self::find($id), 'Category updated');
    }

    public static function destroy(Request $req, array $p, array $user): void
    {
        $id = (int) $p['id'];
        self::find($id);
        if ((int) Database::scalar('SELECT COUNT(*) FROM equipment WHERE category_id = ?', [$id]) > 0) {
            throw new ApiException('This category still contains equipment', 409);
        }
        Database::execute('DELETE FROM categories WHERE category_id = ?', [$id]);
        Audit::log($user['user_id'], 'category.delete', 'category', $id);
        Response::success(null, 'Category deleted');
    }

    private static function find(int $id): array
    {
        $c = Database::one('SELECT category_id, name, description FROM categories WHERE category_id = ?', [$id]);
        if (!$c) {
            throw new ApiException('Category not found', 404);
        }
        return $c;
    }
}
