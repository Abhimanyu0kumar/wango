<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LuckyDrawSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        // 1. Create Category
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Fast Games',
            'slug' => 'fast-games',
            'description' => 'Real-time fast paced games like Lucky Draw.',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 2. Create Single Game with Multiple Timers
        $game = [
            'category_id' => $categoryId,
            'name' => 'Lucky Draw',
            'slug' => 'lucky-draw',
            'engine_key' => 'lucky_draw',
            'provider' => 'internal',
            'min_bet' => 10,
            'max_bet' => 100000,
            'status' => 'active', // Main game switch (kills all timers if inactive)
            'metadata' => json_encode([
                'timers' => [
                    [
                        'duration_sec' => 10,
                        'lock_time_sec' => 5,
                        'status' => 'active', // Admin can pause individual timers
                        'multipliers' => [
                            'small' => 1.90,
                            'draw' => 4.50,
                            'big' => 1.90,
                        ]
                    ],
                    [
                        'duration_sec' => 20,
                        'lock_time_sec' => 5,
                        'status' => 'active',
                        'multipliers' => [
                            'small' => 1.90,
                            'draw' => 4.50,
                            'big' => 1.90,
                        ]
                    ],
                    [
                        'duration_sec' => 30,
                        'lock_time_sec' => 5,
                        'status' => 'active',
                        'multipliers' => [
                            'small' => 1.90,
                            'draw' => 4.50,
                            'big' => 1.90,
                        ]
                    ]
                ]
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('games')->insert($game);
    }
}
