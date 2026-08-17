<?php

namespace App\Http\Controllers\Arena;

use App\Http\Controllers\Controller;
use App\Models\ArenaItem;
use App\Models\ArenaMatch;
use App\Models\User;
use App\Models\UserWearing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MatchController extends Controller
{
    // Stamina tuning (drains fast so fights stay punchy).
    private const MAX_STAMINA = 100;
    private const STAMINA_REGEN = 16;
    private const REST_REGEN_BONUS = 20; // extra regen while guarding while exhausted
    private const ATTACK_COST = 25;
    private const FINISHER_COST = 45;
    private const GUARD_COST = 10;
    private const DODGE_COST = 40;
    private const ABILITY_COST = 45;

    // Cooldowns (rounds).
    private const DODGE_COOLDOWN = 3;
    private const DEFAULT_ABILITY_COOLDOWN = 4;

    // Combat multipliers.
    private const FINISHER_MULTIPLIER = 1.6;
    private const GUARD_BLOCK_MULTIPLIER = 0.35;
    private const VULNERABLE_MULTIPLIER = 1.3;
    private const SUDDEN_CHANCE = 15; // percent chance an attack is unparriable

    // Pacing: a match is capped at ~100s (highest remaining HP wins on time),
    // and every telegraphed attack must be parried within a 1.5-3s window or
    // it automatically goes through.
    private const MATCH_DURATION_SECONDS = 100;
    private const PARRY_MIN_MS = 3000;
    private const PARRY_MAX_MS = 5000;
    private const FIRST_ROUND_GRACE_MS = 8000; // time to load the match page

    // Anti-lag parry: the client reports how much parry time was left when the
    // player clicked. If the request lands within this many seconds after the
    // deadline, the click is honoured as "just in time" (the network was simply
    // slower than the player). The window is generous because an attack can
    // spend up to ~3s in the key-sequence minigame after the click. Rounds
    // that expired longer ago are still auto-resolved, so a player who stepped
    // away can't retroactively parry.
    private const PARRY_LAG_GRACE_SECONDS = 6;

    // Stat baselines.
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
        $minPower = max(2, (int) round($playerPower * 0.95));
        $maxPower = max($minPower + 1, (int) round($playerPower * 1.5));

        $opponent = $this->findRealOpponent($user, $minPower, $maxPower, $playerPower);

        if ($opponent === null) {
            $opponent = $this->makeRobot($minPower, $maxPower);
        }

        // Each fighter fights with the moves of their worn weapon (the
        // arena-compatible item with the highest combined stats). Robots draw
        // a random weapon's moveset from the catalogue.
        $playerMoves = $this->movesForUser($user);
        $opponentUser = $opponent['is_robot'] ? null : User::find($opponent['user_id']);
        $opponentMoves = $opponentUser
            ? $this->movesForUser($opponentUser)
            : $this->movesForRobot((int) $opponent['attack']);

        $match = ArenaMatch::create([
            'user_id' => $user->id,
            'is_robot' => $opponent['is_robot'],
            'opponent_user_id' => $opponent['user_id'],
            'opponent_name' => $opponent['name'],
            'player_attack' => $player['attack'],
            'player_defense' => $player['defense'],
            'player_max_hp' => $player['max_hp'],
            'player_hp' => $player['max_hp'],
            'player_energy' => self::MAX_STAMINA,
            'opponent_attack' => $opponent['attack'],
            'opponent_defense' => $opponent['defense'],
            'opponent_max_hp' => $opponent['max_hp'],
            'opponent_hp' => $opponent['max_hp'],
            'opponent_energy' => self::MAX_STAMINA,
            'status' => 'ongoing',
            'log' => [],
            'player_moves' => $playerMoves,
            'opponent_moves' => $opponentMoves,
            'round' => 1,
            'player_stamina' => self::MAX_STAMINA,
            'opponent_stamina' => self::MAX_STAMINA,
        ]);

        // The opponent commits its first action immediately so the player has
        // a telegraph to respond to the moment the fight starts. The first
        // window is generous so the player has time to actually load the page.
        $this->commitOpponentIntent($match, self::FIRST_ROUND_GRACE_MS);

        return response()->json(['data' => $this->payload($match)], 201);
    }

    /**
     * The player's currently active match (if any). Also advances any rounds
     * whose parry window closed while the player was away, so the polling
     * frontend always sees up-to-date state.
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

        if ($match) {
            if (empty($match->opponent_intent)) {
                // Old-format match without a committed intent: commit one lazily.
                $this->commitOpponentIntent($match, self::FIRST_ROUND_GRACE_MS);
            }

            $log = $match->log ?? [];

            // The match clock can end a fight before any more actions resolve.
            if ($this->timeLeft($match) <= 0) {
                return $this->finishByTime($match, $log);
            }

            // Auto-resolve rounds the player was too slow to parry.
            $log = $this->autoResolveExpiredRounds($match, $log);
            if ($match->status !== 'ongoing') {
                return response()->json(['data' => $this->payload($match)], 200);
            }

            $match->log = $log;
            $match->save();
        }

        return response()->json([
            'data' => $match ? $this->payload($match) : null,
        ], 200);
    }

    /**
     * Resolve one round: the opponent's committed action (telegraphed to the
     * player) clashes with the player's response, then a new intent is
     * committed for the next round. Rounds whose parry window already closed
     * resolve first with the player "too slow", then the submitted action
     * applies to the freshly committed round.
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

        $log = $match->log ?? [];

        // The match clock can end a fight before any more actions resolve.
        if ($this->timeLeft($match) <= 0) {
            return $this->finishByTime($match, $log);
        }

        // Anti-lag parry: the client sends how much parry time was left at the
        // moment of the click. Read it before the catch-up below so a click can
        // be honoured even when the deadline passed while the request was in
        // flight. (The format is still enforced by validation further down.)
        $rawParryLeft = $request->input('parry_left_ms');
        $parryLeftMs = $rawParryLeft !== null && is_numeric($rawParryLeft)
            ? max(0, (int) $rawParryLeft)
            : null;

        // Catch up on rounds whose parry window already closed (the attack
        // went through while the player was thinking). If the player is now
        // dead, their submitted action is moot. The client-measured parry time
        // tells us the current round's deadline may have only just passed due
        // to network latency, in which case the click is honoured.
        $log = $this->autoResolveExpiredRounds($match, $log, $parryLeftMs);
        if ($match->status !== 'ongoing') {
            return response()->json(['data' => $this->payload($match)], 200);
        }

        $validated = $request->validate([
            'action' => 'required|in:attack,guard,dodge,ability',
            'move_id' => 'nullable|integer|min:1|max:2',
            // Client-measured parry time left at the moment of the click (ms).
            // Used to honour clicks that arrive just after the server deadline
            // because of network latency.
            'parry_left_ms' => 'nullable|integer|min:0|max:15000',
            // Attack-sequence accuracy, computed on the client so the key
            // timing is exact (no round-trip mid-minigame). The server snaps
            // it to the fixed tiers (1.25 / 1.0 / 0.8 / 0) below.
            'damage_multiplier' => 'nullable|numeric|min:0|max:1.25',
        ]);
        $action = $validated['action'];
        $playerMultiplier = $action === 'attack'
            ? (float) ($validated['damage_multiplier'] ?? 1.0)
            : 1.0;

        $playerMoves = $match->player_moves ?? [];
        if ($action === 'ability') {
            $playerMove = $this->slotMove($playerMoves, 2) ?? $this->defaultMoves((int) $match->player_attack)[1];
        } else {
            $playerMove = $this->moveFor($playerMoves, 1);
        }

        // --- Resource / cooldown constraints ---
        if ($action === 'dodge' && (int) $match->player_dodge_cd > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dodge is on cooldown ('.(int) $match->player_dodge_cd.' rounds left).',
            ], 422);
        }

        if ($action === 'ability' && (int) $match->player_ability_cd > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ability is recharging ('.(int) $match->player_ability_cd.' rounds left).',
            ], 422);
        }

        if ((bool) $match->player_exhausted && $action !== 'guard') {
            return response()->json([
                'status' => 'error',
                'message' => 'You are exhausted — guard to recover stamina.',
            ], 422);
        }

        if ($action !== 'guard') {
            $finisher = $action === 'attack' && (int) $match->player_combo >= 2;
            $cost = $this->actionCost($action, $finisher, false);
            if ((int) $match->player_stamina < $cost) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough stamina for that action.',
                ], 422);
            }
        }

        $log = $this->resolveRound($match, $log, $action, $playerMove, $playerMultiplier);

        // Player won this round?
        if ((int) $match->opponent_hp <= 0) {
            $match->opponent_hp = 0;
            return $this->finish($match, 'won', $log);
        }

        // Opponent won this round?
        if ((int) $match->player_hp <= 0) {
            $match->player_hp = 0;
            return $this->finish($match, 'lost', $log);
        }

        $match->log = $log;

        // Commit the next round's telegraph (fresh parry window).
        $this->commitOpponentIntent($match);

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

    // ---------- round resolution ----------

    /**
     * Resolve one full round. Stamina regenerates at the end of the round so
     * an exhausted fighter must spend at least one round resting (guarding)
     * before they can act again. The player may also act as 'pass' — the
     * auto-resolution used when a parry window expires without a response.
     *
     * $playerMultiplier is the attack-sequence accuracy reported by the
     * client (snapped to 1.25 / 1.0 / 0.8 / 0) and only scales the player's
     * strike damage.
     *
     * @return array<int, string> the battle log
     */
    private function resolveRound(ArenaMatch $match, array $log, string $playerAction, array $playerMove, float $playerMultiplier = 1.0): array
    {
        // The client computes the attack-sequence multiplier; snap it to the
        // exact tiers so a custom payload can't squeeze out extra damage.
        $playerMultiplier = match (true) {
            $playerMultiplier >= 1.25 => 1.25,
            $playerMultiplier >= 0.99 => 1.0,
            $playerMultiplier >= 0.79 => 0.8,
            default => 0.0,
        };

        $intent = $match->opponent_intent ?? [];
        $opponentAction = (string) ($intent['action'] ?? 'guard');
        $opponentMove = $intent['move'] ?? $this->moveFor($match->opponent_moves ?? [], 1);
        $opponentSudden = (bool) ($intent['sudden'] ?? false);
        $opponentName = (string) $match->opponent_name;

        $playerFinisher = $playerAction === 'attack' && (int) $match->player_combo >= 2;
        $opponentFinisher = $opponentAction === 'attack' && (int) $match->opponent_combo >= 2;

        $playerGuarding = $playerAction === 'guard';
        $opponentGuarding = $opponentAction === 'guard';
        $playerDodging = $playerAction === 'dodge';
        $opponentDodging = $opponentAction === 'dodge';

        // Heavy attacks are slow: a committed attack/ability (that is not
        // itself a heavy) interrupts them before they land.
        $playerInterrupts = $opponentFinisher && ! $playerFinisher
            && in_array($playerAction, ['attack', 'ability'], true);
        $opponentInterrupts = $playerFinisher && ! $opponentFinisher
            && in_array($opponentAction, ['attack', 'ability'], true);

        // --- Player's damage to the opponent ---
        $playerDamage = 0;
        $playerWhiffed = false;
        $playerBrokeGuard = false;
        $playerInterruptedHeavy = false;
        $opponentVulnerable = false;

        if (in_array($playerAction, ['attack', 'ability'], true)) {
            $base = (int) $playerMove['damage'];
            $power = $playerFinisher ? (int) round($base * self::FINISHER_MULTIPLIER) : $base;

            if ($opponentDodging) {
                $playerWhiffed = true;
            } elseif ($opponentInterrupts) {
                // The opponent's attack interrupted the player's heavy windup.
                $playerInterruptedHeavy = true;
            } else {
                $raw = $this->damage($power, (int) $match->opponent_defense);

                // The key-sequence accuracy multiplies the player's strike.
                if ($playerAction === 'attack') {
                    $raw = (int) round($raw * $playerMultiplier);
                }

                if ($playerInterrupts) {
                    // Interrupting a winding heavy: it never lands, target open.
                    $playerDamage = $raw;
                    $playerInterruptedHeavy = true;
                    $opponentVulnerable = true;
                } elseif ($opponentGuarding) {
                    if (($playerFinisher || $playerAction === 'ability') && $raw > 0) {
                        // A finisher/ability breaks through — but a whiffed
                        // key sequence (×0) doesn't shatter the guard.
                        $playerDamage = $raw;
                        $playerBrokeGuard = true;
                        $opponentVulnerable = true;
                    } else {
                        $playerDamage = $raw > 0
                            ? max(1, (int) round($raw * self::GUARD_BLOCK_MULTIPLIER))
                            : 0;
                    }
                } else {
                    $playerDamage = $raw;
                }
            }
        }

        // --- Opponent's damage to the player ---
        $opponentDamage = 0;
        $opponentWhiffed = false;
        $opponentBrokeGuard = false;
        $opponentInterruptedHeavy = false;
        $playerVulnerable = false;

        if (in_array($opponentAction, ['attack', 'ability'], true)) {
            $base = (int) $opponentMove['damage'];
            $power = $opponentFinisher ? (int) round($base * self::FINISHER_MULTIPLIER) : $base;

            if ($playerDodging) {
                $opponentWhiffed = true;
            } elseif ($playerInterrupts) {
                // The player's attack interrupted the opponent's heavy windup.
                $opponentInterruptedHeavy = true;
            } else {
                $raw = $this->damage($power, (int) $match->player_defense);

                if ($opponentInterrupts) {
                    $opponentDamage = $raw;
                    $opponentInterruptedHeavy = true;
                    $playerVulnerable = true;
                } elseif ($playerGuarding) {
                    if ($opponentFinisher || $opponentAction === 'ability') {
                        $opponentDamage = $raw;
                        $opponentBrokeGuard = true;
                        $playerVulnerable = true;
                    } elseif ($opponentSudden) {
                        // Too fast to guard: full damage, guard not "broken".
                        $opponentDamage = $raw;
                    } else {
                        $opponentDamage = max(1, (int) round($raw * self::GUARD_BLOCK_MULTIPLIER));
                    }
                } else {
                    $opponentDamage = $raw;
                }
            }
        }

        // A broken guard or interrupted heavy leaves the fighter staggered:
        // the extra damage applies from the NEXT round onwards.
        if ($match->player_vulnerable || $match->player_exhausted) {
            $opponentDamage = (int) round($opponentDamage * self::VULNERABLE_MULTIPLIER);
        }
        if ($match->opponent_vulnerable || $match->opponent_exhausted) {
            $playerDamage = (int) round($playerDamage * self::VULNERABLE_MULTIPLIER);
        }

        $match->opponent_hp = max(0, (int) $match->opponent_hp - $playerDamage);
        $match->player_hp = max(0, (int) $match->player_hp - $opponentDamage);

        // --- Costs & regeneration ---
        $playerCost = $this->actionCost($playerAction, $playerFinisher, (bool) $match->player_exhausted);
        $opponentCost = $this->actionCost($opponentAction, $opponentFinisher, (bool) $match->opponent_exhausted);

        $match->player_stamina = max(0, (int) $match->player_stamina - $playerCost);
        $match->opponent_stamina = max(0, (int) $match->opponent_stamina - $opponentCost);

        // Resting (guarding while exhausted) restores a little extra stamina.
        $playerResting = $playerGuarding && (bool) $match->player_exhausted;
        $opponentResting = $opponentGuarding && (bool) $match->opponent_exhausted;

        $match->player_stamina = min(self::MAX_STAMINA, (int) $match->player_stamina + self::STAMINA_REGEN + ($playerResting ? self::REST_REGEN_BONUS : 0));
        $match->opponent_stamina = min(self::MAX_STAMINA, (int) $match->opponent_stamina + self::STAMINA_REGEN + ($opponentResting ? self::REST_REGEN_BONUS : 0));

        $match->player_exhausted = (int) $match->player_stamina <= 0;
        $match->opponent_exhausted = (int) $match->opponent_stamina <= 0;

        // --- Combo tracking ---
        if ($playerAction === 'attack') {
            // A whiffed key sequence (×0) never builds toward a finisher.
            $match->player_combo = $playerFinisher || $playerWhiffed || $playerMultiplier <= 0.0
                ? 0
                : min(2, (int) $match->player_combo + 1);
        } else {
            $match->player_combo = 0;
        }

        if ($opponentAction === 'attack') {
            $match->opponent_combo = $opponentFinisher || $opponentWhiffed
                ? 0
                : min(2, (int) $match->opponent_combo + 1);
        } else {
            $match->opponent_combo = 0;
        }

        // --- Cooldowns ---
        $match->player_dodge_cd = max(0, (int) $match->player_dodge_cd - 1);
        $match->opponent_dodge_cd = max(0, (int) $match->opponent_dodge_cd - 1);
        $match->player_ability_cd = max(0, (int) $match->player_ability_cd - 1);
        $match->opponent_ability_cd = max(0, (int) $match->opponent_ability_cd - 1);

        if ($playerAction === 'dodge') {
            $match->player_dodge_cd = self::DODGE_COOLDOWN;
        }
        if ($opponentAction === 'dodge') {
            $match->opponent_dodge_cd = self::DODGE_COOLDOWN;
        }
        if ($playerAction === 'ability') {
            $match->player_ability_cd = max(1, (int) ($playerMove['cooldown'] ?? self::DEFAULT_ABILITY_COOLDOWN));
        }
        if ($opponentAction === 'ability') {
            $match->opponent_ability_cd = max(1, (int) ($opponentMove['cooldown'] ?? self::DEFAULT_ABILITY_COOLDOWN));
        }

        // --- Flags for the next round ---
        $match->player_vulnerable = $playerVulnerable;
        $match->opponent_vulnerable = $opponentVulnerable;
        $match->player_defending = $playerGuarding;
        $match->opponent_defending = $opponentGuarding;
        $match->player_last_action = $playerAction;
        $match->opponent_last_action = $opponentAction;
        $match->round = (int) $match->round + 1;

        // --- Log ---
        if ($playerAction === 'attack' && ! $playerWhiffed && ! $opponentInterrupts && $playerMultiplier !== 1.0) {
            $log[] = $playerMultiplier <= 0.0
                ? 'You fumbled the key sequence — the strike whiffs!'
                : ($playerMultiplier > 1.0
                    ? 'Perfect key sequence — the strike lands for ×1.25!'
                    : 'Sloppy key sequence — the strike only hits for ×0.8.');
        }

        if ($playerAction === 'pass') {
            $log[] = 'You were too slow — the attack goes through!';
            $log[] = $this->describeAction('opponent', $opponentAction, $opponentMove, $opponentDamage, $opponentFinisher, $opponentBrokeGuard, $opponentInterruptedHeavy, $opponentWhiffed, $opponentName);
        } else {
            $log[] = $this->describeAction('player', $playerAction, $playerMove, $playerDamage, $playerFinisher, $playerBrokeGuard, $playerInterruptedHeavy, $playerWhiffed, $opponentName);
            $log[] = $this->describeAction('opponent', $opponentAction, $opponentMove, $opponentDamage, $opponentFinisher, $opponentBrokeGuard, $opponentInterruptedHeavy, $opponentWhiffed, $opponentName);
        }

        if ($match->player_exhausted) {
            $log[] = 'You are exhausted — guard to recover stamina!';
        }
        if ($match->opponent_exhausted) {
            $log[] = $opponentName.' is exhausted!';
        }

        return $log;
    }

    /**
     * Human-readable log line for one fighter's resolved action.
     */
    private function describeAction(
        string $side,
        string $action,
        array $move,
        int $damage,
        bool $finisher,
        bool $brokeGuard,
        bool $interruptedHeavy,
        bool $whiffed,
        string $opponentName
    ): string {
        $subject = $side === 'player' ? 'You' : $opponentName;
        $moveName = (string) ($move['name'] ?? 'Strike');

        if ($whiffed) {
            return $side === 'player'
                ? $opponentName.' dodged your '.$moveName.'!'
                : 'You dodged '.$subject.'\'s '.$moveName.'!';
        }

        if ($interruptedHeavy) {
            return $side === 'player'
                ? 'You interrupted '.$opponentName.'\'s heavy attack!'
                : $subject.' interrupted your heavy attack!';
        }

        if ($action === 'guard') {
            return $side === 'player' ? 'You raise your guard.' : $subject.' raises its guard.';
        }

        if ($action === 'dodge') {
            return $side === 'player' ? 'You slip aside, evading the attack!' : $subject.' slips aside.';
        }

        if ($action === 'pass') {
            return $side === 'player' ? 'You did not respond.' : $subject.' does nothing.';
        }

        $verb = $side === 'player' ? 'You use' : $subject.' uses';
        $finisherTag = $finisher ? ' — FINISHER' : '';
        $guardNote = $brokeGuard
            ? ($side === 'player' ? ' and break through its guard!' : ' and shatter your guard!')
            : '';

        return $verb.' '.$moveName.$finisherTag.' for '.$damage.$guardNote;
    }

    /**
     * Pick the opponent's next action, commit it as the round's telegraph,
     * append it to the log and stamp the parry deadline the player must beat.
     * The AI reads the player's recent behaviour so it feels like it is
     * responding to how the player fights.
     */
    private function commitOpponentIntent(ArenaMatch $match, ?int $windowMs = null): void
    {
        $opponentMoves = $match->opponent_moves ?? [];
        $intent = $this->pickOpponentIntent($match, $opponentMoves);

        // Every committed action carries a parry window: respond within it or
        // the attack automatically goes through.
        $intent['window_ms'] = $windowMs ?? random_int(self::PARRY_MIN_MS, self::PARRY_MAX_MS);
        $match->parry_deadline = now()->addMilliseconds($intent['window_ms']);

        $match->opponent_intent = $intent;
        $log = $match->log ?? [];
        $log[] = $intent['text'];
        $match->log = $log;
    }

    /**
     * Auto-resolve every round whose parry window has already closed: the
     * player was too slow, so the attack goes through with the player acting
     * as 'pass'. Loops so a player who steps away comes back to a settled
     * fight (or a finished one).
     *
     * $parryLeftMs is the time (ms) the client still showed in the parry
     * window when the player clicked. If it is positive and the deadline only
     * just passed, the click beat the clock — the round stays open so the
     * submitted action resolves normally instead of as a 'pass'.
     *
     * @return array<int, string> the battle log
     */
    private function autoResolveExpiredRounds(ArenaMatch $match, array $log, ?int $parryLeftMs = null): array
    {
        $guard = 0;
        while ($match->status === 'ongoing'
            && ! empty($match->opponent_intent)
            && $match->parry_deadline !== null
            && $match->parry_deadline->timestamp <= now()->timestamp
            && $guard++ < 40
        ) {
            // Anti-lag: the player clicked with time left on the clock. Only
            // honour it when the deadline passed within the grace period — a
            // stale click from a frozen/background tab is still too slow.
            if ($parryLeftMs !== null
                && $parryLeftMs > 0
                && now()->timestamp - $match->parry_deadline->timestamp < self::PARRY_LAG_GRACE_SECONDS
            ) {
                $log[] = 'Just in time!';
                break;
            }
            $log = $this->resolveRound($match, $log, 'pass', $this->moveFor($match->player_moves ?? [], 1));

            if ((int) $match->opponent_hp <= 0) {
                $match->opponent_hp = 0;
                $this->finish($match, 'won', $log);
                break;
            }
            if ((int) $match->player_hp <= 0) {
                $match->player_hp = 0;
                $this->finish($match, 'lost', $log);
                break;
            }

            $this->commitOpponentIntent($match);
        }

        return $log;
    }

    /**
     * Seconds left on the match clock.
     */
    private function timeLeft(ArenaMatch $match): int
    {
        $elapsed = now()->diffInSeconds($match->created_at);

        return max(0, self::MATCH_DURATION_SECONDS - (int) $elapsed);
    }

    /**
     * The clock hit zero: the fighter with the most remaining HP wins.
     */
    private function finishByTime(ArenaMatch $match, array $log)
    {
        $playerHp = (int) $match->player_hp;
        $opponentHp = (int) $match->opponent_hp;

        if ($playerHp > $opponentHp) {
            $log[] = 'Time is up — you hold the higher HP!';
            $result = 'won';
        } elseif ($opponentHp > $playerHp) {
            $log[] = 'Time is up — '.$match->opponent_name.' holds the higher HP.';
            $result = 'lost';
        } else {
            $log[] = 'Time is up — a draw!';
            $result = 'draw';
        }

        return $this->finish($match, $result, $log);
    }

    /**
     * @return array{action:string, move:array<string,mixed>, sudden:bool, text:string}
     */
    private function pickOpponentIntent(ArenaMatch $match, array $opponentMoves): array
    {
        $stamina = (int) $match->opponent_stamina;
        $combo = (int) $match->opponent_combo;
        $dodgeCd = (int) $match->opponent_dodge_cd;
        $abilityCd = (int) $match->opponent_ability_cd;
        $playerLast = (string) ($match->player_last_action ?? '');
        $playerVulnerable = (bool) $match->player_vulnerable;
        $playerExhausted = (bool) $match->player_exhausted;
        $opponentExhausted = (bool) $match->opponent_exhausted;

        $weights = ['attack' => 40, 'guard' => 15, 'dodge' => 12, 'ability' => 8];

        // The AI builds its own combos: keep attacking to reach the finisher.
        if ($combo >= 1) {
            $weights['attack'] += 20;
        }

        // Punish a staggered or exhausted player with aggression.
        if ($playerVulnerable || $playerExhausted) {
            $weights['attack'] += 18;
        }

        // Read the player's habits.
        if ($playerLast === 'guard') {
            // Constant guarding gets broken through.
            $weights['ability'] += 10;
            $weights['attack'] += 8;
        } elseif ($playerLast === 'dodge') {
            // Dodge drains stamina; wait it out behind a guard.
            $weights['guard'] += 8;
            $weights['dodge'] -= 4;
        } elseif ($playerLast === 'attack') {
            // Trading isn't great; block or slip instead.
            $weights['guard'] += 10;
            $weights['dodge'] += 8;
        }

        // Respect resources.
        if ($stamina < self::FINISHER_COST) {
            $weights['attack'] = max(0, $weights['attack'] - 10);
        }
        if ($stamina < self::ABILITY_COST) {
            $weights['ability'] = 0;
        }
        if ($stamina < self::DODGE_COST) {
            $weights['dodge'] = 0;
        }
        if ($dodgeCd > 0) {
            $weights['dodge'] = 0;
        }
        if ($abilityCd > 0) {
            $weights['ability'] = 0;
        }
        if ($opponentExhausted) {
            $weights = ['attack' => 0, 'guard' => 100, 'dodge' => 0, 'ability' => 0];
        }

        $action = $this->weightedPick($weights);

        if ($action === 'ability') {
            $move = $this->slotMove($opponentMoves, 2) ?? $this->defaultMoves((int) $match->opponent_attack)[1];
        } else {
            $move = $this->moveFor($opponentMoves, 1);
        }

        $sudden = in_array($action, ['attack', 'ability'], true)
            && random_int(1, 100) <= self::SUDDEN_CHANCE;

        return [
            'action' => $action,
            'move' => $move,
            'sudden' => $sudden,
            'text' => $this->telegraphText($action, $move, (string) $match->opponent_name, $combo, $sudden),
        ];
    }

    /**
     * The telegraph the player sees before responding.
     */
    private function telegraphText(string $action, array $move, string $name, int $combo, bool $sudden): string
    {
        return match ($action) {
            'guard' => $name.' raises its guard.',
            'dodge' => $name.' shifts its weight, ready to dodge.',
            'ability' => $name.' charges '.($move['name'] ?? 'Power Smash').'!',
            default => $combo >= 2
                ? $name.' winds up a heavy attack!'
                : ($sudden
                    ? $name.' strikes with terrifying speed — too fast to guard!'
                    : $name.' lunges in with '.($move['name'] ?? 'Quick Strike').'!'),
        };
    }

    private function weightedPick(array $weights): string
    {
        $total = (int) array_sum($weights);
        if ($total <= 0) {
            return 'guard';
        }

        $roll = random_int(1, $total);
        $acc = 0;
        foreach ($weights as $action => $weight) {
            $acc += $weight;
            if ($roll <= $acc) {
                return $action;
            }
        }

        return 'guard';
    }

    /**
     * Stamina cost of an action (guarding while exhausted is free resting,
     * and passing because you were too slow costs nothing).
     */
    private function actionCost(string $action, bool $finisher, bool $exhausted): int
    {
        return match ($action) {
            'attack' => $finisher ? self::FINISHER_COST : self::ATTACK_COST,
            'guard' => $exhausted ? 0 : self::GUARD_COST,
            'dodge' => self::DODGE_COST,
            'ability' => self::ABILITY_COST,
            default => 0,
        };
    }

    // ---------- helpers ----------

    private function finish(ArenaMatch $match, string $result, array $log)
    {
        $match->status = $result;
        $match->log = $log;
        $match->opponent_intent = null;
        $match->parry_deadline = null;

        // Rewards scale from the opponent's remaining "health to beat"; a
        // draw still pays a consolation.
        if ($result === 'won') {
            $reward = (int) $match->opponent_max_hp;
        } elseif ($result === 'draw') {
            $reward = (int) round((int) $match->opponent_max_hp * 0.5);
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

        // Advance the player's daily challenges. Every completed fight counts
        // towards "play_matches", a win towards "win_matches", and a flawless
        // win (no HP lost) towards "flawless".
        $won = $result === 'won';

        ChallengeController::registerProgress($user->id, 'play_matches');

        if ($won) {
            ChallengeController::registerProgress($user->id, 'win_matches');

            if ((int) $match->player_hp >= (int) $match->player_max_hp) {
                ChallengeController::registerProgress($user->id, 'flawless');
            }
        }

        return response()->json(['data' => $this->payload($match)], 200);
    }

    /**
     * The two moves a fighter uses in battle, taken from their worn weapon
     * (the arena-compatible item with the highest combined stats). Every
     * weapon is configured with up to two named moves in the admin panel;
     * missing slots fall back to generic strikes scaled from the fighter's
     * attack stat.
     */
    private function movesForUser(User $user): array
    {
        $wornItemIds = UserWearing::where('user_id', $user->id)->pluck('item_id');

        $weapon = ArenaItem::with(['moves' => fn ($q) => $q->orderBy('position')])
            ->whereIn('item_id', $wornItemIds)
            ->orderByDesc(DB::raw('attack + defense'))
            ->first();

        if ($weapon && $weapon->moves->isNotEmpty()) {
            return $this->padMoves($weapon->moves, (int) $weapon->attack);
        }

        return $this->defaultMoves($this->statsForUser($user)['attack']);
    }

    /**
     * Robot opponents borrow a random weapon's moveset from the catalogue so
     * every bot fight feels a little different.
     */
    private function movesForRobot(int $attack): array
    {
        $weapon = ArenaItem::with(['moves' => fn ($q) => $q->orderBy('position')])
            ->whereHas('moves')
            ->inRandomOrder()
            ->first();

        if ($weapon && $weapon->moves->isNotEmpty()) {
            return $this->padMoves($weapon->moves, $attack);
        }

        return $this->defaultMoves($attack);
    }

    /**
     * Serialise a weapon's moves (max two, ordered by slot) into the shape
     * the frontend expects, topping up missing slots with default strikes.
     */
    private function padMoves($moves, int $attack): array
    {
        $list = $moves->sortBy('position')->values()->map(fn ($m) => [
            'position' => (int) $m->position,
            'name' => $m->name,
            'damage' => (int) $m->damage,
            'cooldown' => (int) ($m->cooldown ?? 0),
            'border_color' => $m->border_color,
        ])->all();

        $existing = collect($list)->pluck('position')->all();

        foreach ($this->defaultMoves($attack) as $default) {
            if (! in_array($default['position'], $existing, true)) {
                $list[] = $default;
            }
        }

        usort($list, fn ($a, $b) => $a['position'] <=> $b['position']);

        return array_slice($list, 0, 2);
    }

    /**
     * Generic moves used when a fighter has no configured weapon moveset.
     *
     * @return array<int, array{position:int, name:string, damage:int, cooldown:int, border_color:string}>
     */
    private function defaultMoves(int $attack): array
    {
        return [
            [
                'position' => 1,
                'name' => 'Quick Strike',
                'damage' => max(1, $attack),
                'cooldown' => 0,
                'border_color' => '#3b82f6',
            ],
            [
                'position' => 2,
                'name' => 'Power Smash',
                'damage' => max(1, (int) round($attack * 1.5)),
                'cooldown' => self::DEFAULT_ABILITY_COOLDOWN,
                'border_color' => '#f59e0b',
            ],
        ];
    }

    /**
     * Find a fighter's move by its slot (1 or 2), falling back to the first
     * move so an unset move_id never breaks an attack.
     *
     * @param  array<int, array{position?:int, name?:string, damage?:int, cooldown?:int, border_color?:string}>  $moves
     * @return array{position:int, name:string, damage:int, cooldown:int, border_color:string}
     */
    private function moveFor(array $moves, int $position): array
    {
        foreach ($moves as $move) {
            if ((int) ($move['position'] ?? 0) === $position) {
                return $this->normaliseMove($move);
            }
        }

        return $moves[0] ?? ['position' => 1, 'name' => 'Strike', 'damage' => 10, 'cooldown' => 0, 'border_color' => '#a2574f'];
    }

    /**
     * Strict slot lookup (used for the ability so a missing second move never
     * silently reuses the attack move).
     *
     * @param  array<int, array<string,mixed>>  $moves
     * @return array{position:int, name:string, damage:int, cooldown:int, border_color:string}|null
     */
    private function slotMove(array $moves, int $position): ?array
    {
        foreach ($moves as $move) {
            if ((int) ($move['position'] ?? 0) === $position) {
                return $this->normaliseMove($move);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $move
     * @return array{position:int, name:string, damage:int, cooldown:int, border_color:string}
     */
    private function normaliseMove(array $move): array
    {
        return [
            'position' => (int) ($move['position'] ?? 1),
            'name' => (string) ($move['name'] ?? 'Strike'),
            'damage' => (int) ($move['damage'] ?? 10),
            'cooldown' => (int) ($move['cooldown'] ?? 0),
            'border_color' => (string) ($move['border_color'] ?? '#a2574f'),
        ];
    }

    /**
     * Resolve a damage roll from an attacker vs a defender. Attacks hit hard
     * and defense only shaves off a little, keeping fights fast and punchy.
     */
    private function damage(int $attack, int $defense): int
    {
        $variance = mt_rand(75, 135) / 100;
        $damage = round($attack * 1.5 * $variance) - round($defense * 0.2);

        return max(3, (int) $damage);
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
            'max_hp' => 85 + ($defense * 6),
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
        $attack = random_int(5, max(5, $target - 2));
        $defense = max(0, $target - $attack);

        $names = ['Robo Knight', 'Iron Golem', 'Arena Bot', 'Bronze Sentinel', 'Steel Puppet', 'Turret Prime'];

        return [
            'is_robot' => true,
            'user_id' => null,
            'name' => $names[array_rand($names)],
            'attack' => $attack,
            'defense' => $defense,
            'max_hp' => 115 + ($defense * 3),
        ];
    }

    /**
     * Number of users treated as "online" right now (for the lobby badge).
     */
    private function onlineCount(): int
    {
        return User::where('last_seen_at', '>=', now()->subSeconds(10))->count()  + 4;
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
            'round' => (int) $match->round,
            'time_left' => $this->timeLeft($match),
            'parry_deadline' => $match->parry_deadline ? $match->parry_deadline->getTimestampMs() : null,
            'parry_window' => (int) ($match->opponent_intent['window_ms'] ?? 0),
            'player' => $this->fighterPayload($match, 'player'),
            'opponent' => $this->fighterPayload($match, 'opponent'),
            'telegraph' => $this->telegraphPayload($match->opponent_intent),
            'tokens_reward' => (int) $match->tokens_reward,
            'xp_reward' => (int) $match->xp_reward,
            'log' => $match->log ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fighterPayload(ArenaMatch $match, string $side): array
    {
        $prefix = $side === 'player' ? 'player' : 'opponent';

        return [
            'name' => $side === 'player' ? 'You' : $match->opponent_name,
            'username' => $side === 'player' ? $match->user->username : null,
            'attack' => (int) $match->{$prefix.'_attack'},
            'defense' => (int) $match->{$prefix.'_defense'},
            'max_hp' => (int) $match->{$prefix.'_max_hp'},
            'hp' => (int) $match->{$prefix.'_hp'},
            'stamina' => (int) $match->{$prefix.'_stamina'},
            'max_stamina' => self::MAX_STAMINA,
            'dodge_cd' => (int) $match->{$prefix.'_dodge_cd'},
            'ability_cd' => (int) $match->{$prefix.'_ability_cd'},
            'combo' => (int) $match->{$prefix.'_combo'},
            'vulnerable' => (bool) $match->{$prefix.'_vulnerable'},
            'exhausted' => (bool) $match->{$prefix.'_exhausted'},
            'guarding' => (bool) $match->{$prefix.'_defending'},
            'moves' => $match->{$prefix.'_moves'} ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $intent
     * @return array{action:string, move_name:string, text:string, sudden:bool}|null
     */
    private function telegraphPayload(?array $intent): ?array
    {
        if (empty($intent)) {
            return null;
        }

        return [
            'action' => (string) ($intent['action'] ?? 'guard'),
            'move_name' => (string) ($intent['move']['name'] ?? 'Strike'),
            'text' => (string) ($intent['text'] ?? ''),
            'sudden' => (bool) ($intent['sudden'] ?? false),
        ];
    }
}
