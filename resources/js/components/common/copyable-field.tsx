import { AppIcon } from '@/components/common/app-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';

interface CopyableFieldProps {
    value: string;
    label?: string;
}

export function CopyableField({ value, label }: CopyableFieldProps) {
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="flex items-center gap-2">
            <Input readOnly value={value} aria-label={label ?? 'Copyable value'} className="font-mono text-xs" />
            <Button type="button" variant="outline" size="icon" onClick={handleCopy} aria-label="Copy to clipboard">
                <AppIcon icon={copied ? Check : Copy} size="sm" className={copied ? 'text-emerald-600' : undefined} />
            </Button>
        </div>
    );
}
