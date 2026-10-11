<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'service_package_id' => 'nullable|exists:service_packages,id',
            'assigned_zips' => 'nullable|array',
            'config' => 'nullable|array',

            // Step Validation
            'config.*.id' => 'required|string',
            'config.*.order' => 'required|integer',
            'config.*.name' => 'required|string',
            'config.*.type' => 'nullable|string',
            'config.*.isSystem' => 'nullable|boolean',
            'config.*.visible' => 'nullable|boolean',
            'config.*.description' => 'nullable|string',
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
}
