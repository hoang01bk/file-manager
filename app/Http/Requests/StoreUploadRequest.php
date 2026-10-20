<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'ttl_minutes' => [Rule::requiredIf($this->routeIs('files.store')), 'integer', 'min:1'],
            'view_only' => ['sometimes', 'boolean'],
            'all_users_can_edit' => [Rule::prohibitedIf(! $this->user()), 'sometimes', 'boolean'],
            'editor_ids' => [Rule::prohibitedIf(! $this->user()), 'sometimes', 'array', 'max:50'],
            'editor_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')],
        ];
    }
}
