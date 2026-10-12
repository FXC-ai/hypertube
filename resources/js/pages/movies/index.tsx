import { Form, Head, InfiniteScroll, Link } from '@inertiajs/react';
import MovieController from '@/actions/App/Http/Controllers/MovieController';
import Heading from '@/components/heading';

import InputError from '@/components/input-error';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/movies';

type Movie = {
    id: number;
    title: string;
    data: any;
    created_at: string;
};

type MovieIndexProps = {
    movies: {
        data: Movie[];
    };
    filters: {
        search: string | null;
    };
};

function formatRegistrationDate(date: string): string {
    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(date));
}

function MovieItem({ movie }: { movie: Movie }) {
    return (
        <Link
            href={`/movies/${movie.id}`}
            className="flex w-64 flex-col items-center gap-1 p-4 hover:bg-muted/50"
        >
            <div className="relative w-full">
                <object
                    className="aspect-2/3 w-full rounded"
                    data={movie.data?.poster}
                >
                    <img
                        className="aspect-2/3 w-full rounded"
                        src="/default-poster.png"
                        alt="Movie Image"
                    />
                </object>

                {movie.data?.imdbRating && (
                    <div className="absolute right-3 bottom-3 flex gap-1 rounded-lg bg-stone-900 px-2 py-1">
                        {movie.data.imdbRating}
                        <img
                            className="w-5"
                            src="/star-score.svg"
                            alt="stars"
                        />
                    </div>
                )}

                <span className="absolute top-3 right-3 rounded-full bg-white p-1 text-xl text-blue-500">
                    <img className="w-5" src="/watched.svg" alt="✓" />
                </span>
            </div>

            <span className="min-w-0 flex-1 text-center font-medium wrap-break-word">
                {movie.title}
            </span>

            {movie.created_at ? (
                <time
                    dateTime={movie.created_at}
                    className="shrink-0 text-sm text-muted-foreground"
                >
                    {formatRegistrationDate(movie.created_at)}
                </time>
            ) : (
                <></>
            )}
        </Link>
    );
}

export default function MoviesIndex({ movies, filters }: MovieIndexProps) {
    console.log(filters, movies);

    return (
        <>
            <Head title="Movies" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Search"
                        description="Search for a movie by title"
                    />

                    <Form
                        {...MovieController.index.form()}
                        className="flex flex-col gap-2 sm:flex-row sm:items-start"
                        options={{
                            preserveScroll: true,
                            // only: ['users', 'filters'],
                            // reset: ['users'],
                            replace: true,
                        }}
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="min-w-0 flex-1">
                                    <Label htmlFor="search" className="sr-only">
                                        Username
                                    </Label>
                                    <Input
                                        id="search"
                                        type="search"
                                        name="search"
                                        defaultValue={filters.search ?? ''}
                                        placeholder="Search by title..."
                                        aria-invalid={Boolean(errors.search)}
                                        autoComplete="off"
                                        maxLength={100}
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.search}
                                    />
                                </div>

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="sm:shrink-0"
                                >
                                    {processing ? 'Searching…' : 'Search'}
                                </Button>
                            </>
                        )}
                    </Form>

                    {filters.search && (
                        <Button variant="outline" asChild>
                            <Link href={index()} replace>
                                Clear filters
                            </Link>
                        </Button>
                    )}
                </div>

                {movies.data.length > 0 ? (
                    <InfiniteScroll data="movies" buffer={300} onlyNext>
                        <div className="flex flex-wrap justify-center">
                            {movies.data.map((movie) => (
                                <MovieItem
                                    key={movie.id}
                                    movie={movie}
                                ></MovieItem>
                            ))}
                        </div>
                    </InfiniteScroll>
                ) : filters.search != null ? (
                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        {`No user found for username ${filters.search}.`}
                    </div>
                ) : (
                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No movie found.
                    </div>
                )}
            </div>
        </>
    );
}

MoviesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Movies',
            href: index(),
        },
    ],
};
