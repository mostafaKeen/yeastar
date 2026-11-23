import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TenantSwitcher } from '@/components/tenant/tenant-switcher';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Activity, Compass, LayoutGrid, PhoneCall, Plug, ShieldCheck, Terminal, Users } from 'lucide-react';

const mainNavItems: NavItem[] = [
    {
        title: 'Operations Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Call Logs',
        url: '/calls',
        icon: PhoneCall,
    },
    {
        title: 'Onboarding Wizard',
        url: '/onboarding',
        icon: Compass,
    },
    {
        title: 'Integrations',
        url: '/integrations',
        icon: Plug,
    },
    {
        title: 'Extension Mappings',
        url: '/mappings',
        icon: Users,
    },
    {
        title: 'Diagnostics & Audit',
        url: '/diagnostics',
        icon: Terminal,
    },
    {
        title: 'Security & RBAC',
        url: '/security',
        icon: ShieldCheck,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Profile Settings',
        url: '/settings/profile',
        icon: Users,
    },
    {
        title: 'Appearance',
        url: '/settings/appearance',
        icon: Activity,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <TenantSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
