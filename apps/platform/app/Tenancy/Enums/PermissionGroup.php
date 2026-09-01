<?php

declare(strict_types=1);

namespace App\Tenancy\Enums;

/**
 * Display grouping for permissions on role management surfaces.
 */
enum PermissionGroup: string
{
    case Dossiers = 'dossiers';
    case DocumentRequests = 'document_requests';
    case Documents = 'documents';
    case AudioRecordings = 'audio_recordings';
    case Clients = 'clients';
    case ClientPortal = 'client_portal';
    case Templates = 'templates';
    case Processing = 'processing';
    case Members = 'members';
    case Audit = 'audit';
    case DataSubjectRequests = 'data_subject_requests';
    case Settings = 'settings';

    public function label(): string
    {
        return match ($this) {
            self::Dossiers => __('Dossiers'),
            self::DocumentRequests => __('Document requests'),
            self::Documents => __('Documents'),
            self::AudioRecordings => __('Audio recordings'),
            self::Clients => __('Clients'),
            self::ClientPortal => __('Client portal'),
            self::Templates => __('Questionnaire templates'),
            self::Processing => __('Document processing'),
            self::Members => __('Members'),
            self::Audit => __('Audit log'),
            self::DataSubjectRequests => __('Privacy requests'),
            self::Settings => __('Firm settings'),
        };
    }

    /**
     * Display order for grouped permission listings.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Dossiers => 10,
            self::DocumentRequests => 20,
            self::Documents => 30,
            self::AudioRecordings => 35,
            self::Clients => 40,
            self::ClientPortal => 50,
            self::Templates => 60,
            self::Processing => 70,
            self::Members => 80,
            self::Audit => 90,
            self::DataSubjectRequests => 95,
            self::Settings => 100,
        };
    }
}
