<?php
namespace App\Http\Middleware;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureRole { public function handle(Request $request, Closure $next, string ...$roles): Response { $allowed=collect($roles)->flatMap(fn($role)=>explode(',',$role))->all(); $user=$request->user(); abort_unless($user instanceof User && $user->is_active && $user->hasRole(...$allowed), 403); return $next($request); } }
