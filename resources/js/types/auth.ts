export type User = {
    id: number;
    username: string;
    firstname: string;
    lastname: string;
    email: string;
    profilepicture?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    preferredlanguage: 'english' | 'french' | 'german' | 'spanish';
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */
