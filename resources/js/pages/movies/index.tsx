import { Form, Head, InfiniteScroll, Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import MovieController from '@/actions/App/Http/Controllers/MovieController';
import Heading from '@/components/heading';

import InputError from '@/components/input-error';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    index,
    select as selectMovie,
    show as showMovie,
} from '@/routes/movies';
import { edit as editSources } from '@/routes/sources';

type WatchedFilter = 'all' | 'watched' | 'unwatched';
type SortKey = 'popularity' | 'created_at' | 'name' | 'year' | 'rating';
type SortDirection = 'asc' | 'desc';

type Movie = {
    id: number | null;
    title: string;
    source: string;
    imdb_id: string;
    watched: boolean;
    release_year: number | null;
    popularity: number | null;
    genres: string[];
    data: Record<string, unknown> & {
        poster?: string | null;
        imdbRating?: number | string | null;
        year?: string;
        ratingLabel?: string;
    };
    created_at: string | null;
};

type MovieFilters = {
    search: string | null;
    watched: WatchedFilter;
    sort: SortKey;
    dir: SortDirection;
    genre: string | null;
    yearFrom: number | null;
    yearTo: number | null;
    ratingFrom: number | null;
    ratingTo: number | null;
    genres: string[];
    sorts: Record<string, string>;
    sources: string[];
};

type MovieIndexProps = {
    movies: {
        data: Movie[];
    };
    filters: MovieFilters;
};

const ANY_GENRE = 'any';

const sourceLabels: Record<string, string> = {
    wikidata: 'Wikidata',
    whitelist: 'Whitelist',
};

const watchedFilterOptions: { value: WatchedFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'watched', label: 'Watched' },
    { value: 'unwatched', label: 'Unwatched' },
];

const directionOptions: { value: SortDirection; label: string }[] = [
    { value: 'asc', label: 'Ascending' },
    { value: 'desc', label: 'Descending' },
];

/**
 * The listing is filtered and sorted server side, so a criterion change is a visit
 * carrying every criterion: filters never get lost from one control to the next.
 */
function queryFor(filters: MovieFilters, patch: Partial<MovieFilters>) {
    const merged = { ...filters, ...patch };

    return {
        search: merged.search || undefined,
        sort: merged.sort,
        dir: merged.dir,
        genre: merged.genre || undefined,
        yearFrom: merged.yearFrom ?? undefined,
        yearTo: merged.yearTo ?? undefined,
        ratingFrom: merged.ratingFrom ?? undefined,
        ratingTo: merged.ratingTo ?? undefined,
        watched: merged.watched === 'all' ? undefined : merged.watched,
    };
}

function hasActiveFilters(filters: MovieFilters): boolean {
    return (
        filters.search != null ||
        filters.watched !== 'all' ||
        filters.genre != null ||
        filters.yearFrom != null ||
        filters.yearTo != null ||
        filters.ratingFrom != null ||
        filters.ratingTo != null
    );
}

function formatRegistrationDate(date: string): string {
    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(date));
}

function productionYear(movie: Movie): string | null {
    if (movie.release_year) {
        return String(movie.release_year);
    }

    const year = movie.data?.year;

    return typeof year === 'string' && year !== 'N/A' ? year : null;
}

function formatDownloads(count: number): string {
    return new Intl.NumberFormat('en-US').format(count);
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
                    <div className="absolute bg-secondary text-secondary-foreground right-3 bottom-3 flex gap-1 rounded-lg px-2 py-1">
                        {movie.data.imdbRating}
                        <img
                            className="w-5"
                            src="/star-score.svg"
                            alt="stars"
                        />
                    </div>
                )}

                {movie.popularity ? (
                    <div
                        className="absolute bg-secondary bottom-3 left-3 rounded-lg px-2 py-1 text-xs"
                        title={`${formatDownloads(movie.popularity)} downloads on archive.org`}
                    >
                        {formatDownloads(movie.popularity)}
                        <span className="ml-1 text-secondary-foreground/80">downloads</span>
                    </div>
                ) : (
                    <></>
                )}

                <Badge
                    variant="secondary"
                    className="absolute top-3 left-3"
                    title="Legal catalog"
                >
                    {sourceLabels[movie.source] ?? movie.source}
                </Badge>
                {movie.watched && (
                    <span
                        className="absolute top-3 right-3 flex items-center rounded-full bg-secondary p-1 text-blue-600 shadow ring-2 ring-black/5"
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

            {productionYear(movie) ? (
                <span className="text-sm text-muted-foreground">
                    {productionYear(movie)}
                </span>
            ) : (
                <></>
            )}

            {movie.created_at ? (
                <time
                    dateTime={movie.created_at}
                    className="shrink-0 text-xs text-muted-foreground"
                >
                    {`Added ${formatRegistrationDate(movie.created_at)}`}
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

function CriterionSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="flex flex-col gap-1">
            <Label className="text-xs" htmlFor={`${label}-select`}>
                {label}
            </Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger
                    id={`${label}-select`}
                    className="w-52"
                    size="sm"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map(
                        ({ value: optionValue, label: optionLabel }) => (
                            <SelectItem key={optionValue} value={optionValue}>
                                {optionLabel}
                            </SelectItem>
                        ),
                    )}
                </SelectContent>
            </Select>
        </div>
    );
}

function RangeInput({
    id,
    label,
    name,
    value,
    min,
    max,
    step,
}: {
    id: string;
    label: string;
    name: string;
    value: number | null;
    min: number;
    max: number;
    step?: number;
}) {
    return (
        <div className="flex flex-col gap-1">
            <Label htmlFor={name} className="text-xs">
                {label}
            </Label>
            <Input
                id={id}
                type="number"
                name={name}
                defaultValue={value ?? ''}
                min={min}
                max={max}
                step={step}
                className="w-24"
            />
        </div>
    );
}

export default function MoviesIndex({ movies, filters }: MovieIndexProps) {
    const hasFilters = hasActiveFilters(filters);
    const lastMeaningfulYear = new Date().getFullYear() + 1;

    function apply(patch: Partial<MovieFilters>) {
        router.visit(index.url({ query: queryFor(filters, patch) }), {
            preserveScroll: true,
            replace: true,
        });
    }

    return (
        <>
            <Head title="Movies" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Search"
                        description="Search for a movie by title, then filter and sort the results"
                    />

                    <Form
                        method="post"
                        action="/movies/import"
                        className="rounded-lg border p-4"
                        data-test="import-torrent-form"
                    >
                        {({ processing, errors }) => (
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="w-full min-w-0 sm:flex-1">
                                    <Label
                                        htmlFor="torrent_url"
                                        className="sr-only"
                                    >
                                        Torrent or magnet link
                                    </Label>
                                    <Input
                                        id="torrent_url"
                                        type="text"
                                        name="torrent_url"
                                        placeholder="Add a torrent or magnet link..."
                                        aria-invalid={Boolean(
                                            errors.torrent_url,
                                        )}
                                        autoComplete="off"
                                        maxLength={2048}
                                        disabled={processing}
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={errors.torrent_url}
                                    />
                                </div>

                                <Button type="submit" disabled={processing}>
                                    Add video
                                </Button>
                            </div>
                        )}
                    </Form>

                    <Form
                        {...MovieController.index.form()}
                        options={{
                            preserveScroll: true,
                            replace: true,
                        }}
                    >
                        {({ processing, errors }) => (
                            <>
                                {/* Criteria set outside the form travel with the search. */}
                                <input
                                    type="hidden"
                                    name="sort"
                                    value={filters.sort}
                                />
                                <input
                                    type="hidden"
                                    name="dir"
                                    value={filters.dir}
                                />
                                <input
                                    type="hidden"
                                    name="genre"
                                    value={filters.genre ?? ''}
                                />
                                <input
                                    type="hidden"
                                    name="watched"
                                    value={filters.watched}
                                />

                                <div className="flex flex-wrap items-end gap-3">
                                    <div className="w-full min-w-0 sm:w-auto sm:min-w-48 sm:flex-1">
                                        <Label
                                            htmlFor="search"
                                            className="sr-only"
                                        >
                                            Search
                                        </Label>
                                        <Input
                                            key={`search-${filters.search ?? ''}`}
                                            id="search"
                                            type="search"
                                            name="search"
                                            defaultValue={filters.search ?? ''}
                                            placeholder="Search by title..."
                                            aria-invalid={Boolean(
                                                errors.search,
                                            )}
                                            autoComplete="off"
                                            maxLength={100}
                                        />
                                        <InputError
                                            className="mt-2"
                                            message={errors.search}
                                        />
                                    </div>

                                    <RangeInput
                                        key={`yearFrom-${filters.yearFrom ?? ''}`}
                                        id="yearFrom"
                                        label="Year from"
                                        name="yearFrom"
                                        value={filters.yearFrom}
                                        min={1870}
                                        max={lastMeaningfulYear}
                                    />
                                    <RangeInput
                                        key={`yearTo-${filters.yearTo ?? ''}`}
                                        id="yearTo"
                                        label="Year to"
                                        name="yearTo"
                                        value={filters.yearTo}
                                        min={1870}
                                        max={lastMeaningfulYear}
                                    />
                                    <RangeInput
                                        key={`ratingFrom-${filters.ratingFrom ?? ''}`}
                                        id="ratingFrom"
                                        label="IMDb from"
                                        name="ratingFrom"
                                        value={filters.ratingFrom}
                                        min={0}
                                        max={10}
                                        step={0.1}
                                    />
                                    <RangeInput
                                        key={`ratingTo-${filters.ratingTo ?? ''}`}
                                        id="ratingTo"
                                        label="IMDb to"
                                        name="ratingTo"
                                        value={filters.ratingTo}
                                        min={0}
                                        max={10}
                                        step={0.1}
                                    />

                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="shrink-0"
                                    >
                                        {processing ? 'Searching…' : 'Search'}
                                    </Button>
                                </div>

                                {(errors.yearFrom ||
                                    errors.yearTo ||
                                    errors.ratingFrom ||
                                    errors.ratingTo) && (
                                        <InputError className="mt-2">
                                            {
                                                (errors.yearFrom ??
                                                    errors.yearTo ??
                                                    errors.ratingFrom ??
                                                    errors.ratingTo) as string
                                            }
                                        </InputError>
                                    )}
                            </>
                        )}
                    </Form>

                    <div className="flex flex-wrap items-end gap-3">
                        <CriterionSelect
                            label="Sort by"
                            value={filters.sort}
                            options={Object.entries(filters.sorts).map(
                                ([value, label]) => ({ value, label }),
                            )}
                            onChange={(value) =>
                                apply({ sort: value as SortKey })
                            }
                        />

                        <CriterionSelect
                            label="Direction"
                            value={filters.dir}
                            options={directionOptions}
                            onChange={(value) =>
                                apply({ dir: value as SortDirection })
                            }
                        />

                        <CriterionSelect
                            label="Genre"
                            value={filters.genre ?? ANY_GENRE}
                            options={[
                                { value: ANY_GENRE, label: 'Any genre' },
                                ...filters.genres.map((genre) => ({
                                    value: genre,
                                    label:
                                        genre.charAt(0).toUpperCase() +
                                        genre.slice(1),
                                })),
                            ]}
                            onChange={(value) =>
                                apply({
                                    genre: value === ANY_GENRE ? null : value,
                                })
                            }
                        />

                        <div className="flex flex-col gap-1">
                            <Label className="text-xs">Watched</Label>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={filters.watched}
                                onValueChange={(value) => {
                                    if (value) {
                                        apply({
                                            watched: value as WatchedFilter,
                                        });
                                    }
                                }}
                            >
                                {watchedFilterOptions.map(
                                    ({ value, label }) => (
                                        <ToggleGroupItem
                                            key={value}
                                            value={value}
                                            aria-label={`Show ${label.toLowerCase()} movies`}
                                        >
                                            {label}
                                        </ToggleGroupItem>
                                    ),
                                )}
                            </ToggleGroup>
                        </div>

                        {hasFilters && (
                            <Button variant="outline" asChild>
                                <Link href={index()} replace>
                                    Clear filters
                                </Link>
                            </Button>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <span>
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
                        {`No movie found for title ${filters.search}.`}
                    </div>
                ) : hasFilters ? (
                    <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No movie matches these criteria.
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
