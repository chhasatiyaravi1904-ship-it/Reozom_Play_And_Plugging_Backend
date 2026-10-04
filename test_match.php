<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ServicePackage;
use App\Models\ListingProcess;

$servicePackage = ServicePackage::find(2);

$process = ListingProcess::where('service_package_id', $servicePackage->id)
            ->where('agent_id', $servicePackage->agent_id)
            ->where('status', 'active')
            ->first();

echo "Matched process ID: " . ($process ? $process->id : 'none') . "\n";
