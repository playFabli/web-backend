<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'string'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'category_id' => ['sometimes', 'exists:marketplace_categories,id'],
            'texture' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,gif,svg', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'texture.max' => 'The texture file must not be larger than 2MB.',
        ];
    }
}
