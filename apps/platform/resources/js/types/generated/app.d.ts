declare namespace App {
    namespace Accounts {
        namespace Enums {
            export type Locale = 'en' | 'nl';
            export type PlatformRole = 'support';
        }
    }
    namespace AudioProcessing {
        namespace Enums {
            export type TranscriptionDriver = 'mock' | 'ai';
        }
    }
    namespace Audit {
        namespace Enums {
            export type AuditEvent =
                | 'dossier.viewed'
                | 'document_request.created'
                | 'document_request.accepted'
                | 'document_request.rejected'
                | 'document.uploaded'
                | 'questionnaire.answer_submitted'
                | 'client_portal.questionnaire_completed'
                | 'dossier.completed'
                | 'document.viewed'
                | 'document.downloaded'
                | 'dossier.export_requested'
                | 'dossier.export_completed'
                | 'dossier.export_failed'
                | 'dossier.export_downloaded'
                | 'document.processing_started'
                | 'document.processing_completed'
                | 'document.processing_failed'
                | 'client_portal.access_grant_created'
                | 'client_portal.access_grant_revoked'
                | 'client_portal.accessed'
                | 'client_portal.invite_queued'
                | 'client_portal.invite_sent'
                | 'client_portal.invite_failed'
                | 'dossier.reminder_queued'
                | 'dossier.reminder_sent'
                | 'dossier.reminder_failed'
                | 'client_portal.changes_requested_queued'
                | 'client_portal.changes_requested_sent'
                | 'client_portal.changes_requested_failed'
                | 'access.denied'
                | 'member.invited'
                | 'member.invitation_accepted'
                | 'member.invitation_revoked'
                | 'member.role_changed'
                | 'role.created'
                | 'role.updated'
                | 'role.deleted'
                | 'member.suspended'
                | 'member.removed'
                | 'dossier.deleted'
                | 'client.deleted'
                | 'dossier.restored'
                | 'client.restored'
                | 'document_request.deleted'
                | 'questionnaire_template.deleted'
                | 'questionnaire_template_item.deleted'
                | 'pipeline.deleted'
                | 'user.deleted'
                | 'support.session_started'
                | 'support.session_ended'
                | 'support.session_expired'
                | 'audio_recording.uploaded'
                | 'audio_recording.downloaded'
                | 'audio_recording.transcription_started'
                | 'audio_recording.transcription_completed'
                | 'audio_recording.transcription_failed';
            export type AuditPermission = 'audit.view';
        }
    }
    namespace ClientPortal {
        namespace Data {
            export type ClientPortalPageData = {
                readonly firm: App.ClientPortal.Data.PortalFirmData;
                readonly dossier: App.ClientPortal.Data.PortalDossierData;
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
                readonly uploaded_document: App.ClientPortal.Data.PortalUploadedDocumentData | null;
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
            export type PortalUploadedDocumentData = {
                readonly original_filename: string;
                readonly size_bytes: number;
                readonly uploaded_at: string;
            };
        }
        namespace Enums {
            export type ClientAccessGrantPermission =
                'client_access.grant' | 'client_access.revoke';
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
                | 'clients.view'
                | 'clients.create'
                | 'clients.update'
                | 'clients.delete'
                | 'clients.restore';
        }
    }
    namespace DocumentProcessing {
        namespace Data {
            export type PipelineData = {
                readonly id: number;
                readonly type: App.DocumentProcessing.Enums.PipelineType;
                readonly status: App.DocumentProcessing.Enums.PipelineStatus;
                readonly current_stage: string | null;
                readonly attempt_number: number;
                readonly last_activity_at: string | null;
                readonly steps: App.DocumentProcessing.Data.PipelineStepData[];
            };
            export type PipelineIndexRowData = {
                readonly id: number;
                readonly type: App.DocumentProcessing.Enums.PipelineType;
                readonly status: App.DocumentProcessing.Enums.PipelineStatus;
                readonly current_stage: string | null;
                readonly attempt_number: number;
                readonly last_activity_at: string | null;
                readonly created_at: string;
                readonly subject_type: string;
                readonly subject_id: number;
            };
            export type PipelineShowData = {
                readonly id: number;
                readonly type: App.DocumentProcessing.Enums.PipelineType;
                readonly status: App.DocumentProcessing.Enums.PipelineStatus;
                readonly current_stage: string | null;
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
                readonly steps: App.DocumentProcessing.Data.PipelineStepData[];
                readonly subject: App.DocumentProcessing.Data.PipelineSubjectData;
            };
            export type PipelineStepData = {
                readonly key: string;
                readonly status: App.DocumentProcessing.Enums.PipelineStepStatus;
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
            export type DocumentProcessingStatus =
                'pending' | 'processing' | 'completed' | 'failed';
            export type ExtractionFieldSensitivity =
                | 'bsn'
                | 'iban'
                | 'id_number'
                | 'account_number'
                | 'date_of_birth'
                | 'email'
                | 'phone';
            export type OcrDriver = 'mock' | 'textract';
            export type OcrProcessingOutcome = 'immediate' | 'deferred';
            export type PipelinePermission =
                'pipelines.view' | 'pipelines.delete';
            export type PipelineStatus =
                | 'pending'
                | 'running'
                | 'completed'
                | 'failed'
                | 'stuck'
                | 'cancelled';
            export type PipelineStepOperation =
                'start' | 'complete' | 'fail' | 'skip' | 'stuck' | 'heartbeat';
            export type PipelineStepStatus =
                | 'pending'
                | 'running'
                | 'completed'
                | 'failed'
                | 'stuck'
                | 'skipped';
            export type PipelineType =
                'ocr_document' | 'dossier_export' | 'audio_transcription';
            export type TranscriptionStatus =
                'pending' | 'processing' | 'completed' | 'failed';
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
                readonly pipeline: App.DocumentProcessing.Data.PipelineData | null;
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
                readonly has_outstanding_client_items: boolean;
                readonly ready_to_complete: boolean;
                readonly review_summary: App.Dossiers.Data.DossierReviewSummaryData;
                readonly client: App.Dossiers.Data.DossierClientSummaryData;
                readonly document_requests: App.Dossiers.Data.StaffDocumentRequestData[];
                readonly access_grants: App.Dossiers.Data.DossierAccessGrantData[];
                readonly exports: App.Dossiers.Data.DossierExportData[];
                readonly audio_recordings: App.Dossiers.Data.StaffAudioRecordingData[];
            };
            export type StaffAudioRecordingData = {
                readonly id: number;
                readonly original_filename: string;
                readonly size_bytes: number;
                readonly uploaded_at: string;
                readonly transcription_status: App.DocumentProcessing.Enums.TranscriptionStatus;
                readonly transcription_error: string | null;
                readonly pipeline: App.DocumentProcessing.Data.PipelineData | null;
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
                readonly uploaded_document: App.Dossiers.Data.StaffUploadedDocumentData | null;
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
                readonly pipeline: App.DocumentProcessing.Data.PipelineData | null;
            };
        }
        namespace Enums {
            export type AudioRecordingPermission =
                | 'audio_recordings.view'
                | 'audio_recordings.upload'
                | 'audio_recordings.download'
                | 'audio_recordings.delete';
            export type DocumentRequestPermission =
                | 'document_requests.view'
                | 'document_requests.create'
                | 'document_requests.update'
                | 'document_requests.delete'
                | 'document_requests.upload'
                | 'document_requests.review';
            export type DocumentRequestStatus =
                'pending' | 'submitted' | 'accepted' | 'rejected';
            export type DossierExportStatus =
                'pending' | 'processing' | 'completed' | 'failed';
            export type DossierPermission =
                | 'dossiers.view'
                | 'dossiers.create'
                | 'dossiers.update'
                | 'dossiers.delete'
                | 'dossiers.restore'
                | 'dossiers.complete'
                | 'dossiers.export'
                | 'dossiers.send_reminder';
            export type DossierReminderSource = 'manual' | 'scheduled';
            export type DossierStatus =
                'draft' | 'awaiting_client' | 'in_review' | 'completed';
            export type SubmissionContext = 'portal' | 'staff';
            export type UploadedDocumentPermission =
                'documents.view' | 'documents.download';
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
                | 'file'
                | 'text'
                | 'textarea'
                | 'date'
                | 'number'
                | 'boolean'
                | 'choice'
                | 'multi_select';
            export type QuestionnaireTemplateCategory =
                'kyc' | 'jaarrekening' | 'fiscale' | 'pbc' | 'custom';
            export type QuestionnaireTemplatePermission =
                | 'templates.view'
                | 'templates.create'
                | 'templates.update'
                | 'templates.delete'
                | 'templates.copy'
                | 'templates.apply';
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
            export type MediaLibraryDocumentRowData = {
                readonly id: number;
                readonly title: string;
                readonly status: App.Dossiers.Enums.DocumentRequestStatus;
                readonly updated_at: string | null;
                readonly dossier: App.Reporting.Data.MediaLibraryDossierSummaryData;
                readonly client_name: string;
                readonly uploaded_document: App.Reporting.Data.MediaLibraryUploadedDocumentData | null;
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
            export type WorkspaceDashboardPageData = {
                readonly metrics: App.Reporting.Data.DashboardMetricsData;
                readonly recent_dossiers: App.Reporting.Data.DashboardRecentDossierData[];
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
        }
        namespace Enums {
            export type PermissionGroup =
                | 'dossiers'
                | 'document_requests'
                | 'documents'
                | 'audio_recordings'
                | 'clients'
                | 'client_portal'
                | 'templates'
                | 'processing'
                | 'members'
                | 'audit'
                | 'settings';
            export type Role =
                | 'owner'
                | 'administrator'
                | 'adviser'
                | 'reviewer'
                | 'read-only';
            export type TenantMembershipStatus =
                'invited' | 'active' | 'suspended';
            export type WorkspacePermission =
                | 'members.view'
                | 'members.invite'
                | 'members.suspend'
                | 'members.remove'
                | 'members.assign_role'
                | 'roles.view'
                | 'roles.manage'
                | 'tenant.settings.manage';
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
