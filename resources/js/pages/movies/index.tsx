import { Form, Head, InfiniteScroll, Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import MovieController from '@/actions/App/Http/Controllers/MovieController';
import Heading from '@/components/heading';

import InputError from '@/components/input-error';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { index } from '@/routes/movies';

type WatchedFilter = 'all' | 'watched' | 'unwatched';

type Movie = {
    id: number;
    title: string;
    watched: boolean;
    data: any;
    created_at: string;
};

type MovieIndexProps = {
    movies: {
        data: Movie[];
    };
    filters: {
        search: string | null;
        watched: WatchedFilter;
    };
};

const watchedFilterOptions: { value: WatchedFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'watched', label: 'Watched' },
    { value: 'unwatched', label: 'Unwatched' },
];

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

                {movie.watched && (
                    <span
                        className="absolute top-3 right-3 flex items-center rounded-full bg-white p-1 text-blue-600 shadow ring-2 ring-black/5"
                        title="Already watched"
                    >
                        <Check className="size-5" strokeWidth={3} aria-hidden />
                        <span className="sr-only">Watched</span>
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
        </Link>
    );
}

export default function MoviesIndex({ movies, filters }: MovieIndexProps) {
    const watched = filters.watched ?? 'all';
    const hasFilters = filters.search != null || watched !== 'all';

    function selectWatchedFilter(value: WatchedFilter) {
        router.visit(
            index.url({
                query: {
                    search: filters.search || undefined,
                    watched: value === 'all' ? undefined : value,
                },
            }),
            { preserveScroll: true, replace: true },
        );
    }

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

                    <div className="flex flex-wrap items-center gap-2">
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={watched}
                            onValueChange={(value) => {
                                if (value) {
                                    selectWatchedFilter(value as WatchedFilter);
                                }
                            }}
                        >
                            {watchedFilterOptions.map(({ value, label }) => (
                                <ToggleGroupItem
                                    key={value}
                                    value={value}
                                    aria-label={`Show ${label.toLowerCase()} movies`}
                                >
                                    {label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>

                        {hasFilters && (
                            <Button variant="outline" asChild>
                                <Link href={index()} replace>
                                    Clear filters
                                </Link>
                            </Button>
                        )}
                    </div>
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
                        {`No movie found for title ${filters.search}.`}
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
