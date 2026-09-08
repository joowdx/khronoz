interface WelcomeProps {
    packages: string[];
}

export default function Welcome({ packages }: WelcomeProps) {
    return (
        <main className="mx-auto max-w-2xl px-6 py-16">
            <h1 className="text-2xl font-semibold tracking-tight">khronoz</h1>
            <p className="mt-2 text-sm text-neutral-500">
                Inertia + React is wired up. This page is a real Inertia
                response, resolved from <code>resources/js/pages</code>.
            </p>

            <ul className="mt-8 grid gap-1 text-sm">
                {packages.map((name) => (
                    <li key={name} className="font-mono text-neutral-600">
                        {name}
                    </li>
                ))}
            </ul>
        </main>
    );
}
