<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceCategory extends Model
{
    protected $fillable = [
        'title',
        'is_admin_only',
        'has_model',
        'has_texture',
        'parts_affected',
    ];

    protected $casts = [
        'is_admin_only' => 'boolean',
        'has_model' => 'boolean',
        'has_texture' => 'boolean',
    ];

    /**
     * Get the parts affected as an array.
     */
    public function getPartsAffectedArrayAttribute(): array
    {
        return $this->parts_affected ? explode(',', $this->parts_affected) : [];
    }

    /**
     * Check if this category has a 3D model.
     */
    public function hasModel(): bool
    {
        return (bool) $this->has_model;
    }

    /**
     * Check if this category has a texture.
     */
    public function hasTexture(): bool
    {
        return (bool) $this->has_texture;
    }
}