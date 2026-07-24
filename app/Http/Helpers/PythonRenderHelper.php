<?php

namespace App\Http\Helpers;

class PythonRenderHelper
{
    /**
     * Base Python imports and utility functions for Blender scripting.
     */
    private string $pythonBase = <<<PYTHON
import bpy
import math
from mathutils import Euler

bpy.context.scene.render.engine = 'BLENDER_RENDER'
bpy.context.scene.render.threads_mode = 'FIXED'
bpy.context.scene.render.threads = 1

def hex_to_rgb(value):
    """Convert hex color to RGB tuple with gamma correction."""
    gamma = 2.05
    value = value.lstrip('#')
    length = len(value)
    step = length // 3
    r = pow(int(value[0:step], 16) / 255, gamma)
    g = pow(int(value[step:step * 2], 16) / 255, gamma)
    b = pow(int(value[step * 2:step * 3], 16) / 255, gamma)
    return (r, g, b)\n
PYTHON;

    /**
     * @var string The accumulated Python script
     */
    private string $script = '';

    /**
     * Get the complete Python script with base imports.
     */
    public function getScript(): string
    {
        return $this->pythonBase.$this->script;
    }

    /**
     * Reset the script to initial state.
     */
    public function reset(): void
    {
        $this->script = '';
    }

    /**
     * Load a .blend file into the current Blender session.
     *
     * @param  string  $file  Absolute path to the .blend file
     *
     * @throws \InvalidArgumentException If file path is empty
     */
    public function loadBlend(string $file): void
    {
        $this->validateNonEmpty($file, 'File path');
        $escapedFile = $this->escapeString($file);

        $this->script .= <<<PYTHON
bpy.ops.wm.open_mainfile(filepath='{$escapedFile}')\n
PYTHON;
    }

    /**
     * Import an OBJ file into the scene.
     *
     * @param  string  $name  Object name
     * @param  string  $file  Path to the OBJ file (without .obj extension)
     * @param  bool  $createMaterial  Whether to create a material for the object
     */
    public function loadObj(string $name, string $file, bool $createMaterial = true): void
    {
        $this->validateNonEmpty($name, 'Object name');
        $this->validateNonEmpty($file, 'File path');

        $escapedName = $this->escapeString($name);
        $escapedFile = $this->escapeString($file.'.obj');

        $this->script .= <<<PYTHON
bpy.ops.import_scene.obj(filepath='{$escapedFile}')
obj_{$escapedName} = bpy.context.selected_objects[0]
obj_{$escapedName}.data.name = '{$escapedName}'
obj_{$escapedName}.name = '{$escapedName}'\n
PYTHON;

        if ($createMaterial) {
            $this->script .= <<<PYTHON
mat_{$escapedName} = bpy.data.materials.new('{$escapedName}')
mat_{$escapedName}.diffuse_shader = 'LAMBERT'
obj_{$escapedName}.active_material = mat_{$escapedName}\n
PYTHON;
        }
    }

    /**
     * Add a texture to an object.
     *
     * @param  string  $objectName  The object name to apply texture to
     * @param  string  $textureName  Name for the texture
     * @param  string  $file  Path to the texture image file
     */
    public function addTexture(string $objectName, string $textureName, string $file): void
    {
        $this->validateNonEmpty($objectName, 'Object name');
        $this->validateNonEmpty($textureName, 'Texture name');
        $this->validateNonEmpty($file, 'Texture file path');

        $escapedObject = $this->escapeString($objectName);
        $escapedTexture = $this->escapeString($textureName);
        $escapedFile = $this->escapeString($file);

        $this->script .= <<<PYTHON
img_{$escapedTexture} = bpy.data.images.load(filepath='{$escapedFile}')
tex_{$escapedTexture} = bpy.data.textures.new('{$escapedTexture}', type='IMAGE')
tex_{$escapedTexture}.image = img_{$escapedTexture}
slot_{$escapedTexture} = bpy.data.objects['{$escapedObject}'].active_material.texture_slots.add()
slot_{$escapedTexture}.texture = tex_{$escapedTexture}\n
PYTHON;
    }

    /**
     * Create a material for an object.
     *
     * @param  string  $name  Material name
     * @param  string|null  $textureName  Optional texture name to assign to the material
     */
    public function createMaterial(string $name, ?string $textureName = null): void
    {
        $this->validateNonEmpty($name, 'Material name');

        $escapedName = $this->escapeString($name);

        $this->script .= <<<PYTHON
mat_{$escapedName} = bpy.data.materials.new('{$escapedName}')
mat_{$escapedName}.diffuse_shader = 'LAMBERT'\n
PYTHON;

        if ($textureName !== null) {
            $escapedTexture = $this->escapeString($textureName);
            $this->script .= <<<PYTHON
mat_{$escapedName}.texture_slots.add()
mat_{$escapedName}.texture_slots[-1].texture = tex_{$escapedTexture}\n
PYTHON;
        }

        $this->script .= <<<PYTHON
bpy.context.active_object.active_material = mat_{$escapedName}\n
PYTHON;
    }

    /**
     * Set camera focus on specified objects.
     *
     * @param  array|string  $objects  Object names to focus on, or "all" for entire scene
     */
    public function focus(array|string $objects = ['all']): void
    {
        $this->script .= "for obj in bpy.data.objects:\n    obj.select = False\n";

        $objectList = is_array($objects) ? $objects : [$objects];

        foreach ($objectList as $sel) {
            if ($sel === 'all') {
                $this->script .= "bpy.ops.object.select_all(action='SELECT')\n";
            } else {
                $escapedName = $this->escapeString($sel);
                $this->script .= "bpy.data.objects['{$escapedName}'].select = True\n";
            }
        }
    }

    /**
     * Reposition an object in 3D space.
     *
     * @param  string  $part  Object name to reposition
     * @param  array{x?: float, y?: float, z?: float}  $axis  Position values for each axis
     */
    public function setPosition(string $part, array $axis): void
    {
        $this->validateNonEmpty($part, 'Object name');

        $escapedPart = $this->escapeString($part);

        foreach (['x', 'y', 'z'] as $axisName) {
            if (isset($axis[$axisName])) {
                $this->script .= "bpy.data.objects['{$escapedPart}'].location.{$axisName} = {$axis[$axisName]}\n";
            }
        }
    }

    /**
     * Rotate an object in 3D space.
     *
     * @param  string  $part  Object name to rotate
     * @param  array{x: float, y: float, z: float}  $axis  Rotation values in degrees for each axis
     */
    public function rotate(string $part, array $axis): void
    {
        $this->validateNonEmpty($part, 'Object name');

        $x = $axis['x'] ?? 0;
        $y = $axis['y'] ?? 0;
        $z = $axis['z'] ?? 0;

        $escapedPart = $this->escapeString($part);

        $this->script .= <<<PYTHON
bpy.data.objects['{$escapedPart}'].rotation_euler = Euler((
    math.radians({$x}),
    math.radians({$y}),
    math.radians({$z})
), 'XYZ')\n
PYTHON;
    }

    /**
     * Recolor an object using hex color.
     *
     * @param  string  $name  Object name to recolor
     * @param  string  $color  Hex color code (e.g., "#FF0000" or "FF0000")
     */
    public function selectAndColor(string $name, string $color): void
    {
        $this->validateNonEmpty($name, 'Object name');
        $this->validateNonEmpty($color, 'Color');

        $escapedName = $this->escapeString($name);
        $escapedColor = $this->escapeString($color);

        $this->script .= <<<PYTHON
bpy.data.objects['{$escapedName}'].select = True
bpy.data.objects['{$escapedName}'].active_material.diffuse_color = hex_to_rgb('{$escapedColor}')\n
PYTHON;
    }

    /**
     * Render the scene and save to file.
     *
     * @param  string  $hash  Unique identifier for the output file
     * @param  string  $filePath  Directory path to save the rendered image
     */
    public function save(string $hash, string $filePath): void
    {
        $this->validateNonEmpty($hash, 'Hash');
        $this->validateNonEmpty($filePath, 'File path');

        $escapedHash = $this->escapeString($hash);
        $escapedPath = $this->escapeString($filePath);

        $this->script .= <<<PYTHON
bpy.ops.view3d.camera_to_view_selected()
bpy.data.scenes['Scene'].render.filepath = '{$escapedPath}/{$escapedHash}.png'
bpy.ops.render.render(write_still=True)\n
PYTHON;
    }

    /**
     * Add custom Python code to the script.
     *
     * @param  string  $code  Python code to append
     */
    public function addRawCode(string $code): void
    {
        $this->script .= $code."\n";
    }

    /**
     * Validate that a value is not empty.
     *
     * @throws \InvalidArgumentException
     */
    private function validateNonEmpty(string $value, string $fieldName): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException("{$fieldName} cannot be empty.");
        }
    }

    /**
     * Escape a string for safe use in Python code.
     */
    private function escapeString(string $value): string
    {
        return addslashes($value);
    }
}
