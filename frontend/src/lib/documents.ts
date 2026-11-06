export type DocumentType = { id: number; code: string; name: string; is_required: boolean; is_active: boolean };
export type DocumentVersion = {
	id: number; document_type_id: number; version_number: number; original_filename: string;
	mime_type: string; file_size_bytes: number; uploaded_at: string;
};
