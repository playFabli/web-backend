<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuest;
use Carbon\Carbon;

class QuestController extends Controller
{
    /**
     * Get all quests for the authenticated user.
     * Generates new quests if none exist for the current period.
     */
    public function index()
    {
        $user = app('token_user');
        $now = Carbon::now();

        // Generate daily quests if needed
        $this->generateQuestsIfNeeded($user, 'daily', $now->copy()->startOfDay(), $now->copy()->endOfDay());

        // Generate weekly quests if needed
        $this->generateQuestsIfNeeded($user, 'weekly', $now->copy()->startOfWeek(), $now->copy()->endOfWeek());

        // Generate challenge quests if needed
        $this->generateQuestsIfNeeded($user, 'challenge', $now->copy()->startOfWeek(), $now->copy()->endOfWeek());

        $quests = UserQuest::where('user_id', $user->id)
            ->where('expires_at', '>', $now)
            ->orderBy('type')
            ->orderBy('id')
            ->get();

        return response()->json($quests);
    }

    /**
     * Claim a completed quest reward.
     */
    public function claim($questId)
    {
        $user = app('token_user');

        $quest = UserQuest::where('id', $questId)
            ->where('user_id', $user->id)
            ->first();

        if (! $quest) {
            return response()->json([
                'message' => 'Quest not found',
            ], 404);
        }

        if (! $quest->is_completed) {
            return response()->json([
                'message' => 'Quest is not completed yet',
            ], 422);
        }

        if ($quest->is_claimed) {
            return response()->json([
                'message' => 'Quest already claimed',
            ], 422);
        }

        // Award rewards
        $user->coins = $user->coins + $quest->coin_reward;
        $user->exp = $user->exp + $quest->exp_reward;
        $user->save();

        $quest->is_claimed = true;
        $quest->save();

        return response()->json([
            'message' => 'Quest claimed successfully',
            'coins' => $quest->coin_reward,
            'exp' => $quest->exp_reward,
            'user' => $user,
        ]);
    }

    /**
     * Increment quest progress for a specific quest name.
     * This is called by other controllers when users complete actions.
     */
    public static function incrementProgress($userId, $questName, $amount = 1)
    {
        $now = Carbon::now();

        $quest = UserQuest::where('user_id', $userId)
            ->where('name', $questName)
            ->where('is_completed', false)
            ->where('is_claimed', false)
            ->where('expires_at', '>', $now)
            ->first();

        if (! $quest) {
            return;
        }

        $quest->current_value = $quest->current_value + $amount;

        if ($quest->current_value >= $quest->required_value) {
            $quest->is_completed = true;
        }

        $quest->save();
    }

    /**
     * Calculate a level-based multiplier for quest rewards.
     * Base multiplier is 1.0 at level 1, scales up gradually.
     */
    private function getLevelMultiplier($level)
    {
        // 1.0 at level 1, ~1.5 at level 10, ~2.5 at level 25, ~4.0 at level 50
        return 1.0 + (($level - 1) * 0.06);
    }

    /**
     * Generate quests for a user if none exist for the current period.
     */
    private function generateQuestsIfNeeded($user, string $type, Carbon $periodStart, Carbon $periodEnd)
    {
        $existing = UserQuest::where('user_id', $user->id)
            ->where('type', $type)
            ->where('expires_at', '>', Carbon::now())
            ->count();

        if ($existing > 0) {
            return;
        }

        // Get available quest definitions for this type
        $definitions = QuestDefinition::where('type', $type)->get();

        if ($definitions->isEmpty()) {
            return;
        }

        // Determine how many quests to generate (2-5)
        $questCount = rand(2, 5);
        $questCount = min($questCount, $definitions->count());

        // Calculate level-based reward multiplier
        $levelMultiplier = $this->getLevelMultiplier($user->level);

        // Pick random definitions
        $selected = $definitions->random($questCount);

        echo $questCount;

        foreach ($selected as $definition) {
            $requiredValue = rand($definition->min_value, $definition->max_value);

            // For challenges, multiply required value by 2-3x
            if ($type === 'challenge') {
                $multiplier = rand(2, 3);
                $requiredValue = $requiredValue * $multiplier;
            }

            // Scale rewards with user level
            $coinReward = (int) round($definition->coin_reward * $levelMultiplier);
            $expReward = (int) round($definition->exp_reward * $levelMultiplier);

            $quest = new UserQuest;
            $quest->user_id = $user->id;
            $quest->quest_definition_id = $definition->id;
            $quest->type = $type;
            $quest->name = $definition->name;
            $quest->description = $definition->description;
            $quest->required_value = $requiredValue;
            $quest->current_value = 0;
            $quest->coin_reward = $coinReward;
            $quest->exp_reward = $expReward;
            $quest->is_claimed = false;
            $quest->is_completed = false;
            $quest->expires_at = $periodEnd;
            $quest->save();
        }
    }
}
