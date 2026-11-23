import { AppIcon } from '@/components/common/app-icon';
import { cn } from '@/lib/utils';
import { Check, type LucideIcon } from 'lucide-react';

export interface StepDefinition {
    id: number;
    title: string;
    subtitle: string;
    icon: LucideIcon;
    completed: boolean;
}

interface SetupStepperProps {
    steps: StepDefinition[];
    activeStep: number;
    onSelectStep: (stepId: number) => void;
}

export function SetupStepper({ steps, activeStep, onSelectStep }: SetupStepperProps) {
    return (
        <nav aria-label="Onboarding steps" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {steps.map((step) => {
                const isActive = step.id === activeStep;

                return (
                    <button
                        key={step.id}
                        type="button"
                        onClick={() => onSelectStep(step.id)}
                        className={cn(
                            'flex items-start gap-3 rounded-lg border p-3.5 text-left transition-all',
                            isActive
                                ? 'border-foreground bg-accent/50 shadow-xs'
                                : 'border-border hover:border-foreground/30 hover:bg-muted/40',
                        )}
                    >
                        <div
                            className={cn(
                                'flex size-8 shrink-0 items-center justify-center rounded-md border',
                                step.completed
                                    ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                    : isActive
                                      ? 'border-foreground bg-foreground text-background'
                                      : 'bg-muted text-muted-foreground',
                            )}
                        >
                            <AppIcon icon={step.completed ? Check : step.icon} size="sm" />
                        </div>
                        <div className="min-w-0">
                            <div className="text-muted-foreground text-[11px] font-medium uppercase tracking-wider">
                                Step {step.id}
                            </div>
                            <div className="truncate text-sm font-semibold">{step.title}</div>
                            <div className="text-muted-foreground truncate text-xs">{step.subtitle}</div>
                        </div>
                    </button>
                );
            })}
        </nav>
    );
}
