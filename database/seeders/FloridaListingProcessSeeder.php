<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\County;
use App\Models\ListingProcess;
use App\Models\ServicePackage;
use App\Models\State;
use App\Models\User;
use App\Models\ZipCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FloridaListingProcessSeeder extends Seeder
{
    /**
     * Seed Florida locations, the Florida service package and Florida listing processes.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $flState = State::firstOrCreate(
                ['code' => 'FL'],
                [
                    'id' => (string) Str::uuid(),
                    'name' => 'Florida',
                    'slug' => 'florida',
                    'is_active' => true,
                ]
            );

            $countiesData = [
                'Miami-Dade County' => [
                    'code' => '001',
                    'cities' => [
                        'Miami' => ['33101', '33131', '33139'],
                        'Hialeah' => ['33010', '33012'],
                    ],
                ],
                'Orange County' => [
                    'code' => '002',
                    'cities' => [
                        'Orlando' => ['32801', '32803'],
                        'Winter Park' => ['32789', '32792'],
                    ],
                ],
                'Hillsborough County' => [
                    'code' => '003',
                    'cities' => [
                        'Tampa' => ['33602', '33606', '33629'],
                        'Brandon' => ['33510', '33511'],
                    ],
                ],
                'Broward County' => [
                    'code' => '004',
                    'cities' => [
                        'Fort Lauderdale' => ['33301', '33304', '33308'],
                        'Hollywood' => ['33019', '33021'],
                    ],
                ],
            ];

            $zipIds = [];
            $zipStrings = [];

            foreach ($countiesData as $countyName => $cData) {
                $county = County::where('state_id', $flState->id)
                    ->where(function ($q) use ($countyName, $cData) {
                        $q->where('name', $countyName)
                            ->orWhere('slug', Str::slug($countyName))
                            ->orWhere('code', $cData['code']);
                    })
                    ->first();

                if (! $county) {
                    $county = County::create([
                        'id' => (string) Str::uuid(),
                        'state_id' => $flState->id,
                        'name' => $countyName,
                        'slug' => Str::slug($countyName),
                        'code' => $cData['code'],
                        'is_active' => true,
                    ]);
                }

                foreach ($cData['cities'] as $cityName => $zips) {
                    $city = City::where('county_id', $county->id)->where('name', $cityName)->first()
                        ?? City::create([
                            'id' => (string) Str::uuid(),
                            'county_id' => $county->id,
                            'name' => $cityName,
                            'slug' => Str::slug($cityName),
                            'code' => substr(md5($cityName), 0, 4),
                            'is_active' => true,
                        ]);

                    foreach ($zips as $zip) {
                        $zipRecord = ZipCode::updateOrCreate(
                            ['code' => $zip],
                            [
                                'state_id' => $flState->id,
                                'county_id' => $county->id,
                                'city_id' => $city->id,
                                'is_active' => true,
                            ]
                        );

                        $zipIds[] = $zipRecord->id;
                        $zipStrings[] = $zip;
                    }
                }
            }

            $zipIds = array_values(array_unique($zipIds));
            $zipStrings = array_values(array_unique($zipStrings));

            $agent = User::where('email', 'agent@example.com')->first()
                ?? User::where('role', \App\Enums\UserRole::Agent)->first()
                ?? User::first();

            $floridaPackage = ServicePackage::updateOrCreate(
                [
                    'name' => 'Florida Full Service MLS Package',
                    'agent_id' => $agent->id,
                ],
                [
                    'description' => 'Complete Florida MLS listing package with statewide coverage, dynamic listing workflow, Florida statutory seller disclosures (sinkhole, flood zone, HOA, SB 4-D milestone), and EunoDoc digital signing.',
                    'price' => 545.00,
                    'is_active' => true,
                ]
            );

            $floridaPackage->zipCodes()->sync($zipIds);

            // SF and Condo disclosures are both attached; each step only shows for its property sub type.
            $residentialCodes = ['fl_residential_single_family', 'fl_residential_condo', 'lead_paint_disclosure'];

            $flResidentialProcess = ListingProcess::updateOrCreate(
                ['name' => 'Florida Residential Listing Process'],
                [
                    'type' => 'default',
                    'status' => 'active',
                    'agent_id' => null,
                    'assigned_zips' => $zipStrings,
                    'required_disclosures' => $residentialCodes,
                    'config' => ListingProcessDefinitions::withDisclosureSteps(
                        ListingProcessDefinitions::getResidentialProcessConfig(),
                        ListingProcessDefinitions::RESIDENTIAL_LEGACY_DISCLOSURE_STEPS,
                        $residentialCodes
                    ),
                ]
            );

            // Sellers of the Florida package use this process unless the package already has one.
            if ($floridaPackage->listing_process_id === null) {
                $floridaPackage->update(['listing_process_id' => $flResidentialProcess->id]);
            }

            // Florida has exactly one listing process (as in the original Reozom). The fixed steps
            // (Location on the Map, Pictures & Virtual Tours, Payment) come from enforceLockedSteps.
        });
    }
}
