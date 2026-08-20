<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CitySeeder extends Seeder
{
    /**
     * Cities are keyed by `[state code, county code]` to identify their
     * parent county unambiguously.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    public static function cities(): array
    {
        return [
            'CA' => [
                '001' => ['Los Angeles', 'Long Beach'],
                '002' => ['Anaheim', 'Irvine'],
            ],
            'TX' => [
                '001' => ['Houston', 'Pasadena'],
                '002' => ['Austin', 'Round Rock'],
            ],
            'NY' => [
                '001' => ['Albany', 'Colonie'],
                '002' => ['Buffalo', 'Cheektowaga'],
            ],
            'FL' => [
                '001' => ['Miami', 'Hialeah'],
                '002' => ['Orlando', 'Winter Park'],
            ],
            'MI' => [
                '001' => ['Ann Arbor', 'Ypsilanti'],
                '002' => ['Detroit', 'Dearborn'],
            ],
            'IL' => [
                '001' => ['Chicago', 'Evanston'],
                '002' => ['Naperville', 'Wheaton'],
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stateIds = DB::table('states')->pluck('id', 'code');
        $countyIds = DB::table('counties')->get(['id', 'state_id', 'code'])
            ->mapWithKeys(fn ($county) => ["{$county->state_id}:{$county->code}" => $county->id]);
        $now = now();

        $rows = [];
        foreach (self::cities() as $stateCode => $counties) {
            foreach ($counties as $countyCode => $cityNames) {
                $countyId = $countyIds["{$stateIds[$stateCode]}:{$countyCode}"];

                foreach ($cityNames as $index => $name) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'county_id' => $countyId,
                        'name' => $name,
                        'slug' => Str::slug($name),
                        'code' => str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::table('cities')->upsert($rows, ['county_id', 'code'], ['name', 'slug', 'is_active', 'updated_at']);
    }
}
