import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

/**
 * An HR letter on paper (Module 5, Slice 2). Rendered from the SNAPSHOT
 * frozen at issue - the live record may have moved on, the paper must not.
 * The boss signs under the printed name; the system only drafts.
 */
interface LetterData {
    employee_name: string;
    employee_code: string;
    designation: string | null;
    department: string | null;
    join_date: string | null;
    gross_salary?: number | null;
    employment_type?: string;
    probation_months?: number | null;
    confirmation_date?: string;
    effective_from?: string;
    new_gross?: number | null;
    end_date?: string | null;
    still_employed?: boolean;
}

interface LetterProps {
    letter: {
        id: number;
        reference_no: string;
        type: string;
        issued_on: string;
        body_data: LetterData;
    };
    issuer: Issuer;
}

const money = (value: number | null | undefined) =>
    value === null || value === undefined ? '-' : Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

const titles: Record<string, string> = {
    appointment: 'Appointment Letter',
    confirmation: 'Confirmation Letter',
    increment: 'Increment Letter',
    experience: 'Experience Certificate',
};

function Body({ type, data, issuer }: { type: string; data: LetterData; issuer: Issuer }) {
    const salutation = `Dear ${data.employee_name},`;

    if (type === 'appointment') {
        return (
            <>
                <p>{salutation}</p>
                <p>
                    We are pleased to appoint you as <strong>{data.designation ?? 'a member of our team'}</strong>
                    {data.department ? ` in the ${data.department} department` : ''} of {issuer.name}, effective from{' '}
                    <strong>{data.join_date}</strong>. Your employee code is <strong>{data.employee_code}</strong>.
                </p>
                <p>
                    Your gross monthly remuneration is <strong>BDT {money(data.gross_salary)}</strong>, payable as per company rules.
                    {data.employment_type === 'probation' && data.probation_months
                        ? ` Your appointment begins with a probation period of ${data.probation_months} months, after which your confirmation will be reviewed.`
                        : ''}
                </p>
                <p>We look forward to a long and successful association with you.</p>
            </>
        );
    }

    if (type === 'confirmation') {
        return (
            <>
                <p>{salutation}</p>
                <p>
                    We are pleased to confirm your employment as <strong>{data.designation ?? 'a member of our team'}</strong> with effect
                    from <strong>{data.confirmation_date}</strong>, on the satisfactory completion of your probation.
                </p>
                <p>All other terms of your appointment remain unchanged. We wish you continued success with {issuer.name}.</p>
            </>
        );
    }

    if (type === 'increment') {
        return (
            <>
                <p>{salutation}</p>
                <p>
                    In recognition of your performance, your remuneration has been revised with effect from{' '}
                    <strong>{data.effective_from}</strong>. Your gross monthly remuneration is now{' '}
                    <strong>BDT {money(data.new_gross)}</strong>.
                </p>
                <p>We thank you for your contribution and wish you continued success.</p>
            </>
        );
    }

    return (
        <>
            <p>To whom it may concern,</p>
            <p>
                This is to certify that <strong>{data.employee_name}</strong> (employee code {data.employee_code})
                {data.still_employed ? ' has been employed' : ' was employed'} with {issuer.name} as{' '}
                <strong>{data.designation ?? 'a member of our team'}</strong> from <strong>{data.join_date}</strong>
                {data.still_employed ? ' to date.' : (
                    <>
                        {' '}
                        to <strong>{data.end_date}</strong>.
                    </>
                )}
            </p>
            <p>During this period we found their conduct and performance satisfactory. We wish them success in future endeavours.</p>
        </>
    );
}

export default function LetterPrint({ letter, issuer }: LetterProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Employees', href: '/hrm/employees' },
        { title: letter.reference_no, href: `/hrm/letters/${letter.id}/print` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={letter.reference_no} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center gap-2 print:hidden">
                    <Button size="sm" onClick={() => window.print()}>
                        Print / save as PDF
                    </Button>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-3xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title={titles[letter.type] ?? 'Letter'} subtitle={letter.reference_no} />

                    <div className="mt-6 flex justify-between text-sm">
                        <p>
                            Ref: <strong>{letter.reference_no}</strong>
                        </p>
                        <p>Date: {letter.issued_on}</p>
                    </div>

                    <div className="mt-6 space-y-4 text-sm leading-relaxed">
                        <Body type={letter.type} data={letter.body_data} issuer={issuer} />
                    </div>

                    <div className="mt-20 flex justify-between text-sm">
                        {letter.type !== 'experience' && (
                            <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">Accepted by (employee)</div>
                        )}
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">For {issuer.name}</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
