import { Form } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';

import CommentController from '@/actions/App/Http/Controllers/CommentController';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import type { Comment } from '@/types/comment';
import { formatDate } from '@/utils/date';

interface CommentItemProps {
    comment: Comment;
}

export default function CommentItem({ comment }: CommentItemProps) {
    const getInitials = useInitials();

    return (
        <div className="flex gap-3 rounded-lg p-3 transition-colors hover:bg-muted/50">
            <Avatar className="size-8 shrink-0">
                <AvatarImage
                    src={
                        comment.user.profilepicture
                            ? `/storage/${comment.user.profilepicture}`
                            : undefined
                    }
                />
                <AvatarFallback>
                    {getInitials(comment.user.username)}
                </AvatarFallback>
            </Avatar>

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium">
                        {comment.user.username}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {formatDate(comment.created_at)}
                    </span>
                </div>

                <p className="wrap-break-words mt-1 text-sm text-foreground whitespace-pre-wrap">
                    {comment.content}
                </p>

                {comment.can_delete && (
                    <Form
                        {...CommentController.destroy.form(comment.id)}
                        options={{ preserveScroll: true }}
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            className="mt-1 h-auto p-0 text-xs text-destructive hover:text-destructive"
                        >
                            <Trash2 className="mr-1 size-3" />
                            Delete
                        </Button>
                    </Form>
                )}
            </div>
        </div>
    );
}
