<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePatientPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isPatient() || ! $user->patient) {
            return response()->json([
                'message' => 'Patient portal access requires an active account linked to a patient record.',
            ], 403);
        }

        return $next($request);
    }
}
