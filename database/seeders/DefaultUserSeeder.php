<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DefaultUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
          [
              'first_name' => 'test',
              'last_name' => 'abc',
              'email' => 'test@gmail.com',
              'password' => Hash::make('password'),
          ]  
        ];
        
        foreach ($users as $user){
            User::updateOrCreate(
                ['email' => $user['email']],
                $user
            );
        }
    }
}
