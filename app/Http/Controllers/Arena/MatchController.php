<?php

namespace App\Http\Controllers\Arena;

use App\Http\Controllers\Controller;
use App\Models\ArenaItem;
use App\Models\ArenaMatch;
use App\Models\User;
use App\Models\UserWearing;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    // Core balance tuning.
    private const ATTACK_COST = 25;
    private const DEFEND_GAIN = 20;
    private const MAX_ENERGY = 100;
    private const START_ENERGY = 100;
    private const BASE_ATTACK = 10;
    private const BASE_DEFENSE = 5;

    /**
     * The authenticated user's arena statistics.
     */
    public function stats()
    {
        $user = app('token_user');

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $stats = $this->statsForUser($user);

        return response()->json([
            'data' => [
                'attack' => $stats['attack'],
                'defense' => $stats['defense'],
                'max_hp' => $stats['max_hp'],
                'arena_tokens' => (int) $user->arena_tokens,
                'arena_exp' => (int) $user->arena_exp,
                'online' => $this->onlineCount(),
            ],
        ], 200);
    }

    /**
     * Matchmaking: try to find a real online opponent whose power is close
     * to the player's, otherwise spawn a robot built inside the same range.
     */
    public function queue()
    {
        $user = app('token_user');

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Abandon any stale ongoing matches so the player never stacks fights.
        ArenaMatch::where('user_id', $user->id)
            ->where('status', 'ongoing')
            ->update(['status' => 'quit']);

        $player = $this->statsForUser($user);
        $playerPower = $player['attack'] + $player['defense'];

        // "not too strong or too weak" bracket around the player's power.
        $minPower = max(2, (int) round($playerPower * 0.75));
        $maxPower = max($minPower + 1, (int) round($playerPower * 1.35));

        $opponent = $this->findRealOpponent($user, $minPower, $maxPower, $playerPower);

        if ($opponent === null) {
            $opponent = $this->makeRobot($minPower, $maxPower);
        }

        $match = ArenaMatch::create([
            'user_id' => $user->id,
            'is_robot' => $opponent['is_robot'],
            'opponent_user_id' => $opponent['user_id'],
            'opponent_name' => $opponent['name'],
            'player_attack' => $player['attack'],
            'player_defense' => $player['defense'],
            'player_max_hp' => $player['max_hp'],
            'player_hp' => $player['max_hp'],
            'player_energy' => self::START_ENERGY,
            'opponent_attack' => $opponent['attack'],
            'opponent_defense' => $opponent['defense'],
            'opponent_max_hp' => $opponent['max_hp'],
            'opponent_hp' => $opponent['max_hp'],
            'opponent_energy' => self::START_ENERGY,
            'status' => 'ongoing',
            'log' => [],
        ]);

        return response()->json(['data' => $this->payload($match)], 201);
    }

    /**
     * The player's currently active match (if any).
     */
    public function active()
    {
        $user = app('token_user');

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $match = ArenaMatch::where('user_id', $user->id)
            ->where('status', 'ongoing')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => $match ? $this->payload($match) : null,
        ], 200);
    }

    /**
     * Resolve one round: the player acts, then the opponent AI replies.
     */
    public function action(Request $request, $id)
    {
        $user = app('token_user');

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $match = ArenaMatch::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $match || $match->status !== 'ongoing') {
            return response()->json([
                'status' => 'error',
                'message' => 'That match is no longer active.',
            ], 404);
        }

        $action = $request->validate([
            'action' => 'required|in:attack,defend',
        ])['action'];

        $log = $match->log ?? [];

        // --- Player turn ---
        if ($action === 'attack') {
            if ($match->player_energy < self::ATTACK_COST) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough energy to attack. Defend to recover energy.',
                ], 422);
            }

            $match->player_energy -= self::ATTACK_COST;
            $damage = $this->damage($match->player_attack, $match->opponent_defense);

            // A defending opponent takes half damage.
            if ($match->opponent_defending) {
                $damage = (int) max(1, round($damage * 0.5));
                $match->opponent_defending = false;
                $log[] = 'You attacked for '.$damage.' (blocked)';
            } else {
                $log[] = 'You attacked for '.$damage;
            }

            $match->opponent_hp = max(0, $match->opponent_hp - $damage);
        } else {
            $match->player_energy = min(self::MAX_ENERGY, $match->player_energy + self::DEFEND_GAIN);
            $match->player_defending = true;
            $log[] = 'You raised your guard (+'.self::DEFEND_GAIN.' energy)';
        }

        // Player won this round?
        if ($match->opponent_hp <= 0) {
            $match->opponent_hp = 0;
            return $this->finish($match, 'won', $log);
        }

        // --- Opponent turn ---
        if ($match->opponent_energy < self::ATTACK_COST) {
            $match->opponent_energy = min(self::MAX_ENERGY, $match->opponent_energy + self::DEFEND_GAIN);
            $match->opponent_defending = true;
            $log[] = $match->opponent_name.' raised its guard';
        } else {
            $aiAttacks = random_int(0, 100) <= 70;

            if ($aiAttacks) {
                $match->opponent_energy -= self::ATTACK_COST;
                $damage = $this->damage($match->opponent_attack, $match->player_defense);

                if ($match->player_defending) {
                    $damage = (int) max(1, round($damage * 0.5));
                    $match->player_defending = false;
                    $log[] = $match->opponent_name.' hit you for '.$damage.' (blocked)';
                } else {
                    $log[] = $match->opponent_name.' hit you for '.$damage;
                }

                $match->player_hp = max(0, $match->player_hp - $damage);
            } else {
                $match->opponent_energy = min(self::MAX_ENERGY, $match->opponent_energy + self::DEFEND_GAIN);
                $match->opponent_defending = true;
                $log[] = $match->opponent_name.' raised its guard';
            }
        }

        // Opponent won this round?
        if ($match->player_hp <= 0) {
            $match->player_hp = 0;
            return $this->finish($match, 'lost', $log);
        }

        $match->log = $log;
        $match->save();

        return response()->json(['data' => $this->payload($match)], 200);
    }

    /**
     * Abandon the current match voluntarily.
     */
    public function quit($id)
    {
        $user = app('token_user');

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $match = ArenaMatch::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', 'ongoing')
            ->first();

        if (! $match) {
            return response()->json(['status' => 'error', 'message' => 'No active match.'], 404);
        }

        $match->status = 'quit';
        $match->save();

        return response()->json(['data' => $this->payload($match)], 200);
    }

    // ---------- helpers ----------

    private function finish(ArenaMatch $match, string $result, array $log)
    {
        $match->status = $result;
        $match->log = $log;

        // Rewards scale from the opponent's remaining "health to beat".
        if ($result === 'won') {
            $reward = (int) $match->opponent_max_hp;
        } else {
            $reward = (int) max(0, $match->opponent_max_hp - $match->opponent_hp);
        }

        $match->tokens_reward = $reward;
        $match->xp_reward = $reward;
        $match->save();

        // Award the currency only once (finish() is only reached from an
        // ongoing match, so the guard is free).
        $user = $match->user;
        $user->arena_tokens = (int) $user->arena_tokens + $reward;
        $user->arena_exp = (int) $user->arena_exp + $reward;
        $user->save();

        return response()->json(['data' => $this->payload($match)], 200);
    }

    /**
     * Resolve a damage roll from an attacker vs a defender.
     */
    private function damage(int $attack, int $defense): int
    {
        $variance = mt_rand(70, 130) / 100;
        $damage = round($attack * $variance) - round($defense * 0.3);

        return max(1, (int) $damage);
    }

    /**
     * Aggregate a user's arena statistics from their worn arena-compatible
     * items. Every avatar starts with a small base so a naked fighter is
     * still viable.
     *
     * @return array{attack:int, defense:int, max_hp:int}
     */
    private function statsForUser(User $user): array
    {
        $wornItemIds = UserWearing::where('user_id', $user->id)->pluck('item_id');
        $arenaItems = ArenaItem::whereIn('item_id', $wornItemIds)->get();

        $attack = self::BASE_ATTACK;
        $defense = self::BASE_DEFENSE;

        foreach ($arenaItems as $arenaItem) {
            $attack += (int) $arenaItem->attack;
            $defense += (int) $arenaItem->defense;
        }

        return [
            'attack' => $attack,
            'defense' => $defense,
            'max_hp' => 100 + ($defense * 10),
        ];
    }

    /**
     * Try to find a real online opponent fitting the power bracket, choosing
     * the one closest to the player's power.
     *
     * @return array{is_robot:bool, user_id:?int, name:string, attack:int, defense:int, max_hp:int}|null
     */
    private function findRealOpponent(User $player, int $minPower, int $maxPower, int $playerPower): ?array
    {
        $candidates = User::where('id', '!=', $player->id)
            ->where('role', '!=', 'banned')
            ->where('last_seen_at', '>=', now()->subMinutes(5))
            ->whereHas('wearing', function ($q) {
                $q->whereIn('item_id', ArenaItem::select('item_id'));
            })
            ->orderByDesc('last_seen_at')
            ->limit(20)
            ->get();

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $stats = $this->statsForUser($candidate);
            $power = $stats['attack'] + $stats['defense'];

            if ($power < $minPower || $power > $maxPower) {
                continue;
            }

            $distance = abs($power - $playerPower);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = [
                    'user_id' => $candidate->id,
                    'name' => $candidate->username,
                    'attack' => $stats['attack'],
                    'defense' => $stats['defense'],
                    'max_hp' => $stats['max_hp'],
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        return array_merge($best, ['is_robot' => false]);
    }

    /**
     * Build a robot opponent with random stats inside the power bracket.
     *
     * @return array{is_robot:bool, user_id:?int, name:string, attack:int, defense:int, max_hp:int}
     */
    private function makeRobot(int $minPower, int $maxPower): array
    {
        $target = random_int($minPower, $maxPower);
        $attack = random_int(5, max(5, $target - 5));
        $defense = max(0, $target - $attack);

        $names = ['Robo Knight', 'Iron Golem', 'Arena Bot', 'Bronze Sentinel', 'Steel Puppet', 'Turret Prime'];

        return [
            'is_robot' => true,
            'user_id' => null,
            'name' => $names[array_rand($names)],
            'attack' => $attack,
            'defense' => $defense,
            'max_hp' => 100 + ($defense * 10),
        ];
    }

    /**
     * Number of users treated as "online" right now (for the lobby badge).
     */
    private function onlineCount(): int
    {
        return User::where('last_seen_at', '>=', now()->subSeconds(10))->count();
    }

    /**
     * Shape a match for the frontend.
     *
     * @return array<string, mixed>
     */
        private function payload(ArenaMatch $match): array
    {
        return [
            'id' => $match->id,
            'status' => $match->status,
            'is_robot' => (bool) $match->is_robot,
            'opponent_name' => $match->opponent_name,
            'opponent_user_id' => $match->opponent_user_id,
            'player' => [
                'name' => 'You',
                'username' => $match->user->username,
                'attack' => (int) $match->player_attack,
                'defense' => (int) $match->player_defense,
                'max_hp' => (int) $match->player_max_hp,
                'hp' => (int) $match->player_hp,
                'energy' => (int) $match->player_energy,
            ],
            'opponent' => [
                'name' => $match->opponent_name,
                'attack' => (int) $match->opponent_attack,
                'defense' => (int) $match->opponent_defense,
                'max_hp' => (int) $match->opponent_max_hp,
                'hp' => (int) $match->opponent_hp,
                'energy' => (int) $match->opponent_energy,
            ],
            'tokens_reward' => (int) $match->tokens_reward,
            'xp_reward' => (int) $match->xp_reward,
            'log' => $match->log ?? [],
        ];
    }
}
