<?php

namespace App\Jobs;

use App\Http\Helpers\PythonRenderHelper;
use App\Models\AdminLog;
use App\Models\AvatarPoseDefinition;
use App\Models\MarketplaceItem;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RenderAllJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out (0 = no timeout).
     *
     * @var int
     */
    public $timeout = 0;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $kind,
        public ?int $adminId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->kind === 'items') {
            $this->renderAllItems();
        } else {
            $this->renderAllUsers();
        }
    }

    /**
     * Render every user's avatar and headshot in the background.
     */
    private function renderAllUsers(): void
    {
        $userIdList = User::pluck('id');
        $rendered = 0;
        $failed = 0;

        foreach ($userIdList as $userId) {
            try {
                if ($this->renderUser($userId)) {
                    $rendered++;
                } else {
                    $failed++;
                }
            } catch (Throwable $e) {
                $failed++;
            }
        }

        $this->log("Rerendered all users: $rendered succeeded, $failed failed");
    }

    /**
     * Render every item thumbnail in the background.
     */
    private function renderAllItems(): void
    {
        $items = MarketplaceItem::with('category')->get();
        $rendered = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $category = $item->category;
            if (! $category || ! $category->needs_rendering) {
                $skipped++;

                continue;
            }

            try {
                if ($this->renderItem($item)) {
                    $rendered++;
                } else {
                    $failed++;
                }
            } catch (Throwable $e) {
                $failed++;
            }
        }

        $this->log("Rerendered all items: $rendered succeeded, $failed failed, $skipped skipped");
    }

    /**
     * Write a summary entry to the admin log.
     */
    private function log(string $message): void
    {
        AdminLog::create([
            'admin_id' => $this->adminId ?? 0,
            'target_id' => 0,
            'log' => $message,
        ]);
    }

    /**
     * Render a single user's avatar and headshot. Returns true on success.
     */
    private function renderUser(int $userId): bool
    {
        $user = User::where('id', $userId)->first();
        if (! $user) {
            return false;
        }

        $wearing = $user->wearing;
        $colors = $user->avatarColors;

        $renderer = new PythonRenderHelper;
        $renderer->loadBlend(config('app.renderer_directory').'/scene.blend');

        $colorMap = [
            'head' => $colors->head_color,
            'torso' => $colors->torso_color,
            'left_arm' => $colors->left_arm_color,
            'right_arm' => $colors->right_arm_color,
            'left_leg' => $colors->left_leg_color,
            'right_leg' => $colors->right_leg_color,
        ];

        $hasFace = false;
        foreach ($wearing as $wearingItem) {
            $item = $wearingItem->item;
            $category = $item->category;

            if ($category->has_model) {
                $modelPath = config('app.renderer_directory').'/'.$item->model_path;
                $renderer->loadObj($item->id, $modelPath, $category->has_texture);

                $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
                $renderer->addTexture($item->id, $item->id.'_tex', $texturePath);
            }

            if ($category->has_texture && $item->texture_path) {
                $parts = $category->parts_affected_array;
                $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
                foreach ($parts as $part) {
                    if ($part === 'head') {
                        $hasFace = true;
                    }
                    $renderer->addTexture($part, $item->id.'_tex', $texturePath);
                }
            }
        }

        if (! $hasFace) {
            $renderer->addTexture('head', 'default_face', config('app.renderer_directory').'/textures/def_face.png');
        }

        $parts = ['head', 'left_arm', 'right_arm', 'torso', 'right_leg', 'left_leg'];
        foreach ($parts as $part) {
            $renderer->selectAndColor($part, $colorMap[$part]);
        }

        $hash = md5($user->id.time());
        $outputPath = config('app.storage_directory').'/avatars';

        $renderer->focus('all');
        $renderer->save($user->id, $outputPath);

        $outputPath = config('app.storage_directory').'/headshots';
        $renderer->focus(['head', 'torso']);
        $renderer->save($user->id, $outputPath);

        if (file_put_contents(config('app.renderer_directory')."/python/$hash.py", $renderer->getScript())) {
            $output = [];
            $code = 0;
            exec(config('app.blender_path').' -b -P '.config('app.renderer_directory')."/python/$hash.py 2>&1", $output, $code);

            return true;
        }

        return false;
    }

    /**
     * Render a single item thumbnail. Returns true on success.
     */
    private function renderItem(MarketplaceItem $item): bool
    {
        $category = $item->category;

        $renderer = new PythonRenderHelper;
        $renderer->loadBlend(config('app.renderer_directory').'/scene.blend');

        // All colors should be pure white for item rendering
        $whiteColor = '#FFFFFF';

        if ($category->title == 'Gears') {
            $posXyz = ['x' => 0.913747, 'y' => -2.35409, 'z' => 0.836074];
            $rotXyz = ['x' => '-0.000009', 'y' => '-90', 'z' => '0'];
            $renderer->setPosition('left_arm', $posXyz);
            $renderer->rotate('left_arm', $rotXyz);
        }

        // Load the 3D model if the category has one and model was uploaded
        if ($category->has_model) {
            $modelPath = config('app.renderer_directory').'/'.$item->model_path;
            $renderer->loadObj($item->id, $modelPath, $category->has_texture);

            $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
            $renderer->addTexture($item->id, $item->id.'_tex', $texturePath);

            $renderer->addRawCode("obj_{$item->id}.parent = bpy.data.objects['{$category->parts_affected}']");
            $renderer->addRawCode("obj_{$item->id}.matrix_parent_inverse = bpy.data.objects['{$category->parts_affected}'].matrix_world.inverted()");
        }

        // Apply texture if the category has one and the item has a texture path
        if ($category->has_texture && $item->texture_path) {
            $parts = $category->parts_affected_array;
            $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
            foreach ($parts as $part) {
                $renderer->addTexture($part, $item->id.'_tex', $texturePath);
            }
        }

        if ($category->title == 'Avatar Poses') {
            $definition = AvatarPoseDefinition::where('item_id', $item->id)->first();
            $parts = explode(';', $definition['definition']);

            foreach ($parts as $part) {
                $data = explode(':', $part);
                // [0] - Part name
                // [1] - Part X, [2] - Part Y, [3] - Part Z
                // [4] - Part Rot X, [5] - Part Rot Y, [6] - Part Rot Z
                $renderer->setPosition($data[0], ['x' => $data[1], 'y' => $data[2], 'z' => $data[3]]);
                $renderer->rotate($data[0], ['x' => $data[4], 'y' => $data[5], 'z' => $data[6]]);
            }
        }

        // Apply white colors to all parts
        $parts = ['head', 'left_arm', 'right_arm', 'torso', 'right_leg', 'left_leg'];
        foreach ($parts as $part) {
            $renderer->selectAndColor($part, $whiteColor);
        }

        // Generate a unique hash for the render
        $hash = md5($item->id.time());
        $outputPath = config('app.storage_directory').'/items';

        $renderer->focus(['all']);
        $renderer->save($item->id, $outputPath);

        $script = $renderer->getScript();
        if (file_put_contents(config('app.renderer_directory')."/python/$hash.py", $script)) {
            $output = [];
            $code = 0;
            exec(config('app.blender_path').' -b -P '.config('app.renderer_directory')."/python/$hash.py 2>&1", $output, $code);

            return true;
        }

        return false;
    }
}
