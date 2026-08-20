<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CountySeeder extends Seeder
{
    /**
     * Counties are looked up by `[state code, county code]` elsewhere
     * (CitySeeder), so this list is the single source of truth for which
     * counties exist.
     *
     * @return array<string, array<string, string>>
     */
    public static function counties(): array
    {
        return [
            'CA' => ['001' => 'Los Angeles County', '002' => 'Orange County'],
            'TX' => ['001' => 'Harris County', '002' => 'Travis County'],
            'NY' => ['001' => 'Albany County', '002' => 'Erie County'],
            'FL' => ['001' => 'Miami-Dade County', '002' => 'Orange County'],
            'MI' => ['001' => 'Washtenaw County', '002' => 'Wayne County'],
            'IL' => ['001' => 'Cook County', '002' => 'DuPage County'],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stateIds = DB::table('states')->pluck('id', 'code');
        $now = now();

        $rows = [];
        foreach (self::counties() as $stateCode => $counties) {
            foreach ($counties as $code => $name) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'state_id' => $stateIds[$stateCode],
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'code' => $code,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('counties')->upsert($rows, ['state_id', 'code'], ['name', 'slug', 'is_active', 'updated_at']);
    }
}
