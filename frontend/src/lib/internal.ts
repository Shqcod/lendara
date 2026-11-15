import type { Application, Profile } from '#lib/workspace.ts';
import type { Submission, Paginated } from '#lib/submissions.ts';

export const reviewerRoles = ['verifikator', 'penyetuju_1', 'penyetuju_2'] as const;
export const roleLabels: Record<string, string> = { verifikator: 'Verifikator', penyetuju_1: 'Penyetuju tingkat 1', penyetuju_2: 'Penyetuju tingkat 2' };
export const stageLabels: Record<string, string> = { verification: 'Verifikasi', approval_1: 'Persetujuan tingkat 1', approval_2: 'Persetujuan tingkat 2' };
export type Assignment = { id: number; application_id: number; submission_id: number; assigned_to: number; assignee: { id: number; name: string | null }; stage: string; claimed_at: string; ended_at: string | null; end_type: string | null; end_reason: string | null };
export type ReviewRow = { id: number; application_number: string; status: Application['status']; current_submission_id: number; version_number: number; customer_name: string; loan_amount: string; submitted_at: string; current_assignment: Assignment | null };
export type Summary = { status: Application['status']; stage: string; total: number; unassigned: number; mine: number; assigned_to_others: number };
export type Check = { id: number; attempt_number: number; status: 'processing' | 'failed' | 'succeeded'; score: number | null; is_anomaly: boolean | null; failure_reason: string | null; model_version_code: string; started_at: string; deadline_at: string; finished_at: string | null };
export type ReviewSnapshot = Submission & { customer_snapshot: Profile; anomaly_checks: Check[]; verification_results: Paginated<{ id: number; result_type: string; notes: string | null; document_checklist: Record<string, boolean>; created_at: string }> };
export type ReviewDetail = ReviewRow & { current_submission: ReviewSnapshot; submissions: Paginated<{ id: number; version_number: number; submitted_at: string }>; assignments: Paginated<Assignment>; status_histories: Paginated<{ id: number; from_status: Application['status']; to_status: Application['status']; changed_at: string }> };
