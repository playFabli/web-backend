<?php

use App\Http\Helpers\PythonRenderHelper;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Models\UserPaymentContract;
use Illuminate\Support\Facades\Route;

Route::post("/payments/webhook", function() {
    $data = request()->all();

    if(request()->header("X-Api-Key") != env("WEBHOOK_API_KEY")) {
        return response()->json(["Invalid API key"], 403);
    }

    if($data["eventType"] === "payment.success") {
        $contract = UserPaymentContract::where("contract_uuid", $data["contractId"])->first();
        if(!$contract) {
            return response()->json(["Contract not found"], 404);
        } else {
            if($contract->is_finished) {
                return response()->json(["Contract finished"], 200);                
            }

            $availableProducts = ["5" => 500, "9.99" => 1050, "19.99" => 2700, "39.99" => 5500];
            $amountBought = $availableProducts[$data["amount"]];

            $user = User::find($contract->user_id);
            $user->coins = $user->coins + $amountBought;
            $user->save();

            $contract->is_finished = true;
            $contract->save();

            return response()->json(["All good"], 200);
        }
    }
});

Route::get("/render-user", function() {
    if(request()->header("X-Api-Key") != env("WEBHOOK_API_KEY")) {
        return response()->json(["Invalid API key"], 403);
    }

    $id = request()->query("id");
    $user = User::findOrFail($id);

     $wearing = $user->wearing;
        $colors = $user->avatarColors;

        $renderer = new PythonRenderHelper;
        $renderer->loadBlend(config('app.renderer_directory').'/scene.blend');

        // Color mapping for avatar parts
        $colorMap = [
            'head' => $colors->head_color,
            'torso' => $colors->torso_color,
            'left_arm' => $colors->left_arm_color,
            'right_arm' => $colors->right_arm_color,
            'left_leg' => $colors->left_leg_color,
            'right_leg' => $colors->right_leg_color,
        ];

        // Process each worn item
        $hasFace = false;
        foreach ($wearing as $wearingItem) {
            $item = $wearingItem->item;
            $category = $item->category;

            // Load the 3D model if the category has one
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

        // Generate a unique hash for the render
        $hash = md5($user->id.time());
        $outputPath = config('app.storage_directory').'/avatars';

        $renderer->focus('all');
        $renderer->save($user->id, $outputPath);

        $outputPath = config('app.storage_directory').'/headshots';
        $renderer->focus(['head', 'torso']);
        $renderer->save($user->id, $outputPath);

        if (file_put_contents(config('app.renderer_directory')."/python/$hash.py", $renderer->getScript())) {
            $output = '';
            $code = 0;
            exec(config('app.blender_path').' -b -P '.config('app.renderer_directory')."/python/$hash.py 2>&1", $output, $code);
            // if(!config('app.renderer_save_python_files'))
            //     unlink(config('app.renderer_main_path')."/python/$name.py");

            // return response()->json(["image" => config("app.renderer_display_path")."/avatars/$user->avatar_url.png"]);
            return response()->json([
                'data' => [
                    'render_url' => "/{$user->id}.png",
                    'hash' => $hash,
                    'output' => $output,
                    'code' => $code,
                ],
            ], 200);
        }
});