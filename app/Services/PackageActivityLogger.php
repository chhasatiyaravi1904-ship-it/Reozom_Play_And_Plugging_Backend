<?php

namespace App\Services;

use App\Models\PackageActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class PackageActivityLogger
{
    public function log(
        User $user,
        string $packageId,
        ?string $previousPackageId,
        string $action,
        ?Request $request = null,
    ): PackageActivityLog {
        return PackageActivityLog::create([
            'user_id' => $user->id,
            'package_id' => $packageId,
            'previous_package_id' => $previousPackageId,
            'action' => $action,
            'ip' => $request?->ip(),
        ]);
    }
}
