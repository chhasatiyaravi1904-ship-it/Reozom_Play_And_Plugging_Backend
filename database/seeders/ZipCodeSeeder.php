<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ZipCodeSeeder extends Seeder
{
    /**
     * Zip codes mapped by [state code => [county code => [city name => [zip codes]]]]
     *
     * @return array
     */
    public static function zipCodes(): array
    {
        return [
            'CA' => [
                '001' => [
                    'Los Angeles' => ['90001', '90002'],
                    'Long Beach' => ['90801', '90802'],
                ],
            ],
            'TX' => [
                '001' => [
                    'Houston' => ['77001', '77002'],
                ],
                '002' => [
                    'Austin' => ['78701', '78702'],
                ],
            ],
            'NY' => [
                '001' => [
                    'Albany' => ['12201', '12202'],
                ],
            ],
            'MI' => [
                '001' => [
                    'Ann Arbor' => ['48103', '48104', '48105', '48108'],
                ],
            ],
        ];
    }

    public function run(): void
    {
        $stateIds = DB::table('states')->pluck('id', 'code');
        $countyIds = DB::table('counties')->get(['id', 'state_id', 'code'])
            ->mapWithKeys(fn ($county) => ["{$county->state_id}:{$county->code}" => $county->id]);
        $cityIds = DB::table('cities')->get(['id', 'county_id', 'name'])
            ->mapWithKeys(fn ($city) => ["{$city->county_id}:{$city->name}" => $city->id]);
            
        $now = now();
        $rows = [];

        foreach (self::zipCodes() as $stateCode => $counties) {
            foreach ($counties as $countyCode => $cities) {
                if (!isset($stateIds[$stateCode])) continue;
                
                $stateId = $stateIds[$stateCode];
                $countyKey = "{$stateId}:{$countyCode}";
                
                if (!isset($countyIds[$countyKey])) continue;
                $countyId = $countyIds[$countyKey];

                foreach ($cities as $cityName => $zips) {
                    $cityKey = "{$countyId}:{$cityName}";
                    
                    if (!isset($cityIds[$cityKey])) continue;
                    $cityId = $cityIds[$cityKey];

                    foreach ($zips as $zip) {
                        $rows[] = [
                            'id' => (string) Str::uuid(),
                            'code' => $zip,
                            'state_id' => $stateId,
                            'county_id' => $countyId,
                            'city_id' => $cityId,
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }
        }

        DB::table('zip_codes')->upsert($rows, ['code'], ['state_id', 'county_id', 'city_id', 'is_active', 'updated_at']);
    }
}
