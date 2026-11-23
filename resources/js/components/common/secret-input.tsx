import { AppIcon } from '@/components/common/app-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Eye, EyeOff, KeyRound } from 'lucide-react';
import { type ComponentProps, useState } from 'react';

type SecretInputProps = Omit<ComponentProps<'input'>, 'type'>;

export function SecretInput({ className, ...props }: SecretInputProps) {
    const [revealed, setRevealed] = useState(false);

    return (
        <div className="relative flex items-center">
            <span className="text-muted-foreground pointer-events-none absolute left-3">
                <AppIcon icon={KeyRound} size="xs" />
            </span>
            <Input
                {...props}
                type={revealed ? 'text' : 'password'}
                autoComplete="new-password"
                className={`pr-10 pl-8 font-mono text-xs ${className ?? ''}`}
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="absolute right-1 size-7"
                onClick={() => setRevealed((prev) => !prev)}
                aria-label={revealed ? 'Hide secret' : 'Reveal secret'}
            >
                <AppIcon icon={revealed ? EyeOff : Eye} size="xs" />
            </Button>
        </div>
    );
}
