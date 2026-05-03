<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Admin AgniShop',
            'email' => 'admin@agnishop.local',
            'password' => Hash::make('P@ssword123'),
        ]);
    }
}
