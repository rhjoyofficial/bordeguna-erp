import { type ModuleSection } from '@/lib/sections';
import { type SharedData } from '@/types';
import { Banknote, CalendarCheck, CalendarRange, HandCoins, LayoutDashboard, Settings2, Users } from 'lucide-react';

/**
 * ⭐ HRM in ONE sidebar group (Joy, 15 Oct 2026: "keep only one group HRM").
 * It was 26 links in three groups (HRM, Payroll, HR reports). Now seven
 * links, and every screen that used to be a link is a TAB inside its
 * section, drawn by HrmSectionTabs at the top of the page.
 *
 * This list is the one place both read: the sidebar shows a section when the
 * user may open at least one of its tabs, and links it to the FIRST tab they
 * may open; the tab bar shows only the tabs they may open. The gates are the
 * same shared permission flags the old links used, one for one, so nobody
 * gains or loses a screen.
 */

type Flags = SharedData;

const reports = (f: Flags) => f.canManageAttendance || f.canAccessSalary;

export const HRM_SECTIONS: ModuleSection[] = [
    {
        key: 'overview',
        title: 'Overview',
        icon: LayoutDashboard,
        tabs: [
            { title: 'Dashboard', url: '/hrm/dashboard', can: reports },
            { title: 'Reports', url: '/hrm/reports', can: reports },
        ],
    },
    {
        key: 'employees',
        title: 'Employees',
        icon: Users,
        tabs: [
            { title: 'Employees', url: '/hrm/employees', can: (f) => f.canManageEmployees },
            // The org chart is a Workspace link, so it is not a tab here. Org chart and
            // Recruitment are hidden since 24 Oct 2026 (Joy): canViewRecruitment carries
            // the config/features.php switch as well as the permission.
            { title: 'Recruitment', url: '/hrm/recruitment', can: (f) => f.canViewRecruitment },
        ],
    },
    {
        key: 'attendance',
        title: 'Attendance',
        icon: CalendarCheck,
        tabs: [
            { title: 'Daily', url: '/hrm/attendance/daily', can: (f) => f.canManageAttendance },
            { title: 'Summary', url: '/hrm/attendance/summary', can: (f) => f.canManageAttendance },
            { title: 'Punch log', url: '/hrm/attendance', can: (f) => f.canManageAttendance },
            { title: 'Corrections', url: '/hrm/attendance/corrections', can: (f) => f.canManageAttendance },
            { title: 'Import', url: '/hrm/attendance/import', can: (f) => f.canManageAttendance },
            { title: 'Show cause notices', url: '/hrm/notices', can: (f) => f.canManageAttendance },
        ],
    },
    {
        key: 'leave',
        title: 'Leave & schedule',
        icon: CalendarRange,
        tabs: [
            { title: 'Leave & movement', url: '/hrm/leave', can: (f) => f.canManageLeave },
            { title: 'Holiday work', url: '/hrm/holiday-work', can: (f) => f.canManageLeave },
            { title: 'Shifts & schedule', url: '/hrm/schedules', also: ['/hrm/shifts'], can: (f) => f.canManageScheduling },
            { title: 'Calendars', url: '/hrm/calendars', can: (f) => f.canManageScheduling },
        ],
    },
    {
        key: 'payroll',
        title: 'Payroll',
        icon: Banknote,
        tabs: [
            { title: 'Runs', url: '/hrm/payroll', also: ['/hrm/payslips'], can: (f) => f.canManagePayroll },
            { title: 'Inputs', url: '/hrm/payroll-inputs', can: (f) => f.canManagePayroll },
            { title: 'Salary structures', url: '/hrm/salary', can: (f) => f.canAccessSalary },
            { title: 'Increments', url: '/hrm/salary-revisions', can: (f) => f.canAccessSalary },
            { title: 'Salary history', url: '/hrm/payroll/history', can: (f) => f.canManagePayroll },
        ],
    },
    {
        key: 'money',
        title: 'Staff money',
        icon: HandCoins,
        tabs: [
            { title: 'Loans & advances', url: '/hrm/loans', can: (f) => f.canManagePayroll },
            { title: 'Expense claims', url: '/hrm/expense-claims', can: (f) => f.canManagePayroll },
            // HRM-006 (14 Oct 2026): authorised by hrm.employees.manage on the server.
            { title: 'Staff purchases', url: '/hrm/staff-purchases', can: (f) => f.canManageEmployees },
        ],
    },
    {
        key: 'settings',
        title: 'HR settings',
        icon: Settings2,
        tabs: [
            { title: 'Attendance policy', url: '/hrm/policies', can: (f) => f.canManageAttendance },
            { title: 'Expense categories', url: '/hrm/expense-categories', can: (f) => f.canManagePayroll },
        ],
    },
];
