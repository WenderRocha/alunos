<?php

declare(strict_types = 1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->decimal('billing_amount', 10, 2)->nullable()->after('birth_date');
            $table->string('billing_recurrence', 20)->nullable()->after('billing_amount');
            $table->unsignedTinyInteger('billing_due_day')->nullable()->after('billing_recurrence');
            $table->boolean('student_receives_billing')->default(true)->after('billing_due_day');
            $table->date('billing_start_date')->nullable()->after('student_receives_billing');
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->string('phone')->nullable()->change();
        });

        Schema::create('student_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('relationship')->nullable();
            $table->boolean('is_responsible')->default(false);
            $table->timestamps();

            $table->index(['student_id', 'is_responsible']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_reminder_sent_at')->nullable();
            $table->text('last_notification_error')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'due_date']);
            $table->index(['user_id', 'status', 'due_date']);
        });

        Schema::create('billing_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedTinyInteger('reminder_day')->default(0);
            $table->string('recipient_type', 20);
            $table->string('recipient_name');
            $table->string('phone');
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'kind', 'reminder_day', 'phone']);
            $table->index(['invoice_id', 'kind', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_notifications');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('student_contacts');

        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_amount',
                'billing_recurrence',
                'billing_due_day',
                'student_receives_billing',
                'billing_start_date',
            ]);
        });
    }
};
