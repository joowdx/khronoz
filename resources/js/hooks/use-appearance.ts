import { useCallback, useEffect, useState } from 'react';
import type { Appearance } from '@/types';

/**
 * The three choices a user can make. `system` is not a colour — it defers to
 * the OS, and keeps deferring as the OS changes.
 */
const STORAGE_KEY = 'appearance';

function isAppearance(value: unknown): value is Appearance {
    return value === 'light' || value === 'dark' || value === 'system';
}

/** Reading localStorage throws in some private-browsing modes, never just returns null. */
function storedAppearance(): Appearance {
    if (typeof window === 'undefined') {
        return 'system';
    }

    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);

        return isAppearance(stored) ? stored : 'system';
    } catch {
        return 'system';
    }
}

function prefersDark(): boolean {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function resolve(appearance: Appearance): 'light' | 'dark' {
    return appearance === 'system' ? (prefersDark() ? 'dark' : 'light') : appearance;
}

/**
 * Stamp the resolved mode on <html>. app.blade.php runs the same logic
 * synchronously in <head>, so this only ever confirms or changes what is
 * already painted.
 */
function stamp(appearance: Appearance): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.mode = resolve(appearance);
}

/**
 * Read, write and apply the colour mode.
 *
 * The user-facing control lives in the sidebar user menu; this hook is the
 * whole of the behaviour behind it, so that control is a segmented group over
 * `appearance` calling `setAppearance`.
 */
export function useAppearance(): {
    appearance: Appearance;
    /** What `appearance` currently resolves to — what is actually on screen. */
    resolved: 'light' | 'dark';
    setAppearance: (next: Appearance) => void;
} {
    const [appearance, setState] = useState<Appearance>(storedAppearance);
    const [resolved, setResolved] = useState<'light' | 'dark'>(() => resolve(storedAppearance()));

    const setAppearance = useCallback((next: Appearance) => {
        setState(next);
        setResolved(resolve(next));
        stamp(next);

        try {
            window.localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // A choice that cannot be persisted still applies to this tab.
        }
    }, []);

    // `system` has to keep following the OS, not just sample it once.
    useEffect(() => {
        stamp(appearance);

        if (appearance !== 'system') {
            return;
        }

        const query = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = (): void => {
            stamp('system');
            setResolved(resolve('system'));
        };

        query.addEventListener('change', onChange);

        return () => query.removeEventListener('change', onChange);
    }, [appearance]);

    return { appearance, resolved, setAppearance };
}
