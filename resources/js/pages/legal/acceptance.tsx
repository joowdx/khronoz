import { home } from '@/routes';
import { Form } from '@inertiajs/react';
import { destroy } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { create, store } from '@/routes/legal/acceptance';
import { BrandLink } from '@/components/marketing/brand';
import { LegalLinks } from '@/components/legal-links';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { LegalDocument } from './show';

export default function Acceptance({ documents }: { documents: LegalDocument[] }) {
    return (
        <div className="mx-auto max-w-2xl px-6 py-10 sm:py-16">
            <header className="mb-10">
                <BrandLink href={home().url} />
            </header>
            <main>
                <h1 className="text-2xl leading-8 font-bold">Review the agreements</h1>
                <p className="text-muted-foreground mt-3 text-sm leading-6">
                    Please review both documents before continuing. Acknowledging the Privacy Policy confirms you have
                    read the notice; it does not give blanket consent or waive your privacy rights.
                </p>
                {documents.some((document) => document.status === 'draft') && (
                    <Alert className="mt-6">
                        These are drafts for review. This acknowledgment will not count for the published versions.
                    </Alert>
                )}
                <Form {...store.form()} className="mt-8" disableWhileProcessing>
                    {({ errors, processing }) => (
                        <>
                            {errors.form && (
                                <Alert variant="destructive" className="mb-6">
                                    {errors.form}{' '}
                                    <a href={create().url} className="underline">
                                        Reload the documents
                                    </a>
                                </Alert>
                            )}
                            <div className="divide-border divide-y border-y">
                                {documents.map((document) => {
                                    const key = `documents.${document.slug}.accepted`;
                                    const error = errors[key];
                                    return (
                                        <section key={`${document.slug}-${document.version}`} className="py-6">
                                            <h2 className="font-semibold">
                                                <a
                                                    href={document.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-acc-text rounded underline-offset-4 hover:underline"
                                                >
                                                    {document.title}{' '}
                                                    <span className="text-muted-foreground text-xs font-normal">
                                                        (opens in a new tab)
                                                    </span>
                                                </a>
                                            </h2>
                                            <p className="text-muted-foreground mt-1 text-xs tabular-nums">
                                                Version {document.version}
                                            </p>
                                            <input
                                                type="hidden"
                                                name={`documents[${document.slug}][version]`}
                                                value={document.version}
                                            />
                                            <input
                                                type="hidden"
                                                name={`documents[${document.slug}][hash]`}
                                                value={document.hash}
                                            />
                                            <div className="mt-4 flex items-start gap-3">
                                                <Checkbox
                                                    id={document.slug}
                                                    name={`documents[${document.slug}][accepted]`}
                                                    value="1"
                                                    defaultChecked={false}
                                                    aria-invalid={!!error}
                                                    aria-describedby={error ? `${document.slug}-error` : undefined}
                                                    className="mt-0.5"
                                                />
                                                <label htmlFor={document.slug} className="text-sm leading-5">
                                                    {document.slug === 'user-agreement'
                                                        ? 'I agree to the User Agreement.'
                                                        : 'I have read and acknowledge the Privacy Policy.'}
                                                </label>
                                            </div>
                                            <p
                                                id={`${document.slug}-error`}
                                                aria-live="polite"
                                                className="text-destructive mt-2 min-h-4 text-xs"
                                            >
                                                {error || '\u200b'}
                                            </p>
                                        </section>
                                    );
                                })}
                            </div>
                            <Button type="submit" disabled={processing} className="mt-6">
                                Acknowledge and continue
                            </Button>
                        </>
                    )}
                </Form>
                <Form {...destroy.form()} className="mt-3">
                    <Button type="submit" variant="ghost">
                        Decline and sign out
                    </Button>
                </Form>
            </main>
            <footer className="mt-10 border-t pt-6">
                <LegalLinks />
            </footer>
        </div>
    );
}
