-- ⚠⚠ EVERY TABLE NAMED HERE MUST STILL EXIST. One missing table makes the
-- WHOLE query error, the fingerprint file come back empty, and the restore
-- drill report DIFFERENCES on a perfectly good backup. `leave_provision_runs`
-- did exactly that from 29 Aug 2026, when leave provisioning was removed,
-- until 30 Aug. When you drop a table, delete its line here in the same
-- commit.
SELECT 'db' AS metric, DATABASE() AS value
UNION ALL SELECT 'tables', (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE())
UNION ALL SELECT 'migrations', (SELECT COUNT(*) FROM migrations)
UNION ALL SELECT 'businesses', (SELECT COUNT(*) FROM businesses)
UNION ALL SELECT 'users', (SELECT COUNT(*) FROM users)
UNION ALL SELECT 'roles', (SELECT COUNT(*) FROM roles)
UNION ALL SELECT 'permissions', (SELECT COUNT(*) FROM permissions)
UNION ALL SELECT 'accounts', (SELECT COUNT(*) FROM accounts)
UNION ALL SELECT 'account_mappings', (SELECT COUNT(*) FROM account_mappings)
UNION ALL SELECT 'journals', (SELECT COUNT(*) FROM journals)
UNION ALL SELECT 'journal_lines', (SELECT COUNT(*) FROM journal_lines)
UNION ALL SELECT 'employees', (SELECT COUNT(*) FROM employees)
UNION ALL SELECT 'attendance_punches', (SELECT COUNT(*) FROM attendance_punches)
UNION ALL SELECT 'daily_attendance', (SELECT COUNT(*) FROM daily_attendances)
UNION ALL SELECT 'leave_ledger', (SELECT COUNT(*) FROM leave_ledger_entries)
UNION ALL SELECT 'payroll_runs', (SELECT COUNT(*) FROM payroll_runs)
UNION ALL SELECT 'products', (SELECT COUNT(*) FROM products)
UNION ALL SELECT 'stock_batches', (SELECT COUNT(*) FROM stock_batches)
UNION ALL SELECT 'stock_movements', (SELECT COUNT(*) FROM stock_movements)
UNION ALL SELECT 'suppliers', (SELECT COUNT(*) FROM suppliers)
UNION ALL SELECT 'customers', (SELECT COUNT(*) FROM customers)
UNION ALL SELECT 'product_prices', (SELECT COUNT(*) FROM product_prices)
UNION ALL SELECT 'salary_structures', (SELECT COUNT(*) FROM salary_structures)
UNION ALL SELECT 'triggers', (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE())
UNION ALL SELECT 'routines', (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE())
UNION ALL SELECT 'fks', (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE())
UNION ALL SELECT 'user_email_hash', (SELECT COALESCE(MD5(GROUP_CONCAT(email ORDER BY id)), 'none') FROM users)
UNION ALL SELECT 'employee_code_hash', (SELECT COALESCE(MD5(GROUP_CONCAT(employee_code ORDER BY id)), 'none') FROM employees)
UNION ALL SELECT 'account_code_hash', (SELECT COALESCE(MD5(GROUP_CONCAT(code ORDER BY id)), 'none') FROM accounts)
UNION ALL SELECT 'punch_hash', (SELECT COALESCE(MD5(GROUP_CONCAT(CONCAT(employee_id, punched_at) ORDER BY id)), 'none') FROM attendance_punches);
