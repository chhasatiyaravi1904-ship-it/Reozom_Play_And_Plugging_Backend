<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Models\ListingProcess;
use App\Models\User;
use Database\Seeders\ListingProcessDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ListingPublicDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
    }

    private function listing(array $snapshot, array $answers, array $attributes = []): Listing
    {
        $listing = Listing::factory()->create(array_merge([
            'user_id' => $this->seller->id,
            'address' => '1 Ocean Drive',
            'city' => 'Miami',
            'state' => 'FL',
            'workflow_snapshot' => $snapshot,
        ], $attributes));

        foreach ($answers as $stepId => $values) {
            ListingAnswer::create(['listing_id' => $listing->id, 'step_id' => $stepId, 'values' => $values]);
        }

        return $listing->fresh();
    }

    private function publicValues(Listing $listing): array
    {
        return array_column($listing->getPublicListingData()['fields'], 'value', 'id');
    }

    public function test_card_data_comes_from_the_regular_residential_steps(): void
    {
        $listing = $this->listing(ListingProcessDefinitions::getResidentialProcessConfig(), [
            'step_property_type_address' => ['property_sub_type' => '2'],
            'step_property_information' => ['list_price' => 450000, 'ag_square_footage' => 1400, 'year_built' => 2004],
            'step_room_data' => ['total_bedrooms' => 2, 'total_bathrooms' => 2],
        ]);

        $values = $this->publicValues($listing);

        $this->assertSame('Condominium – 1 Ocean Drive, Miami', $values['listing_title']);
        $this->assertEquals(450000, $values['listing_price']);
        $this->assertSame('Condominium', $values['property_type']);
        $this->assertEquals(2, $values['bedrooms']);
        $this->assertEquals(2, $values['bathrooms']);
        $this->assertEquals(1400, $values['square_feet']);

        $resource = (new ListingResource($listing->load('answers')))->toArray(Request::create('/'));
        $this->assertSame(450000.0, $resource['price']);
        $this->assertSame('condo', $resource['propertyType']);
        $this->assertSame(2, $resource['bedrooms']);
    }

    public function test_vacant_land_card_shows_acreage_not_bedrooms(): void
    {
        $listing = $this->listing(ListingProcessDefinitions::getVacantLandProcessConfig(), [
            'step_property_information' => ['list_price' => 99000, 'acreage' => 5.5],
        ]);

        $values = $this->publicValues($listing);

        $this->assertSame('Vacant Land', $values['property_type']);
        $this->assertSame('Vacant Land – 1 Ocean Drive, Miami', $values['listing_title']);
        $this->assertEquals(5.5, $values['acreage']);
        $this->assertArrayNotHasKey('bedrooms', $values);
    }

    public function test_old_listings_still_use_their_public_info_answers(): void
    {
        $snapshot = array_merge(
            [['id' => 'system_public_info', 'name' => 'Public Listing Information', 'sections' => [['id' => 's', 'fields' => [['id' => 'listing_price', 'name' => 'listing_price', 'label' => 'Price', 'type' => 'number']]]]]],
            ListingProcessDefinitions::getResidentialProcessConfig()
        );
        $listing = $this->listing($snapshot, [
            'system_public_info' => ['listing_title' => 'Charming Craftsman', 'listing_price' => 540000, 'property_type' => 'single_family', 'bedrooms' => 4],
            'step_property_information' => ['list_price' => 1],
        ]);

        $values = $this->publicValues($listing);

        $this->assertSame('Charming Craftsman', $values['listing_title']);
        $this->assertEquals(540000, $values['listing_price']);
        $this->assertSame('Single Family Home', $values['property_type']);
        $this->assertEquals(4, $values['bedrooms']);
    }

    public function test_price_quick_edit_updates_the_property_information_step(): void
    {
        $listing = $this->listing(ListingProcessDefinitions::getResidentialProcessConfig(), [
            'step_property_information' => ['list_price' => 300000],
        ]);

        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/price", ['price' => 325000])->assertOk();

        $info = ListingAnswer::where('listing_id', $listing->id)->where('step_id', 'step_property_information')->first();
        $this->assertEquals(325000, $info->values['list_price']);
        $this->assertDatabaseMissing('listing_answers', ['listing_id' => $listing->id, 'step_id' => 'system_public_info']);
        $this->assertEquals(325000, $this->publicValues($listing->fresh())['listing_price']);
    }

    public function test_price_quick_edit_on_an_old_listing_updates_its_public_info_answer(): void
    {
        $listing = $this->listing(ListingProcessDefinitions::getResidentialProcessConfig(), [
            'system_public_info' => ['listing_price' => 540000],
            'step_property_information' => ['list_price' => 540000],
        ]);

        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/price", ['price' => 499000])->assertOk();

        $this->assertEquals(499000, $this->publicValues($listing->fresh())['listing_price']);
    }

    public function test_title_quick_edit_overrides_the_generated_title(): void
    {
        $listing = $this->listing(ListingProcessDefinitions::getResidentialProcessConfig(), [
            'step_property_type_address' => ['property_sub_type' => '1'],
        ]);

        $this->actingAs($this->seller)->putJson("/api/listings/{$listing->id}/remarks", [
            'listing_title' => 'Sunny Family Home',
            'remarks' => 'Close to the beach.',
        ])->assertOk();

        $listing = $listing->fresh();
        $this->assertSame('Sunny Family Home', $this->publicValues($listing)['listing_title']);
        $this->assertSame('Close to the beach.', $listing->publicValue('remarks'));
    }

    public function test_disclosure_filter_uses_property_sub_type(): void
    {
        $listing = $this->listing(ListingProcessDefinitions::getResidentialProcessConfig(), [
            'step_property_type_address' => ['property_sub_type' => '2'],
        ]);

        $this->assertSame('condo', $listing->publicPropertyType());
    }
}
