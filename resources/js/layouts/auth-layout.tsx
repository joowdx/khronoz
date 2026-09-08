import type { ReactNode } from 'react';

export default function AuthLayout({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 p-6">
            <div className="flex w-full max-w-sm flex-col gap-6">
                <span className="text-center font-semibold tracking-tight">khronoz</span>
                <div className="flex flex-col gap-6">
                    <div className="flex flex-col items-center gap-1 text-center">
                        <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                        {description && <p className="text-muted-foreground text-sm">{description}</p>}
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
