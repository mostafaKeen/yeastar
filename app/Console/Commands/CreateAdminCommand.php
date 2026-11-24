<?php

namespace App\Console\Commands;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telephony:create-admin 
                            {email? : The admin email address} 
                            {--password= : The admin password (auto-generated if omitted)} 
                            {--name=Super Admin : The admin display name} 
                            {--tenant=KEEN Master Workspace : The primary tenant workspace name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update a Super Admin user and link them to an Owner tenant workspace';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) ($this->argument('email') ?? $this->ask('Enter admin email', 'admin@keenenter.com'));
        $password = (string) ($this->option('password') ?: $this->secret('Enter admin password') ?: Str::password(16));
        $name = (string) $this->option('name');
        $tenantName = (string) $this->option('tenant');

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        $slug = Str::slug($tenantName);
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $tenantName,
                'status' => 'active',
                'plan_tier' => 'enterprise',
                'settings' => [
                    'auto_create_crm_lead' => true,
                    'enable_call_popups' => true,
                    'enable_click_to_call' => true,
                    'enable_recording_stream' => true,
                    'smart_crm_routing' => true,
                ],
            ]
        );

        if (! $tenant->hasMember($user)) {
            $tenant->addMember($user, TenantRole::Owner, isDefault: true);
        }

        $this->components->info('Super Admin account created / updated successfully.');
        $this->table(
            ['Field', 'Value'],
            [
                ['Name', $user->name],
                ['Email', $user->email],
                ['Super Admin', $user->is_super_admin ? 'Yes' : 'No'],
                ['Tenant Workspace', $tenant->name],
                ['Tenant Role', 'Owner'],
            ]
        );

        return self::SUCCESS;
    }
}
