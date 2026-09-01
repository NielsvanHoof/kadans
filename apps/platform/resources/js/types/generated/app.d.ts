declare namespace App {
    namespace Accounts {
        namespace Enums {
            export type Locale = "en" | "nl";
            export type PlatformRole = "support";
            export type TwoFactorEnforcement = "off" | "all" | "by_permission";
        }
    }
    namespace AudioProcessing {
        namespace Enums {
            export type TranscriptionDriver = "mock" | "transcribe";
            export type TranscriptionProcessingOutcome =
                "immediate" | "deferred";
        }
    }
    namespace Audit {
        namespace Enums {
            export type AuditEvent =
                | "dossier.viewed"
                | "document_request.created"
                | "document_request.accepted"
                | "document_request.rejected"
                | "document.uploaded"
                | "questionnaire.answer_submitted"
                | "client_portal.questionnaire_completed"
                | "dossier.completed"
                | "document.viewed"
                | "document.downloaded"
                | "dossier.export_requested"
                | "dossier.export_completed"
                | "dossier.export_failed"
                | "dossier.export_downloaded"
                | "document.processing_started"
                | "document.processing_completed"
                | "document.processing_failed"
                | "client_portal.access_grant_created"
                | "client_portal.access_grant_revoked"
                | "client_portal.accessed"
                | "client_portal.accessed_from_new_device"
                | "client_portal.new_device_blocked"
                | "client_portal.invite_queued"
                | "client_portal.invite_sent"
                | "client_portal.invite_failed"
                | "dossier.reminder_queued"
                | "dossier.reminder_sent"
                | "dossier.reminder_failed"
                | "client_portal.changes_requested_queued"
                | "client_portal.changes_requested_sent"
                | "client_portal.changes_requested_failed"
                | "access.denied"
                | "member.invited"
                | "member.invitation_accepted"
                | "member.invitation_revoked"
                | "member.role_changed"
                | "role.created"
                | "role.updated"
                | "role.deleted"
                | "member.suspended"
                | "member.removed"
                | "dossier.deleted"
                | "client.deleted"
                | "dossier.restored"
                | "client.restored"
                | "document_request.deleted"
                | "questionnaire_template.deleted"
                | "questionnaire_template_item.deleted"
                | "pipeline.retried"
                | "pipeline.cancelled"
                | "pipeline.deleted"
                | "user.deleted"
                | "data_subject.request_logged"
                | "data_subject.request_refused"
                | "data_subject.export_completed"
                | "data_subject.erasure_completed"
                | "data_subject.export_downloaded"
                | "support.session_started"
                | "support.session_ended"
                | "support.session_expired"
                | "audio_recording.uploaded"
                | "audio_recording.downloaded"
                | "audio_recording.deleted"
                | "audio_recording.transcription_started"
                | "audio_recording.transcription_completed"
                | "audio_recording.transcription_failed"
                | "file.blocked_by_malware_scan";
            export type AuditPermission = "audit.view";
        }
    }
    namespace ClientPortal {
        namespace Data {
            export type ClientPortalPageData = {
                readonly firm: App.ClientPortal.Data.PortalFirmData;
                readonly dossier: App.ClientPortal.Data.PortalDossierData;
                readonly session: App.ClientPortal.Data.PortalSessionData;
            };
            export type PortalClientData = {
                readonly name: string;
            };
            export type PortalDocumentRequestData = {
                readonly id: number;
                readonly type: App.Questionnaires.Enums.QuestionnaireItemType;
                readonly field_key: string;
                readonly title: string;
                readonly instructions: string | null;
                readonly status: App.Dossiers.Enums.DocumentRequestStatus;
                readonly answer_text: string | null;
                readonly answer_boolean: boolean | null;
                readonly answer_option_ids: string[] | null;
                readonly configuration: Record<string, any> | null;
                readonly rejection_reason: string | null;
                readonly can_respond: boolean;
                readonly uploaded_documents: App.ClientPortal.Data.PortalUploadedDocumentData[];
            };
            export type PortalDossierData = {
                readonly title: string;
                readonly reference: string | null;
                readonly status: App.Dossiers.Enums.DossierStatus;
                readonly due_date: string | null;
                readonly client: App.ClientPortal.Data.PortalClientData;
                readonly expires_at: string;
                readonly progress: App.ClientPortal.Data.PortalProgressData;
                readonly document_requests: App.ClientPortal.Data.PortalDocumentRequestData[];
            };
            export type PortalFirmData = {
                name: string;
                readonly contact_name: string;
                readonly contact_email: string;
            };
            export type PortalNextIncompleteData = {
                readonly id: number;
                readonly title: string;
            };
            export type PortalProgressData = {
                readonly total: number;
                readonly completed: number;
                readonly approved: number;
                readonly remaining: number;
                readonly next_incomplete: App.ClientPortal.Data.PortalNextIncompleteData | null;
            };
            export type PortalSessionData = {
                readonly idle_expires_at: string;
                readonly absolute_expires_at: string;
            };
            export type PortalUploadedDocumentData = {
                readonly id: number;
                readonly original_filename: string;
                readonly size_bytes: number;
                readonly uploaded_at: string;
            };
        }
        namespace Enums {
            export type ClientAccessGrantPermission =
                "client_access.grant" | "client_access.revoke";
            export type NewPortalDeviceAction = "notify" | "block" | "ignore";
            export type PortalDeviceOutcome =
                | "first_use"
                | "recognised"
                | "new_device_allowed"
                | "new_device_blocked";
            export type PortalSessionEndReason = "timed_out" | "access_ended";
        }
    }
    namespace Clients {
        namespace Data {
            export type ArchivedClientRowData = {
                readonly id: number;
                readonly name: string;
                readonly email: string;
                readonly dossiers_count: number;
                readonly archived_at: string;
            };
            export type ClientDossierRowData = {
                readonly id: number;
                readonly title: string;
                readonly reference: string | null;
                readonly status: App.Dossiers.Enums.DossierStatus;
                readonly client_name: string;
                readonly updated_at: string;
            };
            export type ClientIndexRowData = {
                readonly id: number;
                readonly name: string;
                readonly email: string;
                readonly dossiers_count: number;
            };
            export type ClientShowPageData = {
                readonly client: App.Clients.Data.ClientSummaryData;
                readonly dossiers: LaravelPaginated<
                    number,
                    Record<string, any>
                >;
            };
            export type ClientSummaryData = {
                readonly id: number;
                readonly name: string;
                readonly email: string;
                readonly active_dossiers_count: number;
                readonly archived_dossiers_count: number;
            };
        }
        namespace Enums {
            export type ClientPermission =
                | "clients.view"
                | "clients.create"
                | "clients.update"
                | "clients.delete"
                | "clients.restore";
        }
    }
    namespace DocumentProcessing {
        namespace Enums {
            export type DocumentProcessingStatus =
                "pending" | "processing" | "completed" | "failed";
            export type ExtractionFieldSensitivity =
                | "bsn"
                | "iban"
                | "id_number"
                | "account_number"
                | "date_of_birth"
                | "email"
                | "phone";
            export type OcrDriver = "mock" | "demo" | "textract";
            export type OcrProcessingOutcome = "immediate" | "deferred";
            export type TranscriptionStatus =
                "pending" | "processing" | "completed" | "failed";
        }
    }
    namespace Dossiers {
        namespace Data {
            export type ArchivedDossierRowData = {
                readonly id: number;
                readonly title: string;
                readonly reference: string | null;
                readonly status: App.Dossiers.Enums.DossierStatus;
                readonly client_name: string;
                readonly client_archived: boolean;
                readonly archived_at: string;
            };
            export type DossierAccessGrantData = {
                readonly id: number;
                readonly expires_at: string;
                readonly revoked_at: string | null;
                readonly last_used_at: string | null;
                readonly is_valid: boolean;
            };
            export type DossierBuilderData = {
                readonly id: number;
                readonly title: string;
                readonly status: App.Dossiers.Enums.DossierStatus;
                readonly document_requests: App.Dossiers.Data.StaffDocumentRequestData[];
            };
            export type DossierBuilderLockData = {
                readonly is_mine: boolean;
                readonly token: string | null;
                readonly expires_at: string | null;
                readonly holder: App.Shared.Data.PersonOptionData | null;
            };
            export type DossierClientSummaryData = {
                readonly name: string;
                readonly email: string;
            };
            export type DossierExportData = {
                readonly id: number;
                readonly status: App.Dossiers.Enums.DossierExportStatus;
                readonly created_at: string;
                readonly expires_at: string | null;
                readonly download_url: string | null;
                readonly error: string | null;
                readonly pipeline: App.Pipelines.Data.PipelineData | null;
            };
            export type DossierIndexRowData = {
                readonly id: number;
                readonly client_name: string;
                readonly title: string;
                readonly reference: string | null;
                readonly status: App.Dossiers.Enums.DossierStatus;
                readonly due_date: string | null;
                readonly responsible_name: string | null;
            };
            export type DossierReviewData = {
                readonly id: number;
                readonly title: string;
                readonly status: App.Dossiers.Enums.DossierStatus;
                readonly client: App.Dossiers.Data.DossierClientSummaryData;
                readonly document_requests: App.Dossiers.Data.StaffDocumentRequestData[];
                readonly review_summary: App.Dossiers.Data.DossierReviewSummaryData;
                readonly ready_to_complete: boolean;
            };
            export type DossierReviewSummaryData = {
                readonly total: number;
                readonly pending: number;
                readonly submitted: number;
                readonly accepted: number;
                readonly rejected: number;
            };
            export type DossierShowData = {
                readonly id: number;
                readonly title: string;
                readonly reference: string | null;
                readonly status: App.Dossiers.Enums.DossierStatus;
                readonly due_date: string | null;
                readonly responsible_staff: App.Shared.Data.PersonOptionData | null;
                readonly reminder_interval_days: number | null;
                readonly next_reminder_at: string | null;
                readonly last_client_message_sent_at: string | null;
                readonly last_client_opened_at: string | null;
                readonly days_remaining: number | null;
                readonly has_outstanding_client_items: boolean;
                readonly ready_to_complete: boolean;
                readonly review_summary: App.Dossiers.Data.DossierReviewSummaryData;
                readonly client: App.Dossiers.Data.DossierClientSummaryData;
                readonly document_requests: App.Dossiers.Data.StaffDocumentRequestData[];
                readonly access_grants: App.Dossiers.Data.DossierAccessGrantData[];
                readonly exports: App.Dossiers.Data.DossierExportData[];
                readonly audio_recordings: App.Dossiers.Data.StaffAudioRecordingData[];
                readonly exports_count: number;
                readonly audio_recordings_count: number;
            };
            export type StaffAudioRecordingData = {
                readonly id: number;
                readonly original_filename: string;
                readonly size_bytes: number;
                readonly uploaded_at: string;
                readonly transcription_status: App.DocumentProcessing.Enums.TranscriptionStatus;
                readonly transcription_error: string | null;
                readonly pipeline: App.Pipelines.Data.PipelineData | null;
                readonly can_delete: boolean;
            };
            export type StaffDocumentRequestData = {
                readonly id: number;
                readonly type: App.Questionnaires.Enums.QuestionnaireItemType;
                readonly field_key: string;
                readonly title: string;
                readonly instructions: string | null;
                readonly configuration: Record<string, any> | null;
                readonly status: App.Dossiers.Enums.DocumentRequestStatus;
                readonly answer_text: string | null;
                readonly answer_boolean: boolean | null;
                readonly answer_option_ids: string[] | null;
                readonly answered_at: string | null;
                readonly reviewed_at: string | null;
                readonly reviewed_by_name: string | null;
                readonly rejection_reason: string | null;
                readonly sort_order: number;
                readonly is_visible: boolean;
                readonly is_applicable: boolean;
                readonly uploaded_documents: App.Dossiers.Data.StaffUploadedDocumentData[];
            };
            export type StaffDocumentRequestRowsData = {
                readonly rows: App.Dossiers.Data.StaffDocumentRequestData[];
                readonly review_summary: App.Dossiers.Data.DossierReviewSummaryData;
                readonly has_outstanding_client_items: boolean;
                readonly ready_to_complete: boolean;
            };
            export type StaffUploadedDocumentData = {
                readonly id: number;
                readonly original_filename: string;
                readonly size_bytes: number;
                readonly uploaded_at: string;
                readonly processing_status: App.DocumentProcessing.Enums.DocumentProcessingStatus;
                readonly processing_error: string | null;
                readonly pipeline: App.Pipelines.Data.PipelineData | null;
                readonly can_delete: boolean;
            };
            export type UploadTicketData = {
                readonly upload_url: string;
                readonly ticket: string;
                readonly content_type: string;
                readonly expires_at: string;
            };
        }
        namespace Enums {
            export type AudioRecordingPermission =
                | "audio_recordings.view"
                | "audio_recordings.upload"
                | "audio_recordings.download"
                | "audio_recordings.delete";
            export type DocumentRequestPermission =
                | "document_requests.view"
                | "document_requests.create"
                | "document_requests.update"
                | "document_requests.delete"
                | "document_requests.upload"
                | "document_requests.review";
            export type DocumentRequestStatus =
                "pending" | "submitted" | "accepted" | "rejected";
            export type DossierExportStatus =
                "pending" | "processing" | "completed" | "failed";
            export type DossierPermission =
                | "dossiers.view"
                | "dossiers.create"
                | "dossiers.update"
                | "dossiers.delete"
                | "dossiers.restore"
                | "dossiers.complete"
                | "dossiers.export"
                | "dossiers.send_reminder";
            export type DossierReminderSource = "manual" | "scheduled";
            export type DossierStatus =
                "draft" | "awaiting_client" | "in_review" | "completed";
            export type MalwareScanStatus =
                | "NO_THREATS_FOUND"
                | "THREATS_FOUND"
                | "UNSUPPORTED"
                | "ACCESS_DENIED"
                | "FAILED";
            export type SubmissionContext = "portal" | "staff";
            export type UploadPurpose = "document" | "audio_recording";
            export type UploadedDocumentPermission =
                "documents.view" | "documents.download";
        }
    }
    namespace Integrations {
        namespace Enums {
            export type OAuthProvider = "google" | "microsoft";
        }
    }
    namespace Pipelines {
        namespace Data {
            export type PipelineData = {
                readonly id: number;
                readonly type: App.Pipelines.Enums.PipelineType;
                readonly status: App.Pipelines.Enums.PipelineStatus;
                readonly current_stage: App.Pipelines.Enums.PipelineStepKey | null;
                readonly attempt_number: number;
                readonly last_activity_at: string | null;
                readonly steps: App.Pipelines.Data.PipelineStepData[];
            };
            export type PipelineIndexRowData = {
                readonly id: number;
                readonly type: App.Pipelines.Enums.PipelineType;
                readonly status: App.Pipelines.Enums.PipelineStatus;
                readonly current_stage: App.Pipelines.Enums.PipelineStepKey | null;
                readonly attempt_number: number;
                readonly last_activity_at: string | null;
                readonly created_at: string;
                readonly subject_type: string;
                readonly subject_id: number;
            };
            export type PipelineShowData = {
                readonly id: number;
                readonly type: App.Pipelines.Enums.PipelineType;
                readonly status: App.Pipelines.Enums.PipelineStatus;
                readonly current_stage: App.Pipelines.Enums.PipelineStepKey | null;
                readonly provider: string | null;
                readonly provider_job_id: string | null;
                readonly attempt_number: number;
                readonly error_code: string | null;
                readonly error_message: string | null;
                readonly metadata: Record<string, any> | null;
                readonly started_at: string | null;
                readonly last_activity_at: string | null;
                readonly next_retry_at: string | null;
                readonly completed_at: string | null;
                readonly failed_at: string | null;
                readonly steps: App.Pipelines.Data.PipelineStepData[];
                readonly subject: App.Pipelines.Data.PipelineSubjectData;
            };
            export type PipelineStepData = {
                readonly key: App.Pipelines.Enums.PipelineStepKey;
                readonly status: App.Pipelines.Enums.PipelineStepStatus;
                readonly attempts: number;
                readonly started_at: string | null;
                readonly last_activity_at: string | null;
                readonly completed_at: string | null;
                readonly error_code: string | null;
                readonly error_message: string | null;
            };
            export type PipelineSubjectData = {
                readonly type: string;
                readonly id: number;
                readonly label: string | null;
                readonly context: string | null;
                readonly href: string | null;
            };
            export type PipelineUpdatedPayloadData = {
                readonly pipeline_id: number;
                readonly type: string;
                readonly status: string;
                readonly subject_type: string;
                readonly subject_id: number;
                readonly dossier_id: number | null;
            };
        }
        namespace Enums {
            export type PipelinePermission =
                | "pipelines.view"
                | "pipelines.retry"
                | "pipelines.cancel"
                | "pipelines.delete";
            export type PipelineStatus =
                | "pending"
                | "running"
                | "completed"
                | "failed"
                | "stuck"
                | "cancelled";
            export type PipelineStepKey =
                | "submit"
                | "provider_processing"
                | "process_results"
                | "transcribe"
                | "build_archive"
                | "store_archive";
            export type PipelineStepStatus =
                | "pending"
                | "running"
                | "completed"
                | "failed"
                | "stuck"
                | "skipped";
            export type PipelineType =
                "ocr_document" | "dossier_export" | "audio_transcription";
        }
    }
    namespace Platform {
        namespace Data {
            export type DataSubjectRequestData = {
                readonly id: number;
                readonly type: string;
                readonly type_label: string;
                readonly status: string;
                readonly status_label: string;
                readonly client_id: number;
                readonly client_name: string;
                readonly requested_at: string;
                readonly due_at: string;
                readonly is_overdue: boolean;
                readonly requested_by_name: string | null;
                readonly completed_at: string | null;
                readonly completed_by_name: string | null;
                readonly refusal_reason: string | null;
                readonly can_download: boolean;
            };
        }
        namespace Enums {
            export type DataSubjectPermission =
                "data_subject.view" | "data_subject.manage";
            export type DataSubjectRequestStatus =
                "pending" | "completed" | "refused";
            export type DataSubjectRequestType = "export" | "erasure";
            export type LegalDocument = "privacy" | "cookies" | "terms";
        }
    }
    namespace Questionnaires {
        namespace Data {
            export type QuestionnaireTemplateItemData = {
                readonly id: number;
                readonly type: App.Questionnaires.Enums.QuestionnaireItemType;
                readonly title: string;
                readonly instructions: string | null;
                readonly sort_order: number;
            };
            export type QuestionnaireTemplateItemOptionData = {
                readonly type: App.Questionnaires.Enums.QuestionnaireItemType;
                readonly field_key: string;
                readonly title: string;
                readonly instructions: string | null;
                readonly configuration: Record<string, any> | null;
                readonly sort_order: number;
            };
            export type QuestionnaireTemplateOptionData = {
                readonly id: number;
                readonly name: string;
                readonly category_label: string;
                readonly items_count: number;
                readonly is_system: boolean;
                readonly items: App.Questionnaires.Data.QuestionnaireTemplateItemOptionData[];
            };
            export type QuestionnaireTemplateRowData = {
                readonly id: number;
                readonly name: string;
                readonly description: string | null;
                readonly category: App.Questionnaires.Enums.QuestionnaireTemplateCategory;
                readonly category_label: string;
                readonly items_count: number;
                readonly is_system: boolean;
            };
            export type QuestionnaireTemplateShowData = {
                readonly id: number;
                readonly name: string;
                readonly description: string | null;
                readonly category: App.Questionnaires.Enums.QuestionnaireTemplateCategory;
                readonly category_label: string;
                readonly is_system: boolean;
                readonly items: App.Questionnaires.Data.QuestionnaireTemplateItemData[];
            };
            export type QuestionnaireTemplatesPageData = {
                readonly system_templates: LaravelPaginated<
                    number,
                    {
                        id: number;
                        name: string;
                        description: string | null;
                        category: string;
                        category_label: string;
                        items_count: number;
                        is_system: boolean;
                    }
                >;
                readonly firm_templates: LaravelPaginated<
                    number,
                    {
                        id: number;
                        name: string;
                        description: string | null;
                        category: string;
                        category_label: string;
                        items_count: number;
                        is_system: boolean;
                    }
                >;
            };
        }
        namespace Enums {
            export type QuestionnaireItemType =
                | "file"
                | "text"
                | "textarea"
                | "date"
                | "number"
                | "boolean"
                | "choice"
                | "multi_select";
            export type QuestionnaireTemplateCategory =
                "kyc" | "jaarrekening" | "fiscale" | "pbc" | "custom";
            export type QuestionnaireTemplatePermission =
                | "templates.view"
                | "templates.create"
                | "templates.update"
                | "templates.delete"
                | "templates.copy"
                | "templates.apply";
        }
    }
    namespace Reporting {
        namespace Data {
            export type ActivityLogAttributeChangesData = {
                readonly attributes: Record<string, any>;
                readonly old: Record<string, any>;
            };
            export type ActivityLogPropertiesData = {
                readonly ip: string | null;
                readonly route: string | null;
            };
            export type ActivityLogRowData = {
                readonly id: number;
                readonly event: string | null;
                readonly label: string;
                readonly description: string;
                readonly causer_name: string | null;
                readonly subject: App.Reporting.Data.ActivityLogSubjectData | null;
                readonly created_at: string | null;
                readonly properties: App.Reporting.Data.ActivityLogPropertiesData;
                readonly attribute_changes: App.Reporting.Data.ActivityLogAttributeChangesData | null;
            };
            export type ActivityLogSubjectData = {
                readonly type: string;
                readonly id: number;
                readonly name: string | null;
            };
            export type DashboardAgendaItemData = {
                readonly id: number;
                readonly title: string;
                readonly client_name: string;
                readonly status: App.Dossiers.Enums.DossierStatus;
                readonly due_date: string;
                readonly days_remaining: number;
            };
            export type DashboardFollowUpItemData = {
                readonly id: number;
                readonly title: string;
                readonly client_name: string;
                readonly since: string | null;
                readonly days: number | null;
            };
            export type DashboardFollowUpSignalsData = {
                readonly never_opened: App.Reporting.Data.DashboardFollowUpItemData[];
                readonly gone_quiet: App.Reporting.Data.DashboardFollowUpItemData[];
                readonly expiring_links: App.Reporting.Data.DashboardFollowUpItemData[];
                readonly total_count: number;
            };
            export type DashboardMetricsData = {
                readonly clients: number;
                readonly open_dossiers: number;
                readonly needs_review: number;
                readonly awaiting_client: number;
                readonly overdue: number;
                readonly in_review: number;
                readonly submitted_document_requests: number;
                readonly outstanding_document_requests: number;
                readonly open_dossiers_this_week: number;
                readonly open_dossiers_last_week: number;
                readonly needs_review_this_week: number;
                readonly needs_review_last_week: number;
                readonly awaiting_client_this_week: number;
                readonly awaiting_client_last_week: number;
                readonly weekly_throughput: App.Reporting.Data.DashboardTrendPointData[];
            };
            export type DashboardMyWorkData = {
                readonly assigned_open: number;
                readonly due_this_week: number;
                readonly overdue: number;
                readonly awaiting_my_review: number;
                readonly agenda: App.Reporting.Data.DashboardAgendaItemData[];
            };
            export type DashboardPipelineFailureData = {
                readonly id: number;
                readonly type: App.Pipelines.Enums.PipelineType;
                readonly status: App.Pipelines.Enums.PipelineStatus;
                readonly error_message: string | null;
                readonly last_activity_at: string | null;
                readonly is_retryable: boolean;
            };
            export type DashboardProcessingHealthData = {
                readonly failed: number;
                readonly stuck: number;
                readonly recent: App.Reporting.Data.DashboardPipelineFailureData[];
            };
            export type DashboardRecentDossierData = {
                readonly id: number;
                readonly title: string;
                readonly reference: string | null;
                readonly status: App.Dossiers.Enums.DossierStatus;
                readonly client_name: string;
                readonly due_date: string | null;
                readonly responsible_name: string | null;
                readonly updated_at: string;
            };
            export type DashboardTrendPointData = {
                readonly week_start: string;
                readonly documents_received: number;
            };
            export type MediaLibraryDocumentRowData = {
                readonly id: number;
                readonly title: string;
                readonly status: App.Dossiers.Enums.DocumentRequestStatus;
                readonly updated_at: string | null;
                readonly dossier: App.Reporting.Data.MediaLibraryDossierSummaryData;
                readonly client_name: string;
                readonly uploaded_documents: App.Reporting.Data.MediaLibraryUploadedDocumentData[];
            };
            export type MediaLibraryDossierSummaryData = {
                readonly id: number;
                readonly title: string;
                readonly reference: string | null;
            };
            export type MediaLibraryUploadedDocumentData = {
                readonly id: number;
                readonly original_filename: string;
                readonly size_bytes: number;
                readonly uploaded_at: string;
            };
        }
    }
    namespace Shared {
        namespace Data {
            export type PersonOptionData = {
                readonly id: number;
                readonly name: string;
                readonly email: string;
            };
        }
    }
    namespace Tenancy {
        namespace Data {
            export type TenantAiSettingsData = {
                readonly document_overview: boolean;
                readonly checklist_draft: boolean;
                readonly reminder_draft: boolean;
            };
            export type TenantSettingsData = {
                readonly ai: App.Tenancy.Data.TenantAiSettingsData;
            };
            export type WorkspaceInvitationData = {
                readonly id: number;
                readonly email: string;
                readonly role: string;
                readonly role_label: string;
                readonly invited_at: string;
                readonly expires_at: string;
            };
            export type WorkspaceMemberData = {
                readonly id: number;
                readonly user_id: number;
                readonly name: string;
                readonly email: string;
                readonly status: App.Tenancy.Enums.TenantMembershipStatus;
                readonly role: string | null;
                readonly role_label: string | null;
                readonly joined_at: string | null;
                readonly last_accessed_at: string | null;
                readonly is_current_user: boolean;
            };
            export type WorkspaceMembersPageData = {
                readonly members: App.Tenancy.Data.WorkspaceMemberData[];
                readonly invitations: App.Tenancy.Data.WorkspaceInvitationData[];
                readonly role_options: App.Tenancy.Data.WorkspaceRoleOptionData[];
                readonly can_assign_owner: boolean;
                readonly role_filter: string | null;
            };
            export type WorkspaceNavItemData = {
                readonly name: string;
                readonly slug: string;
            };
            export type WorkspacePermissionData = {
                readonly value: string;
                readonly label: string;
                readonly description: string;
                readonly granted_to: string[];
            };
            export type WorkspacePermissionGroupData = {
                readonly value: string;
                readonly label: string;
                readonly permissions: App.Tenancy.Data.WorkspacePermissionData[];
            };
            export type WorkspaceRoleData = {
                readonly id: number;
                readonly value: string;
                readonly label: string;
                readonly is_system: boolean;
                readonly member_count: number;
            };
            export type WorkspaceRoleOptionData = {
                readonly value: string;
                readonly label: string;
            };
            export type WorkspaceRolesPageData = {
                readonly roles: App.Tenancy.Data.WorkspaceRoleData[];
                readonly permission_groups: App.Tenancy.Data.WorkspacePermissionGroupData[];
                readonly can_manage_roles: boolean;
            };
            export type WorkspaceSettingsPageData = {
                readonly settings: App.Tenancy.Data.TenantSettingsData;
            };
        }
        namespace Enums {
            export type PermissionGroup =
                | "dossiers"
                | "document_requests"
                | "documents"
                | "audio_recordings"
                | "clients"
                | "client_portal"
                | "templates"
                | "processing"
                | "members"
                | "audit"
                | "data_subject_requests"
                | "settings";
            export type Role =
                | "owner"
                | "administrator"
                | "adviser"
                | "reviewer"
                | "read-only";
            export type TenantAiFeature =
                "document_overview" | "checklist_draft" | "reminder_draft";
            export type TenantMembershipStatus =
                "invited" | "active" | "suspended";
            export type WorkspacePermission =
                | "members.view"
                | "members.invite"
                | "members.suspend"
                | "members.remove"
                | "members.assign_role"
                | "roles.view"
                | "roles.manage"
                | "tenant.settings.manage"
                | "tenant.admin.access";
        }
    }
    namespace WorkspaceNotifications {
        namespace Data {
            export type WorkspaceNotificationItemData = {
                readonly id: string;
                readonly type: string;
                readonly message: string;
                readonly url: string;
                readonly read_at: string | null;
                readonly created_at: string;
                readonly dossier_id: number | null;
                readonly dossier_title: string | null;
                readonly client_name: string | null;
                readonly audio_recording_id: number | null;
                readonly original_filename: string | null;
            };
            export type WorkspaceNotificationsData = {
                readonly unread_count: number;
                readonly items: App.WorkspaceNotifications.Data.WorkspaceNotificationItemData[];
            };
        }
    }
}
