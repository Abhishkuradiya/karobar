<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $business = $user->businesses()->find($user->active_business_id);

        if (! $business) {
            return response()->json([
                'message' => 'Create or select a business before using this feature.',
                'code' => 'business_required',
            ], 409);
        }

        $user->setRelation('activeBusiness', $business);

        return $next($request);
    }
}
