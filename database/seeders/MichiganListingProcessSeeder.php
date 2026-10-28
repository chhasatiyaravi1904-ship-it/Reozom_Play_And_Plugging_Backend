<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\ListingProcess;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\ZipCode;
use App\Models\State;
use App\Models\County;
use App\Models\City;

class MichiganListingProcessSeeder extends Seeder
{
    /**
     * Run the database seeds for Michigan Listing Process and packages.
     *
     * @return void
     */
    public function run()
    {
        DB::transaction(function () {
            // 1. Ensure Michigan State exists
            $miState = State::firstOrCreate(
                ['code' => 'MI'],
                [
                    'id' => '75b26374-89f7-4ff5-b57d-9d3098928849',
                    'name' => 'Michigan',
                    'slug' => 'michigan',
                    'is_active' => true,
                ]
            );

            // 2. Counties in Michigan
            $countiesData = [
                'Wayne County' => [
                    'code' => '062',
                    'cities' => [
                        'Detroit' => ['48201', '48202', '48226', '48206', '48214', '48221'],
                        'Dearborn' => ['48120', '48124', '48126'],
                    ],
                ],
                'Washtenaw County' => [
                    'code' => '001',
                    'cities' => [
                        'Ann Arbor' => ['48103', '48104', '48105', '48108'],
                        'Ypsilanti' => ['48197', '48198'],
                    ],
                ],
                'Oakland County' => [
                    'code' => '125',
                    'cities' => [
                        'Troy' => ['48084', '48098'],
                        'Birmingham' => ['48009'],
                        'Bloomfield Hills' => ['48301', '48302'],
                    ],
                ],
                'Kent County' => [
                    'code' => '081',
                    'cities' => [
                        'Grand Rapids' => ['49503', '49504', '49506'],
                    ],
                ],
                'Ingham County' => [
                    'code' => '065',
                    'cities' => [
                        'Lansing' => ['48933', '48912'],
                    ],
                ],
            ];

            $allMiZipIds = [];
            $allMiZipStrings = [];

            foreach ($countiesData as $countyName => $cData) {
                $county = County::where('state_id', $miState->id)
                    ->where(function ($q) use ($countyName, $cData) {
                        $q->where('name', $countyName)
                          ->orWhere('slug', Str::slug($countyName))
                          ->orWhere('code', $cData['code']);
                    })
                    ->first();

                if (!$county) {
                    $county = County::create([
                        'id' => (string) Str::uuid(),
                        'state_id' => $miState->id,
                        'name' => $countyName,
                        'slug' => Str::slug($countyName),
                        'code' => $cData['code'],
                        'is_active' => true,
                    ]);
                } else {
                    $county->update([
                        'name' => $countyName,
                        'slug' => Str::slug($countyName),
                        'is_active' => true,
                    ]);
                }

                foreach ($cData['cities'] as $cityName => $zips) {
                    $city = City::where('county_id', $county->id)
                        ->where('name', $cityName)
                        ->first();

                    if (!$city) {
                        $city = City::create([
                            'id' => (string) Str::uuid(),
                            'county_id' => $county->id,
                            'name' => $cityName,
                            'slug' => Str::slug($cityName),
                            'code' => substr(md5($cityName), 0, 4),
                            'is_active' => true,
                        ]);
                    }

                    foreach ($zips as $zip) {
                        $zipRecord = ZipCode::where('code', $zip)->first();
                        if (!$zipRecord) {
                            $zipRecord = ZipCode::create([
                                'id' => (string) Str::uuid(),
                                'code' => $zip,
                                'state_id' => $miState->id,
                                'county_id' => $county->id,
                                'city_id' => $city->id,
                                'is_active' => true,
                            ]);
                        } else {
                            $zipRecord->update([
                                'state_id' => $miState->id,
                                'county_id' => $county->id,
                                'city_id' => $city->id,
                                'is_active' => true,
                            ]);
                        }

                        $allMiZipIds[] = $zipRecord->id;
                        $allMiZipStrings[] = $zip;
                    }
                }
            }

            $allMiZipStrings = array_values(array_unique($allMiZipStrings));
            $allMiZipIds = array_values(array_unique($allMiZipIds));

            // 3. Find active Agent User
            $agent = User::where('id', 2)->first() ?? User::where('email', 'agent@gmail.com')->first() ?? User::first();

            // 4. Create or Update Michigan Full Service MLS Package
            $michiganPackage = ServicePackage::updateOrCreate(
                [
                    'name' => 'Michigan Full Service MLS Package',
                    'agent_id' => $agent->id,
                ],
                [
                    'description' => 'Complete Michigan MLS listing package with statewide coverage, dynamic listing workflow, Michigan statutory seller disclosures, and EunoDoc digital signing.',
                    'price' => 495.00,
                    'is_active' => true,
                ]
            );

            // Sync all Michigan ZIP codes to this package
            $michiganPackage->zipCodes()->sync($allMiZipIds);

            // 5. Deactivate obsolete dummy test processes
            ListingProcess::where('name', 'testing')->update([
                'status' => 'inactive',
                'type' => 'custom',
            ]);

            // 6. Michigan Residential Listing Process (Primary)
            $residentialConfig = ListingProcessDefinitions::getResidentialProcessConfig();

            ListingProcess::updateOrCreate(
                [
                    'name' => 'Michigan Residential Listing Process',
                ],
                [
                    'type' => 'default',
                    'status' => 'active',
                    'agent_id' => null,
                    'service_package_id' => $michiganPackage->id,
                    'assigned_zips' => $allMiZipStrings,
                    'config' => $residentialConfig,
                ]
            );

            // Ensure the generic Residential Property Listing Process has the exact same config
            ListingProcess::updateOrCreate(
                [
                    'name' => 'Residential Property Listing Process',
                    'agent_id' => null,
                ],
                [
                    'type' => 'default',
                    'status' => 'active',
                    'service_package_id' => null,
                    'assigned_zips' => $allMiZipStrings,
                    'config' => $residentialConfig,
                ]
            );

            // 7. Michigan Vacant Land Listing Process
            $vacantLandConfig = ListingProcessDefinitions::getVacantLandProcessConfig();
            ListingProcess::updateOrCreate(
                [
                    'name' => 'Michigan Vacant Land Listing Process',
                ],
                [
                    'type' => 'default',
                    'status' => 'active',
                    'agent_id' => null,
                    'service_package_id' => null,
                    'assigned_zips' => $allMiZipStrings,
                    'config' => $vacantLandConfig,
                ]
            );
        });
    }
}
