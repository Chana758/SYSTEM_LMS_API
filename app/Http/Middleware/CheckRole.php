<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next,...$roles): Response
    {
        // Get the currently authenticated user from the request token
        $user=$request->user();

        // Check if user is not logged in or user has no role assigned
        if (!$user || !$user->role) {
             
            return response()->json(['message' => 'Unauthenticated'], 401);
            
        }

        // Check if the user's role is not included in the allowed roles
        if (! in_array($user->role->name, $roles)) {

            // Return 403 Forbidden because user has no permission
            return response()->json([
                'message' => 'You do not have permission to access this resource'
            ], 403);
        }
        
        // User has the correct role, continue to the next middleware or controller
        return $next($request);
    }
}
