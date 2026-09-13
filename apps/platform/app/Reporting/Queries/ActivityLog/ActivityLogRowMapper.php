<?php

declare(strict_types=1);

namespace App\Reporting\Queries\ActivityLog;

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Reporting\Data\ActivityLog\ActivityLogAttributeChangesData;
use App\Reporting\Data\ActivityLog\ActivityLogPropertiesData;
use App\Reporting\Data\ActivityLog\ActivityLogRowData;
use App\Reporting\Data\ActivityLog\ActivityLogSubjectData;
use App\Shared\Database\MorphType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Maps an Activity model to the Inertia activity-log row DTO.
 */
final class ActivityLogRowMapper
{
    public function map(Activity $activity): ActivityLogRowData
    {
        $event = is_string($activity->event) ? $activity->event : null;
        $auditEvent = $event !== null ? AuditEvent::tryFrom($event) : null;

        return new ActivityLogRowData(
            id: $activity->id,
            event: $event,
            label: $auditEvent?->label() ?? $activity->description,
            description: $activity->description,
            causerName: $this->resolveCauserName($activity),
            subject: $this->resolveSubject($activity),
            createdAt: $activity->created_at?->toIso8601String(),
            properties: $this->displayProperties($activity),
            attributeChanges: $this->displayAttributeChanges($activity),
        );
    }

    private function resolveCauserName(Activity $activity): ?string
    {
        $causer = $activity->causer;

        if ($causer instanceof User) {
            return $causer->name;
        }

        return null;
    }

    private function resolveSubject(Activity $activity): ?ActivityLogSubjectData
    {
        if ($activity->subject_type === null || $activity->subject_id === null) {
            return null;
        }

        $subject = $activity->subject;
        $type = MorphType::classBasename($activity->subject_type);
        $name = null;

        if ($subject instanceof ClientAccessGrant) {
            $name = $subject->dossier?->title;
        } elseif ($subject instanceof Client) {
            $name = $subject->name;
        } elseif ($subject instanceof Dossier) {
            $name = $subject->title;
        } elseif ($subject instanceof Model) {
            $name = $this->resolveSubjectName($subject);
        }

        return new ActivityLogSubjectData(
            type: $type,
            id: $activity->subject_id,
            name: $name,
        );
    }

    private function resolveSubjectName(Model $subject): ?string
    {
        $attributes = $subject->getAttributes();

        if (array_key_exists('title', $attributes) && is_string($attributes['title'])) {
            return $attributes['title'];
        }

        if (array_key_exists('name', $attributes) && is_string($attributes['name'])) {
            return $attributes['name'];
        }

        if (array_key_exists('original_filename', $attributes) && is_string($attributes['original_filename'])) {
            return $attributes['original_filename'];
        }

        return null;
    }

    private function displayProperties(Activity $activity): ActivityLogPropertiesData
    {
        $ip = $activity->getProperty('ip');
        $route = $activity->getProperty('route');

        return new ActivityLogPropertiesData(
            ip: is_string($ip) ? $ip : null,
            route: is_string($route) ? $route : null,
        );
    }

    private function displayAttributeChanges(Activity $activity): ?ActivityLogAttributeChangesData
    {
        $changes = $activity->attribute_changes;

        if ($changes === null || $changes->isEmpty()) {
            return null;
        }

        $attributes = $changes->get('attributes', []);
        $old = $changes->get('old', []);

        if (! is_array($attributes) && ! $attributes instanceof Collection) {
            $attributes = [];
        }

        if (! is_array($old) && ! $old instanceof Collection) {
            $old = [];
        }

        $attributesArray = $attributes instanceof Collection ? $attributes->all() : $attributes;
        $oldArray = $old instanceof Collection ? $old->all() : $old;

        if ($attributesArray === [] && $oldArray === []) {
            return null;
        }

        return new ActivityLogAttributeChangesData(
            attributes: $attributesArray,
            old: $oldArray,
        );
    }
}
