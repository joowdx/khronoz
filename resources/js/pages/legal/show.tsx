import { Link } from '@inertiajs/react';
import { BrandLink } from '@/components/marketing/brand';
import { LegalLinks } from '@/components/legal-links';
import { Alert } from '@/components/ui/alert';
import { show } from '@/routes/legal';

export interface LegalDocument {
    slug: string;
    title: string;
    version: string;
    status: 'draft' | 'published';
    effective_at: string | null;
    hash: string;
    html: string;
    url: string;
    versions: string[];
}

export default function Show({ document }: { document: LegalDocument }) {
    return (
        <div className="mx-auto max-w-3xl px-6 py-8 sm:py-12">
            <header className="flex flex-wrap items-center justify-between gap-6 border-b pb-6">
                <BrandLink />
                <LegalLinks />
            </header>
            <main id="main-content" className="py-8">
                {document.status === 'draft' && (
                    <Alert className="mb-8">
                        Draft for review. Operator, contact, hosting and effective-date details must be completed before
                        publication.
                    </Alert>
                )}
                <p className="text-muted-foreground mb-6 text-xs tabular-nums">
                    Version {document.version}
                    {document.effective_at ? ` · Effective ${document.effective_at}` : ' · Not yet effective'}
                </p>
                <article
                    className="[&_a]:text-acc-text text-sm leading-7 break-words [&_a]:underline [&_a]:underline-offset-4 [&_h1]:mb-6 [&_h1]:text-3xl [&_h1]:leading-tight [&_h1]:font-bold [&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:text-lg [&_h2]:font-semibold [&_li]:my-2 [&_p]:my-4 [&_ul]:list-disc [&_ul]:pl-6"
                    dangerouslySetInnerHTML={{ __html: document.html }}
                />
            </main>
            <footer className="border-t pt-6">
                <h2 className="mb-3 text-sm font-semibold">Document versions</h2>
                <ul className="flex flex-wrap gap-4 text-xs tabular-nums">
                    {document.versions.map((version) => (
                        <li key={version}>
                            <Link
                                href={show({ document: document.slug, version })}
                                aria-current={version === document.version ? 'page' : undefined}
                                className="text-acc-text rounded underline-offset-4 hover:underline"
                            >
                                {version}
                            </Link>
                        </li>
                    ))}
                </ul>
            </footer>
        </div>
    );
}
