<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Agent;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class TrackSessionInfo
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($request->hasSession() && $request->session()->getId() && auth()->check()) {
                $agent = new Agent;
                $agent->setUserAgent((string) $request->userAgent());

                $deviceType = 'desktop';
                if ($agent->isTablet()) {
                    $deviceType = 'tablet';
                } elseif ($agent->isMobile()) {
                    $deviceType = 'mobile';
                }

                DB::table('sessions')
                    ->where('id', $request->session()->getId())
                    ->update([
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'device_type' => $deviceType,
                        'browser' => $agent->browser(),
                        'platform' => $agent->platform(),
                    ]);
            }
        } catch (RuntimeException $e) {
            // Session store not set — skip tracking silently.
        }

        return $response;
    }
}
