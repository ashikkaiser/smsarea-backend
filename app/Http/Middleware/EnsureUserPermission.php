<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($permission === 'chat' && ! $user->can_chat) {
            abort(403, 'Chat permission is disabled.');
        }

        if ($permission === 'campaign' && ! $user->can_campaign) {
            abort(403, 'Campaign permission is disabled.');
        }

        return $next($request);
    }
}
