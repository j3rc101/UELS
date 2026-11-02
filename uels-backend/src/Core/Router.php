<?php
declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    /**
     * @param array|null $roles null = public, [] = any logged-in user, ['staff', ...] = only those roles
     */
    public function add(string $method, string $pattern, callable $handler, ?array $roles = null): void
    {
        // {id} matches digits only; any other {name} matches one path segment
        $regex = '#^' . preg_replace_callback('#\{(\w+)\}#', static function (array $m): string {
            return $m[1] === 'id' ? '(?P<id>\d+)' : '(?P<' . $m[1] . '>[^/]+)';
        }, rtrim($pattern, '/')) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'roles');
    }

    public function get(string $p, callable $h, ?array $roles = null): void    { $this->add('GET', $p, $h, $roles); }
    public function post(string $p, callable $h, ?array $roles = null): void   { $this->add('POST', $p, $h, $roles); }
    public function put(string $p, callable $h, ?array $roles = null): void    { $this->add('PUT', $p, $h, $roles); }
    public function delete(string $p, callable $h, ?array $roles = null): void { $this->add('DELETE', $p, $h, $roles); }

    public function dispatch(Request $req): void
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $req->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $req->method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $user = $route['roles'] === null ? null : Auth::require($route['roles']);   // public routes skip sessions
            call_user_func($route['handler'], $req, $params, $user);
            return;
        }
        if ($pathMatched) {
            throw new ApiException('Method not allowed', 405);
        }
        throw new ApiException('Endpoint not found', 404);
    }
}
