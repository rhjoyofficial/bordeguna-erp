<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Models\User;

/**
 * The assistant's how-to knowledge: one article per task somebody actually
 * performs, with the exact menu path and the steps in order.
 *
 * ⚠⚠ FILTERED BY THE ASKER'S REAL PERMISSIONS, ARTICLE BY ARTICLE. The same
 * question has different true answers for different people - "how do I add a
 * movement" is My profile > My leave for an employee and HRM > Leave & movement
 * for HR - so an article names the permission it requires and the lookup
 * returns only what THIS user can actually reach. An article whose permission
 * the user lacks comes back as a `blocked` title (so the assistant can say the
 * feature exists and who can grant it) and never as steps.
 *
 * ⚠ This lives server-side, next to the tool that serves it, instead of in the
 * system prompt: forty articles in the prompt would cost every turn what only
 * how-do-I turns need, and would break the cached static prefix every time a
 * step changed.
 *
 * ⚠ Steps are English on purpose - the model answers in the panel's language
 * setting and translates them; the UI labels quoted here are what the screens
 * actually say.
 */
class GuideBook
{
    /**
     * Look up how-to guidance for one question, as the asker.
     *
     * @return array{found: bool, articles?: list<array<string,mixed>>, blocked?: list<string>, topics?: list<string>}
     */
    public function lookup(User $user, string $query): array
    {
        $terms = $this->terms($query);
        $weights = $this->termWeights($terms);

        $reachable = [];
        $blocked = [];

        foreach ($this->articles() as $article) {
            $score = $this->score($article, $terms, $weights);

            if ($score <= 0) {
                continue;
            }

            if ($article['permission'] === null || $user->can($article['permission'])) {
                $reachable[] = ['score' => $score, 'article' => $article];
            } else {
                $blocked[] = ['score' => $score, 'title' => $article['title']];
            }
        }

        usort($reachable, fn ($a, $b) => $b['score'] <=> $a['score']);
        usort($blocked, fn ($a, $b) => $b['score'] <=> $a['score']);

        if ($reachable === [] && $blocked === []) {
            return [
                'found' => false,
                'topics' => $this->topicsFor($user),
            ];
        }

        return array_filter([
            'found' => $reachable !== [],
            'articles' => array_map(
                fn ($hit) => collect($hit['article'])->except('keywords', 'permission')->all(),
                array_slice($reachable, 0, 3),
            ),
            // Matched but out of reach: name the feature so the assistant can
            // say it exists and that an Owner grants access under Settings >
            // Roles - the same behaviour the prompt already promises.
            'blocked' => array_slice(array_column($blocked, 'title'), 0, 3) ?: null,
        ], fn ($value) => $value !== null && $value !== []) + ['found' => $reachable !== []];
    }

    /**
     * The topic areas THIS user could ask about, for the no-match answer.
     *
     * @return list<string>
     */
    private function topicsFor(User $user): array
    {
        $topics = [];

        foreach ($this->articles() as $article) {
            if ($article['permission'] === null || $user->can($article['permission'])) {
                $topics[$article['topic']] = true;
            }
        }

        return array_keys($topics);
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($query), $matches);

        return $matches[0];
    }

    /**
     * ⚠ Whole tokens, not substrings, and a rare term outweighs a common one.
     * The first cut used str_contains and "add a movement" ranked the
     * customers article (keyword "add customer", and "add" inside "address")
     * above the movement screens; the second matched tokens but let "add" -
     * which sits in five titles - tie everything. A term found in many
     * articles now carries little weight and the distinctive noun decides,
     * because a guide that answers the wrong article confidently is worse
     * than one that answers nothing.
     *
     * @param  array{title:string, keywords:list<string>}  $article
     * @param  array<string, int>  $weights
     */
    private function score(array $article, array $terms, array $weights): int
    {
        $titleTokens = $this->terms($article['title']);
        $keywordTokens = $this->terms(implode(' ', $article['keywords']));
        $score = 0;

        foreach ($terms as $term) {
            $weight = $weights[$term] ?? 0;

            if ($this->hits($titleTokens, $term)) {
                $score += $weight * 2;
            } elseif ($this->hits($keywordTokens, $term)) {
                $score += $weight;
            }
        }

        return $score;
    }

    /**
     * How much each query term is worth: the fewer articles carry it, the
     * more it says about which one is meant.
     *
     * @param  list<string>  $terms
     * @return array<string, int>
     */
    private function termWeights(array $terms): array
    {
        $weights = [];

        foreach ($terms as $term) {
            $df = 0;

            foreach ($this->articles() as $article) {
                $tokens = $this->terms($article['title'].' '.implode(' ', $article['keywords']));

                if ($this->hits($tokens, $term)) {
                    $df++;
                }
            }

            $weights[$term] = $df === 0 ? 0 : max(1, 6 - $df);
        }

        return $weights;
    }

    /**
     * A term matches a token exactly, or as a prefix once it is long enough
     * that a prefix means something ("payrol" finds payroll; "add" no longer
     * finds address).
     *
     * @param  list<string>  $tokens
     */
    private function hits(array $tokens, string $term): bool
    {
        foreach ($tokens as $token) {
            if ($token === $term || (mb_strlen($term) >= 4 && str_starts_with($token, $term))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every article. Grouped by topic, `permission` null = anyone signed in.
     *
     * ⚠ Menu paths quote the sidebar and the self-service tabs verbatim; when a
     * screen moves, this list is the second place to touch (the featureMap in
     * ErpAssistant is the first).
     *
     * @return list<array{key:string, topic:string, title:string, path:string, permission:?string, steps:list<string>, keywords:list<string>, note?:string}>
     */
    public function articles(): array
    {
        return [
            // --- Yourself -------------------------------------------------
            [
                'key' => 'self.profile',
                'topic' => 'Your own profile and self-service',
                'title' => 'See your own profile, or ask for a detail to be corrected',
                'path' => 'My profile',
                'permission' => null,
                'steps' => [
                    'Open "My profile" in the sidebar (it appears when your login is linked to an employee record).',
                    'The tabs across the top are your own corner of the system: My profile, Clock in/out, My leave, My payslips, My expenses, My notices.',
                    'You cannot edit your record directly. Use "Request a change" on the profile tab; HR reviews and applies it.',
                ],
                'keywords' => ['profile', 'my profile', 'own details', 'personal', 'change request', 'correct', 'address', 'phone'],
            ],
            [
                'key' => 'self.clock',
                'topic' => 'Your own profile and self-service',
                'title' => 'Clock in and out from the web',
                'path' => 'My profile > Clock in/out',
                'permission' => null,
                'steps' => [
                    'Open My profile, then the "Clock in/out" tab.',
                    'Press the button once when you start and once when you finish. The punch is recorded with the server time.',
                    'The punch machine at the office records the same way; either counts.',
                ],
                'keywords' => ['clock', 'punch', 'check in', 'check out', 'attendance', 'web clock', 'hajira'],
                'note' => 'A day with an in-punch but no out-punch shows as Missing punch and is corrected by HR, so clock out even on a half day.',
            ],
            [
                'key' => 'self.leave',
                'topic' => 'Your own profile and self-service',
                'title' => 'Request leave for yourself',
                'path' => 'My profile > My leave',
                'permission' => null,
                'steps' => [
                    'Open My profile, then the "My leave" tab. Your remaining quota per leave type is shown.',
                    'Press "Request leave", pick the type and the dates, write the reason, submit.',
                    'The request goes for approval; the day counts as leave only after it is approved.',
                ],
                'keywords' => ['leave', 'holiday request', 'chuti', 'sick', 'casual', 'earned leave', 'quota', 'balance'],
            ],
            [
                'key' => 'self.movement',
                'topic' => 'Your own profile and self-service',
                'title' => 'Record a movement for yourself: field duty, official work outside the office, work from home',
                'path' => 'My profile > My leave',
                'permission' => null,
                'steps' => [
                    'Open My profile, then the "My leave" tab, and find the movements section.',
                    'Press "Request movement". Pick the type (field duty / official work / work from home / holiday work), the date, from and to time, the place, and the reason.',
                    'Submit. A manager approves it; ⚠ until it is APPROVED the day still counts from your punches, so an unapproved movement on a day with no punches reads as Absent.',
                    'Field duty and official work count the day as present by themselves once approved. Work from home does NOT: you still clock in and out through the web clock.',
                ],
                'keywords' => ['movement', 'field duty', 'official work', 'outside office', 'work from home', 'wfh', 'client visit', 'tour'],
            ],
            [
                'key' => 'self.payslips',
                'topic' => 'Your own profile and self-service',
                'title' => 'See and download your own payslips',
                'path' => 'My profile > My payslips',
                'permission' => null,
                'steps' => [
                    'Open My profile, then the "My payslips" tab. One row per paid month.',
                    'Download the PDF. It is password protected: the password is your date of birth as ddmmyyyy (for example 20121992 for 20 December 1992).',
                ],
                'keywords' => ['payslip', 'salary slip', 'pay slip', 'password', 'pdf', 'beton'],
            ],
            [
                'key' => 'self.expense_claim',
                'topic' => 'Your own profile and self-service',
                'title' => 'Claim back money you spent for the company (TA/DA, conveyance, a bill you paid)',
                'path' => 'My profile > My expenses',
                'permission' => null,
                'steps' => [
                    'Open My profile, then the "My expenses" tab.',
                    'Press "New claim": what you spent, the date, the amount, and ATTACH THE BILL or receipt - a claim without evidence is sent back.',
                    'Submit. It goes for approval, and once approved, payroll or finance settles it; you can watch the status on the same tab.',
                    'Only you can raise your own claim - HR cannot type it in for you.',
                ],
                'keywords' => ['expense claim', 'reimburse', 'ta da', 'tada', 'conveyance', 'khoroch', 'money back', 'claim'],
            ],
            [
                'key' => 'self.notices',
                'topic' => 'Your own profile and self-service',
                'title' => 'See a show-cause notice issued to you and reply to it',
                'path' => 'My profile > My notices',
                'permission' => null,
                'steps' => [
                    'Open My profile, then the "My notices" tab.',
                    'Open the notice to read it and print your own copy; reply from the same screen within the time it names.',
                ],
                'keywords' => ['show cause', 'notice', 'warning', 'disciplinary', 'reply'],
            ],
            [
                'key' => 'self.password',
                'topic' => 'Your own profile and self-service',
                'title' => 'Change your password, the look of the app, or its language',
                'path' => 'Settings',
                'permission' => null,
                'steps' => [
                    'Open Settings from the sidebar (bottom).',
                    'Password lives under Settings > Password. Light/dark mode and colors under Settings > Appearance.',
                    'For Bangla: the language toggle is per user - look for the EN/BN switch; the assistant panel has its own EN/BN toggle beside the bin icon.',
                ],
                'keywords' => ['password', 'change password', 'dark mode', 'theme', 'appearance', 'language', 'bangla', 'bengali'],
            ],
            [
                'key' => 'self.todo_notes',
                'topic' => 'Your own profile and self-service',
                'title' => 'Keep personal to-dos and notes; see the shared calendar, files and chat',
                'path' => 'Overview section of the sidebar',
                'permission' => null,
                'steps' => [
                    'Notes, Documents, Chat and Calendar sit at the top of the sidebar; each shows only what your role may see.',
                    'A note with a date on it resurfaces on the calendar that day.',
                    'You can also ask me to add a to-do or note for you right here in this chat, if your role has those grants.',
                ],
                'keywords' => ['todo', 'to-do', 'notes', 'calendar', 'chat', 'documents', 'files', 'reminder'],
            ],

            // --- Selling --------------------------------------------------
            [
                'key' => 'sales.pos',
                'topic' => 'Selling',
                'title' => 'Sell over the counter (POS)',
                'path' => 'Sales > Counter sale',
                'permission' => 'sales.sell',
                'steps' => [
                    'Open Sales > Counter sale.',
                    'Find products by name or scan a barcode; quantities and the price come from the selling price list for the customer\'s group (walk-in customers get Retail).',
                    'Take the payment (cash, bKash, card as configured) and confirm. Stock and the ledger post automatically.',
                    '⚠ If the system refuses to add a product, there is usually no selling price in force for that product and group on today\'s date - that is a pricing gap, not an error.',
                ],
                'keywords' => ['pos', 'counter', 'counter sale', 'sell', 'cash sale', 'bikri', 'scan', 'barcode'],
            ],
            [
                'key' => 'sales.shifts',
                'topic' => 'Selling',
                'title' => 'Cashier shifts: open the till, record movements, close with a count',
                'path' => 'Sales > Cashier shifts',
                'permission' => 'sales.sell',
                'steps' => [
                    'Open Sales > Cashier shifts, pick the till and type the counted opening float BEFORE selling starts.',
                    'Sell normally at the counter - cash sales are counted into the shift automatically.',
                    'Any cash taken out (change, small purchases) or put in must be recorded as a till movement WITH a reason.',
                    'At closing, count every note and coin and type the total. The system computes the expected cash and the over/short itself - the difference is never typed by hand.',
                    '⚠ One till can hold only one open shift at a time; close the running shift before opening the next.',
                ],
                'keywords' => ['shift', 'till', 'cash drawer', 'float', 'over short', 'closing count', 'cash count', 'drawer', 'z report'],
            ],
            [
                'key' => 'sales.order_flow',
                'topic' => 'Selling',
                'title' => 'Sales order to invoice to delivery: the credit-sale flow',
                'path' => 'Sales > Sales orders',
                'permission' => 'sales.orders.record',
                'steps' => [
                    'Create the order under Sales > Sales orders: customer, lines, prices resolve from the customer\'s price group.',
                    'Approve it if your workflow requires approval, then invoice it (Sales > Invoices) - the invoice is what posts revenue and the receivable.',
                    'Deliver against it (Sales > Deliveries) - the delivery is what moves the stock out.',
                    'Record the customer\'s payment under Sales when it arrives; the statement and receivables ageing follow from these documents.',
                ],
                'keywords' => ['sales order', 'order', 'invoice', 'delivery', 'credit sale', 'baki', 'due sale'],
            ],
            [
                'key' => 'sales.receipt',
                'topic' => 'Selling',
                'title' => 'Record money received from a customer',
                'path' => 'Sales > Invoices',
                'permission' => 'sales.receipt.record',
                'steps' => [
                    'Open the customer\'s unpaid invoice (Sales > Invoices) and record a receipt against it, or record a receipt on the customer and allocate it across invoices.',
                    'Pick WHERE the money arrived - BRAC Bank, Cash in Hand, bKash, Nagad, Rocket - that choice is what the cash book and reconciliation read.',
                ],
                'keywords' => ['receipt', 'customer payment', 'money received', 'collection', 'taka joma', 'due collection'],
            ],
            [
                'key' => 'sales.return',
                'topic' => 'Selling',
                'title' => 'Take goods back from a customer (sales return)',
                'path' => 'Sales > Returns',
                'permission' => 'sales.returns.record',
                'steps' => [
                    'Open Sales > Returns and create the return against the original invoice, line by line.',
                    'The return brings the stock back in and reverses the revenue; a refund, if any, is its own recorded step with its own permission.',
                ],
                'keywords' => ['return', 'sales return', 'ferot', 'refund', 'goods back'],
            ],
            [
                'key' => 'sales.customers',
                'topic' => 'Selling',
                'title' => 'Add a customer and set which prices they get',
                'path' => 'Sales > Customers',
                'permission' => 'sales.customers.manage',
                'steps' => [
                    'Open Sales > Customers, press New customer.',
                    'The CODE is permanent and never reused - choose it carefully (CUS-0001 style).',
                    'Set the PRICE GROUP (Retail, Dealer, Distributor, Corporate): it decides which price list every sale to them uses.',
                    'Payment terms and credit limit control when their invoices count as overdue and how much they may owe.',
                    'The customer\'s full story - invoices, payments, balance - is on their 360 page, opened from the customer list.',
                ],
                'keywords' => ['customer', 'add customer', 'new customer', 'price group', 'credit limit', 'dealer', 'client', '360'],
            ],
            [
                'key' => 'sales.prices',
                'topic' => 'Selling',
                'title' => 'Set or change selling prices',
                'path' => 'Sales > Selling prices',
                'permission' => 'sales.prices.manage',
                'steps' => [
                    'Open Sales > Selling prices. One rule = product + price group + price, with an effective-from date and optional quantity breaks.',
                    'A new rule supersedes the old one from its date; history is kept, nothing is overwritten.',
                    '⚠ A sale is REFUSED when no rule is in force for the product and the buyer\'s group - there is no fallback to another group\'s price.',
                    'For many prices at once use Sales > Import prices: a CSV with sku, retail_price, dealer_price, distributor_price, corporate_price, previewed as a dry run before anything is written.',
                ],
                'keywords' => ['price', 'selling price', 'dam', 'mrp', 'price list', 'import prices', 'change price', 'discount'],
            ],

            // --- Stock and production ------------------------------------
            [
                'key' => 'inventory.stock_view',
                'topic' => 'Stock and production',
                'title' => 'See what stock you have, what is low, what is expiring',
                'path' => 'Inventory > Stock',
                'permission' => null,
                'steps' => [
                    'Inventory > Stock shows on-hand per product per warehouse, batch by batch.',
                    'Inventory > Low stocks lists what has fallen to its reorder level; Inventory > Stock expiry lists batches near or past their date.',
                    '⚠ Stock only ever changes through documents: opening stock, goods receipt, transfer, production run, delivery or sale, return, count adjustment. No screen edits a quantity directly.',
                ],
                'keywords' => ['stock', 'on hand', 'inventory', 'low stock', 'expiry', 'mojut', 'batch', 'warehouse stock'],
            ],
            [
                'key' => 'inventory.transfer',
                'topic' => 'Stock and production',
                'title' => 'Move stock between warehouses',
                'path' => 'Inventory > Stock',
                'permission' => 'inventory.stock.record',
                'steps' => [
                    'From Inventory > Stock, start a transfer: source warehouse, destination, lines.',
                    'The transfer document moves the batches; both warehouses\' books change together, and the valuation travels with the stock.',
                ],
                'keywords' => ['transfer', 'move stock', 'warehouse transfer', 'savar', 'shekhertek', 'godown'],
            ],
            [
                'key' => 'inventory.request',
                'topic' => 'Stock and production',
                'title' => 'Ask another warehouse for stock (stock request)',
                'path' => 'Inventory > Stock requests',
                'permission' => 'inventory.stock.request',
                'steps' => [
                    'Open Inventory > Stock requests and raise a request: what you need, how much, from where.',
                    'The other side approves and fulfils it; the fulfilment creates the transfer.',
                ],
                'keywords' => ['stock request', 'request stock', 'need stock', 'requisition'],
            ],
            [
                'key' => 'inventory.production',
                'topic' => 'Stock and production',
                'title' => 'Run production: turn raw materials into finished goods',
                'path' => 'Inventory > Production',
                'permission' => 'inventory.production.manage',
                'steps' => [
                    'Open Inventory > Production and create a run. Picking a recipe (BOM) pre-fills the component lines; adjust to what was actually used.',
                    'The run consumes components by FIFO and produces the finished goods at the cost that consumed stock carried.',
                    'Watch Inventory > Production yield afterwards: it notices when a recipe\'s real yield drifts from its paper yield.',
                    'A recipe itself never moves stock - only a run does.',
                ],
                'keywords' => ['production', 'manufacture', 'production run', 'bom', 'recipe', 'utpadon', 'make'],
            ],
            [
                'key' => 'inventory.opening_stock',
                'topic' => 'Stock and production',
                'title' => 'Enter opening stock (the cutover document)',
                'path' => 'Inventory > Opening stock',
                'permission' => 'inventory.stock.adjust',
                'steps' => [
                    'Open Inventory > Opening stock. One document per warehouse, written ONCE - it cannot be re-imported, because it sets the FIFO cost layers behind every margin after it.',
                    'Type the lines or import the count-sheet file (sku, warehouse, quantity, unit_cost); the import shows a dry-run preview first.',
                    '⚠ unit_cost is what the item COST US, never its selling price.',
                ],
                'keywords' => ['opening stock', 'opening balance stock', 'cutover', 'initial stock', 'import stock'],
            ],
            [
                'key' => 'inventory.count',
                'topic' => 'Stock and production',
                'title' => 'Count stock and adjust the book to the shelf',
                'path' => 'Inventory > Stock counts',
                'permission' => 'inventory.stock.record',
                'steps' => [
                    'Open Inventory > Stock counts and start a count for a warehouse. Print the blind sheet - it deliberately shows no expected quantity.',
                    'Count, enter the results, and post: variances become ordinary adjustments sized against live stock at that moment.',
                ],
                'keywords' => ['stock count', 'count', 'cycle count', 'gonona', 'physical count', 'variance'],
            ],
            [
                'key' => 'inventory.products',
                'topic' => 'Stock and production',
                'title' => 'Add a product, import the catalogue, print barcode labels',
                'path' => 'Inventory > Products',
                'permission' => null,
                'steps' => [
                    'Add one product under Inventory > Products (the SKU can auto-generate); category, unit and type are required.',
                    'Many at once: Inventory > Import products takes a CSV (sku, name, category, unit, unit_abbr, type + optional brand, barcode) with a dry-run preview.',
                    'Labels: Inventory > Barcode labels prints Code 128 labels for products that carry a barcode.',
                ],
                'keywords' => ['product', 'add product', 'new product', 'sku', 'catalogue', 'import products', 'barcode', 'label'],
            ],

            // --- Buying and spending -------------------------------------
            [
                'key' => 'purchasing.flow',
                'topic' => 'Buying and spending',
                'title' => 'Buy goods: purchase order, goods receipt, bill, payment',
                'path' => 'Purchases',
                'permission' => 'purchasing.po.create',
                'steps' => [
                    'Raise the PO under Purchases > Purchase orders; it is a promise, it moves nothing.',
                    'When the goods arrive, record the receipt (Purchases, from the PO) - THAT is what brings stock in, valued at the PO price.',
                    'Enter the supplier\'s bill against the receipt (Purchases > Bills); price differences post as purchase price variance.',
                    'Pay it under Purchases > Payments, choosing which account the money leaves.',
                ],
                'keywords' => ['purchase', 'po', 'purchase order', 'goods receipt', 'grn', 'bill', 'supplier bill', 'kena', 'payment'],
            ],
            [
                'key' => 'purchasing.expense',
                'topic' => 'Buying and spending',
                'title' => 'Record an expense (a cost that is not stock): rent, hosting, electricity',
                'path' => 'Purchases > Expenses',
                'permission' => 'purchasing.expense.record',
                'steps' => [
                    'Open Purchases > Expenses and press the button to open the form (the list is the page; the form is behind the button).',
                    'Date, who was paid, the expense head, the amount, and PAID FROM - which account the money left.',
                    '⚠ A service bill like hosting is an EXPENSE here, never a supplier "Bill" - Bills are for stock purchases against a receipt.',
                    'Many at once: the expense importer refuses a duplicate reference on purpose, because importing the same reference twice would pay the bill twice.',
                ],
                'keywords' => ['expense', 'kharoch', 'rent', 'electricity', 'hosting', 'record expense', 'voucher', 'utility'],
            ],
            [
                'key' => 'purchasing.recurring',
                'topic' => 'Buying and spending',
                'title' => 'Standing monthly costs: schedule them once, get reminded, pay pre-filled',
                'path' => 'Purchases > Recurring expenses',
                'permission' => 'purchasing.expense.record',
                'steps' => [
                    'Open Purchases > Recurring expenses and add the cost: name, supplier, amount (tick "amount varies" for dollar or usage billing), day of month (31 means the last day), paid from, expense head.',
                    'The schedule predicts and reminds - it NEVER posts by itself.',
                    'When one falls due, press "Record payment": the ordinary expense form opens pre-filled and still editable; confirm to post.',
                ],
                'keywords' => ['recurring', 'standing cost', 'monthly bill', 'subscription', 'nikola', 'hosting bill', 'reminder'],
            ],
            [
                'key' => 'purchasing.suppliers',
                'topic' => 'Buying and spending',
                'title' => 'Add a supplier or fix their details',
                'path' => 'Purchases > Suppliers',
                'permission' => 'purchasing.suppliers.manage',
                'steps' => [
                    'Open Purchases > Suppliers. The CODE is permanent and never reused.',
                    'Fill payment terms (drives payables ageing) and bank or bKash details (needed to pay them from the system).',
                    'A supplier who is a person needs no contact name - their own name is the supplier name.',
                ],
                'keywords' => ['supplier', 'vendor', 'add supplier', 'sorobrahokari', 'supplier details'],
            ],
            [
                'key' => 'purchasing.credit_note',
                'topic' => 'Buying and spending',
                'title' => 'Return goods to a supplier / record a supplier credit note',
                'path' => 'Purchases > Credit notes',
                'permission' => 'purchasing.credit_note.record',
                'steps' => [
                    'Record the purchase return (stock goes back out), then the credit note against the supplier.',
                    'The credit reduces what you owe them; settle it against their next bill or a refund.',
                ],
                'keywords' => ['credit note', 'purchase return', 'return to supplier', 'debit note'],
            ],

            // --- People: HR administration -------------------------------
            [
                'key' => 'hrm.movement_admin',
                'topic' => 'People (HR administration)',
                'title' => 'Record or approve a movement for ANY employee (field duty, official work, holiday work)',
                'path' => 'HRM > Leave & movement',
                'permission' => 'hrm.leave.manage',
                'steps' => [
                    'Open HRM > Leave & movement; movements sit beside leave.',
                    'Record: employee, type, date, from/to time, place, reason. Approve: from the same screen (or Overview > Approvals).',
                    '⚠⚠ A movement that is never APPROVED does nothing - the day still judges itself from punches, so an unapproved official-work day with no punches reads as Absent.',
                    'Holiday work is its own screen (HRM > Holiday work) and its own question: it credits work on a non-working day, and never counts unless explicitly approved.',
                ],
                'keywords' => ['movement', 'approve movement', 'field duty', 'official work', 'holiday work', 'employee movement'],
            ],
            [
                'key' => 'hrm.leave_admin',
                'topic' => 'People (HR administration)',
                'title' => 'Approve leave, grant quota, manage leave types',
                'path' => 'HRM > Leave & movement',
                'permission' => 'hrm.leave.manage',
                'steps' => [
                    'Open HRM > Leave & movement: pending requests, balances and history in one place.',
                    'Approve or reject with a note; grant extra quota from the same screen when the year needs it.',
                    'Leave types and their rules are edited on the Leave types tab.',
                ],
                'keywords' => ['leave approve', 'leave admin', 'quota', 'leave types', 'grant leave'],
            ],
            [
                'key' => 'hrm.attendance_import',
                'topic' => 'People (HR administration)',
                'title' => 'Import the punch machine file',
                'path' => 'HRM > Attendance import',
                'permission' => 'hrm.attendance.manage',
                'steps' => [
                    'Export the period\'s file from the punch machine (.xls is fine as it comes).',
                    'Upload it under HRM > Attendance import. Duplicate punches are reported and skipped, never blocking - re-uploading an overlapping range is safe.',
                    'The engine reprocesses exactly the days the file touched, immediately - no waiting for the nightly run.',
                    '⚠ Days finalise about a week after they pass; import at least weekly, or absences finalise and then need corrections instead of just re-importing.',
                ],
                'keywords' => ['attendance import', 'punch', 'biometric', 'machine file', 'xls', 'upload attendance'],
            ],
            [
                'key' => 'hrm.attendance_review',
                'topic' => 'People (HR administration)',
                'title' => 'Review attendance: daily view, corrections, missing punches',
                'path' => 'HRM > Daily attendance',
                'permission' => 'hrm.attendance.manage',
                'steps' => [
                    'HRM > Daily attendance shows every judged day; filter by "needs review" to see what is holding.',
                    'Fix a wrong day with a CORRECTION (HRM > Corrections): propose, approve, and the day is re-judged - the punch log itself is never edited.',
                    'A day flagged "holds payroll" blocks the payroll run until it is resolved.',
                ],
                'keywords' => ['attendance', 'daily attendance', 'correction', 'missing punch', 'needs review', 'absent fix'],
            ],
            [
                'key' => 'self.timeline',
                'topic' => 'Your own profile and self-service',
                'title' => "Read a record's history: the Timeline",
                'path' => "The Timeline section at the foot of a record's page",
                'permission' => null,
                'steps' => [
                    'Open a customer, supplier, purchase order or employee - the Timeline at the bottom of the page tells its story: created, what changed and when, submitted for approval, who approved and with what note.',
                    'It is read-only and comes from the audit record the system already keeps - if an event is not on the timeline, it was never recorded.',
                    'The full company-wide audit trail (every change to everything) lives at Settings > Audit, for administrators.',
                ],
                'keywords' => ['timeline', 'history', 'who changed', 'audit', 'record history', 'log', 'itihash'],
            ],
            [
                'key' => 'self.mywork',
                'topic' => 'Your own profile and self-service',
                'title' => 'Start the day on one page: My work',
                'path' => 'My work (top of the sidebar)',
                'permission' => null,
                'steps' => [
                    'Open "My work" in the sidebar. Everything waiting on you is gathered there: approvals you can act on, follow-ups assigned to you, your own to-dos, and the requests you are waiting on others to decide.',
                    'Roles that hold the permissions also see attendance rows needing review and standing costs falling due this week.',
                    'Each card links to the screen where the work is actually done - My work gathers, it never decides.',
                ],
                'keywords' => ['my work', 'work queue', 'today', 'pending', 'waiting', 'what to do', 'inbox', 'kaj'],
            ],
            [
                'key' => 'self.search',
                'topic' => 'Your own profile and self-service',
                'title' => 'Find anything fast: the search box',
                'path' => 'The search box in the header (or press Ctrl+K)',
                'permission' => null,
                'steps' => [
                    'Press Ctrl+K anywhere, or click the search box in the header, and type at least two characters.',
                    'It finds products, customers (by name OR phone), suppliers, employees, leads - and DOCUMENT NUMBERS: type an invoice, sales order, purchase order, expense reference or journal number and land on the document itself.',
                    'You only ever see what your own role could open anyway - the results are filtered by your permissions.',
                ],
                'keywords' => ['search', 'find', 'lookup', 'ctrl k', 'invoice number', 'po number', 'journal number', 'phone lookup', 'khoja'],
            ],
            [
                'key' => 'self.orgchart',
                'topic' => 'Your own profile and self-service',
                'title' => 'See the organization chart: who reports to whom',
                'path' => 'Org chart (top of the sidebar)',
                'permission' => null,
                'steps' => [
                    'Open "Org chart" in the sidebar. Everyone still employed appears, indented under the person they report to.',
                    'Someone with no reporting line recorded sits at the top level; the note at the top counts how many lines are still unrecorded.',
                    'The chart shows names, codes and designations only - never salary or contact details.',
                ],
                'keywords' => ['org chart', 'organization chart', 'organogram', 'hierarchy', 'who reports', 'supervisor', 'boss', 'team structure'],
            ],
            [
                'key' => 'hrm.set_supervisor',
                'topic' => 'People (HR administration)',
                'title' => 'Set who an employee reports to',
                'path' => 'HRM > Employees',
                'permission' => 'hrm.employees.manage',
                'steps' => [
                    'Open HRM > Employees, open the person, and press "Reassign" on their page.',
                    'Set the Supervisor there, with the effective date - the reporting line is part of the posting, so it keeps its history when someone transfers.',
                    'The form refuses a choice that would make the chain circle back on itself.',
                    'The Org chart page updates immediately; approval chains that follow the reporting line use this same field.',
                ],
                'keywords' => ['supervisor', 'reports to', 'reporting line', 'manager assign', 'set boss', 'hierarchy set'],
            ],
            [
                'key' => 'hrm.employees',
                'topic' => 'People (HR administration)',
                'title' => 'Add an employee, assign shift and schedule',
                'path' => 'HRM > Employees',
                'permission' => 'hrm.employees.manage',
                'steps' => [
                    'HRM > Employees > New: personal details, joining date, employment type, bank or cash pay method.',
                    'Link a login if they should sign in; give the login a role under Settings > Roles.',
                    'Assign shift and holiday calendar under HRM > Shift & schedule - an employee with no schedule cannot be judged present or absent.',
                ],
                'keywords' => ['employee', 'add employee', 'new staff', 'shift', 'schedule', 'karmochari', 'onboard'],
            ],

            // --- Payroll --------------------------------------------------
            [
                'key' => 'payroll.run',
                'topic' => 'Payroll',
                'title' => 'Run a month\'s payroll, end to end',
                'path' => 'Payroll > Payroll runs',
                'permission' => 'hrm.payroll.manage',
                'steps' => [
                    'The cycle runs 24th to 23rd and wears the LATER month\'s name.',
                    'Payroll > Payroll runs > Calculate. Blocked employees (attendance holds) are named - resolve those first.',
                    'Review the sheet, then the approver approves (Owner), then Mark disbursed.',
                    'Record HOW the money left: Disbursement & payslips > Paid from (pick the real account - bank batch and cash batch separately) > Confirm batch paid. This clears Salaries Payable.',
                    'Payslips email themselves on disbursement (password: date of birth ddmmyyyy). Lock the month as the last step - after the lock, changes need an unlock request.',
                ],
                'keywords' => ['payroll', 'salary run', 'calculate salary', 'disburse', 'beton', 'payroll run', 'lock month', 'salaries payable'],
            ],
            [
                'key' => 'payroll.advance',
                'topic' => 'Payroll',
                'title' => 'Give a salary advance or loan, and how it is recovered',
                'path' => 'Payroll > Loans & advances',
                'permission' => 'hrm.payroll.manage',
                'steps' => [
                    'Payroll > Loans & advances > New: employee, amount, recovery plan.',
                    'Approve it, then DISBURSE it, choosing the account the money left and the real date.',
                    'Payroll recovers the installments automatically in each run until the balance clears.',
                    '⚠ Approve from THIS screen, not the Approvals inbox - the inbox approve for advances is a known no-op, not yet fixed.',
                ],
                'keywords' => ['advance', 'loan', 'salary advance', 'agrim', 'recover', 'installment'],
            ],
            [
                'key' => 'payroll.structure',
                'topic' => 'Payroll',
                'title' => 'Set a salary, give an increment',
                'path' => 'Payroll > Salary structures',
                'permission' => 'hrm.salary.manage',
                'steps' => [
                    'Payroll > Salary structures: the component breakdown (basic, allowances) per employee.',
                    'An increment is a new revision from an effective date (Payroll > Increments) - history is kept, and the run picks the structure in force for its period.',
                    'Salary history (Payroll > Salary history) shows one row per finished month at its surviving version.',
                ],
                'keywords' => ['salary', 'increment', 'salary structure', 'raise', 'beton kathamo', 'salary history', 'wage register'],
            ],

            // --- Money and the books -------------------------------------
            [
                'key' => 'accounting.reports',
                'topic' => 'Money and the books',
                'title' => 'Read the money: P&L, balance sheet, ledger, ageing, statements',
                'path' => 'Finance & Accounts',
                'permission' => 'accounting.view',
                'steps' => [
                    'Finance & Accounts holds them all: Profit & loss, Balance sheet, Trial balance, General ledger, Receivables/Payables ageing, Customer statement, Cash & bank book, Inventory valuation, COGS, Reconciliation.',
                    'Every report exports (CSV, Excel, PDF, print) and the print wears the letterhead and the Prepared/Checked/Approved signature row.',
                    'Every number traces to a journal; click through rather than re-deriving.',
                    '⚠ A balanced trial balance proves the arithmetic, not the truth - a missing transaction balances perfectly.',
                ],
                'keywords' => ['report', 'profit', 'loss', 'balance sheet', 'trial balance', 'ledger', 'ageing', 'statement', 'valuation', 'cogs', 'hisab'],
            ],
            [
                'key' => 'accounting.cash_forecast',
                'topic' => 'Money and the books',
                'title' => 'See the weeks ahead: the cash forecast',
                'path' => 'Finance & Accounts > Cash forecast',
                'permission' => 'accounting.view',
                'steps' => [
                    'Open Finance & Accounts > Cash forecast: eight weeks ahead, built only from documents that already exist - invoices due in, supplier bills and standing costs due out, and the last approved payroll as a labelled estimate on the 1st.',
                    'Overdue receivables land in week one - money late is money expected now, not never.',
                    'The first week the running balance goes negative is flagged in red: collect sooner or move a payment later. The Payment reminders screen is the collect-sooner tool.',
                    'Read-only: the forecast never writes anything, and a week with no documents honestly forecasts zero.',
                ],
                'keywords' => ['cash forecast', 'cash flow ahead', 'runway', 'cash position future', 'taka thakbe', 'projection'],
            ],
            [
                'key' => 'accounting.dunning',
                'topic' => 'Money and the books',
                'title' => 'Chase overdue invoices: payment reminders',
                'path' => 'Finance & Accounts > Payment reminders',
                'permission' => 'accounting.view',
                'steps' => [
                    'Open Finance & Accounts > Payment reminders. Everyone with an overdue invoice appears, oldest debt first, with the same figures as the receivables ageing and when they were last chased.',
                    'Press Send reminder: a polite email lists their open invoices, ages and the total, and the send is logged with your name. Nothing ever sends itself.',
                    'A customer without an email address cannot be reminded - add the address on the customer page first.',
                    'Viewing needs accounting view; sending needs the receipt-recording permission, because chasing money is the collections job.',
                ],
                'keywords' => ['reminder', 'dunning', 'overdue', 'chase payment', 'baki taka', 'collection', 'due letter', 'tagada'],
            ],
            [
                'key' => 'accounting.amortizations',
                'topic' => 'Money and the books',
                'title' => 'Spread a cost or income over its months (prepaid, accrued, deferred)',
                'path' => 'Finance & Accounts > Accruals & prepayments',
                'permission' => 'accounting.view',
                'steps' => [
                    'Use this when money and its meaning land in different months: a year of hosting paid in advance (prepaid expense), a cost you recognise before its bill arrives (accrued expense), or money received up front that is earned monthly (deferred income).',
                    'Open Finance & Accounts > Accruals & prepayments > New schedule: pick the kind, the balance-sheet account where the amount waits, the P&L account each month lands in, the total, the first month and how many months.',
                    'A real journal posts for each month automatically on the 1st, or press Release due months now. The final month carries the rounding remainder so the schedule releases exactly its total.',
                    'Cancelling stops future months only - released months are posted ledger and stay. Creating and releasing need the journal posting permission.',
                ],
                'keywords' => ['prepaid', 'accrual', 'accrued', 'deferred', 'amortization', 'advance payment spread', 'monthly release', 'agrim khoroch'],
            ],
            [
                'key' => 'accounting.bank_statements',
                'topic' => 'Money and the books',
                'title' => 'Match the bank statement against the book',
                'path' => 'Finance & Accounts > Bank statements',
                'permission' => 'accounting.view',
                'steps' => [
                    'Open Finance & Accounts > Bank statements and pick the account (BRAC Bank, bKash, and so on).',
                    'Import the statement as CSV - it needs a date column plus either a signed amount column or the usual debit/credit pair. Save the bank Excel file as CSV first.',
                    'Certain matches are made automatically: exact amount, right direction, within three days, exactly one candidate. Everything else waits for you: Match against a book line (must agree to the paisa), or Ignore a bank charge that is not booked yet - then book it as an expense.',
                    'The right-hand list shows book movements the bank has not confirmed - both halves of the same truth on one screen. Acting needs the journal posting permission.',
                ],
                'keywords' => ['bank statement', 'bank matching', 'reconcile bank', 'statement import', 'brac statement', 'milano', 'bank milan'],
            ],
            [
                'key' => 'accounting.budgets',
                'topic' => 'Money and the books',
                'title' => 'Set a budget and watch the actuals against it',
                'path' => 'Finance & Accounts > Budgets',
                'permission' => 'accounting.view',
                'steps' => [
                    'Open Finance & Accounts > Budgets. The grid is accounts down and the twelve fiscal months across (July to June).',
                    'Add the accounts you plan for, type the monthly figures, Save. Editing needs the chart-management permission; viewing needs only accounting view.',
                    'The judgment lives in Reports > Budget vs actual: plan, ledger actual, variance and percent used per account - positive variance is room left, negative is overspent. It exports and prints with the letterhead like every report.',
                ],
                'keywords' => ['budget', 'budgets', 'plan', 'budget vs actual', 'variance', 'overspent', 'yearly plan', 'borad'],
            ],
            [
                'key' => 'accounting.period_close',
                'topic' => 'Money and the books',
                'title' => 'Close a period, or unlock it for a correction',
                'path' => 'Finance & Accounts > Period close',
                'permission' => 'accounting.view',
                'steps' => [
                    'Open Finance & Accounts > Period close. The page shows where the boundary stands and a pre-close checklist (unpaid salaries, unbilled receipts, attendance still under review) - the checklist informs, it never blocks.',
                    'Pick the date and close: everything ON OR BEFORE it is sealed - no journal, voucher, receipt or payment can land there.',
                    'To correct something inside a closed period: Unlock with a written reason (required, kept on record with your name), make the correction, then close again.',
                    'Closing and unlocking need the chart-management permission; viewing the page needs only accounting view.',
                ],
                'keywords' => ['period close', 'close books', 'lock month', 'month end', 'year end', 'unlock period', 'reopen', 'boundary', 'hisab bondho'],
            ],
            [
                'key' => 'accounting.journal',
                'topic' => 'Money and the books',
                'title' => 'Post a manual journal',
                'path' => 'Finance & Accounts > Journals',
                'permission' => 'accounting.journal.post',
                'steps' => [
                    'Finance & Accounts > Journals > New: date, memo, balanced debit and credit lines against the chart of accounts.',
                    'Use it for what no document covers (an opening position, an accountant\'s adjustment) - normal operations post their own journals and never need one.',
                ],
                'keywords' => ['journal', 'manual journal', 'jv', 'adjustment', 'entry', 'debit credit'],
            ],
            [
                'key' => 'accounting.transfer',
                'topic' => 'Money and the books',
                'title' => 'Move money between accounts (bank to cash, cash to bKash)',
                'path' => 'Finance & Accounts > Money transfer',
                'permission' => 'accounting.view',
                'steps' => [
                    'Finance & Accounts > Money transfer: from account, to account, amount, date.',
                    '⚠ Any fee comes OFF THE SOURCE account - the destination receives the clean amount.',
                ],
                'keywords' => ['money transfer', 'bank to cash', 'withdraw', 'deposit', 'bkash transfer', 'fund transfer'],
            ],

            // --- Administration ------------------------------------------
            [
                'key' => 'admin.approvals',
                'topic' => 'Administration',
                'title' => 'See and act on everything waiting for approval',
                'path' => 'Overview > Approvals',
                'permission' => 'inventory.stock.approve',
                'steps' => [
                    'Overview > Approvals lists every pending request your role may act on, as decision cards with the detail on the card.',
                    'Approve or reject with a note; the underlying screen shows the same request if you want the full context first.',
                    '⚠ Salary advances are the one known exception: approve those on Payroll > Loans & advances, not here.',
                ],
                'keywords' => ['approval', 'approve', 'pending', 'inbox', 'onumodon', 'waiting'],
            ],
            [
                'key' => 'admin.roles',
                'topic' => 'Administration',
                'title' => 'Give someone access: roles, permissions, landing pages',
                'path' => 'Settings > Roles',
                'permission' => 'foundation.users.manage',
                'steps' => [
                    'Settings > Roles: each role is a set of permissions; assign the role to the user under Settings > Team.',
                    'Everything is default-closed - a permission nobody granted is a screen nobody sees.',
                    'Each role can be given a landing page (where its people arrive after login) from the same screen.',
                    'The AI assistant has four grants of its own (use, read data, notes, to-dos), all off until granted - even for Owners.',
                ],
                'keywords' => ['role', 'permission', 'access', 'grant', 'team', 'user access', 'landing page', 'onumoti'],
            ],
            [
                'key' => 'admin.assistant',
                'topic' => 'Administration',
                'title' => 'Configure me: name, avatar, providers, who may use me',
                'path' => 'Settings > AI providers',
                'permission' => 'foundation.organization.manage',
                'steps' => [
                    'Settings > AI providers: the API key that powers me, my name, my picture and my message length limit, per business.',
                    'Who may talk to me is a permission question: grant foundation.ai.use (and .read for data questions) on a role under Settings > Roles.',
                ],
                'keywords' => ['assistant', 'ai', 'hudhud', 'configure ai', 'api key', 'avatar', 'ai name'],
            ],
            [
                'key' => 'admin.business',
                'topic' => 'Administration',
                'title' => 'Company details, branding, organization structure',
                'path' => 'Settings',
                'permission' => 'foundation.organization.manage',
                'steps' => [
                    'Settings > Business: name, address, logo (it appears on report letterheads), fiscal year, timezone.',
                    'Settings > Organization: departments, designations, offices.',
                    'Switching between businesses is at the bottom of the sidebar; each business keeps its own books.',
                ],
                'keywords' => ['business settings', 'company', 'logo', 'branding', 'department', 'designation', 'office', 'organization'],
            ],
            [
                'key' => 'admin.assets',
                'topic' => 'Administration',
                'title' => 'Register a fixed asset and run depreciation',
                'path' => 'Assets > Fixed assets',
                'permission' => 'assets.manage',
                'steps' => [
                    'Assets > Fixed assets > New: what it is, cost, purchase date, useful life; an asset bought before cutover needs its accumulated depreciation too.',
                    'Assets > Depreciation runs the month\'s charge and posts it.',
                ],
                'keywords' => ['asset', 'fixed asset', 'depreciation', 'sthayi sompod', 'machine', 'vehicle'],
            ],
            [
                'key' => 'crm.leads',
                'topic' => 'Administration',
                'title' => 'Track a sales lead from first contact to customer',
                'path' => 'CRM > Leads',
                'permission' => 'crm.leads.view',
                'steps' => [
                    'CRM > Leads: capture the lead with its source; CRM > Follow-ups is the queue of who to call today.',
                    'Convert a won lead into a customer from the lead itself - conversion carries the details over.',
                    'Campaigns and sources under CRM > Sources & campaigns; SMS and email campaigns from the Phone book section once a provider is configured.',
                ],
                'keywords' => ['lead', 'crm', 'follow up', 'prospect', 'convert', 'campaign', 'phone book', 'sms'],
            ],
        ];
    }
}
