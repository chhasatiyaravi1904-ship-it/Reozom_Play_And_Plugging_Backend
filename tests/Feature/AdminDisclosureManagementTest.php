<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\PropertyDisclosure;
use App\Models\State;
use App\Models\User;
use App\Services\DisclosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDisclosureManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $seller;
    protected User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->buyer = User::factory()->create(['role' => 'buyer']);
    }

    public function test_non_admins_cannot_access_disclosure_management(): void
    {
        // Unauthenticated
        $this->getJson('/api/admin/disclosures')->assertStatus(401);

        // Seller
        $this->actingAs($this->seller)->getJson('/api/admin/disclosures')->assertStatus(403);
        $this->actingAs($this->seller)->postJson('/api/admin/disclosures', ['title' => 'Hack'])->assertStatus(403);

        // Buyer
        $this->actingAs($this->buyer)->getJson('/api/admin/disclosures')->assertStatus(403);
    }

    public function test_admin_can_list_and_create_dynamic_disclosures(): void
    {
        State::factory()->create(['code' => 'FL', 'name' => 'Florida', 'slug' => 'florida']);

        $payload = [
            'title' => 'Florida Hurricane & Flood Disclosure',
            'code' => 'fl_hurricane_flood',
            'state_code' => 'FL',
            'property_type' => 'residential',
            'category' => 'environmental',
            'description' => 'Mandatory hurricane shutter and flood zone verification.',
            'order' => 2,
            'is_required' => true,
            'is_active' => true,
            'schema' => [
                'sections' => [
                    [
                        'id' => 'sec_hurricane_shutters',
                        'name' => 'Hurricane Shutters & Impact Glass',
                        'order' => 1,
                        'fields' => [
                            [
                                'id' => 'impact_glass_installed',
                                'label' => 'Are all exterior windows impact-rated glass or protected by shutters?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'Yes - Full Impact Protection', 'value' => 'yes'],
                                    ['label' => 'Partial Protection', 'value' => 'partial'],
                                    ['label' => 'No Protection', 'value' => 'no'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/admin/disclosures', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('property_disclosures', [
            'code' => 'fl_hurricane_flood',
            'state_code' => 'FL',
            'property_type' => 'residential',
            'is_active' => 1,
        ]);

        $listRes = $this->actingAs($this->admin)->getJson('/api/admin/disclosures');
        $listRes->assertStatus(200);
        $this->assertCount(1, $listRes->json('data'));
    }

    public function test_admin_can_update_toggle_and_delete_disclosure(): void
    {
        $disclosure = PropertyDisclosure::create([
            'title' => 'Original Radon Disclosure',
            'code' => 'radon_test',
            'state_code' => 'MI',
            'property_type' => 'residential',
            'category' => 'statutory',
            'is_active' => true,
            'is_required' => true,
            'schema' => ['sections' => []],
        ]);

        // 1. Update
        $updateRes = $this->actingAs($this->admin)->putJson("/api/admin/disclosures/{$disclosure->id}", [
            'title' => 'Updated Radon & Gas Hazard Disclosure',
            'is_required' => false,
        ]);
        $updateRes->assertStatus(200);
        $this->assertEquals('Updated Radon & Gas Hazard Disclosure', $disclosure->fresh()->title);
        $this->assertFalse($disclosure->fresh()->is_required);

        // 2. Toggle Status (Active -> Draft)
        $toggleRes = $this->actingAs($this->admin)->postJson("/api/admin/disclosures/{$disclosure->id}/toggle");
        $toggleRes->assertStatus(200);
        $this->assertFalse($disclosure->fresh()->is_active);

        // 3. Delete (Admin Only)
        $deleteRes = $this->actingAs($this->admin)->deleteJson("/api/admin/disclosures/{$disclosure->id}");
        $deleteRes->assertStatus(200);
        $this->assertDatabaseMissing('property_disclosures', ['id' => $disclosure->id]);
    }

    private function minimalPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Septic Disclosure',
            'schema' => ['sections' => [['id' => 's1', 'name' => 'General', 'fields' => []]]],
        ], $overrides);
    }

    public function test_state_scope_must_be_a_known_state_or_all_states(): void
    {
        State::factory()->create(['code' => 'MI', 'name' => 'Michigan', 'slug' => 'michigan']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/disclosures', $this->minimalPayload(['state_code' => 'XX']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('state_code');

        $this->actingAs($this->admin)
            ->postJson('/api/admin/disclosures', $this->minimalPayload(['code' => 'mi_one', 'state_code' => 'mi']))
            ->assertStatus(201);
        $this->assertDatabaseHas('property_disclosures', ['code' => 'mi_one', 'state_code' => 'MI']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/disclosures', $this->minimalPayload(['code' => 'all_one', 'state_code' => 'ALL']))
            ->assertStatus(201);
        $this->assertDatabaseHas('property_disclosures', ['code' => 'all_one', 'state_code' => null]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/disclosures', $this->minimalPayload(['code' => 'none_one', 'state_code' => null]))
            ->assertStatus(201);
        $this->assertDatabaseHas('property_disclosures', ['code' => 'none_one', 'state_code' => null]);
    }

    public function test_update_rejects_unknown_state_and_allows_switching_to_all_states(): void
    {
        State::factory()->create(['code' => 'MI', 'name' => 'Michigan', 'slug' => 'michigan']);
        $disclosure = PropertyDisclosure::create([
            'title' => 'Scoped', 'code' => 'scoped', 'state_code' => 'MI',
            'category' => 'statutory', 'is_active' => true, 'is_required' => true,
            'schema' => ['sections' => []],
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/disclosures/{$disclosure->id}", ['state_code' => 'ZZ'])
            ->assertStatus(422);
        $this->assertSame('MI', $disclosure->fresh()->state_code);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/disclosures/{$disclosure->id}", ['state_code' => null])
            ->assertStatus(200);
        $this->assertNull($disclosure->fresh()->state_code);
    }

    public function test_new_disclosure_defaults_when_admin_form_omits_hidden_fields(): void
    {
        PropertyDisclosure::create([
            'title' => 'Existing', 'code' => 'existing', 'order' => 7,
            'category' => 'statutory', 'is_active' => true, 'is_required' => true,
            'schema' => ['sections' => []],
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/disclosures', $this->minimalPayload(['code' => 'fresh_one']))
            ->assertStatus(201);

        $fresh = PropertyDisclosure::where('code', 'fresh_one')->first();
        $this->assertSame(8, $fresh->order);
        $this->assertFalse($fresh->is_required);
        $this->assertSame('custom', $fresh->category);
        $this->assertTrue($fresh->is_active);
    }

    public function test_update_without_hidden_fields_keeps_existing_values(): void
    {
        $disclosure = PropertyDisclosure::create([
            'title' => 'Seeded', 'code' => 'seeded', 'order' => 3,
            'category' => 'statutory', 'is_active' => true, 'is_required' => true,
            'schema' => ['sections' => []],
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/disclosures/{$disclosure->id}", ['title' => 'Seeded Renamed'])
            ->assertStatus(200);

        $fresh = $disclosure->fresh();
        $this->assertSame('statutory', $fresh->category);
        $this->assertSame(3, $fresh->order);
        $this->assertTrue($fresh->is_required);
        $this->assertTrue($fresh->is_active);
    }

    public function test_duplicate_is_placed_at_end_of_list(): void
    {
        $first = PropertyDisclosure::create([
            'title' => 'First', 'code' => 'first', 'order' => 1,
            'category' => 'statutory', 'is_active' => true, 'is_required' => true,
            'schema' => ['sections' => []],
        ]);
        PropertyDisclosure::create([
            'title' => 'Second', 'code' => 'second', 'order' => 2,
            'category' => 'statutory', 'is_active' => true, 'is_required' => true,
            'schema' => ['sections' => []],
        ]);

        $res = $this->actingAs($this->admin)->postJson("/api/admin/disclosures/{$first->id}/duplicate");
        $res->assertStatus(201);
        $this->assertSame(3, PropertyDisclosure::find($res->json('data.id'))->order);
    }

    public function test_listing_loads_dynamic_admin_disclosures(): void
    {
        PropertyDisclosure::create([
            'title' => 'Custom Septic & Well Disclosure',
            'code' => 'custom_septic_well',
            'state_code' => 'MI',
            'property_type' => 'residential',
            'category' => 'statutory',
            'is_active' => true,
            'is_required' => true,
            'schema' => [
                'sections' => [
                    [
                        'id' => 'sec_well',
                        'name' => 'Well Water Quality',
                        'fields' => [
                            [
                                'id' => 'well_flow_rate',
                                'label' => 'Tested flow rate (GPM):',
                                'type' => 'text',
                                'required' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $listing = Listing::create([
            'user_id' => $this->seller->id,
            'address' => '456 Country Lane',
            'city' => 'Dexter',
            'state' => 'MI',
            'zip' => '48130',
            'status' => 'in_progress',
        ]);

        $service = new DisclosureService();
        $schema = $service->getDisclosureSchema($listing);

        $this->assertNotEmpty($schema);
        $this->assertEquals('custom_septic_well', $schema[0]['id']);
        $this->assertEquals('Custom Septic & Well Disclosure', $schema[0]['name']);
    }
}

