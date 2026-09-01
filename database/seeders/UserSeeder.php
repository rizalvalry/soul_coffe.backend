<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\CentralKitchen;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $kitchen = CentralKitchen::query()->firstOrFail();

        $users = [
            ['phone_e164' => '+6281100000001', 'password' => 'admin123', 'role' => Role::ADMINISTRATOR, 'name' => 'Rizal Admin', 'kitchen_id' => null],
            ['phone_e164' => '+6281100000002', 'password' => 'finance123', 'role' => Role::FINANCE, 'name' => 'Sari Finance', 'kitchen_id' => null],
            ['phone_e164' => '+6281100000003', 'password' => 'barista123', 'role' => Role::BARISTA, 'name' => 'Dimas Barista', 'kitchen_id' => $kitchen->id],
            ['phone_e164' => '+6281100000004', 'password' => 'rider123', 'role' => Role::RIDER, 'name' => 'Agus Rider', 'kitchen_id' => null],
            ['phone_e164' => '+6281100000005', 'password' => 'staff123', 'role' => Role::STAFF, 'name' => 'Maufu', 'kitchen_id' => null],
        ];

        foreach ($users as $data) {
            User::query()->create([
                'name' => $data['name'],
                'phone_e164' => $data['phone_e164'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
                'kitchen_id' => $data['kitchen_id'],
                // PIN fallback (E7) only ever verifies the requesting STAFF's PIN, so
                // only the STAFF demo user gets one.
                'pin_hash' => $data['role'] === Role::STAFF ? Hash::make('123456') : null,
                'is_active' => true,
            ]);
        }
    }
}
