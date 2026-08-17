<?php

namespace App\Services;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class AdminActivityLogger
{
    public function log(?User $user, string $action, ?string $description = null, ?Request $request = null): AdminActivityLog
    {
        return AdminActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'description' => $description,
            'ip' => $request?->ip(),
        ]);
    }
}
