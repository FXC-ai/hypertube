import { Form, Head, InfiniteScroll, Link } from '@inertiajs/react';
import MovieController from '@/actions/App/Http/Controllers/MovieController';
import Heading from '@/components/heading';

import InputError from '@/components/input-error';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    index,
    select as selectMovie,
    show as showMovie,
} from '@/routes/movies';
import { edit as editSources } from '@/routes/sources';

type Movie = {
    id: number | null;
    title: string;
    source: string;
    imdb_id: string;
    watched: boolean;
    data: Record<string, unknown> & {
        poster?: string | null;
        imdbRating?: number | string | null;
        ratingLabel?: string;
    };
    created_at: string | null;
};

type MovieIndexProps = {
    movies: {
        data: Movie[];
    };
    filters: {
        search: string | null;
        sources: string[];
    };
};

const sourceLabels: Record<string, string> = {
    wikidata: 'Wikidata',
    whitelist: 'Whitelist',
};

function formatRegistrationDate(date: string): string {
    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(date));
}

function MovieCard({ movie }: { movie: Movie }) {
    return (
        <div className="flex w-64 flex-col items-center gap-1 p-4">
            <div className="relative w-full">
                <object
                    className="aspect-2/3 w-full rounded"
                    data={movie.data?.poster ?? undefined}
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

                <Badge
                    variant="secondary"
                    className="absolute top-3 left-3"
                    title="Legal catalog"
                >
                    {sourceLabels[movie.source] ?? movie.source}
                </Badge>
                {movie.watched && (
                    <span className="absolute top-3 right-3 rounded-full bg-white p-1 text-xl text-blue-500">
                        <img className="w-5" src="/watched.svg" alt="✓" />
                    </span>
                )}
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
        </div>
    );
}

function MovieItem({ movie }: { movie: Movie }) {
    // A movie this server does not have yet is loaded from the source that offered it.
    const href =
        movie.id === null
            ? selectMovie({ source: movie.source, imdb_id: movie.imdb_id })
            : showMovie({ movie: movie.id });

    return (
        <Link
            href={href}
            className="flex w-64 flex-col items-center gap-1 p-4 hover:bg-muted/50"
        >
            <MovieCard movie={movie} />
        </Link>
    );
}

export default function MoviesIndex({ movies, filters }: MovieIndexProps) {
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
                            replace: true,
                        }}
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="min-w-0 flex-1">
                                    <Label htmlFor="search" className="sr-only">
                                        Search
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

                    <div className="flex flex-wrap items-center gap-2">
                        {filters.search && (
                            <Button variant="outline" asChild>
                                <Link href={index()} replace>
                                    Clear filters
                                </Link>
                            </Button>
                        )}

                        <span className="text-sm text-muted-foreground">
                            Sources queried:{' '}
                            {filters.sources
                                .map((source) => sourceLabels[source] ?? source)
                                .join(', ')}
                        </span>

                        <Button variant="link" asChild className="px-0">
                            <Link href={editSources()}>Manage sources</Link>
                        </Button>
                    </div>
                </div>

                {movies.data.length > 0 ? (
                    <InfiniteScroll data="movies" buffer={300} onlyNext>
                        <div className="flex flex-wrap justify-center">
                            {movies.data.map((movie) => (
                                <MovieItem
                                    key={movie.imdb_id}
                                    movie={movie}
                                ></MovieItem>
                            ))}
                        </div>
                    </InfiniteScroll>
                ) : filters.search != null ? (
                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        {`No movie found for ${filters.search}.`}
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
