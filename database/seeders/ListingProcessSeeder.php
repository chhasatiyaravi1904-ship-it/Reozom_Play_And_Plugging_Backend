<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ListingProcess;
use App\Models\User;

class ListingProcessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $agent = User::where('email', 'krupalic@gmail.com')->first();
        $agentId = $agent ? $agent->id : null;

        $config = [];
        
        for ($i = 1; $i <= 5; $i++) {
            $config[] = [
                'id' => 'step_' . $i,
                'name' => 'Step ' . $i,
                'description' => 'This is Step ' . $i . ' of the process.',
                'sections' => [
                    [
                        'id' => 'section_1_' . $i,
                        'name' => 'Section ' . $i,
                        'description' => 'Section for Step ' . $i,
                        'fields' => [
                            [
                                'id' => 'field_1_' . $i,
                                'label' => 'Example Field ' . $i,
                                'name' => 'example_field_' . $i,
                                'type' => 'text',
                                'required' => false,
                                'active' => true,
                            ]
                        ]
                    ]
                ]
            ];
        }

        ListingProcess::create([
            'name' => '5 Step Process Builder',
            'type' => 'custom',
            'status' => 'active',
            'agent_id' => $agentId,
            'assigned_zips' => [],
            'config' => $config,
        ]);
    }
}
