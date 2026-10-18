<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenBelongsToProject
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if (! $project instanceof Project) {
            abort(404);
        }

        $accessToken = PersonalAccessToken::findToken((string) $request->bearerToken());

        if (
            $accessToken === null
            || $accessToken->tokenable_type !== Project::class
            || (int) $accessToken->tokenable_id !== $project->id
        ) {
            abort(403, 'Token does not belong to this project.');
        }

        return $next($request);
    }
}
