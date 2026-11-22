<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Document sets per state and property type, matching the original Reozom
 * (Services/Listings/{State}/{Type}/DocumentsService):
 *  - FL Residential: SF Disclosure, Condo Disclosure, Mold, HOA, Lead, Radon, Listing Agreement, Services Provided, Agency Disclosure
 *  - MI Residential: Lead, Listing Agreement, Real Estate Disclosure, Seller's Disclosure, Services Provided
 *    (GRAR exists in Reozom but is never created, so its template is inactive)
 *  - FL / MI Vacant Land: Listing Agreement, Real Estate Disclosure, Seller's Disclosure, Services Provided
 *
 * Disclosure documents are rendered from the listing's own disclosure steps (including questions an
 * agent added). The text of the agreement-type documents is a generic starting point that admins
 * should replace with the official state forms.
 */
class DocumentTemplateSeeder extends Seeder
{
    /** Trust-owned, non-owner-occupied properties skip the seller disclosures (as in Reozom). */
    private const TRUST_NON_OWNER = [
        'all' => [
            ['field' => 'owner_type', 'equals' => 'trust'],
            ['field' => 'owner_type_trust_type', 'equals' => 'non_owner_occupied'],
        ],
    ];

    public function run(): void
    {
        $notTrust = ['not' => self::TRUST_NON_OWNER];
        // Reozom creates the Lead Disclosure for every MI/FL residential listing (whatever the year built),
        // except trust-owned, non-owner-occupied properties
        $lead = $notTrust;

        $templates = [
            // Florida – Residential
            $this->disclosure('fl_res_sf_disclosure', "Seller's Property Disclosure – Single Family", 'FL', 'residential', ['fl_residential_single_family'], 1),
            $this->disclosure('fl_res_condo_disclosure', "Seller's Property Disclosure – Condominium", 'FL', 'residential', ['fl_residential_condo'], 2),
            $this->static('fl_res_mold', 'Mold Disclosure', 'FL', 'residential', 3, $notTrust,
                'Mold is naturally occurring and may cause health risks or damage to property. The Seller discloses that the Seller has disclosed all known mold conditions in the Seller\'s Property Disclosure. The Buyer is advised to have the property inspected for mold.'),
            $this->static('fl_res_hoa', 'Homeowners Association Disclosure', 'FL', 'residential', 4,
                ['all' => [$notTrust, ['field' => 'field_11', 'equals' => 'yes']]],
                'The property is subject to a homeowners\' association with mandatory membership. The Buyer should obtain and review the association documents, fees and assessments before signing a contract.'),
            $this->disclosure('fl_res_lead', 'Lead-Based Paint Disclosure', 'FL', 'residential', ['lead_paint_disclosure'], 5, $lead),
            $this->static('fl_res_radon', 'Radon Gas Disclosure', 'FL', 'residential', 6, $notTrust,
                'Radon is a naturally occurring radioactive gas that, when accumulated in a building in sufficient quantities, may present health risks. Additional information regarding radon and radon testing may be obtained from the county health department.'),
            $this->static('fl_res_listing_agreement', 'Exclusive Right to Sell Listing Agreement', 'FL', 'residential', 7, null,
                'The Seller, {{seller_name}}, grants the brokerage the exclusive right to list the property at {{property_address}} for the list price of {{list_price}} under the {{package_name}} service package.'),
            $this->static('fl_res_services_provided', 'Services Provided Agreement', 'FL', 'residential', 8, null,
                'This agreement describes the services included in the {{package_name}} package for the property at {{property_address}}.'),
            $this->static('fl_res_agency_disclosure', 'Agency Disclosure', 'FL', 'residential', 9, null,
                'This notice describes the brokerage relationship between the Seller, {{seller_name}}, and the brokerage for the property at {{property_address}}.'),

            // Michigan – Residential
            $this->disclosure('mi_res_lead', 'Lead-Based Paint Disclosure', 'MI', 'residential', ['lead_paint_disclosure'], 1, $lead),
            $this->static('mi_res_listing_agreement', 'Exclusive Right to Sell Listing Agreement', 'MI', 'residential', 2, null,
                'The Seller, {{seller_name}}, grants the brokerage the exclusive right to list the property at {{property_address}} for the list price of {{list_price}} under the {{package_name}} service package.'),
            $this->static('mi_res_real_estate_disclosure', 'Disclosure Regarding Real Estate Agency Relationships', 'MI', 'residential', 3, null,
                'This disclosure describes the agency relationship between the Seller, {{seller_name}}, and the brokerage for the property at {{property_address}}.'),
            $this->disclosure('mi_res_sellers_disclosure', "Seller's Disclosure Statement", 'MI', 'residential', ['mi_residential_appliances', 'mi_residential_conditions'], 4),
            $this->static('mi_res_services_provided', 'Services Provided Agreement', 'MI', 'residential', 5, null,
                'This agreement describes the services included in the {{package_name}} package for the property at {{property_address}}.'),
            $this->static('mi_res_grar', 'GRAR Addendum', 'MI', 'residential', 6, null,
                'Addendum to the listing agreement for the property at {{property_address}}, required by the local REALTOR® association.'),

            // Florida – Vacant Land
            $this->static('fl_land_listing_agreement', 'Exclusive Right to Sell Listing Agreement', 'FL', 'vacant_land', 1, null,
                'The Seller, {{seller_name}}, grants the brokerage the exclusive right to list the land at {{property_address}} for the list price of {{list_price}} under the {{package_name}} service package.'),
            $this->static('fl_land_real_estate_disclosure', 'Real Estate Agency Disclosure', 'FL', 'vacant_land', 2, null,
                'This disclosure describes the agency relationship between the Seller, {{seller_name}}, and the brokerage for the land at {{property_address}}.'),
            $this->disclosure('fl_land_sellers_disclosure', "Seller's Disclosure – Vacant Land", 'FL', 'vacant_land', ['fl_vacant_land_disclosure'], 3),
            $this->static('fl_land_services_provided', 'Services Provided Agreement', 'FL', 'vacant_land', 4, null,
                'This agreement describes the services included in the {{package_name}} package for the land at {{property_address}}.'),

            // Michigan – Vacant Land
            $this->static('mi_land_listing_agreement', 'Exclusive Right to Sell Listing Agreement', 'MI', 'vacant_land', 1, null,
                'The Seller, {{seller_name}}, grants the brokerage the exclusive right to list the land at {{property_address}} for the list price of {{list_price}} under the {{package_name}} service package.'),
            $this->static('mi_land_real_estate_disclosure', 'Disclosure Regarding Real Estate Agency Relationships', 'MI', 'vacant_land', 2, $notTrust,
                'This disclosure describes the agency relationship between the Seller, {{seller_name}}, and the brokerage for the land at {{property_address}}.'),
            $this->disclosure('mi_land_sellers_disclosure', "Seller's Disclosure – Vacant Land", 'MI', 'vacant_land', ['mi_vacant_land_disclosure'], 3),
            // Reozom: a non-owner-occupied trust keeps only the Listing Agreement for MI vacant land
            $this->static('mi_land_services_provided', 'Services Provided Agreement', 'MI', 'vacant_land', 4, $notTrust,
                'This agreement describes the services included in the {{package_name}} package for the land at {{property_address}}.'),
        ];

        // Reozom "free listing" documents: the disclosures, created before the listing is paid.
        // Everything else (listing agreement, services provided, agency) is created once it is paid.
        $beforePayment = [
            'fl_res_sf_disclosure', 'fl_res_condo_disclosure', 'fl_res_mold', 'fl_res_hoa', 'fl_res_lead', 'fl_res_radon',
            'mi_res_lead', 'mi_res_sellers_disclosure',
            'fl_land_sellers_disclosure', 'mi_land_sellers_disclosure',
        ];
        // Defined in Reozom but never created for a listing
        $neverCreated = ['mi_res_grar'];

        foreach ($templates as $template) {
            $template['available_before_payment'] = in_array($template['code'], $beforePayment, true);
            if (in_array($template['code'], $neverCreated, true)) {
                $template['is_active'] = false;
            }
            DocumentTemplate::updateOrCreate(['code' => $template['code']], $template);
        }
    }

    private function disclosure(string $code, string $name, string $state, string $type, array $disclosureCodes, int $order, ?array $rules = null): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'state_code' => $state,
            'property_type' => $type,
            'source' => DocumentTemplate::SOURCE_DISCLOSURE,
            'disclosure_codes' => $disclosureCodes,
            'rules' => $rules,
            'body' => null,
            'requires_signature' => true,
            'order' => $order,
            'is_active' => true,
        ];
    }

    private function static(string $code, string $name, string $state, string $type, int $order, ?array $rules, string $text): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'state_code' => $state,
            'property_type' => $type,
            'source' => DocumentTemplate::SOURCE_STATIC,
            'disclosure_codes' => null,
            'rules' => $rules,
            'body' => '<p>'.$text.'</p><p>Reference: {{reference_code}} &middot; Date: {{date}}</p>',
            'requires_signature' => true,
            'order' => $order,
            'is_active' => true,
        ];
    }
}
