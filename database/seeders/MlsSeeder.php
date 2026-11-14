<?php

namespace Database\Seeders;

use App\Models\MlsDirectory;
use App\Models\MlsInfo;
use Illuminate\Database\Seeder;

class MlsSeeder extends Seeder
{
    /**
     * Run the MLS seeders.
     */
    public function run(): void
    {
        $directories = [
            [
                'title' => 'Realcomp II Ltd MLS',
                'infos' => [
                    [
                        'title' => 'Realcomp Primary Service',
                        'countries' => ['United States'],
                        'public_websites_title' => 'Realcomp Move In Michigan Portal',
                        'websites' => ['https://realcomp.moveinmichigan.com', 'https://www.realtor.com'],
                        'info' => 'Realcomp II Ltd. is Michigan\'s largest REALTOR-owned Multiple Listing Service, serving over 17,000 valued broker and agent subscribers across Southeastern Michigan and Metro Detroit.',
                    ],
                ],
            ],
            [
                'title' => 'MiRealSource MLS',
                'infos' => [
                    [
                        'title' => 'MiRealSource Statewide Feed',
                        'countries' => ['United States'],
                        'public_websites_title' => 'MiRealSource Property Finder',
                        'websites' => ['https://www.mirealsource.com'],
                        'info' => 'MiRealSource provides MLS technology and reliable real estate listing syndication throughout the state of Michigan.',
                    ],
                ],
            ],
            [
                'title' => 'Great Lakes Repository (GLR)',
                'infos' => [
                    [
                        'title' => 'Great Lakes Data Share',
                        'countries' => ['United States'],
                        'public_websites_title' => 'Great Lakes Regional MLS',
                        'websites' => ['https://www.greatlakesmls.com'],
                        'info' => 'Multi-board data cooperative providing shared MLS coverage across western, central, and northern Michigan.',
                    ],
                ],
            ],
            [
                'title' => 'California Regional MLS (CRMLS)',
                'infos' => [
                    [
                        'title' => 'CRMLS Greater Los Angeles & Orange County',
                        'countries' => ['United States'],
                        'public_websites_title' => 'CRMLS Public Search',
                        'websites' => ['https://crmls.org'],
                        'info' => 'The largest and most recognized state MLS in the United States, providing comprehensive property data in Southern California.',
                    ],
                ],
            ],
            [
                'title' => 'ACTRIS MLS (Austin Central Texas)',
                'infos' => [
                    [
                        'title' => 'Austin Board of REALTORS ACTRIS',
                        'countries' => ['United States'],
                        'public_websites_title' => 'Austin Home Search',
                        'websites' => ['https://www.abor.com', 'https://www.austinhomesearch.com'],
                        'info' => 'Central Texas regional MLS providing accurate and timely listing data for Austin, Travis County, and surrounding markets.',
                    ],
                ],
            ],
        ];

        foreach ($directories as $dirData) {
            $directory = MlsDirectory::firstOrCreate(
                ['title' => $dirData['title']],
            );

            foreach ($dirData['infos'] as $infoData) {
                MlsInfo::updateOrCreate(
                    [
                        'mls_directory_id' => $directory->id,
                        'title' => $infoData['title'],
                    ],
                    [
                        'countries' => $infoData['countries'],
                        'public_websites_title' => $infoData['public_websites_title'],
                        'websites' => $infoData['websites'],
                        'info' => $infoData['info'],
                    ]
                );
            }
        }
    }
}
