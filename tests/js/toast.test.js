import { describe, expect, it, vi } from 'vitest';
import { showToast } from '../../resources/js/admin/toast.js';
import { mount } from './helpers.js';

describe('showToast', () => {
    it('zeigt den Text und blendet nach der eingestellten Dauer aus', () => {
        vi.useFakeTimers();
        window.SwayyAdmin.ui.toastMs = 1500;
        mount('');
        const toast = document.getElementById('swayyToast');

        showToast('Gespeichert');
        expect(toast.textContent).toBe('Gespeichert');
        expect(toast.dataset.state).toBe('ok');
        expect(toast.classList.contains('opacity-0')).toBe(false);

        vi.advanceTimersByTime(1499);
        expect(toast.classList.contains('opacity-0')).toBe(false);
        vi.advanceTimersByTime(1);
        expect(toast.classList.contains('opacity-0')).toBe(true);
        expect(toast.classList.contains('pointer-events-none')).toBe(true);
    });

    it('macht den Rückgängig-Knopf beim Ausblenden unerreichbar', () => {
        vi.useFakeTimers();
        mount('');
        const undo = vi.fn();

        showToast('Raum gelöscht', { undo });
        const button = document.querySelector('#swayyToast button');
        expect(button.textContent).toBe('Rückgängig');

        vi.advanceTimersByTime(window.SwayyAdmin.ui.toastMs);
        expect(button.inert).toBe(true);
    });

    it('kennzeichnet Fehler', () => {
        mount('');
        showToast('Keine Verbindung', { error: true });
        expect(document.getElementById('swayyToast').dataset.state).toBe('error');
    });

    it('ruft Rückgängig auf und blendet den Hinweis aus', () => {
        mount('');
        const undo = vi.fn();

        showToast('Raum gelöscht', { undo });
        document.querySelector('#swayyToast button').click();

        expect(undo).toHaveBeenCalledTimes(1);
        expect(document.getElementById('swayyToast').classList.contains('opacity-0')).toBe(true);
    });
});
