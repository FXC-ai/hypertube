import { Form } from '@inertiajs/react';
import { Send } from 'lucide-react';

import CommentController from '@/actions/App/Http/Controllers/CommentController';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

interface CommentFormProps {
    movieId: number;
}

export default function CommentForm({ movieId }: CommentFormProps) {
    return (
        <Form
            {...CommentController.store.form(movieId)}
            options={{
                preserveScroll: true,
            }}
            className="space-y-3"
            resetOnSuccess
        >
            <Textarea
                placeholder="Share your thoughts about this video..."
                rows={3}
                className="resize-none whitespace-pre-wrap"
                name="content"
            />
            <Button type="submit" className="gap-2">
                <Send className="size-4" />
                Post comment
            </Button>
        </Form>
    );
}
