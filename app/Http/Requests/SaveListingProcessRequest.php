<?php

namespace App\Http\Requests;

use App\Models\ListingProcess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveListingProcessRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Authorization is handled in the controller
    }

    public function rules()
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'type' => 'nullable|string|in:default,custom',
            'status' => 'nullable|string|in:active,draft',
            'agent_id' => 'nullable|exists:users,id',
            // Packages whose sellers use this process (each package has one process)
            'service_package_ids' => 'nullable|array',
            'service_package_ids.*' => 'integer|distinct|exists:service_packages,id',
            // Create only: System Default process whose steps the new process starts from
            'base_process_id' => 'nullable|integer|exists:listing_processes,id',
            'assigned_zips' => 'nullable|array',
            'required_disclosures' => 'nullable|array',
            'required_disclosures.*' => 'string',
            'documents' => 'nullable|array',
            'documents.*' => 'string|exists:document_templates,code',
            'config' => 'nullable|array',

            // Step Validation
            'config.*.id' => 'required|string',
            'config.*.order' => 'required|integer',
            'config.*.name' => 'required|string',
            'config.*.type' => 'nullable|string',
            'config.*.isSystem' => 'nullable|boolean',
            'config.*.visible' => 'nullable|boolean',
            'config.*.description' => 'nullable|string',
            'config.*.disclosure_code' => 'nullable|string|max:100',
            'config.*.customized' => 'nullable|boolean',
            'config.*.locked' => 'nullable|boolean',
            'config.*.kind' => 'nullable|string|max:50',
            'config.*.showIf' => 'nullable|array',
            'config.*.skipIf' => 'nullable|array',
            'config.*.sections' => 'nullable|array',

            // Section Validation
            'config.*.sections.*.id' => 'required|string',
            'config.*.sections.*.order' => 'required|integer',
            'config.*.sections.*.name' => 'required|string',
            'config.*.sections.*.description' => 'nullable|string',
            'config.*.sections.*.fields' => 'nullable|array',

            // Field Validation
            'config.*.sections.*.fields.*.id' => 'required|string',
            'config.*.sections.*.fields.*.name' => 'required|string',
            'config.*.sections.*.fields.*.label' => 'required|string',
            'config.*.sections.*.fields.*.type' => 'required|string|in:text,number,date,email,select,radio,checkbox,textarea,file',
            'config.*.sections.*.fields.*.required' => 'nullable|boolean',
            'config.*.sections.*.fields.*.active' => 'nullable|boolean',
            'config.*.sections.*.fields.*.defaultValue' => 'nullable|string',
            'config.*.sections.*.fields.*.helpText' => 'nullable|string',
            'config.*.sections.*.fields.*.minLength' => 'nullable|integer',
            'config.*.sections.*.fields.*.maxLength' => 'nullable|integer',
            'config.*.sections.*.fields.*.showInExport' => 'nullable|boolean',
            
            // Options Validation (for select, radio, checkbox)
            'config.*.sections.*.fields.*.options' => 'nullable|array',
            'config.*.sections.*.fields.*.options.*.label' => 'required_with:config.*.sections.*.fields.*.options|string',
            'config.*.sections.*.fields.*.options.*.value' => 'required_with:config.*.sections.*.fields.*.options|string',

            // Dependencies Validation
            'config.*.sections.*.fields.*.dependencies' => 'nullable|array',
            'config.*.sections.*.fields.*.dependencies.*.parentFieldId' => 'required_with:config.*.sections.*.fields.*.dependencies|string',
            'config.*.sections.*.fields.*.dependencies.*.condition' => 'required_with:config.*.sections.*.fields.*.dependencies|string|in:equals,contains,checked',
            'config.*.sections.*.fields.*.dependencies.*.value' => 'nullable|string', // value can be empty sometimes, e.g. for checked
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $config = $this->input('config');
                if (! is_array($config)) {
                    return;
                }

                foreach ($config as $sIndex => $step) {
                    // Locked and legacy public-info steps are replaced by the server, so their fields are not checked
                    $id = is_array($step) ? (string) ($step['id'] ?? '') : '';
                    if ($id === '' || $id === ListingProcess::LEGACY_PUBLIC_STEP_ID || ListingProcess::isLockedStepId($id)) {
                        continue;
                    }

                    $names = [];
                    foreach ($step['sections'] ?? [] as $secIndex => $section) {
                        foreach ($section['fields'] ?? [] as $fIndex => $field) {
                            $name = $field['name'] ?? null;
                            if (! is_string($name) || $name === '') {
                                continue;
                            }
                            $key = "config.$sIndex.sections.$secIndex.fields.$fIndex.name";

                            if (isset($names[$name])) {
                                $validator->errors()->add($key, "The field name \"$name\" is used more than once in step \"".($step['name'] ?? '')."\".");
                            }
                            $names[$name] = true;
                        }
                    }
                }
            },
        ];
    }
}
