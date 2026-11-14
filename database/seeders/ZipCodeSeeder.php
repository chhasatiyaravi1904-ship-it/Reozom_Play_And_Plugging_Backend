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
                '002' => [
                    'Anaheim' => ['92801', '92805'],
                    'Irvine' => ['92602', '92618'],
                ],
            ],
            'TX' => [
                '001' => [
                    'Houston' => ['77001', '77002'],
                    'Pasadena' => ['77502', '77504'],
                ],
                '002' => [
                    'Austin' => ['78701', '78702'],
                    'Round Rock' => ['78664', '78681'],
                ],
            ],
            'NY' => [
                '001' => [
                    'Albany' => ['12201', '12202'],
                    'Colonie' => ['12205'],
                ],
                '002' => [
                    'Buffalo' => ['14201', '14202'],
                    'Cheektowaga' => ['14225'],
                ],
            ],
            'FL' => [
                '001' => [
                    'Miami' => ['33101', '33131', '33139'],
                    'Hialeah' => ['33010', '33012'],
                ],
                '002' => [
                    'Orlando' => ['32801', '32803'],
                    'Winter Park' => ['32789', '32792'],
                ],
            ],
            'MI' => [
                '001' => [
                    'Ann Arbor' => ['48103', '48104', '48105', '48108'],
                    'Ypsilanti' => ['48197', '48198'],
                ],
                '002' => [
                    'Detroit' => ['48201', '48202', '48226', '48206', '48214', '48221'],
                    'Dearborn' => ['48120', '48124', '48126'],
                ],
            ],
            'IL' => [
                '001' => [
                    'Chicago' => ['60601', '60611', '60614'],
                    'Evanston' => ['60201', '60202'],
                ],
                '002' => [
                    'Naperville' => ['60540', '60563'],
                    'Wheaton' => ['60187', '60189'],
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
