import type { InertiaLinkProps } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import { toUrl } from '@/lib/utils';

export type IsCurrentUrlFn = (
    urlToCheck: NonNullable<InertiaLinkProps['href']>,
    currentUrl?: string,
    startsWith?: boolean,
) => boolean;

export type IsCurrentOrParentUrlFn = (
    urlToCheck: NonNullable<InertiaLinkProps['href']>,
    currentUrl?: string,
) => boolean;

// Without ifFalse it returns null; overloads type that without casting null to TIfFalse (P-068).
export type WhenCurrentUrlFn = {
    <TIfTrue>(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: TIfTrue,
    ): TIfTrue | null;
    <TIfTrue, TIfFalse>(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: TIfTrue,
        ifFalse: TIfFalse,
    ): TIfTrue | TIfFalse;
};

export type UseCurrentUrlReturn = {
    currentUrl: string;
    isCurrentUrl: IsCurrentUrlFn;
    isCurrentOrParentUrl: IsCurrentOrParentUrlFn;
    whenCurrentUrl: WhenCurrentUrlFn;
};

export function useCurrentUrl(): UseCurrentUrlReturn {
    const page = usePage();
    const currentUrlPath = new URL(
        page.url,
        typeof window !== 'undefined'
            ? window.location.origin
            : 'http://localhost',
    ).pathname;

    const isCurrentUrl: IsCurrentUrlFn = (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
        startsWith: boolean = false,
    ) => {
        const urlToCompare = currentUrl ?? currentUrlPath;
        const urlString = toUrl(urlToCheck);

        const comparePath = (path: string): boolean =>
            startsWith ? urlToCompare.startsWith(path) : path === urlToCompare;

        if (!urlString.startsWith('http')) {
            return comparePath(urlString);
        }

        try {
            const absoluteUrl = new URL(urlString);

            return comparePath(absoluteUrl.pathname);
        } catch {
            return false;
        }
    };

    const isCurrentOrParentUrl: IsCurrentOrParentUrlFn = (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
    ) => {
        return isCurrentUrl(urlToCheck, currentUrl, true);
    };

    function whenCurrentUrl<TIfTrue>(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: TIfTrue,
    ): TIfTrue | null;
    function whenCurrentUrl<TIfTrue, TIfFalse>(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: TIfTrue,
        ifFalse: TIfFalse,
    ): TIfTrue | TIfFalse;
    function whenCurrentUrl<TIfTrue, TIfFalse>(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: TIfTrue,
        ...ifFalse: [] | [TIfFalse]
    ): TIfTrue | TIfFalse | null {
        if (isCurrentUrl(urlToCheck)) {
            return ifTrue;
        }

        return ifFalse.length === 1 ? ifFalse[0] : null;
    }

    return {
        currentUrl: currentUrlPath,
        isCurrentUrl,
        isCurrentOrParentUrl,
        whenCurrentUrl,
    };
}
