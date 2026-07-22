<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class CreateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'exists:marketplace_categories,id'],
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['required', 'string', 'min:1'],
            'price' => ['required', 'integer', 'min:0'],
            'texture' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,svg', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'texture.max' => 'The texture file must not be larger than 2MB.',
        ];
    }
}
