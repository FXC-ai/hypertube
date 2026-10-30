import { Form } from '@inertiajs/react';
import { Send } from 'lucide-react';

import CommentController from '@/actions/App/Http/Controllers/CommentController';
import InputError from '@/components/input-error';
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
            {({ errors, processing }) => (
                <>
                    <Textarea
                        placeholder="Share your thoughts about this video..."
                        rows={3}
                        className="resize-none whitespace-pre-wrap"
                        name="content"
                        aria-invalid={Boolean(errors.content)}
                    />
                    <InputError message={errors.content} />
                    <Button
                        type="submit"
                        className="gap-2"
                        disabled={processing}
                    >
                        <Send className="size-4" />
                        Post comment
                    </Button>
                </>
            )}
        </Form>
    );
}
