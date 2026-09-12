import { Alert } from '@/components/ui/alert';
export function FormErrors({ errors }: { errors: Record<string, string> }) {
    const messages = [...new Set(Object.values(errors))];
    return messages.length ? (
        <Alert variant="destructive">
            <ul className="grid gap-1">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </Alert>
    ) : null;
}
