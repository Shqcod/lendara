import type { Application, Profile } from '#lib/workspace.ts';
import type { DocumentVersion } from '#lib/documents.ts';

export type Submission = Pick<Application, 'loan_amount' | 'tenor_months' | 'purpose' | 'monthly_income' | 'monthly_expenses' | 'existing_installments'> & {
	id: number; version_number: number; customer_snapshot: Profile; submitted_at: string; documents: DocumentVersion[];
};
export type StatusHistory = { id: number; submission_id: number | null; from_status: Application['status']; to_status: Application['status']; changed_at: string; revision_reason: string | null };
export type Paginated<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number; per_page: number } };
