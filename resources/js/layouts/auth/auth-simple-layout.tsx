import AppLogoIcon from '@/components/app-logo-icon';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, ShieldCheck, Sparkles } from 'lucide-react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <>
            <Head>
                <meta
                    name="description"
                    content="Bor de Guna ERP access portal for operations, procurement, stock control, and business workflows."
                />
            </Head>
            <div className="relative min-h-svh overflow-hidden bg-[radial-gradient(circle_at_top,_rgba(55,148,11,0.18),_transparent_32%),linear-gradient(160deg,hsl(96,33%,97%),hsl(0,0%,100%)_42%,hsl(98,28%,95%)_100%)] dark:bg-[radial-gradient(circle_at_top,_rgba(113,221,73,0.18),_transparent_30%),linear-gradient(160deg,hsl(118,10%,8%),hsl(120,8%,5%)_50%,hsl(120,9%,7%)_100%)]">
                <div className="absolute inset-0 overflow-hidden">
                    <div className="bg-primary/18 absolute top-[-6rem] left-[-8rem] h-56 w-56 rounded-full blur-3xl" />
                    <div className="bg-chart-2/18 absolute right-[-4rem] bottom-[-7rem] h-64 w-64 rounded-full blur-3xl" />
                </div>

                <div className="relative mx-auto grid min-h-svh max-w-7xl gap-10 px-6 py-8 lg:grid-cols-[1.05fr_minmax(24rem,30rem)] lg:px-10 lg:py-10">
                    <section className="hidden flex-col justify-between rounded-[2rem] border border-white/60 bg-white/70 p-10 shadow-[0_24px_80px_rgba(40,70,20,0.12)] backdrop-blur xl:flex dark:border-white/10 dark:bg-white/5 dark:shadow-[0_24px_80px_rgba(0,0,0,0.35)]">
                        <div className="space-y-10">
                            <Link href={route('home')} className="inline-flex items-center gap-3">
                                <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white shadow-sm ring-1 ring-black/5 dark:bg-white/10 dark:ring-white/10">
                                    <AppLogoIcon className="h-10 w-10" />
                                </div>
                                <div>
                                    <p className="text-primary text-xs font-semibold tracking-[0.3em] uppercase">
                                        <span className="brand-name normal-case">Bor de Guna</span> ERP
                                    </p>
                                    <p className="text-muted-foreground text-sm">Unified control for business operations</p>
                                </div>
                            </Link>

                            <div className="max-w-xl space-y-5">
                                <div className="border-primary/15 bg-primary/10 text-primary inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-medium">
                                    <Sparkles className="h-4 w-4" />
                                    Built for finance, inventory, and purchasing teams
                                </div>
                                <div className="space-y-3">
                                    <h2 className="text-foreground text-4xl font-semibold tracking-tight">
                                        Run daily operations from one calm, focused workspace.
                                    </h2>
                                    <p className="text-muted-foreground max-w-lg text-base leading-7">
                                        Approvals, warehouse movement, supplier activity, and team access stay aligned without switching tools or
                                        losing visibility.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="border-border/70 bg-background/80 rounded-2xl border p-4">
                                <ShieldCheck className="text-primary mb-3 h-5 w-5" />
                                <p className="text-sm font-medium">Role-based access</p>
                                <p className="text-muted-foreground mt-1 text-sm">Secure entry and scoped access by business.</p>
                            </div>
                            <div className="border-border/70 bg-background/80 rounded-2xl border p-4">
                                <CheckCircle2 className="text-primary mb-3 h-5 w-5" />
                                <p className="text-sm font-medium">Clear approvals</p>
                                <p className="text-muted-foreground mt-1 text-sm">Track requests and actions from one place.</p>
                            </div>
                            <div className="border-border/70 bg-background/80 rounded-2xl border p-4">
                                <ArrowRight className="text-primary mb-3 h-5 w-5" />
                                <p className="text-sm font-medium">Faster handoffs</p>
                                <p className="text-muted-foreground mt-1 text-sm">Keep inventory and purchasing workflows moving.</p>
                            </div>
                        </div>
                    </section>

                    <section className="flex items-center justify-center">
                        <div className="w-full max-w-xl rounded-[2rem] border border-white/70 bg-white/88 p-6 shadow-[0_20px_80px_rgba(40,70,20,0.14)] backdrop-blur md:p-8 dark:border-white/10 dark:bg-[#0b120b]/85 dark:shadow-[0_24px_80px_rgba(0,0,0,0.45)]">
                            <div className="mb-8 flex flex-col gap-6">
                                <Link href={route('home')} className="inline-flex items-center gap-3 self-start">
                                    <div className="bg-primary/10 ring-primary/15 flex h-12 w-12 items-center justify-center rounded-2xl ring-1">
                                        <AppLogoIcon className="h-8 w-8" />
                                    </div>
                                    <div>
                                        <p className="brand-name text-primary text-sm font-semibold normal-case">Bor de Guna</p>
                                        <p className="text-muted-foreground text-xs">Enterprise Resource Planning</p>
                                    </div>
                                </Link>

                                <div className="space-y-2">
                                    <h1 className="text-foreground text-3xl font-semibold tracking-tight">{title}</h1>
                                    <p className="text-muted-foreground max-w-md text-sm leading-6">{description}</p>
                                </div>
                            </div>

                            {children}
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}
