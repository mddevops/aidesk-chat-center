<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateOrganization extends Command
{
    protected $signature = 'aidesk:organization {name} {email} {--owner-name=Владелец}';

    protected $description = 'Создать организацию и владельца (пароль вводится скрыто)';

    public function handle(): int
    {
        $data = ['name' => $this->argument('name'), 'email' => strtolower(trim($this->argument('email'))), 'owner_name' => $this->option('owner-name'), 'password' => $this->secret('Пароль владельца (минимум 12 символов, буквы и цифры)')];
        $validator = Validator::make($data, ['name' => ['required', 'string', 'max:150'], 'owner_name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::min(12)->letters()->numbers()]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            $organization = Organization::create(['name' => $data['name']]);
            $owner = new User(['name' => $data['owner_name'], 'email' => $data['email'], 'password' => $data['password']]);
            $owner->organization_id = $organization->id;
            $owner->role = Role::Owner;
            $owner->save();
        });
        $this->info('Организация и владелец созданы. Пароль не выводится и не сохраняется в открытом виде.');

        return self::SUCCESS;
    }
}
