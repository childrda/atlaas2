import {
    BookOpen,
    ClipboardCheck,
    HeartHandshake,
    Mail,
    MessageSquare,
    Sparkles,
    Table,
    Users,
    type LucideIcon,
} from 'lucide-react';

const MAP: Record<string, LucideIcon> = {
    'book-open': BookOpen,
    table: Table,
    'clipboard-check': ClipboardCheck,
    users: Users,
    mail: Mail,
    'message-square': MessageSquare,
    'heart-handshake': HeartHandshake,
    sparkles: Sparkles,
};

export function ToolkitToolIcon({ name, className }: { name: string; className?: string }) {
    const Icon = MAP[name] ?? Sparkles;

    return <Icon className={className} />;
}
