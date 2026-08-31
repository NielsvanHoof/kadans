import { afterEach, describe, expect, it } from 'vite-plus/test';
import {
    isTemplateLibraryTab,
    readTemplateLibraryTabFromUrl,
    writeTemplateLibraryTabToUrl,
} from '@/features/questionnaires/list/template-library-tab';

afterEach(() => {
    window.history.replaceState({}, '', '/');
});

describe('isTemplateLibraryTab', () => {
    it('accepts the known tab values', () => {
        expect(isTemplateLibraryTab('system')).toBe(true);
        expect(isTemplateLibraryTab('custom')).toBe(true);
    });

    it('rejects anything else', () => {
        expect(isTemplateLibraryTab('other')).toBe(false);
        expect(isTemplateLibraryTab('')).toBe(false);
    });
});

describe('readTemplateLibraryTabFromUrl', () => {
    it('reads a valid ?tab= value', () => {
        window.history.replaceState({}, '', '/templates?tab=custom');

        expect(readTemplateLibraryTabFromUrl()).toBe('custom');
    });

    it('returns null when ?tab= is missing', () => {
        window.history.replaceState({}, '', '/templates');

        expect(readTemplateLibraryTabFromUrl()).toBeNull();
    });

    it('returns null for an unrecognised ?tab= value', () => {
        window.history.replaceState({}, '', '/templates?tab=bogus');

        expect(readTemplateLibraryTabFromUrl()).toBeNull();
    });
});

describe('writeTemplateLibraryTabToUrl', () => {
    it('sets ?tab= without adding a history entry', () => {
        window.history.replaceState({}, '', '/templates?tab=system');

        writeTemplateLibraryTabToUrl('custom');

        expect(window.location.search).toBe('?tab=custom');
    });

    it('preserves the rest of the path', () => {
        window.history.replaceState({}, '', '/workspaces/acme/templates');

        writeTemplateLibraryTabToUrl('system');

        expect(window.location.pathname).toBe('/workspaces/acme/templates');
        expect(window.location.search).toBe('?tab=system');
    });
});
