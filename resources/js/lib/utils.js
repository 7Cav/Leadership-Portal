import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

// shadcn-vue's class merger: later Tailwind utilities win over earlier conflicting ones, so a
// caller's `class` can override a component's defaults without `!important`.
export function cn(...inputs) {
    return twMerge(clsx(inputs));
}
