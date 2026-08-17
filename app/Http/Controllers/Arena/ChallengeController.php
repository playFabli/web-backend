<?php

namespace App\Http\Controllers\Arena;

use App\Http\Controllers\Controller;
use App\Models\ArenaDailyChallenge;
use App\Models\ActivityLog;
use Carbon\Carbon;

class ChallengeController extends Controller
{
    /**
     * The fixed catalogue of daily challenges. Each entry defines the reward
     * and a random requirement range; generation rolls the concrete target for
     * the day. The description uses a {count} placeholder that generation
     * replaces with the rolled value so the wording always matches.
     *
     * @return array<string, array{title:string, description:string, required_min:int, required_max:int, tokens:int, exp:int}>
     */
    public static function catalogue(): array
    {
        return [
            'play_matches' => [
                'title' => 'Arena Regular',
                'description' => 'Play {count} arena fight(s)',
                'required_min' => 3,
                'required_max' => 6,
                'tokens' => 30,
                'exp' => 30,
            ],
            'win_matches' => [
                'title' => 'Arena Victor',
                'description' => 'Win {count} arena fight(s)',
                'required_min' => 2,
                'required_max' => 5,
                'tokens' => 40,
                'exp' => 40,
            ],
            'flawless' => [
                'title' => 'Untouchable',
                'description' => 'Win {count} fight(s) without losing any HP',
                'required_min' => 1,
                'required_max' => 2,
                'tokens' => 360,
                'exp' => 360,
            ],
        ];
    }

    /**
     * Today's challenges for the authenticated user, generating them on first
     * request of the day.
     */
    public function index()
    {
        $user = app('token_user');

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $now = Carbon::now();
        $this->generateForDay($user->id, $now->copy()->startOfDay(), $now->copy()->endOfDay());

        $challenges = ArenaDailyChallenge::where('user_id', $user->id)
            ->where('expires_at', '>', $now)
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $challenges]);
    }

    /**
     * Claim a completed challenge, awarding its arena tokens and XP.
     */
    public function claim($challengeId)
    {
        $user = app('token_user');

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $challenge = ArenaDailyChallenge::where('id', $challengeId)
            ->where('user_id', $user->id)
            ->first();

        if (! $challenge) {
            return response()->json(['message' => 'Challenge not found'], 404);
        }

        if (! $challenge->is_completed) {
            return response()->json(['message' => 'Challenge not completed yet'], 422);
        }

        if ($challenge->is_claimed) {
            return response()->json(['message' => 'Challenge already claimed'], 422);
        }

        $challenge->is_claimed = true;
        $challenge->save();

        $user->arena_tokens = (int) $user->arena_tokens + (int) $challenge->token_reward;
        $user->arena_exp = (int) $user->arena_exp + (int) $challenge->exp_reward;
        $user->save();

        ActivityLog::log(
            $user->id,
            'arena_challenge_complete',
            "claimed the daily challenge: {$challenge->title}",
            null,
            ['key' => $challenge->key, 'tokens' => $challenge->token_reward, 'exp' => $challenge->exp_reward]
        );

        return response()->json([
            'message' => 'Challenge claimed successfully',
            'challenge' => $challenge,
            'arena_tokens' => (int) $user->arena_tokens,
            'arena_exp' => (int) $user->arena_exp,
        ]);
    }

    /**
     * Advance progress on the user's matching daily challenge. Called by the
     * match controller every time a fight ends. No-ops when the user has no
     * active challenge for the given key.
     */
    public static function registerProgress(int $userId, string $key, int $amount = 1): void
    {
        $challenge = ArenaDailyChallenge::where('user_id', $userId)
            ->where('key', $key)
            ->where('is_completed', false)
            ->where('is_claimed', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (! $challenge) {
            return;
        }

        $challenge->current_value = (int) $challenge->current_value + $amount;

        if ((int) $challenge->current_value >= (int) $challenge->required_value) {
            $challenge->current_value = (int) $challenge->required_value;
            $challenge->is_completed = true;

            ActivityLog::log(
                $userId,
                'arena_challenge_complete',
                "completed the daily challenge: {$challenge->title}",
                null,
                ['key' => $challenge->key]
            );
        }

        $challenge->save();
    }

    /**
     * Create the day's challenges from the catalogue if the user doesn't have
     * any for the current period yet.
     */
    private function generateForDay(int $userId, Carbon $start, Carbon $end): void
    {
        $existing = ArenaDailyChallenge::where('user_id', $userId)
            ->where('expires_at', '>', Carbon::now())
            ->count();

        if ($existing > 0) {
            return;
        }

        foreach (self::catalogue() as $key => $def) {
            $required = random_int($def['required_min'], $def['required_max']);

            ArenaDailyChallenge::create([
                'user_id' => $userId,
                'key' => $key,
                'title' => $def['title'],
                'description' => $this->buildDescription($def['description'], $required),
                'required_value' => $required,
                'current_value' => 0,
                'token_reward' => $def['tokens'] * $required,
                'exp_reward' => $def['exp'] * $required,
                'is_completed' => false,
                'is_claimed' => false,
                'expires_at' => $end,
            ]);
        }
    }

    /**
     * Fill a challenge description template with its rolled requirement.
     * {count} becomes the number and "(s)" resolves to "s" for plurals or
     * nothing for a single fight.
     */
    private function buildDescription(string $template, int $count): string
    {
        return str_replace(
            ['{count}', '(s)'],
            [(string) $count, $count === 1 ? '' : 's'],
            $template
        );
    }
}
