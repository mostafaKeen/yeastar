import { AppIcon } from '@/components/common/app-icon';
import { PhoneCall } from 'lucide-react';

export default function AppLogo() {
    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                <AppIcon icon={PhoneCall} size="sm" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">Yeastar Bridge</span>
                <span className="text-muted-foreground truncate text-xs leading-none">Bitrix24 Telephony</span>
            </div>
        </>
    );
}
