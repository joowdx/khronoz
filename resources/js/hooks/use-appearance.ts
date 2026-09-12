import { useCallback, useEffect, useState } from 'react';
import type { Appearance } from '@/types';

const STORAGE_KEY = 'appearance';

function isAppearance(value: unknown): value is Appearance {
    return value === 'light' || value === 'dark' || value === 'system';
}

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

function stamp(appearance: Appearance): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.mode = resolve(appearance);
}

export function useAppearance(): {
    appearance: Appearance;
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
        }
    }, []);

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
