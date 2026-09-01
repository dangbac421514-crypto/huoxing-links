<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function welcome(Request $request)
    {
        $code = $request->query('code');
        if (is_string($code) && preg_match('/^[A-Za-z0-9]{8}$/D', $code) === 1) {
            return redirect('/j/'.$code);
        }

        try {
            DB::connection()->getPdo();
            if (DB::table('sys_configs')->count() < 1) {
                abort(503, 'The application is not initialized.');
            }
        } catch (\Exception $e) {
            abort(503, 'The application is temporarily unavailable.');
        }

        return redirect('/web/#/login');
    }
}
