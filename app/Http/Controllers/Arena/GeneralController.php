<?php

namespace App\Http\Controllers\Arena;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceItem;
use App\Models\UserWearing;
use Illuminate\Support\Facades\File;

class GeneralController extends Controller
{
    /**
     * Build a manifest of the current user's wearing, materialised as
     * temporary model files the frontend can load. We deliberately never
     * expose the internal rendering directory - each worn model is copied
     * into a throwaway session folder and served through this controller.
     */
    public function manifest()
    {
        $user = app('token_user');

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // The authenticated user is the default, but an optional ?user_id=
        // query parameter lets the frontend materialise another user's outfit
        // (e.g. the robot opponent, which is user id 2) in the avatar showcase.
        $targetUserId = request()->query('user_id', $user->id);

        $wearing = UserWearing::where('user_id', $targetUserId)
            ->with(['item.category'])
            ->get();

        $session = substr(md5($user->id.'|'.now()->timestamp), 0, 16);
        $this->cleanupOldTempFiles();

        $data = [];

        foreach ($wearing as $wearingItem) {
            $item = $wearingItem->item;

            if (! $item || $item->category === null) {
                continue;
            }

            $category = $item->category;

            $entry = [
                'item_id' => $item->id,
                'title' => $item->title,
                'category' => $category->title,
                'slots' => $this->slotsFor($category),
                'model_url' => null,
                'texture' => null,
            ];

            if ($item->model_path) {
                $entry['model_url'] = $this->makeTemporaryModel($session, $item);
            }

            $entry['texture'] = $this->makeTemporaryTexture($session, $item);

            $data[] = $entry;
        }

        return response()->json([
            'data' => $data,
            'session' => $session,
        ]);
    }

    /**
     * Stream a temporary model file that was created for a wearing session.
     */
    public function model(string $session, string $itemId)
    {
        if (! preg_match('/^[a-f0-9]{16}$/', $session) || ! ctype_digit($itemId)) {
            return response()->json(['error' => 'Invalid request'], 400);
        }

        $file = storage_path('app/private/arena/temp/'.$session.'/'.$itemId.'/model.obj');

        if (! is_file($file)) {
            return response()->json(['error' => 'Temporary model not found'], 404);
        }

        return response(File::get($file))
            ->header('Content-Type', 'model/obj')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Stream a temporary texture file that was created for a wearing session.
     */
    public function texture(string $session, string $itemId)
    {
        if (! preg_match('/^[a-f0-9]{16}$/', $session) || ! ctype_digit($itemId)) {
            return response()->json(['error' => 'Invalid request'], 400);
        }

        $matches = glob(storage_path('app/private/arena/temp/'.$session.'/'.$itemId.'/texture.*'));

        if (empty($matches)) {
            return response()->json(['error' => 'Temporary texture not found'], 404);
        }

        $extension = strtolower(pathinfo($matches[0], PATHINFO_EXTENSION));

        return response(File::get($matches[0]))
            ->header('Content-Type', $this->contentTypeFor($extension))
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Copy an item's model OBJ into a temporary, self-contained file. Any mtllib
     * reference is stripped so the browser never chases a missing MTL - the
     * texture is applied manually by the frontend.
     */
    private function makeTemporaryModel(string $session, MarketplaceItem $item): ?string
    {
        $source = config('app.renderer_directory').'/'.$item->model_path.'.obj';

        if (! is_file($source)) {
            return null;
        }

        $targetDir = storage_path('app/private/arena/temp/'.$session.'/'.$item->id);
        File::ensureDirectoryExists($targetDir);

        $lines = array_filter(explode("\n", File::get($source)), function (string $line) {
            return ! preg_match('/^\s*mtllib\b/i', $line);
        });

        File::put($targetDir.'/model.obj', implode("\n", $lines));

        return 'arena/model/'.$session.'/'.$item->id;
    }

    /**
     * Copy an item's texture into the same temporary session folder as its
     * model, then return the route URL that streams it back (mirroring the
     * OBJ flow instead of inlining the file as a base64 data URL).
     */
    private function makeTemporaryTexture(string $session, MarketplaceItem $item): ?string
    {
        if (! $item->texture_path) {
            return null;
        }

        $source = config('app.renderer_directory').'/'.$item->texture_path;

        if (! is_file($source)) {
            return null;
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $targetDir = storage_path('app/private/arena/temp/'.$session.'/'.$item->id);
        File::ensureDirectoryExists($targetDir);

        File::copy($source, $targetDir.'/texture.'.$extension);

        return 'arena/texture/'.$session.'/'.$item->id;
    }

    /**
     * Map a texture file extension to its MIME type.
     */
    private function contentTypeFor(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            default => 'application/octet-stream',
        };
    }

    /**
     * Resolve which character part(s) an item should attach to.
     *
     * @return array<int, string>
     */
    private function slotsFor($category): array
    {
        $slots = $category->parts_affected_array;

        if (empty($slots)) {
            $slots = ['head'];
        }

        return array_values($slots);
    }

    /**
     * Remove temporary session folders that are no longer fresh so they don't
     * accumulate on disk.
     */
    private function cleanupOldTempFiles(): void
    {
        $root = storage_path('app/private/arena/temp');

        if (! is_dir($root)) {
            return;
        }

        foreach (File::directories($root) as $directory) {
            if (filemtime($directory) < now()->subHour()->getTimestamp()) {
                File::deleteDirectory($directory);
            }
        }
    }
}
