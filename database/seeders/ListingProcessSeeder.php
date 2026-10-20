<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
        DB::transaction(function () {
            // 1. Default Residential Property Listing Process (Michigan Residential Type 1)
            ListingProcess::updateOrCreate(
                [
                    'name' => 'Residential Property Listing Process',
                    'agent_id' => null,
                ],
                [
                    'type' => 'default',
                    'status' => 'active',
                    'assigned_zips' => [],
                    'config' => ListingProcessDefinitions::getResidentialProcessConfig(),
                ]
            );

            // 2. Default Vacant Land Listing Process (Michigan Vacant Land Type 2)
            ListingProcess::updateOrCreate(
                [
                    'name' => 'Vacant Land Listing Process',
                    'agent_id' => null,
                ],
                [
                    'type' => 'default',
                    'status' => 'active',
                    'assigned_zips' => [],
                    'config' => ListingProcessDefinitions::getVacantLandProcessConfig(),
                ]
            );

            // 3. Sample Process Builder (retained for backward compatibility, updated to follow schema)
            $agent = User::where('email', 'krupalic@gmail.com')->first();
            $agentId = $agent ? $agent->id : null;

            $sampleConfig = [];
            for ($i = 1; $i <= 5; $i++) {
                $sampleConfig[] = [
                    'id' => 'step_' . $i,
                    'order' => $i,
                    'name' => 'Step ' . $i,
                    'description' => 'This is Step ' . $i . ' of the process.',
                    'sections' => [
                        [
                            'id' => 'section_1_' . $i,
                            'order' => 1,
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

            ListingProcess::updateOrCreate(
                [
                    'name' => '5 Step Process Builder',
                    'agent_id' => $agentId,
                ],
                [
                    'type' => 'custom',
                    'status' => 'active',
                    'assigned_zips' => [],
                    'config' => $sampleConfig,
                ]
            );
        });
    }
}

