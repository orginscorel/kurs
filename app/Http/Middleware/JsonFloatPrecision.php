<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Ondalık sayıların JSON'a "75.31" olarak yazılmasını sağlar (sunucu php.ini'de
 * serialize_precision=17 → "75.310000000000002"). Sınav uçlarında kullanılır;
 * genel çözüm AppServiceProvider'da ini_set('serialize_precision', '-1').
 */
class JsonFloatPrecision
{
    public function handle(Request $request, Closure $next)
    {
        ini_set('serialize_precision', '-1');
        ini_set('precision', '14');

        return $next($request);
    }
}
