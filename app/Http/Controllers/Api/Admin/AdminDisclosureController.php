<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PropertyDisclosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminDisclosureController extends Controller
{
    /**
     * Enforce strict admin access on all methods.
     */
    protected function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || (!$user->isAdmin() && $user->role !== 'admin')) {
            abort(403, 'Unauthorized. Dynamic disclosure management is strictly restricted to platform administrators.');
        }
    }

    /**
     * Display a listing of dynamic disclosures with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = PropertyDisclosure::query()->orderBy('order')->orderBy('created_at', 'desc');

        if ($state = $request->input('state_code')) {
            if ($state !== 'ALL') {
                $query->where(function ($q) use ($state) {
                    $q->where('state_code', strtoupper($state))
                      ->orWhereNull('state_code')
                      ->orWhere('state_code', 'ALL');
                });
            }
        }

        if ($type = $request->input('property_type')) {
            if ($type !== 'all') {
                $query->where(function ($q) use ($type) {
                    $q->where('property_type', strtolower($type))
                      ->orWhereNull('property_type')
                      ->orWhere('property_type', 'all');
                });
            }
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $disclosures = $query->get();

        return api_success($disclosures, 'Disclosures retrieved successfully.');
    }

    /**
     * Store a newly created dynamic disclosure in storage (Admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', 'unique:property_disclosures,code'],
            'state_code' => ['nullable', 'string', 'max:10'],
            'property_type' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'schema' => ['required', 'array'],
            'schema.sections' => ['nullable', 'array'],
        ]);

        $code = !empty($validated['code']) 
            ? Str::slug($validated['code'], '_') 
            : Str::slug($validated['title'], '_') . '_' . Str::random(4);

        $disclosure = PropertyDisclosure::create([
            'title' => $validated['title'],
            'code' => $code,
            'state_code' => !empty($validated['state_code']) ? strtoupper($validated['state_code']) : null,
            'property_type' => !empty($validated['property_type']) ? strtolower($validated['property_type']) : null,
            'category' => $validated['category'] ?? 'statutory',
            'description' => $validated['description'] ?? null,
            'order' => $validated['order'] ?? 1,
            'is_required' => $validated['is_required'] ?? true,
            'is_active' => $validated['is_active'] ?? true,
            'schema' => $this->sanitizeSchema($validated['schema']),
        ]);

        return api_success($disclosure, 'Dynamic property disclosure created successfully.', 201);
    }

    /**
     * Display the specified dynamic disclosure (Admin only).
     */
    public function show(Request $request, PropertyDisclosure $disclosure): JsonResponse
    {
        $this->authorizeAdmin($request);

        return api_success($disclosure);
    }

    /**
     * Update the specified dynamic disclosure in storage (Admin only).
     */
    public function update(Request $request, PropertyDisclosure $disclosure): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('property_disclosures', 'code')->ignore($disclosure->id)],
            'state_code' => ['nullable', 'string', 'max:10'],
            'property_type' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_required' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'schema' => ['sometimes', 'required', 'array'],
            'schema.sections' => ['nullable', 'array'],
        ]);

        if (!empty($validated['code'])) {
            $validated['code'] = Str::slug($validated['code'], '_');
        }
        if (!empty($validated['state_code'])) {
            $validated['state_code'] = strtoupper($validated['state_code']);
        }
        if (!empty($validated['property_type'])) {
            $validated['property_type'] = strtolower($validated['property_type']);
        }
        if (isset($validated['schema'])) {
            $validated['schema'] = $this->sanitizeSchema($validated['schema']);
        }

        $disclosure->update($validated);

        return api_success($disclosure, 'Dynamic property disclosure updated successfully.');
    }

    /**
     * Remove the specified dynamic disclosure from storage (Admin only).
     */
    public function destroy(Request $request, PropertyDisclosure $disclosure): JsonResponse
    {
        $this->authorizeAdmin($request);

        $code = $disclosure->code;
        $disclosure->delete();

        return api_success(null, "Dynamic disclosure '{$code}' deleted successfully.");
    }

    /**
     * Toggle active/draft status of a disclosure (Admin only).
     */
    public function toggle(Request $request, PropertyDisclosure $disclosure): JsonResponse
    {
        $this->authorizeAdmin($request);

        $disclosure->update([
            'is_active' => !$disclosure->is_active,
        ]);

        $statusStr = $disclosure->is_active ? 'activated' : 'deactivated';
        return api_success($disclosure, "Disclosure successfully {$statusStr}.");
    }

    /**
     * Duplicate an existing dynamic disclosure for quick cloning (Admin only).
     */
    public function duplicate(Request $request, PropertyDisclosure $disclosure): JsonResponse
    {
        $this->authorizeAdmin($request);

        $newCode = $disclosure->code . '_copy_' . Str::lower(Str::random(4));
        $newTitle = $disclosure->title . ' (Copy)';

        $cloned = PropertyDisclosure::create([
            'title' => $newTitle,
            'code' => $newCode,
            'state_code' => $disclosure->state_code,
            'property_type' => $disclosure->property_type,
            'category' => $disclosure->category,
            'description' => $disclosure->description,
            'order' => $disclosure->order + 1,
            'is_required' => $disclosure->is_required,
            'is_active' => false, // Start copies as draft
            'schema' => $disclosure->schema,
        ]);

        return api_success($cloned, 'Disclosure duplicated successfully as draft.', 201);
    }

    /**
     * Ensure schema integrity (sections, field IDs, options).
     */
    protected function sanitizeSchema(array $schema): array
    {
        $sections = $schema['sections'] ?? [];
        $sanitizedSections = [];

        foreach ($sections as $sIdx => $sec) {
            $secId = !empty($sec['id']) ? Str::slug($sec['id'], '_') : 'section_' . ($sIdx + 1);
            $fields = $sec['fields'] ?? [];
            $sanitizedFields = [];

            foreach ($fields as $fIdx => $field) {
                $fieldId = !empty($field['id']) ? Str::slug($field['id'], '_') : 'field_' . ($fIdx + 1);
                $sanitizedFields[] = [
                    'id' => $fieldId,
                    'name' => $fieldId,
                    'label' => $field['label'] ?? 'Question ' . ($fIdx + 1),
                    'type' => $field['type'] ?? 'select',
                    'required' => !empty($field['required']),
                    'placeholder' => $field['placeholder'] ?? '',
                    'options' => $field['options'] ?? [
                        ['label' => 'No', 'value' => 'no'],
                        ['label' => 'Yes', 'value' => 'yes'],
                        ['label' => 'Unknown', 'value' => 'unknown'],
                    ],
                ];
            }

            $sanitizedSections[] = [
                'id' => $secId,
                'name' => $sec['name'] ?? 'General Section ' . ($sIdx + 1),
                'order' => $sec['order'] ?? ($sIdx + 1),
                'fields' => $sanitizedFields,
            ];
        }

        return [
            'sections' => $sanitizedSections,
        ];
    }
}

