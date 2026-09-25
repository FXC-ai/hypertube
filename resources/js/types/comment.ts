export interface CommentUser {
    id: number;
    username: string;
    profilepicture: string | null;
}

export interface Comment {
    id: number;
    movie_id: number;
    user_id: number;
    content: string;
    created_at: string;
    user: CommentUser;
    can_delete: boolean;
}
