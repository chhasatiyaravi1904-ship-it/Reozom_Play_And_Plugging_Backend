<?php

namespace App\Services;

use App\Models\ServicePackage;
use App\Models\ZipCode;

class PackageAvailabilityService
{
    /**
     * Validate that the ZIP exists in zip_codes, is active, and exactly matches.
     * 
     * @param string $zip
     * @return ZipCode|null
     */
    public function validateZip(string $zip): ?ZipCode
    {
        return ZipCode::where('code', $zip)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Return active Service Packages assigned to the exact ZIP code, 
     * belonging to an active Agent with a valid subscription.
     * 
     * @param string $zip
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailablePackages(string $zip)
    {
        if (!$this->validateZip($zip)) {
            return collect();
        }

        // We use whereHas to ensure the package is linked to this specific zip code
        return ServicePackage::with(['agent:id,first_name,last_name'])
            ->where('is_active', true)
            ->whereHas('zipCodes', function ($query) use ($zip) {
                $query->where('code', $zip);
            })
            ->whereHas('agent', function ($query) {
                // Ensure agent is active and has an active subscription
                $query->where('is_active', true)
                      ->whereHas('agentPackages', function ($q) {
                          $q->where('expires_at', '>', now());
                      });
            })
            ->get();
    }

    /**
     * Paginated packages for the public ZIP matching engine.
     *
     * With a ZIP prefix, only packages serving an active ZIP that starts with
     * it are returned; without one, the newest available packages are the
     * default list. Same availability rules as getAvailablePackages().
     */
    public function paginateByZipPrefix(?string $zipPrefix, int $perPage)
    {
        $zipFilter = function ($query) use ($zipPrefix) {
            $query->where('is_active', true);
            if ($zipPrefix !== null && $zipPrefix !== '') {
                $query->where('code', 'like', $zipPrefix . '%');
            }
        };

        return ServicePackage::query()
            ->with([
                'agent:id,first_name,last_name',
                'zipCodes' => function ($query) use ($zipFilter) {
                    $zipFilter($query);
                    $query->orderBy('code');
                },
            ])
            ->where('is_active', true)
            ->whereHas('zipCodes', $zipFilter)
            ->whereHas('agent', function ($query) {
                $query->where('is_active', true)
                      ->whereHas('agentPackages', function ($q) {
                          $q->where('expires_at', '>', now());
                      });
            })
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Verify that the Service Package exists, is active, belongs to an active Agent
     * with a valid subscription, and is assigned to the exact ZIP.
     * 
     * @param mixed $servicePackageId
     * @param string $zip
     * @return ServicePackage
     * @throws \Exception
     */
    public function validatePackageForZip($servicePackageId, string $zip): ServicePackage
    {
        if (!$this->validateZip($zip)) {
            throw new \Exception('The selected ZIP code is not valid.');
        }

        $package = ServicePackage::where('id', $servicePackageId)
            ->where('is_active', true)
            ->whereHas('zipCodes', function ($query) use ($zip) {
                $query->where('code', $zip);
            })
            ->whereHas('agent', function ($query) {
                $query->where('is_active', true)
                      ->whereHas('agentPackages', function ($q) {
                          $q->where('expires_at', '>', now());
                      });
            })
            ->first();

        if (!$package) {
            throw new \Exception('The selected service package is not available for this ZIP code.');
        }

        return $package;
    }
}
