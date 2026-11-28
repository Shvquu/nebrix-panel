<?php

declare(strict_types=1);

namespace Nebrix\Http\Middleware;

use Nebrix\Auth\User;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;

final class Authenticate implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        if (!$request->attribute('user') instanceof User) {
            if ($request->expectsJson() || !$request->isMethod('GET')) {
                throw new HttpException(401, 'Please sign in to continue.');
            }

            // Path and simple query values (e.g. a configuration chosen on the website); checked again on use.
            $intended = $request->path();
            $query = array_filter($request->allQuery(), static fn (mixed $value): bool => is_string($value) && strlen($value) <= 255);
            if ($query !== [] && strlen($intended . '?' . http_build_query($query)) <= 1000) {
                $intended .= '?' . http_build_query($query);
            }
            $request->session()->put('url.intended', $intended);

            return Response::redirect('/login');
        }

        return $next($request);
    }
}
