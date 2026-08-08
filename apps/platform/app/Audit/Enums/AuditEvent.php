<?php

declare(strict_types=1);

namespace App\Audit\Enums;

enum AuditEvent: string
{
    case DossierViewed = 'dossier.viewed';
    case DocumentRequestCreated = 'document_request.created';
    case DocumentRequestAccepted = 'document_request.accepted';
    case DocumentRequestRejected = 'document_request.rejected';
    case DocumentUploaded = 'document.uploaded';
    case QuestionnaireAnswerSubmitted = 'questionnaire.answer_submitted';
    case ClientQuestionnaireCompleted = 'client_portal.questionnaire_completed';
    case DossierCompleted = 'dossier.completed';
    case DocumentViewed = 'document.viewed';
    case DocumentDownloaded = 'document.downloaded';
    case DossierExportRequested = 'dossier.export_requested';
    case DossierExportCompleted = 'dossier.export_completed';
    case DossierExportFailed = 'dossier.export_failed';
    case DossierExportDownloaded = 'dossier.export_downloaded';
    case DocumentProcessingStarted = 'document.processing_started';
    case DocumentProcessingCompleted = 'document.processing_completed';
    case DocumentProcessingFailed = 'document.processing_failed';
    case ClientPortalAccessGrantCreated = 'client_portal.access_grant_created';
    case ClientPortalAccessGrantRevoked = 'client_portal.access_grant_revoked';
    case ClientPortalAccessed = 'client_portal.accessed';
    case ClientPortalInviteQueued = 'client_portal.invite_queued';
    case ClientPortalInviteSent = 'client_portal.invite_sent';
    case ClientPortalInviteFailed = 'client_portal.invite_failed';
    case DossierReminderQueued = 'dossier.reminder_queued';
    case DossierReminderSent = 'dossier.reminder_sent';
    case DossierReminderFailed = 'dossier.reminder_failed';
    case ClientChangesRequestedQueued = 'client_portal.changes_requested_queued';
    case ClientChangesRequestedSent = 'client_portal.changes_requested_sent';
    case ClientChangesRequestedFailed = 'client_portal.changes_requested_failed';
    case AccessDenied = 'access.denied';
    case MemberInvited = 'member.invited';
    case MemberInvitationAccepted = 'member.invitation_accepted';
    case MemberInvitationRevoked = 'member.invitation_revoked';
    case MemberRoleChanged = 'member.role_changed';
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';
    case MemberSuspended = 'member.suspended';
    case MemberRemoved = 'member.removed';
    case DossierDeleted = 'dossier.deleted';
    case ClientDeleted = 'client.deleted';
    case DossierRestored = 'dossier.restored';
    case ClientRestored = 'client.restored';
    case DocumentRequestDeleted = 'document_request.deleted';
    case QuestionnaireTemplateDeleted = 'questionnaire_template.deleted';
    case QuestionnaireTemplateItemDeleted = 'questionnaire_template_item.deleted';
    case PipelineRetried = 'pipeline.retried';
    case PipelineCancelled = 'pipeline.cancelled';
    case PipelineDeleted = 'pipeline.deleted';
    case UserDeleted = 'user.deleted';
    case SupportSessionStarted = 'support.session_started';
    case SupportSessionEnded = 'support.session_ended';
    case SupportSessionExpired = 'support.session_expired';
    case AudioRecordingUploaded = 'audio_recording.uploaded';
    case AudioRecordingDownloaded = 'audio_recording.downloaded';
    case AudioTranscriptionStarted = 'audio_recording.transcription_started';
    case AudioTranscriptionCompleted = 'audio_recording.transcription_completed';
    case AudioTranscriptionFailed = 'audio_recording.transcription_failed';

    public function label(): string
    {
        return match ($this) {
            self::DossierViewed => __('Dossier viewed'),
            self::DocumentRequestCreated => __('Document request created'),
            self::DocumentRequestAccepted => __('Document request accepted'),
            self::DocumentRequestRejected => __('Document request rejected'),
            self::DocumentUploaded => __('Document uploaded'),
            self::QuestionnaireAnswerSubmitted => __('Questionnaire answer submitted'),
            self::ClientQuestionnaireCompleted => __('Client questionnaire completed'),
            self::DossierCompleted => __('Dossier completed'),
            self::DocumentViewed => __('Document viewed'),
            self::DocumentDownloaded => __('Document downloaded'),
            self::DossierExportRequested => __('Dossier export requested'),
            self::DossierExportCompleted => __('Dossier export completed'),
            self::DossierExportFailed => __('Dossier export failed'),
            self::DossierExportDownloaded => __('Dossier export downloaded'),
            self::DocumentProcessingStarted => __('Document processing started'),
            self::DocumentProcessingCompleted => __('Document processing completed'),
            self::DocumentProcessingFailed => __('Document processing failed'),
            self::ClientPortalAccessGrantCreated => __('Client portal access grant created'),
            self::ClientPortalAccessGrantRevoked => __('Client portal access grant revoked'),
            self::ClientPortalAccessed => __('Client portal accessed'),
            self::ClientPortalInviteQueued => __('Client portal invitation queued'),
            self::ClientPortalInviteSent => __('Client portal invitation sent'),
            self::ClientPortalInviteFailed => __('Client portal invitation failed'),
            self::DossierReminderQueued => __('Client reminder queued'),
            self::DossierReminderSent => __('Client reminder sent'),
            self::DossierReminderFailed => __('Client reminder failed'),
            self::ClientChangesRequestedQueued => __('Client changes-requested notification queued'),
            self::ClientChangesRequestedSent => __('Client changes-requested notification sent'),
            self::ClientChangesRequestedFailed => __('Client changes-requested notification failed'),
            self::AccessDenied => __('Access denied'),
            self::MemberInvited => __('Member invited'),
            self::MemberInvitationAccepted => __('Member invitation accepted'),
            self::MemberInvitationRevoked => __('Member invitation revoked'),
            self::MemberRoleChanged => __('Member role changed'),
            self::RoleCreated => __('Role created'),
            self::RoleUpdated => __('Role updated'),
            self::RoleDeleted => __('Role deleted'),
            self::MemberSuspended => __('Member suspended'),
            self::MemberRemoved => __('Member removed'),
            self::DossierDeleted => __('Dossier archived'),
            self::ClientDeleted => __('Client archived'),
            self::DossierRestored => __('Dossier restored'),
            self::ClientRestored => __('Client restored'),
            self::DocumentRequestDeleted => __('Document request deleted'),
            self::QuestionnaireTemplateDeleted => __('Questionnaire template deleted'),
            self::QuestionnaireTemplateItemDeleted => __('Questionnaire template item deleted'),
            self::PipelineRetried => __('Pipeline retried'),
            self::PipelineCancelled => __('Pipeline cancelled'),
            self::PipelineDeleted => __('Pipeline deleted'),
            self::UserDeleted => __('User account deleted'),
            self::SupportSessionStarted => __('Support session started'),
            self::SupportSessionEnded => __('Support session ended'),
            self::SupportSessionExpired => __('Support session expired'),
            self::AudioRecordingUploaded => __('Audio recording uploaded'),
            self::AudioRecordingDownloaded => __('Audio recording downloaded'),
            self::AudioTranscriptionStarted => __('Audio transcription started'),
            self::AudioTranscriptionCompleted => __('Audio transcription completed'),
            self::AudioTranscriptionFailed => __('Audio transcription failed'),
        };
    }
}
