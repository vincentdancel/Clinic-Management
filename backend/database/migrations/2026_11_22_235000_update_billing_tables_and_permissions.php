<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->string('item_type', 30)->default('service')->after('prescription_item_id')->index();
            $table->decimal('line_total', 10, 2)->default(0)->after('unit_price');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable()->unique()->after('receipt_number');
        });

        $permissions = [
            [
                'name' => 'View billing and payment history',
                'slug' => 'billing.view',
                'description' => 'View patient invoices, balances, and payment transaction history.',
            ],
            [
                'name' => 'Manage billing and payments',
                'slug' => 'billing.manage',
                'description' => 'Generate invoices, apply charges, record payments, and cancel unpaid invoices.',
            ],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->insertOrIgnore([
                ...$perm,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['billing.view', 'billing.manage'])
            ->pluck('id', 'slug');

        $roles = DB::table('roles')
            ->whereIn('slug', ['administrator', 'cashier', 'receptionist'])
            ->pluck('id', 'slug');

        foreach (['administrator', 'cashier'] as $roleSlug) {
            if (! isset($roles[$roleSlug])) {
                continue;
            }
            foreach (['billing.view', 'billing.manage'] as $permSlug) {
                if (isset($permissionIds[$permSlug])) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $roles[$roleSlug],
                        'permission_id' => $permissionIds[$permSlug],
                    ]);
                }
            }
        }

        if (isset($roles['receptionist']) && isset($permissionIds['billing.view'])) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $roles['receptionist'],
                'permission_id' => $permissionIds['billing.view'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropIndex(['item_type']);
            $table->dropColumn(['item_type', 'line_total']);
        });
    }
};

