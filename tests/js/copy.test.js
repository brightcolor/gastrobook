import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initCopyButtons } from '../../resources/js/admin/copy.js';
import { mount } from './helpers.js';

beforeAll(() => initCopyButtons());

describe('Kopierknopf', () => {
    it('legt den Text in die Zwischenablage und meldet das', async () => {
        const writeText = vi.fn(async () => {});
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        mount('<button type="button" data-copy="https://swayy.test/invitation/abc" data-copy-message="Link kopiert.">Kopieren</button>');

        document.querySelector('[data-copy]').click();

        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Link kopiert.'));
        expect(writeText).toHaveBeenCalledWith('https://swayy.test/invitation/abc');
    });

    it('nennt die Ursache und den Weg von Hand, wenn der Browser das Kopieren verweigert', async () => {
        vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(async () => { throw new Error('denied'); }) } });
        mount('<button type="button" data-copy="abc">Kopieren</button>');

        document.querySelector('[data-copy]').click();

        await vi.waitFor(() => expect(document.getElementById('swayyToast').dataset.state).toBe('error'));
        expect(document.getElementById('swayyToast').textContent).toBe(
            'Der Browser erlaubt den Zugriff auf die Zwischenablage nicht. Bitte den Text markieren und mit Strg+C (Mac: ⌘+C) kopieren.',
        );
    });
});
