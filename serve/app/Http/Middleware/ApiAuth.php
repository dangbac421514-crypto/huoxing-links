<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Ugly\Base\Traits\ApiResource;

class ApiAuth
{
    use ApiResource;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$userType): Response
    {
        $user = auth('api')->user();
        if (empty($user)) {
            return $this->failed('未登录', Response::HTTP_UNAUTHORIZED);
        }
        if (empty($user->status)) {
            return $this->failed('账号被禁用', Response::HTTP_FORBIDDEN);
        }
        $route = $request->route();
        $isPasswordChangeRoute = $route
            && $route->uri() === 'api/change-password'
            && $request->isMethod('post');
        if ($user->must_change_password && ! $isPasswordChangeRoute) {
            return response()->json([
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'message' => '请先修改密码',
            ], Response::HTTP_FORBIDDEN);
        }
        if (! empty($userType) && ! in_array(strtolower($user->type->name), $userType)) {
            return $this->failed('无权限访问！', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
