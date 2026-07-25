<?php

declare(strict_types=1);

namespace App\Queries\Reporting;

use App\Enums\AuditEvent;
use App\Enums\TenantMembershipStatus;
use App\Models\Activity;
use App\Models\ClientAccessGrant;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Queries\Filters\ScoutSearchFilter;
use App\Queries\PaginatedIndexQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Throwable;

use function is_string;

/**
 * @extends PaginatedIndexQuery<Activity>
 */
final class FetchActivityLogIndexQuery extends PaginatedIndexQuery
{
    private Tenant $tenant;

    public function __construct(
        private readonly ActivityLogRowMapper $rowMapper,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     event: string|null,
     *     label: string,
     *     description: string,
     *     causer_name: string|null,
     *     subject: array{type: string, id: int, name: string|null}|null,
     *     created_at: string|null,
     *     properties: array{ip: string|null, route: string|null},
     *     attribute_changes: array{attributes: array<string, mixed>, old: array<string, mixed>}|null
     * }>
     */
    public function handle(Tenant $tenant): LengthAwarePaginator
    {
        $this->tenant = $tenant;

        /** @var LengthAwarePaginator<int, array{id: int, event: string|null, label: string, description: string, causer_name: string|null, subject: array{type: string, id: int, name: string|null}|null, created_at: string|null, properties: array{ip: string|null, route: string|null}, attribute_changes: array{attributes: array<string, mixed>, old: array<string, mixed>}|null}> */
        return $this->paginate();
    }

    /**
     * @return array{
     *     filters: list<array<string, mixed>>,
     *     sorts: list<array{key: string, label: string}>,
     *     defaults: array{sort: string, per_page: int}
     * }
     */
    public function toolbarMetadata(): array
    {
        return [
            'filters' => [
                ['key' => 'description', 'type' => 'search', 'label' => __('Search')],
                [
                    'key' => 'event',
                    'type' => 'select',
                    'label' => __('Event'),
                    'options' => collect(AuditEvent::cases())
                        ->map(fn (AuditEvent $event): array => [
                            'value' => $event->value,
                            'label' => $event->label(),
                        ])
                        ->values()
                        ->all(),
                ],
                [
                    'key' => 'causer_id',
                    'type' => 'select',
                    'label' => __('Actor'),
                    'options' => $this->actorFilterOptions(),
                ],
                [
                    'key' => 'created_from',
                    'type' => 'search',
                    'label' => __('From date'),
                ],
                [
                    'key' => 'created_to',
                    'type' => 'search',
                    'label' => __('To date'),
                ],
            ],
            'sorts' => [
                ['key' => '-created_at', 'label' => __('Newest first')],
                ['key' => 'created_at', 'label' => __('Oldest first')],
                ['key' => 'event', 'label' => __('Event (A–Z)')],
                ['key' => '-event', 'label' => __('Event (Z–A)')],
            ],
            'defaults' => [
                'sort' => '-created_at',
                'per_page' => 15,
            ],
        ];
    }

    /**
     * @return Builder<Activity>
     */
    protected function subject(): Builder
    {
        return Activity::query()
            ->where('tenant_id', $this->tenant->id)
            ->with([
                'causer',
                'subject' => function ($morphTo): void {
                    if (! $morphTo instanceof MorphTo) {
                        return;
                    }

                    $morphTo->morphWith([
                        ClientAccessGrant::class => ['dossier:id,title'],
                    ]);
                },
            ]);
    }

    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::exact('event'),
            AllowedFilter::exact('causer_id'),
            ScoutSearchFilter::make(Activity::class, 'description'),
            AllowedFilter::callback('created_from', function (Builder $query, mixed $value): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                try {
                    $date = Carbon::parse($value)->startOfDay();
                } catch (Throwable) {
                    return;
                }

                $query->where('created_at', '>=', $date);
            }),
            AllowedFilter::callback('created_to', function (Builder $query, mixed $value): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                try {
                    $date = Carbon::parse($value)->endOfDay();
                } catch (Throwable) {
                    return;
                }

                $query->where('created_at', '<=', $date);
            }),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('created_at'),
            AllowedSort::field('event'),
        ];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    /**
     * @return array{
     *     id: int,
     *     event: string|null,
     *     label: string,
     *     description: string,
     *     causer_name: string|null,
     *     subject: array{type: string, id: int, name: string|null}|null,
     *     created_at: string|null,
     *     properties: array{ip: string|null, route: string|null},
     *     attribute_changes: array{attributes: array<string, mixed>, old: array<string, mixed>}|null
     * }
     */
    protected function mapModel(Model $model): array
    {
        /** @var Activity $model */
        return $this->rowMapper->map($model)->toArray();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function actorFilterOptions(): array
    {
        if (! isset($this->tenant)) {
            return [];
        }

        /** @var list<array{value: string, label: string}> $options */
        $options = [];

        $memberships = TenantMembership::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('status', TenantMembershipStatus::Active)
            ->with('user:id,name')
            ->get();

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if (! $user instanceof User) {
                continue;
            }

            $options[] = [
                'value' => (string) $user->id,
                'label' => $user->name,
            ];
        }

        // Unique by user id, then sort labels for the filter dropdown.
        $uniqueByValue = [];

        foreach ($options as $option) {
            $uniqueByValue[$option['value']] = $option;
        }

        $options = array_values($uniqueByValue);

        usort(
            $options,
            static fn (array $left, array $right): int => strcmp($left['label'], $right['label']),
        );

        return $options;
    }
}
