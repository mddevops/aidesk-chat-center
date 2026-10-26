<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class E2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('E2E fixtures are forbidden outside local/testing.');
        }
        DB::transaction(function () {
            foreach (['a', 'b'] as $suffix) {
                $email = 'e2e-owner-'.$suffix.'@example.test';
                if (User::where('email', $email)->exists()) {
                    continue;
                }
                $organization = Organization::create(['name' => 'E2E Организация '.strtoupper($suffix)]);
                $owner = new User(['name' => 'E2E Владелец '.strtoupper($suffix), 'email' => $email, 'password' => 'E2eOnlyPassword123']);
                $owner->organization_id = $organization->id;
                $owner->role = Role::Owner;
                $owner->save();
            }
        });
    }
}
