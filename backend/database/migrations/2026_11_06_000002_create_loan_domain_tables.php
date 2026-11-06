<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('identity_number', 16)->nullable()->unique();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('occupation_type', 100)->nullable();
            $table->string('workplace_name', 150)->nullable();
            $table->unsignedInteger('work_duration_months')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $this->check('customer_profiles', 'profiles_identity_check', "identity_number IS NULL OR identity_number REGEXP '^[0-9]{16}$'");

        Schema::create('loan_applications', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->string('application_number', 40)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('draft')->index();
            $table->unsignedBigInteger('current_submission_id')->nullable();
            $table->decimal('loan_amount', 18, 2)->nullable();
            $table->unsignedInteger('tenor_months')->nullable();
            $table->text('purpose')->nullable();
            $table->decimal('monthly_income', 18, 2)->nullable();
            $table->decimal('monthly_expenses', 18, 2)->nullable();
            $table->decimal('existing_installments', 18, 2)->nullable();
            $table->unsignedBigInteger('active_customer_id')->nullable()
                ->storedAs("CASE WHEN status NOT IN ('approved', 'rejected') THEN customer_id ELSE NULL END")->unique();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $this->check('loan_applications', 'applications_status_check', "status IN ('draft','pending_verification','revision_required','pending_approval_1','pending_approval_2','approved','rejected')");
        $this->check('loan_applications', 'applications_finance_check', '(loan_amount IS NULL OR loan_amount > 0) AND (tenor_months IS NULL OR tenor_months > 0) AND (monthly_income IS NULL OR monthly_income > 0) AND (monthly_expenses IS NULL OR monthly_expenses >= 0) AND (existing_installments IS NULL OR existing_installments >= 0)');

        Schema::create('application_submissions', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('application_id')->constrained('loan_applications')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('customer_snapshot');
            $table->decimal('loan_amount', 18, 2);
            $table->unsignedInteger('tenor_months');
            $table->text('purpose');
            $table->decimal('monthly_income', 18, 2);
            $table->decimal('monthly_expenses', 18, 2);
            $table->decimal('existing_installments', 18, 2);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at');
            $table->unique(['application_id', 'version_number'], 'submissions_application_version_unique');
            $table->unique(['application_id', 'id'], 'submissions_application_id_unique');
        });
        $this->check('application_submissions', 'submissions_values_check', 'version_number > 0 AND loan_amount > 0 AND tenor_months > 0 AND monthly_income > 0 AND monthly_expenses >= 0 AND existing_installments >= 0');
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->foreign(['id', 'current_submission_id'], 'applications_current_submission_fk')
                ->references(['application_id', 'id'])->on('application_submissions')->restrictOnDelete();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->boolean('is_required');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $this->check('document_types', 'document_types_boolean_check', 'is_required IN (0,1) AND is_active IN (0,1)');

        Schema::create('document_versions', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('application_id')->constrained('loan_applications')->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('original_filename');
            $table->string('storage_path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes');
            $table->char('file_hash', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at');
            $table->unique(['application_id', 'document_type_id', 'version_number'], 'documents_application_type_version_unique');
        });
        $this->check('document_versions', 'documents_version_check', 'version_number > 0');

        Schema::create('submission_documents', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('submission_id')->constrained('application_submissions')->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['submission_id', 'document_type_id'], 'submission_documents_type_unique');
        });

        Schema::create('model_versions', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->string('version_code', 100)->unique();
            $table->string('algorithm', 100);
            $table->json('feature_names');
            $table->json('parameters');
            $table->decimal('contamination', 6, 5);
            $table->double('decision_threshold')->default(0);
            $table->string('score_method', 40);
            $table->string('artifact_path');
            $table->char('artifact_hash', 64);
            $table->string('training_data_version', 100);
            $table->boolean('is_active')->default(false);
            $table->unsignedTinyInteger('active_marker')->nullable()->storedAs('CASE WHEN is_active = 1 THEN 1 ELSE NULL END')->unique();
            $table->dateTime('created_at');
        });
        $this->check('model_versions', 'models_values_check', "contamination > 0 AND contamination <= 0.5 AND is_active IN (0,1) AND decision_threshold = 0 AND score_method = 'decision_function'");

        Schema::create('anomaly_checks', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('submission_id')->constrained('application_submissions')->restrictOnDelete();
            $table->foreignId('model_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 20);
            $table->json('input_features');
            $table->double('score')->nullable();
            $table->boolean('is_anomaly')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('deadline_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('running_submission_id')->nullable()->storedAs("CASE WHEN status = 'processing' THEN submission_id ELSE NULL END")->unique();
            $table->unsignedBigInteger('succeeded_submission_id')->nullable()->storedAs("CASE WHEN status = 'succeeded' THEN submission_id ELSE NULL END")->unique();
            $table->unique(['submission_id', 'attempt_number'], 'checks_submission_attempt_unique');
            $table->unique(['submission_id', 'id'], 'checks_submission_id_unique');
            $table->index(['status', 'deadline_at']);
        });
        $this->check('anomaly_checks', 'checks_values_check', "status IN ('processing','succeeded','failed') AND attempt_number > 0 AND deadline_at > started_at AND (finished_at IS NULL OR finished_at >= started_at) AND (is_anomaly IS NULL OR is_anomaly IN (0,1))");
        $this->check('anomaly_checks', 'checks_result_check', "(status = 'succeeded' AND score IS NOT NULL AND is_anomaly IS NOT NULL AND is_anomaly = (score < 0) AND finished_at IS NOT NULL AND failure_reason IS NULL) OR (status = 'processing' AND score IS NULL AND is_anomaly IS NULL AND finished_at IS NULL AND failure_reason IS NULL) OR (status = 'failed' AND score IS NULL AND is_anomaly IS NULL AND finished_at IS NOT NULL)");

        Schema::create('assignments', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('application_id')->constrained('loan_applications')->restrictOnDelete();
            $table->unsignedBigInteger('submission_id');
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->string('stage', 20);
            $table->dateTime('claimed_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('end_type', 20)->nullable();
            $table->text('end_reason')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('active_application_id')->nullable()->storedAs('CASE WHEN ended_at IS NULL THEN application_id ELSE NULL END')->unique();
            $table->foreign(['application_id', 'submission_id'], 'assignments_submission_fk')->references(['application_id', 'id'])->on('application_submissions')->restrictOnDelete();
            $table->unique(['id', 'submission_id'], 'assignments_id_submission_unique');
            $table->index(['assigned_to', 'ended_at']);
        });
        $this->check('assignments', 'assignments_stage_check', "stage IN ('verification','approval_1','approval_2')");
        $this->check('assignments', 'assignments_end_check', "(ended_at IS NULL AND end_type IS NULL) OR (ended_at IS NOT NULL AND end_type IS NOT NULL AND end_type IN ('completed','released') AND ended_at >= claimed_at)");

        Schema::create('verification_results', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('submission_id')->constrained('application_submissions')->restrictOnDelete();
            $table->unsignedBigInteger('assignment_id');
            $table->foreignId('verifier_id')->constrained('users')->restrictOnDelete();
            $table->string('result_type', 20);
            $table->text('notes')->nullable();
            $table->json('document_checklist');
            $table->unsignedBigInteger('anomaly_check_id')->nullable();
            $table->unsignedBigInteger('terminal_submission_id')->nullable()->storedAs("CASE WHEN result_type IN ('forward','request_revision') THEN submission_id ELSE NULL END")->unique();
            $table->dateTime('created_at');
            $table->foreign(['assignment_id', 'submission_id'], 'verifications_assignment_fk')->references(['id', 'submission_id'])->on('assignments')->restrictOnDelete();
            $table->foreign(['submission_id', 'anomaly_check_id'], 'verifications_check_fk')->references(['submission_id', 'id'])->on('anomaly_checks')->restrictOnDelete();
        });
        $this->check('verification_results', 'verifications_result_check', "result_type IN ('note','request_revision','forward') AND (result_type <> 'request_revision' OR (notes IS NOT NULL AND CHAR_LENGTH(TRIM(notes)) > 0)) AND (result_type <> 'forward' OR anomaly_check_id IS NOT NULL)");

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('submission_id')->constrained('application_submissions')->restrictOnDelete();
            $table->unsignedBigInteger('assignment_id');
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('decision', 20);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('anomaly_check_id');
            $table->dateTime('decided_at');
            $table->unique(['submission_id', 'level']);
            $table->foreign(['assignment_id', 'submission_id'], 'decisions_assignment_fk')->references(['id', 'submission_id'])->on('assignments')->restrictOnDelete();
            $table->foreign(['submission_id', 'anomaly_check_id'], 'decisions_check_fk')->references(['submission_id', 'id'])->on('anomaly_checks')->restrictOnDelete();
        });
        $this->check('approval_decisions', 'decisions_values_check', "level IN (1,2) AND decision IN ('approved','rejected') AND (decision <> 'rejected' OR (notes IS NOT NULL AND CHAR_LENGTH(TRIM(notes)) > 0))");

        Schema::create('application_status_histories', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('application_id')->constrained('loan_applications')->restrictOnDelete();
            $table->unsignedBigInteger('submission_id')->nullable();
            $table->string('from_status', 40);
            $table->string('to_status', 40);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->dateTime('changed_at');
            $table->foreign(['application_id', 'submission_id'], 'histories_submission_fk')->references(['application_id', 'id'])->on('application_submissions')->restrictOnDelete();
        });
        $statuses = "('draft','pending_verification','revision_required','pending_approval_1','pending_approval_2','approved','rejected')";
        $this->check('application_status_histories', 'histories_status_check', "from_status IN $statuses AND to_status IN $statuses AND from_status <> to_status");

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');
        });
    }

    private function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($expression)");
    }

    public function down(): void
    {
        Schema::table('loan_applications', fn (Blueprint $table) => $table->dropForeign('applications_current_submission_fk'));
        foreach (['activity_logs', 'application_status_histories', 'approval_decisions', 'verification_results', 'assignments', 'anomaly_checks', 'model_versions', 'submission_documents', 'document_versions', 'document_types', 'application_submissions', 'loan_applications', 'customer_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
