<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserLoginLog;
use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;

class LoginActivityLogger
{
    public function log(User $user, Request $request, string $loginType = 'direct'): UserLoginLog
    {
        $agent = new Agent;
        $agent->setUserAgent($request->userAgent());

        $platform = $agent->platform() ?: null;
        $browser = $agent->browser() ?: null;

        return $user->loginLogs()->create([
            'login_type' => $loginType,
            'device' => $agent->device() ?: null,
            'platform' => $platform,
            'platform_version' => $platform ? ($agent->version($platform) ?: null) : null,
            'browser' => $browser,
            'browser_version' => $browser ? ($agent->version($browser) ?: null) : null,
            'ip' => $request->ip(),
        ]);
    }
}
