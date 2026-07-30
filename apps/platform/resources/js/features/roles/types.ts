export type WorkspaceRole = {
    id: number;
    value: string;
    label: string;
    /** True for the presets Qompose defines; false for firm-authored roles. */
    is_system: boolean;
    member_count: number;
};

export type WorkspacePermission = {
    value: string;
    label: string;
    description: string;
    /** Names of the roles that currently hold this permission. */
    granted_to: string[];
};

export type WorkspacePermissionGroup = {
    value: string;
    label: string;
    permissions: WorkspacePermission[];
};
