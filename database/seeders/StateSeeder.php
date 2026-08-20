<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StateSeeder extends Seeder
{
    /**
     * States are looked up by `code` elsewhere (CountySeeder), so this list
     * is the single source of truth for which states exist.
     *
     * @return array<int, string>
     */
    public static function states(): array
    {
        return [
            'CA' => 'California',
            'TX' => 'Texas',
            'NY' => 'New York',
            'FL' => 'Florida',
            'MI' => 'Michigan',
            'IL' => 'Illinois',
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $rows = collect(self::states())->map(fn (string $name, string $code) => [
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name),
            'code' => $code,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        DB::table('states')->upsert($rows, ['code'], ['name', 'slug', 'is_active', 'updated_at']);
    }
}
