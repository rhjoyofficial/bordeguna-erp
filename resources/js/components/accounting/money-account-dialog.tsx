import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogMoneyAccount {
    id: number;
    name: string;
    type: string;
    account_number: string | null;
    bank_name: string | null;
    branch: string | null;
    routing_number: string | null;
    overdraft_limit: string;
    provider: string | null;
    note: string | null;
    is_active: boolean;
    account_id: number | null;
    ledger_account: string | null;
    /** Initial plan B3: who holds an Employee cash account. */
    custodian_employee_id?: number | null;
}

export interface MoneyAccountOption {
    value?: string;
    id?: number;
    label: string;
}

const EMPTY = {
    name: '',
    type: 'bank',
    account_number: '',
    bank_name: '',
    branch: '',
    routing_number: '',
    overdraft_limit: '0',
    provider: '',
    account_id: '',
    custodian_employee_id: '',
    is_active: true as boolean,
    // ⚠ Carried, not drawn: the server accepts a note and the inline form never
    // had a box for it, so an edit sends back whatever is already stored.
    note: '',
};

function fromAccount(account: DialogMoneyAccount): typeof EMPTY {
    return {
        name: account.name,
        type: account.type,
        account_number: account.account_number ?? '',
        bank_name: account.bank_name ?? '',
        branch: account.branch ?? '',
        routing_number: account.routing_number ?? '',
        overdraft_limit: account.overdraft_limit ?? '0',
        provider: account.provider ?? '',
        account_id: account.account_id === null ? '' : String(account.account_id),
        custodian_employee_id: account.custodian_employee_id ? String(account.custodian_employee_id) : '',
        is_active: account.is_active,
        note: account.note ?? '',
    };
}

/**
 * One popup to add a bank, cash, wallet, courier or gateway account, or correct
 * one (Joy, 14 Oct 2026: "click add button pop up will add ... same when
 * edit"). The form that sat open above the list is gone; the list is the page.
 *
 * ⚠⚠ The owner's money screen. Same fields, same routes, same server rules as
 * the inline form, field for field: the dialog only changes where the form is
 * drawn. The ledger is shown, never chosen.
 */
export function MoneyAccountDialog({
    open,
    onOpenChange,
    account,
    types,
    ledgerAccounts,
    courierLedgerAccountId,
    gatewayLedgerAccountId,
    employees = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The account being edited; null for a new one. */
    account: DialogMoneyAccount | null;
    types: MoneyAccountOption[];
    ledgerAccounts: MoneyAccountOption[];
    courierLedgerAccountId: number | null;
    gatewayLedgerAccountId: number | null;
    /** Initial plan B3: HR employees who may hold company cash. */
    employees?: { id: number; name: string }[];
}) {
    const { t } = useTranslation();
    const editing = account !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new account, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = account ? fromAccount(account) : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, account]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (account) {
            put(route('accounting.money-accounts.update', account.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('accounting.money-accounts.store'), { preserveScroll: true, onSuccess: close });
    };

    const isCash = data.type === 'cash';
    const isMfs = data.type === 'mfs';
    const isCourier = data.type === 'courier';
    const isGateway = data.type === 'gateway';
    const isEmployeeCash = data.type === 'employee_cash';

    // ⚠ A courier's account can only report under 1145 Receivable from Couriers,
    // and a payment gateway's under 1146, so choosing the kind chooses the ledger
    // account too.
    const chooseType = (type: string) => {
        const forced = type === 'courier' ? courierLedgerAccountId : type === 'gateway' ? gatewayLedgerAccountId : null;

        setData((current) => ({
            ...current,
            type,
            account_id: forced !== null ? String(forced) : current.account_id,
        }));
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${account.name}` : 'Add a bank or cash account'}</DialogTitle>
                    </DialogHeader>

                    <div className="flex flex-wrap items-start gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="money-name" required>
                                Name
                            </Label>
                            <Input
                                id="money-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="w-56"
                                placeholder="e.g. BRAC Bank - Current"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="money-type" required>
                                Kind
                            </Label>
                            <SearchSelect fit id="money-type" value={data.type} onChange={(e) => chooseType(e.target.value)}>
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.type} />
                        </div>

                        {isEmployeeCash && (
                            <div className="grid gap-2">
                                <Label htmlFor="money-custodian" required>
                                    {t('ecash.custodian')}
                                </Label>
                                <SearchSelect
                                    fit
                                    id="money-custodian"
                                    value={data.custodian_employee_id}
                                    onChange={(e) => setData('custodian_employee_id', e.target.value)}
                                >
                                    <option value="">{t('ecash.pick_employee')}</option>
                                    {employees.map((employee) => (
                                        <option key={employee.id} value={employee.id}>
                                            {employee.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.custodian_employee_id} />
                            </div>
                        )}

                        {!isCash && !isEmployeeCash && (
                            <div className="grid gap-2">
                                <Label htmlFor="money-account_number">
                                    {isMfs ? 'Wallet number' : isCourier || isGateway ? 'Merchant account' : 'Account number'}
                                </Label>
                                <Input
                                    id="money-account_number"
                                    value={data.account_number}
                                    onChange={(e) => setData('account_number', e.target.value)}
                                    className="w-52"
                                />
                                <InputError message={errors.account_number} />
                            </div>
                        )}

                        {(isMfs || isCourier || isGateway) && (
                            <div className="grid gap-2">
                                <Label htmlFor="money-provider">{isCourier ? 'Courier' : isGateway ? 'Gateway' : 'Provider'}</Label>
                                <Input
                                    id="money-provider"
                                    value={data.provider}
                                    onChange={(e) => setData('provider', e.target.value)}
                                    className="w-36"
                                    placeholder={isCourier ? 'Pathao' : isGateway ? 'SSLCommerz' : 'bKash'}
                                />
                                <InputError message={errors.provider} />
                            </div>
                        )}
                    </div>

                    {isEmployeeCash && <p className="text-muted-foreground max-w-xl text-xs">{t('ecash.dialog_hint')}</p>}

                    {data.type === 'bank' && (
                        <div className="flex flex-wrap items-start gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="money-bank_name">Bank</Label>
                                <Input
                                    id="money-bank_name"
                                    value={data.bank_name}
                                    onChange={(e) => setData('bank_name', e.target.value)}
                                    className="w-44"
                                />
                                <InputError message={errors.bank_name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="money-branch">Branch</Label>
                                <Input id="money-branch" value={data.branch} onChange={(e) => setData('branch', e.target.value)} className="w-40" />
                                <InputError message={errors.branch} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="money-routing_number">Routing no.</Label>
                                <Input
                                    id="money-routing_number"
                                    value={data.routing_number}
                                    onChange={(e) => setData('routing_number', e.target.value)}
                                    className="w-36"
                                />
                                <InputError message={errors.routing_number} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="money-overdraft_limit">Overdraft limit</Label>
                                <Input
                                    id="money-overdraft_limit"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={data.overdraft_limit}
                                    onChange={(e) => setData('overdraft_limit', e.target.value)}
                                    className="w-36"
                                />
                                <InputError message={errors.overdraft_limit} />
                            </div>
                        </div>
                    )}

                    <div className="flex flex-wrap items-start gap-3">
                        {/*
                          ⚠⚠ No ledger picker (21 Sep 2026). A cash box, bank account or
                          wallet gets a ledger head of its own, made by the system when it
                          is saved, so its balance is its own line on every report. A
                          courier's or gateway's always reports under 1145 / 1146.
                        */}
                        <div className="grid gap-2">
                            <span className="text-sm font-medium">Ledger</span>
                            <p className="text-muted-foreground flex h-10 max-w-72 items-center text-sm">
                                {isCourier || isGateway
                                    ? (ledgerAccounts.find((ledger) => String(ledger.id) === data.account_id)?.label ??
                                      (isCourier ? '1145 - Receivable from Couriers' : '1146 - Receivable from Payment Gateways'))
                                    : (account?.ledger_account ?? 'Its own, created when you save')}
                            </p>
                            <InputError message={errors.account_id} />
                        </div>

                        {editing && (
                            <div className="grid gap-2">
                                <span className="text-sm font-medium">Status</span>
                                <label className="flex h-10 items-center gap-2 text-sm">
                                    <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                                    Active
                                </label>
                                <InputError message={errors.is_active} />
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? `Save ${account.name}` : 'Add account'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
