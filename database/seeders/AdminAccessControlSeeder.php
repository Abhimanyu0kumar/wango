<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Admin;

class AdminAccessControlSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',

            'users.view',
            'users.block',
            'users.unblock',

            'kyc.view',
            'kyc.approve',
            'kyc.reject',

            'bets.view',
            'bets.settle',

            'wallets.view',
            'withdrawals.view',
            'withdrawals.approve',
            'withdrawals.reject',
            'deposits.view',

            'rewards.view',
            'rewards.manage',

            'vip.view',
            'vip.manage',

            'reports.view',

            'admins.view',
            'admins.create',
            'admins.update',
            'admins.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'admin',
            ]);
        }

        $roles = [
            'super-admin' => $permissions,

            'finance-manager' => [
                'dashboard.view',
                'wallets.view',
                'withdrawals.view',
                'withdrawals.approve',
                'withdrawals.reject',
                'deposits.view',
                'reports.view',
            ],

            'kyc-manager' => [
                'dashboard.view',
                'users.view',
                'kyc.view',
                'kyc.approve',
                'kyc.reject',
            ],

            'support-agent' => [
                'dashboard.view',
                'users.view',
                'bets.view',
                'wallets.view',
            ],

            'risk-manager' => [
                'dashboard.view',
                'bets.view',
                'bets.settle',
                'reports.view',
            ],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'admin',
            ]);

            $role->syncPermissions($rolePermissions);
        }

        $admin = Admin::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'password' => Hash::make('admin@123'),
                'status'   => true,
            ]
        );

        $admin->assignRole('super-admin');
    }
}
