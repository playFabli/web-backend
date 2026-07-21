<?php

namespace Database\Seeders;

use App\Models\QuestDefinition;
use Illuminate\Database\Seeder;

class QuestDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        // Daily quests
        QuestDefinition::create([
            'name' => 'Play Minigames',
            'description' => 'Play minigames to earn rewards.',
            'type' => 'daily',
            'min_value' => 3,
            'max_value' => 8,
            'coin_reward' => 25,
            'exp_reward' => 15,
        ]);

        QuestDefinition::create([
            'name' => 'Post on Forums',
            'description' => 'Contribute to the community by posting on the forums.',
            'type' => 'daily',
            'min_value' => 4,
            'max_value' => 9,
            'coin_reward' => 20,
            'exp_reward' => 8,
        ]);

        QuestDefinition::create([
            'name' => 'Open Cases',
            'description' => 'Test your luck by opening cases.',
            'type' => 'daily',
            'min_value' => 1,
            'max_value' => 5,
            'coin_reward' => 20,
            'exp_reward' => 10,
        ]);

        QuestDefinition::create([
            'name' => 'Visit Marketplace',
            'description' => 'Browse the marketplace for great deals.',
            'type' => 'daily',
            'min_value' => 1,
            'max_value' => 3,
            'coin_reward' => 5,
            'exp_reward' => 1,
        ]);

        QuestDefinition::create([
            'name' => 'Send Friend Requests',
            'description' => 'Expand your network by sending friend requests.',
            'type' => 'daily',
            'min_value' => 1,
            'max_value' => 3,
            'coin_reward' => 10,
            'exp_reward' => 5,
        ]);

        QuestDefinition::create([
            'name' => 'Trade Items',
            'description' => 'Complete trades with other users.',
            'type' => 'daily',
            'min_value' => 3,
            'max_value' => 10,
            'coin_reward' => 25,
            'exp_reward' => 10,
        ]);

        // Weekly quests
        QuestDefinition::create([
            'name' => 'Sell Items on Marketplace',
            'description' => 'Help other players by selling items on the marketplace.',
            'type' => 'weekly',
            'min_value' => 20,
            'max_value' => 40,
            'coin_reward' => 150,
            'exp_reward' => 100,
        ]);

        QuestDefinition::create([
            'name' => 'Open Many Cases',
            'description' => 'Open a large number of cases this week.',
            'type' => 'weekly',
            'min_value' => 30,
            'max_value' => 75,
            'coin_reward' => 200,
            'exp_reward' => 120,
        ]);

        QuestDefinition::create([
            'name' => 'Forum Engagement',
            'description' => 'Create threads and reply to discussions.',
            'type' => 'weekly',
            'min_value' => 20,
            'max_value' => 50,
            'coin_reward' => 180,
            'exp_reward' => 110,
        ]);

        QuestDefinition::create([
            'name' => 'Trade Volume',
            'description' => 'Complete multiple trades throughout the week.',
            'type' => 'weekly',
            'min_value' => 20,
            'max_value' => 80,
            'coin_reward' => 250,
            'exp_reward' => 150,
        ]);

        // Challenge quests (same as daily but 2-3x values)
        QuestDefinition::create([
            'name' => 'Minigame Master',
            'description' => 'Play a massive number of minigames.',
            'type' => 'challenge',
            'min_value' => 20,
            'max_value' => 60,
            'coin_reward' => 500,
            'exp_reward' => 300,
        ]);

        QuestDefinition::create([
            'name' => 'Marketplace Tycoon',
            'description' => 'Sell a huge volume of items on the marketplace.',
            'type' => 'challenge',
            'min_value' => 60,
            'max_value' => 100,
            'coin_reward' => 600,
            'exp_reward' => 350,
        ]);

        QuestDefinition::create([
            'name' => 'Case Opener',
            'description' => 'Open an extreme number of cases.',
            'type' => 'challenge',
            'min_value' => 10,
            'max_value' => 25,
            'coin_reward' => 750,
            'exp_reward' => 400,
        ]);

        QuestDefinition::create([
            'name' => 'Social Butterfly',
            'description' => 'Send many friend requests and interact with the community.',
            'type' => 'challenge',
            'min_value' => 80,
            'max_value' => 170,
            'coin_reward' => 400,
            'exp_reward' => 250,
        ]);
    }
}