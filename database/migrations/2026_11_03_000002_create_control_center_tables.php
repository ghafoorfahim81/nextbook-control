<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The control center's own data: customers, what they bought, what they owe,
 * what they use. None of a customer's business data is ever stored here, only
 * counters (usage_snapshots).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name_en');
            $table->string('name_fa')->nullable();
            $table->string('name_ps')->nullable();
            $table->string('business_type', 40)->nullable();
            $table->string('province', 60)->nullable();
            $table->string('city', 60)->nullable();
            $table->string('license_no', 60)->nullable();
            $table->char('base_currency', 3)->default('AFN');
            $table->string('calendar_type', 10)->default('jalali');
            $table->string('locale', 5)->default('fa');
            // The part of the life cycle that is decided, not derived: the
            // running state of a subscription (expiring, expired, blocked) is
            // worked out from its dates.
            $table->string('status', 20)->default('lead'); // lead|trial|active|cancelled|archived
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->date('cancelled_on')->nullable();
            $table->date('archived_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role', 40)->nullable(); // manager|finance|technical
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // Where a customer's NextBook runs, and the company in it.
        Schema::create('instances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('erp_company_id', 26)->nullable();
            $table->string('base_url')->nullable();
            $table->string('api_token_hash', 64)->nullable();
            $table->string('erp_version', 40)->nullable();
            $table->timestamp('last_license_pull_at')->nullable();
            $table->timestamp('last_usage_at')->nullable();
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('modules');
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();
            $table->unsignedInteger('max_monthly_documents')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // A price is versioned: changing it adds a row and never rewrites the
        // old one, so an old invoice keeps the price it was made at.
        Schema::create('package_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('package_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('AFN');
            $table->unsignedSmallInteger('period_months')->default(12);
            $table->date('effective_from');
            $table->timestamps();

            $table->index(['package_id', 'currency', 'effective_from']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('package_id')->constrained();
            $table->string('kind', 10)->default('paid'); // trial|paid
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('blocked_at')->nullable();
            $table->string('block_reason')->nullable();
            // Limits changed for this customer only ({"max_users": 12}),
            // each one explained in the change history.
            $table->json('overrides')->nullable();
            $table->text('notes')->nullable();
            // Goes up on every change, so an ERP can tell a newer licence
            // from an older one that arrives late.
            $table->unsignedInteger('license_version')->default(1);
            $table->timestamps();
        });

        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // started|renewed|package_changed|blocked|unblocked|overridden|cancelled
            $table->foreignUlid('from_package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->foreignUlid('to_package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->date('ends_on_before')->nullable();
            $table->date('ends_on_after')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number', 30)->unique();
            $table->foreignUlid('customer_id')->constrained();
            $table->foreignUlid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('amount', 19, 4);
            $table->decimal('discount', 19, 4)->default(0);
            $table->char('currency', 3)->default('AFN');
            $table->decimal('exchange_rate', 19, 8)->nullable();
            $table->string('description')->nullable();
            $table->string('status', 10)->default('open'); // open|void
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'issued_on']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained();
            $table->decimal('amount', 19, 4);
            $table->date('paid_on');
            $table->string('method', 10); // cash|hawala|bank|other
            $table->string('reference')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Counters sent by an ERP once a day; never the business data itself.
        Schema::create('usage_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->date('taken_on');
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('branches')->default(0);
            $table->unsignedInteger('storage_mb')->default(0);
            $table->unsignedInteger('documents_this_month')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['customer_id', 'taken_on']);
        });

        // Append only: see App\Models\AuditLog.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 26)->nullable();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'usage_snapshots', 'payments', 'invoices', 'subscription_changes', 'subscriptions', 'package_prices', 'packages', 'instances', 'contacts', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
